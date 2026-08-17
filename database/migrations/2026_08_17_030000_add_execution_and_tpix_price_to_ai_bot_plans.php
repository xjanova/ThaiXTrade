<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — แยก "บอทที่รันบนคลาวด์" ออกจาก "บอทที่รันในเบราว์เซอร์" + ราคาเป็น TPIX.
 *
 *   execution = browser → บอททำงานเฉพาะตอนเปิดหน้าเว็บทิ้งไว้ ปิดแท็บแล้วหยุด (แพลนฟรี)
 *   execution = cloud   → เซิร์ฟเวอร์รันให้ ปิดเครื่องไปก็ยังทำงาน (แพลนที่เสียเงิน)
 *
 * ความต่างนี้ต้องอยู่ในฐานข้อมูล ไม่ใช่แค่ข้อความโฆษณา เพราะตัวสั่งงาน (aibot:tick)
 * ใช้ค่านี้ตัดสินว่าจะรันบอทตัวไหน — ถ้าเก็บไว้แค่ในคำบรรยาย แพลนฟรีจะได้คลาวด์ฟรีไปด้วย
 *
 * ราคาเก็บเป็น TPIX ต่อวัน (เจ้าของกำหนด: จ่ายด้วย TPIX เท่านั้น)
 *
 * ⚠️ ครอบด้วย hasColumn ทุกจุด — MySQL ไม่ทำ DDL แบบ transaction ถ้าพังกลางทาง
 *    ตารางจะค้างครึ่งๆ แล้ว migration ตัวหลังถูกบล็อกทั้งสาย (เจอมาแล้ว 2026-08-17)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('ai_bot_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_bot_plans', 'execution')) {
                $table->enum('execution', ['browser', 'cloud'])
                    ->default('cloud')
                    ->after('tier')
                    ->comment('browser = ต้องเปิดเว็บทิ้งไว้, cloud = เซิร์ฟเวอร์รันให้');
            }

            if (! Schema::hasColumn('ai_bot_plans', 'price_tpix_per_day')) {
                $table->decimal('price_tpix_per_day', 24, 8)
                    ->default(0)
                    ->after('credits_per_day')
                    ->comment('ราคาเช่าเป็น TPIX ต่อวัน (0 = ฟรี)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_bot_plans', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['execution', 'price_tpix_per_day'],
                fn ($column) => Schema::hasColumn('ai_bot_plans', $column)
            ));

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
