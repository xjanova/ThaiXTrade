<?php

namespace App\Console\Commands;

use App\Models\AiBotDecision;
use App\Models\AiBotDemoAccount;
use App\Models\AiBotTrade;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * TPIX TRADE — เก็บผลการทดลองของบอทออกมาดู/ส่งต่อ.
 *
 * เจ้าของสั่งให้รันบนคลาวด์เก็บข้อมูลไว้ก่อน แล้วค่อยสั่งเก็บอีกครั้งเพื่อเอาไปปรับปรุง
 * คำสั่งนี้คือ "การสั่งเก็บ" นั้น — อ่านอย่างเดียว ไม่ลบ ไม่แก้ข้อมูลใดๆ
 *
 *   php artisan aibot:harvest                  → สรุปรายกลยุทธ์บนหน้าจอ
 *   php artisan aibot:harvest --days=7         → เฉพาะ 7 วันล่าสุด
 *   php artisan aibot:harvest --export=out.json → เขียนข้อมูลดิบลงไฟล์ด้วย
 *
 * ⚠️ ตัวเลขทั้งหมดมาจากโหมดทดลอง (paper trading ด้วยราคาจริง) ที่คิดค่าธรรมเนียม
 *    และ slippage เข้าข้างตลาดเสมอ — ตั้งใจให้ผลแย่กว่าความจริงเล็กน้อย
 *    ตัวเลขที่เห็นจึงเป็นขอบล่าง ไม่ใช่ค่าที่สวยเกินจริง
 *
 * Developed by Xman Studio.
 */
class AiBotHarvest extends Command
{
    protected $signature = 'aibot:harvest {--days= : ดูเฉพาะ N วันล่าสุด (ไม่ระบุ = ทั้งหมด)}
                            {--wallet= : เจาะจงกระเป๋าเดียว}
                            {--export= : เขียนข้อมูลดิบเป็น JSON ลงไฟล์ที่ระบุ}';

    protected $description = 'สรุปผลการทดลองของบอท AI TRADE รายกลยุทธ์ (อ่านอย่างเดียว)';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $wallet = $this->option('wallet') ? strtolower($this->option('wallet')) : null;
        $since = $days ? now()->subDays($days) : null;

        $this->line($since
            ? 'ช่วงเวลา: '.$since->toDateTimeString().' → ปัจจุบัน'
            : 'ช่วงเวลา: ตั้งแต่เริ่มเก็บข้อมูล');
        $this->newLine();

        $decisions = $this->summariseDecisions($since, $wallet);
        $trades = $this->summariseTrades($since, $wallet);

        if ($decisions->isEmpty()) {
            $this->warn('ยังไม่มีข้อมูลการตัดสินใจเลย — ตรวจว่าตัวจับเวลาทำงานอยู่ไหม (crontab -l | grep schedule:run)');

            return self::SUCCESS;
        }

        $this->renderTable($decisions, $trades);
        $this->renderPortfolios($wallet);

        if ($path = $this->option('export')) {
            $this->export($path, $since, $wallet, $decisions, $trades);
        }

