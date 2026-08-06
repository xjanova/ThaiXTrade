<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * กันสมุดบัญชีบันทึกธุรกรรมเดียวกันซ้ำ.
 *
 * ตัวเก็บรายการอัตโนมัติ (tpix:treasury-sync) รันซ้ำได้ตลอด ถ้าไม่มี unique
 * รันสองรอบก็จะได้รายการซ้ำ แล้วตัวกระทบยอดจะคำนวณผิดทันที
 *
 * คีย์เป็นสามส่วนเพราะธุรกรรมเดียวอาจโผล่สองแถวโดยชอบธรรม — ตอนโอนเงิน
 * ระหว่างกระเป๋าคลังสองใบ ใบส่งได้ debit ใบรับได้ credit จาก tx_hash เดียวกัน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_ledger', function (Blueprint $table) {
            $table->unique(['tx_hash', 'wallet_key', 'direction'], 'treasury_ledger_tx_wallet_dir_unique');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_ledger', function (Blueprint $table) {
            $table->dropUnique('treasury_ledger_tx_wallet_dir_unique');
        });
    }
};
