<?php

namespace App\Console\Commands;

use App\Services\SaleClaimPayoutService;
use Illuminate\Console\Command;

/**
 * ตามเก็บโควตาของออเดอร์ที่ปิดแล้วซึ่ง TreasuryPayout::updated คืนไม่สำเร็จ.
 *
 * ปกติโควตาคืนทันทีที่แถวจ่ายแถวสุดท้ายจบโดยเงินไม่ออก แต่ตัวนั้นทำงานครั้งเดียวและกลืน error
 * ถ้าล้ม (deadlock, ฐานข้อมูลสะดุด) จะไม่มีเหตุการณ์ไหนมาปลุกอีก โควตาก้อนนั้นจะค้างถาวร
 *
 * ลืมรันแค่ทำให้ขายได้น้อยลงชั่วคราว ไม่ทำให้ขายเกิน
 */
class SaleReleaseKeptQuota extends Command
{
    protected $signature = 'sale:release-kept-quota';

    protected $description = 'คืนโควตาของออเดอร์ที่ปิดแล้วและพิสูจน์ได้ว่าไม่มีเหรียญออกไป';

    public function handle(SaleClaimPayoutService $claims): int
    {
        $result = $claims->releaseStrandedQuotas();

        $this->line("คืนโควตา {$result['released']} ออเดอร์ · ล้มเหลว {$result['failed']} ออเดอร์");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
