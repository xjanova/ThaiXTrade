<?php

namespace App\Console\Commands;

use App\Models\ThaipromptKycLink;
use App\Services\Kyc\ThaipromptKycService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ถามผลยืนยันตัวตนจาก Thaiprompt ให้เอง.
 *
 * สองกลุ่ม:
 *   1. ผูกแล้วแต่ยังไม่ผ่าน — ลูกค้าไปทำ eKYC ในแอป Thaiprompt แล้วไม่ได้กลับมาเปิดหน้าเว็บเรา
 *      ถ้าไม่มีคำสั่งนี้ สิทธิ์ของเขาจะไม่ขยับจนกว่าเขาจะกลับมาที่หน้า /kyc เอง
 *      ถามแค่ช่วง 30 วันแรก — หลังจากนั้น refresh token ของ Thaiprompt หมดอายุอยู่ดี
 *   2. ผ่านแล้ว — ตรวจทวนสัปดาห์ละครั้ง เผื่อ Thaiprompt ยกเลิกผล (เจอปลอม/แอดมินรีเซ็ต)
 *      และเพื่อต่ออายุ token ไม่ให้ขาด (refresh token ของ Thaiprompt อยู่ได้ 30 วัน)
 *
 * รันทุก 10 นาที (ดู routes/console.php)
 *
 * Developed by Xman Studio.
 */
class KycThaipromptSync extends Command
{
    protected $signature = 'kyc:thaiprompt-sync
        {--limit=100 : จำนวนบัญชีสูงสุดต่อรอบ}';

    protected $description = 'ถามผลยืนยันตัวตนจาก Thaiprompt ของบัญชีที่ผูกไว้';

    /** ลูกค้าที่รอทำ eKYC: ถามซ้ำไม่ถี่กว่านี้ */
    private const PENDING_EVERY_MINUTES = 5;

    /** ลูกค้าที่ผ่านแล้ว: ตรวจทวน */
    private const APPROVED_EVERY_DAYS = 7;

    public function handle(ThaipromptKycService $thaiprompt): int
    {
        if (! $thaiprompt->isConfigured()) {
            $this->line('ยังไม่ได้ตั้งค่า client ของ Thaiprompt — ข้าม');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $waiting = ThaipromptKycLink::query()
            ->where('needs_reconnect', false)
            ->where('kyc_status', '!=', ThaipromptKycLink::STATUS_APPROVED)
            ->where('linked_at', '>=', now()->subDays(30))
            ->where(fn (Builder $q) => $q
                ->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', now()->subMinutes(self::PENDING_EVERY_MINUTES)))
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();

        $approved = ThaipromptKycLink::query()
            ->where('needs_reconnect', false)
            ->where('kyc_status', ThaipromptKycLink::STATUS_APPROVED)
            ->where(fn (Builder $q) => $q
                ->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', now()->subDays(self::APPROVED_EVERY_DAYS)))
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $nowApproved = 0;
        $failed = 0;

        foreach ($waiting->concat($approved) as $link) {
            $was = $link->kyc_status;

            try {
                $link = $thaiprompt->refresh($link, force: true);
                $checked++;

                if ($was !== ThaipromptKycLink::STATUS_APPROVED && $link->isApproved()) {
                    $nowApproved++;
                }
            } catch (\Throwable $e) {
                // บัญชีหนึ่งพังต้องไม่หยุดทั้งรอบ — บันทึกเหตุสั้นๆ ไว้ให้หน้าเว็บ/แอดมินเห็น
                $failed++;
                $link->forceFill([
                    'last_checked_at' => now(),
                    'last_error' => mb_substr($e->getMessage(), 0, 120),
                ])->save();
                Log::warning('kyc:thaiprompt-sync failed for link', ['link_id' => $link->id, 'error' => get_class($e)]);
            }
        }

        $this->info("ถามแล้ว {$checked} บัญชี · เพิ่งผ่าน {$nowApproved} · ล้มเหลว {$failed}");

        return self::SUCCESS;
    }
}
