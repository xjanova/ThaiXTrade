<?php

namespace App\Console\Commands;

use App\Models\AiMarketView;
use App\Services\AiBotService;
use App\Services\MarketDataService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ให้คะแนน "ความฉลาด" ของ AI จากผลที่เกิดขึ้นจริง.
 *
 *   php artisan aibot:analyst-report --days=2
 *
 * ═══ ทำไมต้องมีคำสั่งนี้ ═══
 *
 * เจ้าของถามตรงๆ ว่า *"มันทำกำไรและเลือกเหรียญได้เองจริงไหม ให้น้ำหนักถูกต้องไหม
 * ฉลาดพอไหม ไม่ใช่บอทเอไอโง่ๆ พานักลงทุนไปเสียเงินเล่นๆ"*
 *
 * คำถามพวกนี้ตอบด้วยความรู้สึกไม่ได้ และตอบด้วย "กำไรของพอร์ต" ก็ไม่ได้ เพราะ
 * พอร์ตเคลื่อนจากหลายเหตุปนกัน (กฎของกลยุทธ์ · ด่านความเสี่ยง · จังหวะตลาด)
 * ต้องแยกวัด **คำตัดสินของ AI เอง** เทียบกับสิ่งที่เกิดขึ้นจริงหลังจากนั้น
 *
 * ⚠️ เกณฑ์ที่โหดที่สุดคือ "ชนะต้นทุนไหม" ไม่ใช่ "ทายถูกไหม"
 *    ออดิท 28 ส.ค. พิสูจน์แล้วว่า scalping ทายถูก-ผิดพอๆ กับการสุ่ม (edge = 0)
 *    แต่ขาดทุนจริงเพราะต้นทุนไป-กลับ 0.36% กินหมด — การทายถูกที่ราคาขยับ
 *    น้อยกว่าต้นทุนจึงไม่มีค่าอะไรเลย รายงานนี้แยกสองอย่างนี้ออกจากกันเสมอ
 *
 * Developed by Xman Studio.
 */
class AiBotAnalystReport extends Command
{
    protected $signature = 'aibot:analyst-report
        {--days=2 : ย้อนหลังกี่วัน}
        {--horizon=4 : วัดผลที่กี่ชั่วโมงหลังให้ความเห็น}';

    protected $description = 'ให้คะแนนคำตัดสินของ AI เทียบกับราคาที่เกิดขึ้นจริง';

    public function handle(MarketDataService $market, AiBotService $bots): int
    {
        $days = max(1, (int) $this->option('days'));
        $horizon = max(1, (int) $this->option('horizon'));
        $costBps = $bots->roundTripCostBps();

        $views = AiMarketView::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at')
            ->get();

        if ($views->isEmpty()) {
            $this->warn("ยังไม่มีมุมมองของ AI ในช่วง {$days} วันที่ผ่านมา");
            $this->line('ถ้าเพิ่งเปิดระบบ ให้รอรอบใหญ่รอบแรก (ทุก 4 ชั่วโมง) หรือสั่งเองด้วย');
            $this->line('  php artisan aibot:analyze --scope=strategic');

            return self::FAILURE;
        }

        $this->components->info("รายงานความแม่นของ AI — {$views->count()} มุมมอง · วัดผลที่ +{$horizon} ชม.");
        $this->line('ต้นทุนเข้า-ออกหนึ่งรอบ '.round($costBps, 1).' bps — คำทายที่ราคาขยับน้อยกว่านี้ถือว่าไม่มีค่า');
        $this->newLine();

        $calls = $this->scoreCalls($views, $market, $horizon, $costBps);

        if ($calls === []) {
            $this->warn('มีมุมมองอยู่ แต่ยังไม่ถึงเวลาวัดผล (ต้องรออย่างน้อย '.$horizon.' ชม. หลังแต่ละรอบ)');

            return self::FAILURE;
        }

        $this->reportStances($calls, $costBps);
        $this->reportCalibration($calls);
        $this->reportCoinPicking($views, $calls);
        $this->reportVerdict($calls, $costBps);

        return self::SUCCESS;
    }

