<?php

namespace App\Console\Commands;

use App\Models\AiCopyMaster;
use App\Services\AiBot\Copy\CopyTradeService;
use App\Services\AiBot\Copy\RankService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ประเมินระดับ (rank) ของมาสเตอร์ทุกคนจากผลเงินจริงล่าสุด.
 *
 *   php artisan aibot:copy-rank
 *
 * ตั้งเวลาให้เดินวันละครั้ง (routes/console.php) — ระดับขึ้นทันทีเมื่อคะแนนถึง แต่จะลงก็ต่อเมื่อ
 * ต่ำกว่าเกณฑ์ต่อเนื่องครบช่วงผ่อน (RankService) การเดินวันละครั้งจึงเป็นหน่วยนับของช่วงผ่อนนั้นด้วย
 *
 * Developed by Xman Studio.
 */
class AiBotCopyRank extends Command
{
    protected $signature = 'aibot:copy-rank';

    protected $description = 'ประเมินระดับของมาสเตอร์ Copy Trade จากไม้เงินจริงที่ปิดแล้ว';

    public function handle(CopyTradeService $copy, RankService $ranks): int
    {
        if (! $copy->enabled()) {
            $this->line('Copy Trade ยังไม่เปิดใช้งาน — ข้าม');

            return self::SUCCESS;
        }

        $changed = 0;

        AiCopyMaster::open()->with('bot')->orderBy('id')->each(function (AiCopyMaster $master) use ($copy, $ranks, &$changed) {
            $before = (int) $master->rank_tier;

            try {
                $copy->refreshStats($master);
                $after = (int) $ranks->evaluate($master->fresh(['bot']))->rank_tier;
            } catch (\Throwable $e) {
                // มาสเตอร์คนเดียวพังต้องไม่ทำให้คนที่เหลือไม่ถูกประเมิน
                $this->warn("มาสเตอร์ #{$master->id}: ".$e->getMessage());

                return;
            }

            if ($after !== $before) {
                $changed++;
                $this->line("มาสเตอร์ #{$master->id} {$master->display_name}: ระดับ {$before} → {$after}");
            }
        });

        $this->info("ประเมินเสร็จ — ระดับเปลี่ยน {$changed} คน");

        return self::SUCCESS;
    }
}
