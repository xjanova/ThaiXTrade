<?php

namespace App\Console\Commands;

use App\Models\AiBotDecision;
use App\Models\AiBotDemoAccount;
use App\Models\AiBotTrade;
use Illuminate\Console\Command;
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
    private function summariseDecisions(?\DateTimeInterface $since, ?string $wallet)
    {
        return AiBotDecision::query()
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->when($wallet, fn ($q) => $q->forWallet($wallet))
            ->get()
            ->groupBy('strategy')
            ->map(fn ($rows) => [
                'ticks' => $rows->count(),
                'buy' => $rows->where('action', 'buy')->count(),
                'sell' => $rows->where('action', 'sell')->count(),
                'hold' => $rows->where('action', 'hold')->count(),
                'stopped' => $rows->whereIn('action', ['stopped', 'error'])->count(),
                // เหตุผลที่พบบ่อยที่สุดตอนไม่ลงมือ = จุดที่ควรไปแก้ก่อนเพื่อน
                'top_hold_reason' => $rows->where('action', 'hold')
                    ->groupBy('reason')
                    ->sortByDesc(fn ($g) => $g->count())
                    ->keys()
                    ->first(),
            ]);
    }

    /** ไม้ที่ปิดแล้วเท่านั้น — ไม้ที่ยังไม่ปิดยังไม่รู้ผล เอามานับปนกันไม่ได้ */
    private function summariseTrades(?\DateTimeInterface $since, ?string $wallet)
    {
        return AiBotTrade::query()
            ->where('mode', 'demo')
            ->whereNotNull('realized_pnl')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->when($wallet, fn ($q) => $q->where('wallet_address', $wallet))
            ->get()
            ->groupBy('strategy')
            ->map(function ($rows) {
                $wins = $rows->where('realized_pnl', '>', 0)->count();

                return [
                    'closed' => $rows->count(),
                    'pnl' => round((float) $rows->sum('realized_pnl'), 2),
                    'fees' => round((float) $rows->sum('fee'), 2),
                    'win_rate' => $rows->count() > 0 ? round($wins / $rows->count() * 100, 1) : null,
                ];
            });
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
        $payload = [
            // เวลาที่เก็บ + ช่วงข้อมูล — ต้องมี ไม่งั้นไฟล์ที่เก็บคนละรอบแยกกันไม่ออก
            'harvested_at' => now()->toIso8601String(),
            'since' => $since?->format(DATE_ATOM),
            'wallet' => $wallet,
            'by_strategy' => $decisions->map(fn (array $d, string $s) => $d + ($trades[$s] ?? []))->all(),
            'decisions' => AiBotDecision::query()
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->when($wallet, fn ($q) => $q->forWallet($wallet))
                ->orderBy('id')
                ->get()
                ->toArray(),
        ];

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info('เขียนข้อมูลดิบลง '.$path.' ('.count($payload['decisions']).' รอบการตัดสินใจ)');
    }

    private function money(float $value): string
    {
        return ($value >= 0 ? '+' : '').number_format($value, 2);
    }
}