        return self::SUCCESS;
    }

    /**
     * รอบการคิดทั้งหมด แยกตามกลยุทธ์.
     *
     * นับ "ทุกครั้งที่คิด" ไม่ใช่เฉพาะตอนลงมือ — สัดส่วนของการถือเทียบกับการลงมือ
     * คือตัวเลขที่บอกได้เร็วที่สุดว่ากลยุทธ์ไหนเงียบเกินไปจนลูกค้าจ่ายแล้วไม่ได้อะไร
     */
    private function summariseDecisions(?\DateTimeInterface $since, ?string $wallet): Collection
    {
        /*
         * รวมในฐานข้อมูล ไม่ใช่โหลดทุกแถวขึ้นมา groupBy ในหน่วยความจำ
         *
         * ⚠️ เดิม ->get() ทั้งตาราง — 81,105 แถวบน prod (2 ก.ย. 2026) ทำให้คำสั่งนี้
         *    ตายด้วย "Allowed memory size of 134217728 bytes exhausted" ก่อนจะพิมพ์
         *    ตารางแรก คำสั่งเก็บผลที่ใช้ไม่ได้ตอนข้อมูลเยอะ คือคำสั่งที่ใช้ไม่ได้ตอน
         *    ที่ต้องการมันที่สุด
         *
         * repeat_count: หนึ่งแถวแทนหลายรอบที่สภาพเหมือนเดิม (ดู AiBotDecision) —
         * "รอบคิด" จึงต้องบวกตัวนับ ไม่ใช่นับแถว ส่วน buy/sell/stopped เกิดรอบละครั้ง
         */
        $base = AiBotDecision::query()
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->when($wallet, fn ($q) => $q->forWallet($wallet));

        $rows = (clone $base)
            ->selectRaw(implode(', ', [
                'strategy',
                'SUM(COALESCE(repeat_count, 1)) AS ticks',
                "SUM(CASE WHEN action = 'buy' THEN 1 ELSE 0 END) AS buy",
                "SUM(CASE WHEN action = 'sell' THEN 1 ELSE 0 END) AS sell",
                "SUM(CASE WHEN action = 'hold' THEN COALESCE(repeat_count, 1) ELSE 0 END) AS hold",
                "SUM(CASE WHEN action IN ('stopped', 'error') THEN 1 ELSE 0 END) AS stopped",
            ]))
            ->groupBy('strategy')
            ->get();

        // เหตุผลที่พบบ่อยที่สุดตอนไม่ลงมือ = จุดที่ควรไปแก้ก่อนเพื่อน
        $topReasons = (clone $base)
            ->where('action', 'hold')
            ->selectRaw('strategy, reason, SUM(COALESCE(repeat_count, 1)) AS n')
            ->groupBy('strategy', 'reason')
            ->orderByDesc('n')
            ->get()
            ->groupBy('strategy')
            ->map(fn ($group) => (string) $group->first()->reason);

        return $rows->keyBy('strategy')->map(fn ($r) => [
            'ticks' => (int) $r->ticks,
            'buy' => (int) $r->buy,
            'sell' => (int) $r->sell,
            'hold' => (int) $r->hold,
            'stopped' => (int) $r->stopped,
            'top_hold_reason' => $topReasons[$r->strategy] ?? null,
        ]);
    }

    /** ไม้ที่ปิดแล้วเท่านั้น — ไม้ที่ยังไม่ปิดยังไม่รู้ผล เอามานับปนกันไม่ได้ */
    private function summariseTrades(?\DateTimeInterface $since, ?string $wallet): Collection
    {
        return AiBotTrade::query()
            ->where('mode', 'demo')
            ->whereNotNull('realized_pnl')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->when($wallet, fn ($q) => $q->where('wallet_address', $wallet))
            ->selectRaw(implode(', ', [
                'strategy',
                'COUNT(*) AS closed',
                'SUM(realized_pnl) AS pnl',
                'SUM(fee) AS fees',
                'SUM(CASE WHEN realized_pnl > 0 THEN 1 ELSE 0 END) AS wins',
            ]))
            ->groupBy('strategy')
            ->get()
            ->keyBy('strategy')
            ->map(fn ($r) => [
                'closed' => (int) $r->closed,
                'pnl' => round((float) $r->pnl, 2),
                'fees' => round((float) $r->fees, 2),
                'win_rate' => (int) $r->closed > 0 ? round((int) $r->wins / (int) $r->closed * 100, 1) : null,
            ]);
    }

    private function renderTable($decisions, $trades): void
    {
        $rows = $decisions->map(function (array $d, string $strategy) use ($trades) {
            $t = $trades[$strategy] ?? ['closed' => 0, 'pnl' => 0.0, 'fees' => 0.0, 'win_rate' => null];

            return [
                $strategy,
                $d['ticks'],
                $d['buy'].' / '.$d['sell'],
                $d['hold'],
                $t['closed'],
                $t['win_rate'] === null ? '—' : $t['win_rate'].'%',
                $this->money($t['pnl']),
                mb_strimwidth((string) $d['top_hold_reason'], 0, 42, '…'),
            ];
        })->values()->all();

        $this->table(
            ['กลยุทธ์', 'รอบคิด', 'ซื้อ/ขาย', 'ถือ', 'ปิดไม้', 'ชนะ', 'กำไรสุทธิ', 'เหตุผลที่ถือบ่อยสุด'],
            $rows,
        );
    }

    /** ยอดคงเหลือของแต่ละพอร์ตทดลอง — เทียบกลยุทธ์กันได้ตรงๆ เพราะเริ่มทุนเท่ากัน */
    private function renderPortfolios(?string $wallet): void
    {
        $accounts = AiBotDemoAccount::query()
            ->when($wallet, fn ($q) => $q->where('wallet_address', $wallet))
            ->get();

        if ($accounts->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('พอร์ตทดลองแยกตามกลยุทธ์');

        $this->table(
            ['กลยุทธ์', 'ทุนตั้งต้น', 'คงเหลือ', 'ส่วนต่าง'],
            $accounts->sortBy('bucket')->map(fn (AiBotDemoAccount $a) => [
                $a->bucket ?? '(พอร์ตรวมเดิม)',
                $this->money((float) $a->starting_balance),
                $this->money((float) $a->balance),
                $this->money((float) $a->balance - (float) $a->starting_balance),
            ])->values()->all(),
        );
    }

    private function export(string $path, ?\DateTimeInterface $since, ?string $wallet, $decisions, $trades): void
    {
        $header = [
            // เวลาที่เก็บ + ช่วงข้อมูล — ต้องมี ไม่งั้นไฟล์ที่เก็บคนละรอบแยกกันไม่ออก
            'harvested_at' => now()->toIso8601String(),
            'since' => $since?->format(DATE_ATOM),
            'wallet' => $wallet,
            'by_strategy' => $decisions->map(fn (array $d, string $s) => $d + ($trades[$s] ?? []))->all(),
        ];

        File::ensureDirectoryExists(dirname($path));

        /*
         * สตรีมทีละแถว ไม่ประกอบอาเรย์ทั้งก้อนแล้ว json_encode — เหตุผลเดียวกับ
         * summariseDecisions(): ข้อมูลดิบคือส่วนที่โตไม่หยุด และมันคือส่วนที่คนขอ export
         */
        $handle = fopen($path, 'w');
        $json = json_encode($header, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        fwrite($handle, rtrim(substr($json, 0, -1)).",\n  \"decisions\": [\n");

        $count = 0;
        $query = AiBotDecision::query()
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->when($wallet, fn ($q) => $q->forWallet($wallet))
            ->orderBy('id');

        foreach ($query->cursor() as $row) {
            fwrite($handle, ($count > 0 ? ",\n" : '').'    '.json_encode($row->toArray(), JSON_UNESCAPED_UNICODE));
            $count++;
        }

        fwrite($handle, "\n  ]\n}\n");
        fclose($handle);

        $this->newLine();
        $this->info('เขียนข้อมูลดิบลง '.$path.' ('.$count.' รอบการตัดสินใจ)');
    }

    private function money(float $value): string
    {
        return ($value >= 0 ? '+' : '').number_format($value, 2);
    }
}
