<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ทะเบียนผู้สร้างเหรียญ — เข้าด้วยกระเป๋า กรอกข้อมูล ผ่าน KYC แล้วเซ็นข้อตกลงด้วยกระเป๋า.
 *
 * หนึ่งกระเป๋ามีใบเดียว (unique) — ใบที่ถูกปฏิเสธแก้แล้วส่งใหม่ได้ในแถวเดิม
 * ประวัติการตัดสินอยู่ใน audit_logs
 *
 * ⚠️ เวลาทุกคอลัมน์เป็น dateTime ไม่ใช่ timestamp — MariaDB บนเซิร์ฟเวอร์จริงใส่
 *    ON UPDATE current_timestamp() ให้ TIMESTAMP NOT NULL ตัวแรกของตาราง (ดู BusinessTimeColumnsTest)
 *    เวลาลงนามที่เปลี่ยนเองทุกครั้งที่แอดมินกดอนุมัติ = หลักฐานพัง
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('creator_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('wallet_address', 42)->unique();
            $table->string('status', 20)->default('draft')->index();

            // ข้อมูลที่หลังบ้านต้องใช้คัดคิว — ที่เหลืออยู่ใน form_data (เข้ารหัส)
            $table->string('applicant_type', 20)->nullable();
            $table->string('project_name', 120)->nullable();
            $table->string('token_symbol', 16)->nullable();
            $table->text('form_data')->nullable();
            $table->string('form_hash', 64)->nullable();

            // หลักฐานว่าคุมกระเป๋านี้จริง (personal_sign ตอนเริ่มลงทะเบียน)
            $table->text('ownership_message')->nullable();
            $table->string('ownership_signature', 132)->nullable();
            $table->dateTime('ownership_signed_at')->nullable();
            $table->string('ownership_ip', 45)->nullable();

            // ลงนามข้อตกลง — hash ของเอกสารฉบับนั้น + hash ของข้อมูลที่กรอก ผูกอยู่ในข้อความที่เซ็น
            $table->string('agreement_version', 20)->nullable();
            $table->string('agreement_hash', 64)->nullable();
            $table->text('agreement_message')->nullable();
            $table->string('agreement_signature', 132)->nullable();
            $table->dateTime('agreement_signed_at')->nullable();
            $table->string('agreement_ip', 45)->nullable();
            $table->string('agreement_user_agent', 255)->nullable();

            $table->dateTime('submitted_at')->nullable();

            // ส่วนเริ่มโครงการ (basis points ของซัพพลายเหรียญลูก) — แอดมินกำหนดตอนอนุมัติ เพดาน 2000 = 20%
            $table->unsignedSmallInteger('starter_allocation_bps')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_registrations');
    }
};
