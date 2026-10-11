<?php

namespace App\Console\Commands;

use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use App\Services\Bridge\Wtpix\WtpixBridgeReconciler;
use App\Support\Wei;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * กระทบยอด wTPIX supply (BSC) กับ TPIX ในคลัง (เชน 4289) — ขาดสองรอบติด หรือมี mint/ปล่อยที่ระบบไม่ได้สั่ง
 * = ปิดสวิตช์ + guardian pause ทั้ง minter และ vault.
 */
class BridgeWtpixReconcile extends Command
{
    protected $signature = 'bridge:wtpix-reconcile';

    protected $description = 'กระทบยอด wTPIX supply กับ TPIX ในคลังสะพาน (หยุดเองถ้าขาด)';

    public function handle(WtpixBridgeConfig $config, WtpixBridgeReconciler $reconciler): int
    {
        if (! $config->installed()) {
            $this->line('สะพาน TPIX⇄wTPIX ยังไม่ได้ติดตั้ง — ข้าม');

            return self::SUCCESS;
        }

        $lock = Cache::lock('wtpix-bridge:reconcile:lock', 600);
        if (! $lock->get()) {
            $this->warn('มีรอบกระทบยอดอื่นทำงานอยู่ — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        try {
            $snapshot = $reconciler->run();
        } finally {
            $lock->release();
        }

        $fmt = fn ($v) => $v === null ? '-' : Wei::format((string) $v, 18);
        $line = "สถานะ {$snapshot->status} · wTPIX supply {$fmt($snapshot->supply_wei)} · คลังสุทธิ {$fmt($snapshot->backing_wei)}"
            ." · ขาด {$fmt($snapshot->deficit_wei)}".($snapshot->action ? " · {$snapshot->action}" : '');

        $snapshot->status === 'ok' ? $this->info($line) : $this->warn($line);

        return self::SUCCESS;
    }
}
