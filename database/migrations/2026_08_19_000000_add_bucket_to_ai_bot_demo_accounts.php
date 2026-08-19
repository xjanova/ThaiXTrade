<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — พอร์ตทดลองแยกตามกลยุทธ์.
 *
 * เดิมมีพอร์ตเดียวต่อกระเป๋า (unique บน wallet_address) ทุกกลยุทธ์จึงกินเงิน
 * ก้อนเดียวกัน — เอาผลมาเทียบกันไม่ได้เลยว่ากลยุทธ์ไหนทำได้ดีกว่า เพราะ
 * กลยุทธ์ที่เข้าไม้ก่อนกินงบไปหมดแล้วตัวอื่นไม่เหลือให้เทรด
 *
 * `bucket` = รหัสกลยุทธ์ (null = พอร์ตรวมของเดิม เก็บไว้ไม่ให้ข้อมูลเก่าหาย)
 *
 * ⚠️ SQLite ที่ใช้ตอน dev เปลี่ยน index ไม่ได้ตรงๆ ต้องผ่าน doctrine หรือสร้างใหม่
 *    Laravel 11 จัดการ dropUnique/unique ให้ได้ทั้งสองฐาน แต่ต้องสั่งแยกคำสั่ง
 *    (รวมใน closure เดียวแล้ว SQLite สร้างตารางใหม่ก่อนจะลบ index เก่า → พัง)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_bot_demo_accounts')) {
            return;
        }

        if (! Schema::hasColumn('ai_bot_demo_accounts', 'bucket')) {
            Schema::table('ai_bot_demo_accounts', function (Blueprint $table) {
                $table->string('bucket', 40)->nullable()->after('wallet_address')
                    ->comment('รหัสกลยุทธ์ที่เป็นเจ้าของพอร์ตนี้ (null = พอร์ตรวมของเดิม)');
            });
        }

        Schema::table('ai_bot_demo_accounts', function (Blueprint $table) {
            $table->dropUnique(['wallet_address']);
        });

        Schema::table('ai_bot_demo_accounts', function (Blueprint $table) {
            $table->unique(['wallet_address', 'bucket'], 'uniq_demo_wallet_bucket');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_bot_demo_accounts')) {
            return;
        }

        Schema::table('ai_bot_demo_accounts', function (Blueprint $table) {
            $table->dropUnique('uniq_demo_wallet_bucket');
        });

        Schema::table('ai_bot_demo_accounts', function (Blueprint $table) {
            $table->unique(['wallet_address']);
        });

        Schema::table('ai_bot_demo_accounts', function (Blueprint $table) {
            $table->dropColumn('bucket');
        });
    }
};
