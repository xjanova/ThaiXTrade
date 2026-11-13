<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Copy Trade เฟส D — ลิงก์ไลฟ์/คลิปของมาสเตอร์ (เจ้าของสั่ง 2026-11-13)
 *
 * เก็บแค่ "ผู้ให้บริการ + รหัสวิดีโอ" ที่ผ่านการตรวจรูปแบบแล้ว — ไม่เก็บลิงก์ดิบที่ผู้ใช้พิมพ์
 * และไม่โฮสต์วิดีโอเอง (ลิงก์ดู/ลิงก์ฝังถูกประกอบขึ้นใหม่ทุกครั้งที่ส่งออก ดู MediaLink)
 *
 * ai_copy_masters.media_revoked_at = สวิตช์ทีมงาน: ถอนสิทธิ์แปะลิงก์ของมาสเตอร์คนนี้ (ลิงก์เดิมหายจากหน้าสาธารณะทันที)
 *
 * ⚠️ เวลาทางธุรกิจใช้ dateTime (BusinessTimeColumnsTest)
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('ai_copy_masters', function (Blueprint $table) {
            $table->dateTime('media_revoked_at')->nullable()->after('rating_count');
        });

        Schema::create('ai_copy_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_copy_master_id')->constrained('ai_copy_masters')->cascadeOnDelete();
            // live = ไลฟ์สอน · clip = คลิป
            $table->string('kind', 8);
            $table->string('provider', 16);
            $table->string('external_id', 32);
            $table->string('title', 120);
            // visible = โชว์ · hidden = ทีมงานซ่อน
            $table->string('status', 16)->default('visible');
            $table->timestamps();

            $table->unique(['ai_copy_master_id', 'provider', 'external_id']);
            $table->index(['ai_copy_master_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_copy_media');

        Schema::table('ai_copy_masters', function (Blueprint $table) {
            $table->dropColumn('media_revoked_at');
        });
    }
};
