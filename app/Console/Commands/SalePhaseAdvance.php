<?php

namespace App\Console\Commands;

use App\Models\TokenSale;
use App\Services\TokenSaleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * เลื่อนสถานะเฟสรอบขายตามเวลาจริง — ปิดเฟสที่หมดอายุ แล้วเปิดเฟสถัดไป.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมต้องมีคำสั่งนี้
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิมไม่มีอะไรในระบบเลื่อนสถานะเฟสเลยสักตัว — `sale:reschedule` ตั้งวันให้ครั้งเดียว
 * แล้วปล่อยไว้ พอเลยวันปิดของเฟสแรก คอลัมน์ status ก็ยังเป็น 'active' อยู่อย่างนั้น
 *
 * ผลที่เกิดจริงบน production: เฟส "Private Sale" ค้าง active ตั้งแต่ 25 พ.ค.
 * จนถึง 21 ส.ค. (3 เดือน) โดยไม่มีสัญญาณเตือนใดๆ
 *   - `getActivePhase()` คืน null เพราะเช็คช่วงวันด้วย → ซื้อไม่ได้เลย
 *   - หน้าเว็บเช็คแค่ status → ยังโชว์ปุ่มซื้อตามปกติ
 *   - ผู้ใช้โอนเงินจริงบน BSC ก่อน แล้วหลังบ้านค่อยปฏิเสธ = เงินหาย
 *
 * ตัวนี้ทำให้สถานะในฐานข้อมูล "ตรงกับความจริง" เสมอ หน้าเว็บกับหลังบ้านจึงเห็นตรงกัน
 * และถ้าเฟสหมดจริงๆ ผู้ใช้จะเห็นว่าปิดแล้วตั้งแต่แรก แทนที่จะรู้ตอนเงินออกไปแล้ว
 *
 * Developed by Xman Studio.
 */
class SalePhaseAdvance extends Command
{
    protected $signature = 'sale:advance-phases
        {--dry-run : แสดงสิ่งที่จะเปลี่ยนโดยไม่บันทึกจริง}';

    protected $description = 'ปิดเฟสรอบขายที่หมดอายุ แล้วเปิดเฟสถัดไปที่ถึงกำหนด';

    private TokenSaleService $sales;

    public function handle(TokenSaleService $sales): int
    {
        $this->sales = $sales;
        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $changes = [];

        foreach (TokenSale::where('status', 'active')->get() as $sale) {
            $changes = array_merge($changes, $this->advanceSale($sale, $now, $dryRun));
        }

        if ($changes === []) {
            $this->info('ไม่มีเฟสไหนต้องเลื่อนสถานะ');

            return self::SUCCESS;
        }

        $this->table(['รอบขาย', 'เฟส', 'จาก', 'เป็น', 'เหตุผล'], $changes);

        if ($dryRun) {
            $this->comment('โหมดทดลอง (--dry-run) — ยังไม่บันทึกลงฐานข้อมูล');

            return self::SUCCESS;
        }

        // getActiveSale() แคชไว้ 30 วิ — ล้างทันที ไม่งั้นหน้าเว็บยังเห็นสถานะเก่า
        Cache::forget('token_sale:active');

        Log::info('sale:advance-phases เลื่อนสถานะเฟส', ['changes' => $changes]);
        $this->info('บันทึกแล้ว '.count($changes).' รายการ');

        return self::SUCCESS;
    }

    /**
     * เลื่อนสถานะของรอบขายหนึ่งรอบ.
     *
     * ตรรกะจริงอยู่ที่ TokenSaleService::advancePhases() ที่เดียว — ทางซื้อทั้งสามทาง
     * เรียกตัวเดียวกันทันทีที่เฟสขายหมด ตัวนี้เป็นแค่ทางสำรองรายชั่วโมง
     *
     * @return array<int, array<int, string>> รายการที่เปลี่ยน (สำหรับแสดงผล/บันทึก log)
     */
    private function advanceSale(TokenSale $sale, Carbon $now, bool $dryRun): array
    {
        /*
         * ทำทั้งก้อนในทรานแซกชันเดียว + ล็อกแถวไว้
         *
         * ถ้าปิดเฟสเก่าสำเร็จแล้วเปิดเฟสใหม่ล้ม จะเหลือสภาพ "ไม่มีเฟสไหน active เลย"
         * = รอบขายดับสนิทโดยไม่มีใครสั่ง ซึ่งแย่กว่าปล่อยให้สถานะเดิมค้างไว้
         */
        $runner = fn () => $this->sales->advancePhases($sale, $now);

        if ($dryRun) {
            // อ่านอย่างเดียว — ม้วนกลับทุกอย่างเพื่อดูผลลัพธ์โดยไม่แตะข้อมูลจริง
            DB::beginTransaction();

            try {
                return $runner();
            } finally {
                DB::rollBack();
            }
        }

        return DB::transaction($runner);
    }
}