    // ── ให้คะแนน ─────────────────────────────────────────────────────────────

    /**
     * จับคู่ทุกคำตัดสินกับราคาที่เกิดขึ้นจริงหลังจากนั้น.
     *
     * @return list<array{stance: string, confidence: float, score: float, symbol: string, move_bps: float, shortlisted: bool}>
     */
    private function scoreCalls($views, MarketDataService $market, int $horizon, float $costBps): array
    {
        $symbols = collect($views)
            ->flatMap(fn (AiMarketView $v) => array_keys((array) $v->coins))
            ->unique()
            ->values();

        $this->line('กำลังดึงราคาย้อนหลังของ '.$symbols->count().' เหรียญ...');

        $prices = [];

        foreach ($symbols as $symbol) {
            $prices[$symbol] = $this->priceSeries($market, $symbol);
        }

        $calls = [];

        foreach ($views as $view) {
            $shortlist = array_map(
                fn (string $pair) => AiMarketView::baseOf($pair),
                $view->shortlistPairs(),
            );

            foreach ((array) $view->coins as $symbol => $entry) {
                $series = $prices[$symbol] ?? [];

                $at = $this->priceAt($series, $view->created_at->getTimestamp());
                $later = $this->priceAt($series, $view->created_at->addHours($horizon)->getTimestamp());

                // ยังไม่ถึงเวลาวัด หรือไม่มีข้อมูลราคาของเหรียญนี้
                if ($at === null || $later === null || $at <= 0) {
                    continue;
                }

                $calls[] = [
                    'symbol' => $symbol,
                    'stance' => (string) ($entry['stance'] ?? 'hold'),
                    'score' => (float) ($entry['score'] ?? 0),
                    'confidence' => (float) $view->confidence,
                    'move_bps' => (($later - $at) / $at) * 10000,
                    'shortlisted' => in_array($symbol, $shortlist, true),
                ];
            }
        }

        return $calls;
    }

    /** @return array<int, float> timestamp(วินาที) => ราคาปิด */
    private function priceSeries(MarketDataService $market, string $symbol): array
    {
        try {
            $klines = $market->getKlines("{$symbol}/USDT", '1h', 200);
        } catch (\Throwable) {
            return [];
        }

        $series = [];

        foreach ($klines as $bar) {
            $series[(int) (((int) ($bar['time'] ?? 0)) / 1000)] = (float) ($bar['close'] ?? 0);
        }

        ksort($series);

        return $series;
    }

    /**
     * ราคา ณ เวลาที่ใกล้ที่สุด "ก่อนหรือเท่ากับ" ที่ขอ.
     *
     * ต้องไม่ใช้แท่งอนาคต — ใช้แท่งที่ยังไม่เกิด ณ เวลาที่ AI ตัดสิน จะกลายเป็น
     * การให้คะแนนด้วยข้อมูลที่มันไม่มีทางเห็น แล้วผลจะสวยเกินจริงทุกครั้ง
     */
    private function priceAt(array $series, int $timestamp): ?float
    {
        $best = null;

        foreach ($series as $ts => $price) {
            if ($ts > $timestamp) {
                break;
            }

            $best = $price;
        }

        return $best;
    }

    // ── รายงาน ───────────────────────────────────────────────────────────────

