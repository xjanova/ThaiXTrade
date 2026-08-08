<?php

namespace App\Console\Commands;

use App\Models\InfraHeartbeat;
use App\Models\SystemAlert;
use Illuminate\Console\Command;

/**
 * ตรวจสัญญาณชีพของทุกเครื่องในโครงสร้างพื้นฐาน — รันทุก 1 นาทีผ่าน scheduler
 *
 * ชั้นนี้สำคัญที่สุดของระบบคาดแดง: watchdog บนเครื่องเชนแจ้งเหตุเองได้ทุกกรณี
 * ยกเว้นกรณีเดียวคือ "ทั้งเครื่องดับ" — เครื่องเว็บ (คนละเครื่องกัน) จึงต้อง
 * เป็นคนจับความเงียบเอง: heartbeat ขาดเกินเกณฑ์ = ยกเหตุ critical ทันที
 */
class CheckInfraHeartbeats extends Command
{
    protected $signature = 'infra:check-heartbeats';

    protected $description = 'ยกเหตุ heartbeat_missing เมื่อเครื่องในโครงสร้างพื้นฐานเงียบเกินเกณฑ์';

    public function handle(): int
    {
        $staleMinutes = (int) config('services.infra_alerts.stale_minutes', 3);
        $threshold = now()->subMinutes($staleMinutes);

        $stale = InfraHeartbeat::query()
            ->where('last_seen_at', '<', $threshold)
            ->get();

        foreach ($stale as $hb) {
            SystemAlert::raise(
                $hb->node,
                'heartbeat_missing',
                'critical',
                "ไม่ได้รับสัญญาณจาก {$hb->node} เกิน {$staleMinutes} นาที (ครั้งสุดท้าย: {$hb->last_seen_at?->format('H:i:s')} บล็อก {$hb->last_block}) — เครื่อง/เน็ต/watchdog อาจดับทั้งตัว ต้องเข้าไปดูทันที",
            );
        }

        // ฝั่งกลับ (node หายแล้วกลับมา) ไม่ต้องทำที่นี่ — heartbeat ที่เข้ามาใหม่
        // ปิดเหตุ heartbeat_missing ให้เองใน InfraAlertController::heartbeat()

        $this->info(sprintf('checked %d node(s), stale: %d', InfraHeartbeat::count(), $stale->count()));

        return self::SUCCESS;
    }
}
