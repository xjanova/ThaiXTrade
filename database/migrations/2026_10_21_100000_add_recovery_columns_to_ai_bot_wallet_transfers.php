<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — คิวถอนของกระเป๋าบอท: คอลัมน์ที่ทำให้รายการ "ไม่ค้างตลอดกาล".
 *
 * raw_tx       ธุรกรรมที่เซ็นแล้ว (บันทึกก่อนส่งเสมอ) — โหนดทิ้งจาก mempool = ส่งตัวเดิมซ้ำได้
 *              ด้วย nonce เดิม (ลงบล็อกได้ครั้งเดียว) แทนที่จะค้างรอ receipt ที่ไม่มีวันมา
 *              ⚠️ ไม่ใช่ความลับ (ข้อมูลสาธารณะเมื่อส่งแล้ว) แต่ยาว — โมเดลซ่อนจาก JSON
 * failure_code รหัสเหตุผลที่ล้ม — หน้าเว็บแปลเป็นข้อความของแต่ละภาษาเอง ข้อความดิบจาก
 *              RPC/ระบบเข้ารหัสไม่ถูกส่งถึงผู้ใช้อีก (ไปอยู่ใน log แทน)
 * signing_at   เวลาที่เริ่มเซ็น — โปรเซสตายกลางคันก่อนบันทึก hash = ยังไม่มีอะไรออกจากเครื่อง
 *              ตัวส่งคืนรายการเข้าคิวได้อย่างปลอดภัยเมื่อค้างนานเกินรอบปกติ
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_bot_wallet_transfers')) {
            return;
        }

        Schema::table('ai_bot_wallet_transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_bot_wallet_transfers', 'raw_tx')) {
                $table->text('raw_tx')->nullable()->after('nonce');
            }
            if (! Schema::hasColumn('ai_bot_wallet_transfers', 'failure_code')) {
                $table->string('failure_code', 40)->nullable()->after('failure_reason');
            }
            if (! Schema::hasColumn('ai_bot_wallet_transfers', 'signing_at')) {
                $table->timestamp('signing_at')->nullable()->after('requested_ip');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_bot_wallet_transfers')) {
            return;
        }

        Schema::table('ai_bot_wallet_transfers', function (Blueprint $table) {
            foreach (['raw_tx', 'failure_code', 'signing_at'] as $column) {
                if (Schema::hasColumn('ai_bot_wallet_transfers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
