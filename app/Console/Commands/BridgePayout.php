<?php

namespace App\Console\Commands;

use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgePayouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * จ่าย USDT จริงบนเชนปลายทางให้คำขอถอน (กุญแจ payout) และตามผลรายการที่ส่งไปแล้ว.
 *
 * ล็อก "bridge:payout" กันสองโปรเซสใช้ nonce ของกระเป๋าจ่ายพร้อมกัน
 */
class BridgePayout extends Command
{
    protected $signature = 'bridge:payout {--limit=3 : จำนวนรายการสูงสุดต่อเชนต่อรอบ}';

    protected $description = 'จ่าย USDT ให้คำขอถอนของสะพาน (และตามผลรายการที่ส่งไปแล้ว)';

    public function handle(BridgeConfig $config, BridgePayouter $payouter): int
    {
        if (! $config->installed()) {
            $this->line('สะพาน USDT ยังไม่ได้ติดตั้ง — ข้าม');

            return self::SUCCESS;
        }

        $lock = Cache::lock('bridge:payout:lock', 900);
        if (! $lock->get()) {
            $this->warn('มีรอบจ่ายอื่นทำงานอยู่ — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        try {
            $summary = $payouter->run(max(1, (int) $this->option('limit')));
        } finally {
            $lock->release();
        }

        $chains = collect($summary['chains'])->map(fn ($why, $id) => "{$id}:".($why ?? 'ok'))->implode(' ');
        $this->line("ตามผล {$summary['tracked']} · จ่ายสำเร็จ {$summary['paid']} · เซ็น {$summary['signed']}"
            .($chains !== '' ? " · {$chains}" : '')
            .($summary['blocked'] ? " · หยุด: {$summary['blocked']}" : ''));

        return self::SUCCESS;
    }
}