    private function reportStances(array $calls, float $costBps): void
    {
        $this->components->twoColumnDetail('<fg=cyan>ท่าทีที่ให้ไว้</>', '<fg=cyan>ผลที่เกิดขึ้นจริง</>');

        $rows = [];

        foreach (['buy', 'avoid', 'exit', 'hold'] as $stance) {
            $group = array_values(array_filter($calls, fn ($c) => $c['stance'] === $stance));

            if ($group === []) {
                continue;
            }

            $moves = array_column($group, 'move_bps');
            $avg = array_sum($moves) / count($moves);

            // ทายถูกไหม — buy ควรขึ้น · avoid/exit ควรลง · hold ไม่ตัดสิน
            $correct = match ($stance) {
                'buy' => count(array_filter($moves, fn ($m) => $m > 0)),
                'avoid', 'exit' => count(array_filter($moves, fn ($m) => $m < 0)),
                default => null,
            };

            // ชนะต้นทุนไหม — เกณฑ์ที่แพงกว่าและสำคัญกว่า
            $beatCost = match ($stance) {
                'buy' => count(array_filter($moves, fn ($m) => $m > $costBps)),
                'avoid', 'exit' => count(array_filter($moves, fn ($m) => $m < -$costBps)),
                default => null,
            };

            $rows[] = [
                $stance,
                count($group),
                $correct === null ? '—' : sprintf('%.0f%%', $correct / count($group) * 100),
                $beatCost === null ? '—' : sprintf('%.0f%%', $beatCost / count($group) * 100),
                sprintf('%+.1f', $avg),
            ];
        }

        $this->table(['ท่าที', 'จำนวน', 'ทายถูก', 'ชนะต้นทุน', 'ราคาขยับเฉลี่ย (bps)'], $rows);
    }

