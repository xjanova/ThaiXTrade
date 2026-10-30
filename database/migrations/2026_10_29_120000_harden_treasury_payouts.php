<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * คิวจ่ายเงินจากกระเป๋าร้อน — กันจ่ายซ้ำ (ออดิท 2026-10-29: C3, C5, L1, L7)
 *
 *   origin        ใครสร้างรายการ: admin (กรอกมือ) หรือระบบจาก claim / ออเดอร์โอน / คืนค่าธรรมเนียม
 *                 การยกเว้น whitelist ดูจากคอลัมน์นี้ ไม่ดูจาก purpose ที่แอดมินเลือกเองได้
 *                 ค่าเริ่มต้น admin = แถวเก่าต้องผ่าน whitelist (fail-closed) — prod ยังไม่มีแถวเลย
 *                 จึงไม่ backfill (idempotency_key แอดมินกรอกเองได้ จะใช้เดา origin ไม่ได้)
 *   raw_tx        ธุรกรรมที่เซ็นแล้ว — ส่งตัวเดิมซ้ำได้โดยไม่ต้องเซ็นใหม่ (hash เดิม nonce เดิม)
 *   nonce / from_address  ไว้พิสูจน์ว่า nonce ของธุรกรรมนี้ยังว่างหรือถูกใช้ไปแล้ว
 *   claimed_at / claim_token  ตัวจองแถวแบบ atomic ของรอบที่กำลังเซ็น — กันสองรอบเซ็นแถวเดียวกัน
 *                 แม้ mutex ของ scheduler จะหมดอายุหรือโดน cache:clear ล้าง
 *   last_error    เหตุล่าสุดของแถวที่ยัง broadcasting (ส่งไม่ยืนยันผล / ค้าง / nonce ถูกใช้)
 *                 แยกจาก failure_reason เพราะแถวนี้ยังไม่ล้มเหลว — เงินอาจออกไปแล้ว
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_payouts', function (Blueprint $table) {
            $table->string('origin', 20)->default('admin')->after('purpose');
            $table->text('raw_tx')->nullable()->after('tx_hash');
            $table->unsignedBigInteger('nonce')->nullable()->after('raw_tx');
            $table->string('from_address', 42)->nullable()->after('nonce');
            $table->timestamp('claimed_at')->nullable()->after('from_address');
            $table->string('claim_token', 40)->nullable()->after('claimed_at');
            $table->text('last_error')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_payouts', function (Blueprint $table) {
            $table->dropColumn(['origin', 'raw_tx', 'nonce', 'from_address', 'claimed_at', 'claim_token', 'last_error']);
        });
    }
};
