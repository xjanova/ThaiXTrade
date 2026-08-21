<?php

namespace App\Console\Commands;

use App\Models\SalePhase;
use App\Models\TokenSale;
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

    public function handle(): int
    {
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
     * @return array<int, array<int, string>> รายการที่เปลี่ยน (สำหรับแสดงผล/บันทึก log)
     */
    private function advanceSale(TokenSale $sale, Carbon $now, bool $dryRun): array
    {
        $changes = [];

        /*
         * ทำทั้งก้อนในทรานแซกชันเดียว + ล็อกแถวไว้
         *
         * ถ้าปิดเฟสเก่าสำเร็จแล้วเปิดเฟสใหม่ล้ม จะเหลือสภาพ "ไม่มีเฟสไหน active เลย"
         * = รอบขายดับสนิทโดยไม่มีใครสั่ง ซึ่งแย่กว่าปล่อยให้สถานะเดิมค้างไว้
         */
        $runner = function () use ($sale, $now, &$changes) {
            $phases = $sale->phases()->orderBy('phase_order')->lockForUpdate()->get();

            // ── 1) ปิดเฟสที่เลยวันปิดไปแล้ว ─────────────────────────────────
            foreach ($phases as $phase) {
                if ($phase->status !== 'active') {
                    continue;
                }

                if ($phase->ends_at !== null && $phase->ends_at->lt($now)) {
                    $phase->status = 'completed';
                    $phase->save();

                    $changes[] = [$sale->name, $phase->name, 'active', 'completed', 'เลยวันปิด '.$phase->ends_at->format('Y-m-d H:i')];
                }
            }

            // ── 2) ยังมีเฟสที่เปิดขายได้จริงอยู่ไหม ──────────────────────────
            $stillOpen = $phases->first(fn (SalePhase $p) => $this->isOpen($p, $now));

            if ($stillOpen !== null) {
                return;
            }

            /*
             * ── 3) เปิดเฟสถัดไปที่ถึงกำหนดแล้ว ─────────────────────────────
             *
             * เปิดทีละเฟสเท่านั้น (เฟสลำดับต่ำสุดที่เข้าเงื่อนไข) เพราะถ้าเว้นช่วง
             * ไว้นานจนหลายเฟสถึงกำหนดพร้อมกัน การเปิดหมดทุกเฟสจะทำให้มีหลายราคา
             * เปิดขายพร้อมกัน แล้ว getActivePhase() ที่ใช้ ->first() จะหยิบมาแบบ
             * ที่ทายไม่ได้ — ลูกค้าอาจได้ราคาที่เราไม่ได้ตั้งใจขาย
             */
            $next = $phases
                ->filter(fn (SalePhase $p) => $p->status === 'upcoming')
                ->first(function (SalePhase $p) use ($now) {
                    $started = $p->starts_at === null || $p->starts_at->lte($now);
                    $notEnded = $p->ends_at === null || $p->ends_at->gte($now);

                    return $started && $notEnded;
                });

            if ($next === null) {
                return;
            }

            $next->status = 'active';
            $next->save();

            $changes[] = [$sale->name, $next->name, 'upcoming', 'active', 'ถึงกำหนดเปิด '.($next->starts_at?->format('Y-m-d H:i') ?? 'ทันที')];
        };

        if ($dryRun) {
            // อ่านอย่างเดียว — ม้วนกลับทุกอย่างเพื่อดูผลลัพธ์โดยไม่แตะข้อมูลจริง
            DB::beginTransaction();

            try {
                $runner();
            } finally {
                DB::rollBack();
            }

            return $changes;
        }

        DB::transaction($runner);

        return $changes;
    }

    /** เงื่อนไขต้องตรงกับ TokenSaleService::assertPhaseOpen() เป๊ะ */
    private function isOpen(SalePhase $phase, Carbon $now): bool
    {
        if ($phase->status !== 'active') {
            return false;
        }

        if ($phase->starts_at !== null && $phase->starts_at->gt($now)) {
            return false;
        }

        if ($phase->ends_at !== null && $phase->ends_at->lt($now)) {
            return false;
        }

        return true;
    }
}
