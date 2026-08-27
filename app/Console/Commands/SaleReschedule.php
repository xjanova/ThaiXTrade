<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * ตั้งตารางรอบขายใหม่ — ตอนนี้เป็นทางลัดไปที่ `sale:launch`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมเหลือแค่ตัวเรียกต่อ
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิมคำสั่งนี้มีตรรกะการไล่ตั้งวันของตัวเอง แล้ว `sale:launch` ก็มีอีกชุด
 * ซึ่งเป็นกับดักเดิม ๆ ของโปรเจกต์นี้: ตรรกะเดียวกันเขียนสองที่แล้วแตกกัน
 * (เคยเกิดมาแล้วกับด่าน "เฟสเปิดอยู่ไหม" ที่ preview กับ Stripe เช็คไม่เหมือนกัน
 *  จน Stripe ขายราคาเฟสที่ปิดไปแล้วได้ตลอดกาล)
 *
 * ตรรกะจริงอยู่ที่ App\Services\SaleLaunchService ที่เดียว
 *
 * ต่างกันตรงนี้: `sale:reschedule` ข้ามด่านความพร้อมเสมอ (--force)
 * เพราะเป็นเครื่องมือของแอดมินสำหรับ "ขยับวัน" ไม่ใช่ "เปิดขาย"
 * ถ้าต้องการเปิดขายจริงพร้อมด่านตรวจ ให้ใช้ `sale:launch`
 *
 * Developed by Xman Studio.
 */
class SaleReschedule extends Command
{
    protected $signature = 'sale:reschedule
        {--start= : วันเริ่มเฟสแรก (ค่าเริ่มต้น: เดี๋ยวนี้) เช่น 2026-08-20}
        {--days= : บังคับให้ทุกเฟสยาวเท่ากันกี่วัน (ค่าเริ่มต้น: ใช้ความยาวของแต่ละเฟส)}
        {--sale= : ระบุ id ของรอบขาย (ค่าเริ่มต้น: รอบที่ active อยู่)}
        {--dry-run : แสดงผลลัพธ์ที่จะเกิดขึ้นโดยไม่บันทึกจริง}';

    protected $description = 'ขยับวันของทุกเฟสให้เริ่มนับใหม่ (ข้ามด่านความพร้อม — ใช้ sale:launch ถ้าจะเปิดขายจริง)';

    public function handle(): int
    {
        $this->comment('sale:reschedule เรียก sale:launch --force ให้อีกที');

        return $this->call('sale:launch', array_filter([
            '--start' => $this->option('start'),
            '--days' => $this->option('days'),
            '--sale' => $this->option('sale'),
            '--dry-run' => $this->option('dry-run'),
            '--force' => true,
        ], fn ($v) => $v !== null && $v !== false));
    }
}
