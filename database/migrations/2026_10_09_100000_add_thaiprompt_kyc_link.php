<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — ใช้ผลยืนยันตัวตนของ Thaiprompt แทนการส่งเอกสารซ้ำ.
 *
 * เจ้าของสั่ง: "ให้ไปยืนยันใน thaiprompt app ถ้าผ่านก็บันทึกว่าผ่านแล้ว
 *              ถ้าเคยยืนยันแล้วก็ผ่านเลย ไม่ต้องยืนยันอีก"
 *
 *   thaiprompt_kyc_links       — บัญชีเราผูกกับบัญชี Thaiprompt ไหน + token ไว้ถามสถานะซ้ำ
 *                                ระหว่างที่ลูกค้ายังทำ eKYC ในแอปไม่เสร็จ
 *   kyc_submissions.source     — ใบนี้มาจากไหน (manual = ส่งเอกสารกับเรา · thaiprompt = ผลจาก Thaiprompt)
 *   kyc_submissions.external_ref — รหัสผู้ใช้ฝั่ง Thaiprompt ของใบที่มาจากที่นั่น
 *
 * ด่าน (KycGate) ไม่ต้องแก้เลย — มันอ่าน "ใบที่อนุมัติ" อยู่แล้ว ไม่สนว่าใบมาจากไหน
 *
 * ⚠️ ไม่มีข้อมูลบัตรจาก Thaiprompt ในตารางนี้ — อีกฝั่งส่งมาแค่ผ่าน/ไม่ผ่าน + วันที่ (PDPA)
 * ⚠️ token เข้ารหัสด้วย APP_KEY (cast encrypted) จึงเป็น text
 * ⚠️ คอลัมน์เวลาที่ไม่ nullable ต้องเป็น dateTime ไม่ใช่ timestamp (กับดัก MySQL 1067 — ดู migration KYC เดิม)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kyc_submissions', 'source')) {
            Schema::table('kyc_submissions', function (Blueprint $table) {
                $table->string('source', 20)->default('manual')->after('level')
                    ->comment('manual | thaiprompt');
                $table->string('external_ref', 64)->nullable()->after('source')->index()
                    ->comment('รหัสผู้ใช้ Thaiprompt ของใบที่มาจาก Thaiprompt');
            });
        }

        if (! Schema::hasTable('thaiprompt_kyc_links')) {
            Schema::create('thaiprompt_kyc_links', function (Blueprint $table) {
                $table->id();
                // หนึ่งบัญชีของเราผูกได้บัญชี Thaiprompt เดียว
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                // หนึ่งบัญชี Thaiprompt (= หนึ่งคนที่ผ่าน KYC) ใช้ปลดล็อกได้บัญชีเดียว
                // ไม่งั้นคนเดียวยืนยันครั้งเดียวแล้วเปิดบัญชีของเราได้ไม่จำกัด
                $table->string('thaiprompt_user_id', 64)->unique();

                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->dateTime('token_expires_at')->nullable();

                // สถานะล่าสุดที่ Thaiprompt ตอบ: none | pending | approved | rejected
                $table->string('kyc_status', 20)->default('none');
                $table->dateTime('verified_at')->nullable();
                $table->string('method', 20)->nullable()->comment('ekyc | manual — วิธีที่ใช้ยืนยันที่ Thaiprompt');

                $table->dateTime('linked_at')->nullable();
                $table->dateTime('last_checked_at')->nullable();
                // token ใช้ไม่ได้แล้ว (หมดอายุ/ลูกค้าถอนสิทธิ์) — ต้องให้ลูกค้ากดเชื่อมใหม่
                $table->boolean('needs_reconnect')->default(false);
                $table->string('last_error', 120)->nullable();

                $table->timestamps();

                $table->index(['kyc_status', 'last_checked_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('thaiprompt_kyc_links');

        if (Schema::hasColumn('kyc_submissions', 'source')) {
            Schema::table('kyc_submissions', function (Blueprint $table) {
                $table->dropIndex(['external_ref']);
                $table->dropColumn(['source', 'external_ref']);
            });
        }
    }
};
