<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * โลโก้เหรียญ TPIX → ตราใหม่ของแบรนด์.
 *
 * เจ้าของสั่ง (2026-10-10): "โลโก้เหรียญเปลี่ยนให้หมดทุกจุด" — เหรียญ TPIX ใช้ตราเดียวกับแพลตฟอร์ม (ตัวย่อ)
 *
 * แถวเชน/โทเคนที่ seed ไว้ชี้ /tpixlogo.webp — ย้ายไปไฟล์ใหม่แทนการเขียนทับไฟล์เดิมอย่างเดียว
 * เพราะ /tpixlogo.webp ถูก Cloudflare แคชไว้ 1 ปี (ไม่มี ?v=) เขียนทับแล้วผู้ใช้ยังเห็นตัวเก่าอีกนาน
 * แตะเฉพาะแถวที่ยังเป็นค่า seed เดิม — โลโก้ที่แอดมินอัปโหลดเองไม่ยุ่ง
 *
 * Developed by Xman Studio.
 */
return new class extends Migration
{
    private const OLD = '/tpixlogo.webp';

    private const NEW = '/images/brand/tpix-coin.png';

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    private function swap(string $from, string $to): void
    {
        foreach (['chains', 'tokens'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'logo')) {
                DB::table($table)->where('logo', $from)->update(['logo' => $to]);
            }
        }
    }
};
