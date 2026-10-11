<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * คำขอสร้างเหรียญจากระบบเก่าที่ยังไม่ถูก deploy → ปฏิเสธพร้อมเหตุผล.
 *
 * ระบบเก่าไม่เคยตรวจค่าธรรมเนียมบนเชน (fee_tx_hash ว่างหรือมั่วก็ผ่าน) และเคยรับ testnet 4290
 * ที่ "ฟรี" แต่สคริปต์ส่งขึ้น 4289 เสมอ — ถ้าแอดมินเผลอกดอนุมัติ/retry แถวพวกนี้ = เหรียญบน mainnet ฟรี
 * (และ chain_id 4290 หลุด unique index (chain_id, active_symbol) ไปด้วย)
 *
 * ปฏิเสธเฉพาะ pending/failed ที่ไม่มีใบเสนอราคา หรือไม่ใช่เชน 4289 — ไม่ลบข้อมูล ผู้สร้างส่งคำขอใหม่ได้
 * แถวที่ค้าง deploying ใช้ปุ่ม "รีเซ็ตแถวค้าง" แล้วปฏิเสธจากหน้าแอดมิน (approve ถูกกันไว้แล้ว)
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::table('factory_tokens')
            ->whereIn('status', ['pending', 'failed'])
            ->where(function ($query) {
                $query->whereNull('fee_quote_id')->orWhere('chain_id', '!=', 4289);
            })
            ->update([
                'status' => 'rejected',
                'reject_reason' => 'คำขอจากระบบเก่าที่ยังไม่ได้ตรวจค่าธรรมเนียมบนเชน — กรุณาส่งคำขอใหม่',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // ไม่ย้อนสถานะ — การปฏิเสธคำขอที่ไม่ผ่านการตรวจเป็นการตัดสินที่ตั้งใจ
    }
};
