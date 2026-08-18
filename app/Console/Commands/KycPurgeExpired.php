<?php

namespace App\Console\Commands;

use App\Models\KycSubmission;
use App\Services\Kyc\KycPurgeService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ล้างเอกสารยืนยันตัวตนที่ครบกำหนดเก็บแล้ว.
 *
 * PDPA ไม่ให้เก็บข้อมูลส่วนบุคคลไว้เกินความจำเป็นตามวัตถุประสงค์
 * ถ้าไม่มีคำสั่งนี้ บัตรประชาชนทุกใบที่เคยส่งมาจะอยู่บนดิสก์เราตลอดไป
 * ซึ่งเป็นทั้งการผิดกฎหมายและเป็นกองข้อมูลที่รอวันรั่ว
 *
 * รันอัตโนมัติทุกวันตี 3 (ดู routes/console.php)
 *
 * Developed by Xman Studio.
 */
class KycPurgeExpired extends Command
{
    protected $signature = 'kyc:purge
        {--limit=200 : จำนวนใบสูงสุดต่อรอบ}
        {--dry-run : ดูว่าจะล้างกี่ใบโดยยังไม่ลบจริง}';

    protected $description = 'ล้างเอกสารและข้อมูลส่วนบุคคลของใบ KYC ที่ครบกำหนดเก็บแล้ว';

    public function handle(KycPurgeService $purge): int
    {
        $limit = (int) $this->option('limit');

        if ($this->option('dry-run')) {
            $due = KycSubmission::query()
                ->whereNull('purged_at')
                ->whereNotNull('purge_after')
                ->where('purge_after', '<=', now())
                ->where('status', '!=', KycSubmission::STATUS_PENDING)
                ->count();

            $this->info("มีใบที่ครบกำหนดล้าง {$due} ใบ (ยังไม่ได้ลบ เพราะใส่ --dry-run)");

            return self::SUCCESS;
        }

        $result = $purge->runRetention($limit);

        $this->info(sprintf(
            'ล้างแล้ว %d ใบ · ลบไฟล์ %d ไฟล์',
            $result['submissions'],
            $result['files'],
        ));

        return self::SUCCESS;
    }
}
