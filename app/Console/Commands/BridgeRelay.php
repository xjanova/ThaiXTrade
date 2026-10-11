<?php

namespace App\Console\Commands;

use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgeRelayer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * mint USDT_TPIX ให้เงินฝากที่ยืนยันแล้ว ผ่าน TPIXBridgeMinter (กุญแจ relayer).
 *
 * ต้องอยู่ฝั่ง CLI เพราะการเซ็นเรียก ethers ผ่าน Node ซึ่ง php-fpm ทำไม่ได้
 * ล็อก "bridge:relayer" กันสองโปรเซสใช้ nonce ของกุญแจเดียวกันพร้อมกัน
 */
class BridgeRelay extends Command
{
    protected $signature = 'bridge:relay {--limit=5 : จำนวนรายการสูงสุดต่อรอบ}';

    protected $description = 'mint USDT_TPIX ให้เงินฝากที่ยืนยันแล้ว (และตามผลรายการที่ส่งไปแล้ว)';

    public function handle(BridgeConfig $config, BridgeRelayer $relayer): int
    {
        if (! $config->installed()) {
            $this->line('สะพาน USDT ยังไม่ได้ติดตั้ง — ข้าม');

            return self::SUCCESS;
        }

        $lock = Cache::lock('bridge:relayer:lock', 600);
        if (! $lock->get()) {
            $this->warn('มีรอบ relay อื่นทำงานอยู่ — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        try {
            $summary = $relayer->run(max(1, (int) $this->option('limit')));
        } finally {
            $lock->release();
        }

        $this->line("ตามผล {$summary['tracked']} · mint สำเร็จ {$summary['minted']} · เซ็น {$summary['signed']}"
            .($summary['blocked'] ? " · หยุด: {$summary['blocked']}" : ''));

        return self::SUCCESS;
    }
}
