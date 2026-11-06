<?php

namespace App\Console\Commands;

use App\Services\Bridge\BridgeConfig;
use App\Services\Launchpad\LaunchpadAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * ตัวแทนขายของ Launchpad — ส่งเหรียญให้คำสั่งที่จ่ายแล้ว / คืนเงิน / เติมพูล (กุญแจ launchpad_agent).
 *
 * ทำงานต่อแม้ LAUNCHPAD_ENABLED=false — ปิดแค่ "รับคำสั่งใหม่" เงินที่เข้ามาแล้วต้องไปถึงผู้ซื้อเสมอ
 * ล็อก "launchpad:agent" กันสองโปรเซสใช้ nonce ของกุญแจเดียวกันพร้อมกัน
 */
class LaunchpadDeliver extends Command
{
    protected $signature = 'launchpad:deliver {--limit=5 : จำนวนคำสั่งสูงสุดต่อรอบ}';

    protected $description = 'ส่งเหรียญ Launchpad ให้คำสั่งที่จ่ายแล้ว (หรือคืนเงิน) และเติมพูล';

    public function handle(BridgeConfig $config, LaunchpadAgent $agent): int
    {
        if (! $config->installed()) {
            $this->line('สะพาน USDT ยังไม่ได้ติดตั้ง — ข้าม');

            return self::SUCCESS;
        }

        $lock = Cache::lock('launchpad:agent:lock', 600);
        if (! $lock->get()) {
            $this->warn('มีรอบตัวแทนอื่นทำงานอยู่ — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        try {
            $s = $agent->run(max(1, (int) $this->option('limit')));
        } finally {
            $lock->release();
        }

        $this->line("หมดเวลา {$s['expired']} · ยกเลิก {$s['cancelled']} · ตามผล {$s['tracked']} · ส่ง {$s['sent']}"
            ." · ส่งเหรียญแล้ว {$s['delivered']} · คืนเงิน {$s['refunded']} · เติมพูล {$s['fed']}"
            .($s['blocked'] ? " · หยุด: {$s['blocked']}" : ''));

        return self::SUCCESS;
    }
}