    private function reportCalibration(array $calls): void
    {
        /*
         * "ให้น้ำหนักถูกต้องไหม" วัดตรงนี้
         *
         * AI ที่ให้น้ำหนักเป็นควรทายถูกบ่อยขึ้นเมื่อมันบอกว่ามั่นใจมากขึ้น
         * ถ้าอัตราทายถูกเท่ากันทุกช่วงความมั่นใจ แปลว่าตัวเลขความมั่นใจของมัน
         * ไม่มีความหมาย — ซึ่งอันตรายกว่าทายผิด เพราะเราเอาไปคูณขนาดไม้
         */
        $buckets = [
            'ต่ำ (< 0.6)' => fn ($c) => $c['confidence'] < 0.6,
            'กลาง (0.6-0.8)' => fn ($c) => $c['confidence'] >= 0.6 && $c['confidence'] < 0.8,
            'สูง (≥ 0.8)' => fn ($c) => $c['confidence'] >= 0.8,
        ];

        $rows = [];

        foreach ($buckets as $label => $filter) {
            $group = array_values(array_filter($calls, fn ($c) => $filter($c) && $c['stance'] !== 'hold'));

            if ($group === []) {
                $rows[] = [$label, 0, '—', '—'];

                continue;
            }

            $correct = count(array_filter(
                $group,
                fn ($c) => $c['stance'] === 'buy' ? $c['move_bps'] > 0 : $c['move_bps'] < 0,
            ));

            $moves = array_column($group, 'move_bps');

            $rows[] = [
                $label,
                count($group),
                sprintf('%.0f%%', $correct / count($group) * 100),
                sprintf('%+.1f', array_sum($moves) / count($moves)),
            ];
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>ความมั่นใจ</>', '<fg=cyan>ยิ่งมั่นใจควรยิ่งแม่น</>');
        $this->table(['ช่วงความมั่นใจ', 'จำนวน', 'ทายถูก', 'ขยับเฉลี่ย (bps)'], $rows);
    }

    private function reportCoinPicking(array $views, array $calls): void
    {
        /*
         * "เลือกเหรียญได้เองจริงไหม" วัดด้วยการเทียบเหรียญที่มันคัด กับเหรียญ
         * ที่มันไม่ได้คัด ในช่วงเวลาเดียวกัน — ถ้าคัดแล้วไม่ต่างจากไม่คัด
         * ความสามารถในการเลือกเหรียญก็ยังพิสูจน์ไม่ได้
         */
        $picked = array_values(array_filter($calls, fn ($c) => $c['shortlisted']));
        $rest = array_values(array_filter($calls, fn ($c) => ! $c['shortlisted']));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>การเลือกเหรียญ</>', '<fg=cyan>คัดแล้วดีกว่าไม่คัดไหม</>');

        if ($picked === [] || $rest === []) {
            $this->line('  ยังเทียบไม่ได้ — ต้องมีทั้งเหรียญที่คัดและไม่คัดในช่วงเดียวกัน');

            return;
        }

        $avgPicked = array_sum(array_column($picked, 'move_bps')) / count($picked);
        $avgRest = array_sum(array_column($rest, 'move_bps')) / count($rest);

        $this->table(
            ['กลุ่ม', 'จำนวน', 'ขยับเฉลี่ย (bps)'],
            [
                ['เหรียญที่ AI คัดไว้', count($picked), sprintf('%+.1f', $avgPicked)],
                ['เหรียญที่ไม่ได้คัด', count($rest), sprintf('%+.1f', $avgRest)],
                ['ส่วนต่าง', '', sprintf('%+.1f', $avgPicked - $avgRest)],
            ],
        );
    }

    /**
     * สรุปแบบตรงไปตรงมา — ตอบคำถามเดียวที่เจ้าของถาม.
     *
     * ตั้งใจให้เกณฑ์ "ยังสรุปไม่ได้" กว้าง เพราะสองวันคือ sample เล็กมาก
     * การประกาศว่า AI เก่งจากข้อมูลไม่กี่สิบคำตัดสินคือความผิดพลาดแบบเดียว
     * กับที่ทำให้ตารางกลยุทธ์โชว์ "ชนะ 100%" จากไม้เดียว
     */
    private function reportVerdict(array $calls, float $costBps): void
    {
        $actionable = array_values(array_filter($calls, fn ($c) => $c['stance'] !== 'hold'));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>สรุป</>', '');

        if (count($actionable) < 30) {
            $this->warn('  ยังสรุปอะไรไม่ได้ — มีคำตัดสินที่วัดได้แค่ '.count($actionable).' ครั้ง');
            $this->line('  ต้องมีอย่างน้อย ~30 ครั้งถึงจะแยกฝีมือออกจากความบังเอิญได้');
            $this->line('  (บทเรียนจากออดิท 28 ส.ค.: กลยุทธ์ที่ "ชนะ 100%" จริงๆ มีแค่ไม้เดียว)');

            return;
        }

        $moves = array_map(
            fn ($c) => $c['stance'] === 'buy' ? $c['move_bps'] : -$c['move_bps'],
            $actionable,
        );

        $edge = array_sum($moves) / count($moves);

        $this->line(sprintf('  คำตัดสินที่วัดได้ %d ครั้ง · ได้เปรียบเฉลี่ย %+.1f bps ต่อครั้ง', count($actionable), $edge));
        $this->line(sprintf('  ต้นทุนเข้า-ออก %.1f bps', $costBps));
        $this->newLine();

        if ($edge > $costBps) {
            $this->info('  ✅ ชนะต้นทุน — AI มีความได้เปรียบจริงในช่วงที่วัด');
            $this->line('  ขั้นต่อไป: ปิดโหมดเงา (AIBOT_ANALYST_SHADOW=false) แล้วดูผลจริงต่ออีกช่วง');
        } elseif ($edge > 0) {
            $this->warn('  ⚠️ ทายถูกทางแต่ไม่ชนะต้นทุน — เปิดใช้ตอนนี้จะขาดทุนช้าๆ');
            $this->line('  อาการเดียวกับ scalping ที่ edge เป็นบวกนิดเดียวแต่ค่าธรรมเนียมกินหมด');
        } else {
            $this->error('  ❌ ยังไม่มีความได้เปรียบ — อย่าเพิ่งให้ AI แตะเงินจริง');
            $this->line('  คงโหมดเงาไว้ แล้วเก็บข้อมูลต่อ หรือทบทวนบริบทที่ส่งให้มัน');
        }
    }
}
