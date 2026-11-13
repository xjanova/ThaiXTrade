<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Copy Trade — เก็บค่าบริการจริงจากคลัง TPIX + หน้าแนะนำตัวมาสเตอร์ (เจ้าของสั่ง 2026-11-13)
 *
 *   ai_copy_fees.settled_at      เวลาที่หักจากคลัง TPIX ของผู้ตามสำเร็จ (null = ยังไม่ได้หัก)
 *   ai_copy_masters.intro        ข้อความแนะนำตัวบนหน้าโปรไฟล์ (ข้อความล้วน)
 *   ai_copy_media.featured_at    คลิปแนะนำบนหน้าโปรไฟล์ — ไม่ null = เป็นคลิปแนะนำ (เรียงตามเวลาที่ตั้ง)
 *
 * ⚠️ เวลาทางธุรกิจใช้ dateTime (BusinessTimeColumnsTest)
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('ai_copy_fees', function (Blueprint $table) {
            $table->dateTime('settled_at')->nullable()->after('charged_at');
        });

        Schema::table('ai_copy_masters', function (Blueprint $table) {
            $table->text('intro')->nullable()->after('bio');
        });

        Schema::table('ai_copy_media', function (Blueprint $table) {
            $table->dateTime('featured_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_copy_media', function (Blueprint $table) {
            $table->dropColumn('featured_at');
        });

        Schema::table('ai_copy_masters', function (Blueprint $table) {
            $table->dropColumn('intro');
        });

        Schema::table('ai_copy_fees', function (Blueprint $table) {
            $table->dropColumn('settled_at');
        });
    }
};
