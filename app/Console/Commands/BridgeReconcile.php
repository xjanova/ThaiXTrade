<?php

namespace App\Console\Commands;

use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgeReconciler;
use App\Support\Wei;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * กระทบยอด supply ของ USDT_TPIX กับเงินหนุนจริงทุกเชน — ขาดสองรอบติดสั่งหยุดสะพานเอง.
 */
class BridgeReconcile extends Command
{
    protected $signature = 'bridge:reconcile';

    protected $description = 'กระทบยอด USDT_TPIX กับเงินหนุนทุกเชน (หยุดสะพานเองถ้าขาด)';

    public function handle(BridgeConfig $config, BridgeReconciler $reconciler): int
    {
        if (! $config->installed()) {
            $this->line('สะพาน USDT ยังไม่ได้ติดตั้ง — ข้าม');

            return self::SUCCESS;
        }

        $lock = Cache::lock('bridge:reconcile:lock', 600);
        if (! $lock->get()) {
            $this->warn('มีรอบกระทบยอดอื่นทำงานอยู่ — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        try {
            $snapshot = $reconciler->run();
        } finally {
            $lock->release();
        }

        $fmt = fn ($v) => $v === null ? '-' : Wei::format((string) $v, 6);
        $line = "สถานะ {$snapshot->status} · supply {$fmt($snapshot->supply_units)} · เงินหนุน {$fmt($snapshot->backing_units)}"
            ." · ขาด {$fmt($snapshot->deficit_units)}".($snapshot->action ? " · {$snapshot->action}" : '');

        $snapshot->status === 'ok' ? $this->info($line) : $this->warn($line);

        return self::SUCCESS;
    }
}
