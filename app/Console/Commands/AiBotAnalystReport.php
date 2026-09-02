<?php

namespace App\Console\Commands;

use App\Models\AiMarketView;
use App\Services\AiBot\Analyst\AnalystCalibration;
use App\Services\AiBot\Analyst\AnalystScorer;
use App\Services\AiBotService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ให้คะแนน "ความฉลาด" ของ AI จากผลที่เกิดขึ้นจริง.
 *
 *   php artisan aibot:analyst-report --days=4
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
 * การให้คะแนนอยู่ใน AnalystScorer (ใช้ร่วมกับ calibration และส่วน "ความจำ" ใน prompt)
 * — สามที่นี้ต้องได้เลขชุดเดียวกัน
 *
 * Developed by Xman Studio.
 */
class AiBotAnalystReport extends Command
{
    protected $signature = 'aibot:analyst-report
        {--days=4 : ย้อนหลังกี่วัน}
        {--horizon=4 : วัดผลที่กี่ชั่วโมงหลังให้ความเห็น}';

    protected $description = 'ให้คะแนนคำตัดสินของ AI เทียบกับราคาที่เกิดขึ้นจริง';

    public function handle(AnalystScorer $scorer, AiBotService $bots): int
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

        if ($days * 24 + $horizon > 500) {
            $this->warn("หน้าต่าง {$days} วันเกินข้อมูลราคาที่ดึงได้ (500 แท่ง) — มุมมองช่วงต้นจะถูกข้าม");
        }

        $this->newLine();

        $calls = $scorer->score($views, $horizon);

        if ($calls === []) {
            $this->warn('มีมุมมองอยู่ แต่ยังไม่ถึงเวลาวัดผล (ต้องรออย่างน้อย '.$horizon.' ชม. หลังแต่ละรอบ)');

            return self::FAILURE;
        }

        $this->reportStances($scorer, $calls);
        $this->reportCalibration($calls);
        $this->reportCoinPicking($calls);
        $this->reportVerdict($calls, $costBps);

        return self::SUCCESS;
    }

    // ── รายงาน ───────────────────────────────────────────────────────────────

    private function reportStances(AnalystScorer $scorer, array $calls): void
    {
        $this->components->twoColumnDetail('<fg=cyan>ท่าทีที่ให้ไว้</>', '<fg=cyan>ผลที่เกิดขึ้นจริง</>');

        $rows = [];

        foreach ($scorer->summarizeByStance($calls) as $stance => $s) {
            $rows[] = [
                $stance,
                $s['n'],
                $s['correct_pct'] === null ? '—' : sprintf('%.0f%%', $s['correct_pct']),
                $s['beat_cost_pct'] === null ? '—' : sprintf('%.0f%%', $s['beat_cost_pct']),
                sprintf('%+.1f', $s['avg_move_bps']),
            ];
        }

        $this->table(['ท่าที', 'จำนวน', 'ทายถูก', 'ชนะต้นทุน', 'ราคาขยับเฉลี่ย (bps)'], $rows);

        $brier = AnalystScorer::brier($calls);

        if ($brier !== null) {
            // 0.25 = โยนเหรียญ · ต่ำกว่า = ความน่าจะเป็นที่ให้มีความหมาย · สูงกว่า = มั่นใจผิดทาง
            $this->line(sprintf('  Brier score ของ p_up: %.3f (0.25 = โยนเหรียญ · ยิ่งต่ำยิ่งดี)', $brier));
        }
    }

    private function reportCalibration(array $calls): void
    {
        /*
         * "ให้น้ำหนักถูกต้องไหม" วัดตรงนี้
         *
         * AI ที่ให้น้ำหนักเป็นควรทายถูกบ่อยขึ้นเมื่อมันบอกว่ามั่นใจมากขึ้น
         * ถ้าอัตราทายถูกเท่ากันทุกช่วงความมั่นใจ แปลว่าตัวเลขความมั่นใจของมัน
         * ไม่มีความหมาย — ซึ่งอันตรายกว่าทายผิด เพราะเราเอาไปคูณขนาดไม้
         *
         * ตารางเดียวกับที่ AiViewGate ใช้ (AnalystCalibration::tabulate) — ที่นี่แค่พิมพ์
         */
        $labels = ['low' => 'ต่ำ (< 0.6)', 'mid' => 'กลาง (0.6-0.8)', 'high' => 'สูง (≥ 0.8)'];
        $table = AnalystCalibration::tabulate($calls);
        $rows = [];

        foreach ($labels as $bucket => $label) {
            $n = 0;
            $hits = 0.0;
            $move = 0.0;

            foreach (['buy', 'avoid', 'exit'] as $stance) {
                $cell = $table[$stance][$bucket];
                $n += $cell['n'];
                $hits += ($cell['hit_rate'] ?? 0) * $cell['n'];
                $move += ($cell['avg_move_bps'] ?? 0) * $cell['n'];
            }

            $rows[] = $n === 0
                ? [$label, 0, '—', '—']
                : [$label, $n, sprintf('%.0f%%', $hits / $n * 100), sprintf('%+.1f', $move / $n)];
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>ความมั่นใจ</>', '<fg=cyan>ยิ่งมั่นใจควรยิ่งแม่น</>');
        $this->table(['ช่วงความมั่นใจ', 'จำนวน', 'ทายถูก', 'ขยับเฉลี่ย (bps)'], $rows);
    }

    private function reportCoinPicking(array $calls): void
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
