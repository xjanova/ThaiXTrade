<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — เปลี่ยน tier จาก enum เป็น string.
 *
 * เดิมเป็น enum('basic','pro','vip') พอเพิ่มแพลนฟรี (tier = 'free') ก็ชน
 * CHECK constraint ทันที — ทั้งบน SQLite และ MySQL
 *
 * เลือกแก้เป็น string แทนการขยาย enum เพราะปัญหานี้จะกลับมาทุกครั้งที่เพิ่มชั้นใหม่
 * (ซึ่งเป็นเรื่องที่เกิดแน่ๆ กับระบบแพลน) ส่วนค่าที่ยอมรับได้จริงถูกคุมด้วย
 * AiBotPlan::TIER_RANK ในโค้ดอยู่แล้ว — กลยุทธ์ที่ tier ไม่รู้จักจะได้ rank 0 เอง
 *
 * ไม่ทำให้ข้อมูลเดิมหาย: basic/pro/vip ยังเป็นสตริงเดิมทุกแถว
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('ai_bot_plans', function (Blueprint $table) {
            $table->string('tier', 20)->default('basic')->change();
        });
    }

    public function down(): void
    {
        // ย้อนกลับเป็น enum ไม่ได้ถ้ามีแถว tier = 'free' ค้างอยู่ — ปล่อยเป็น string ปลอดภัยกว่า
    }
};
