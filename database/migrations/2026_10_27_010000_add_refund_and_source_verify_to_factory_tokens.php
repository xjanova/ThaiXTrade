<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Token Factory — คืนค่าธรรมเนียมเมื่อถูกปฏิเสธ + ซื้อการยืนยันซอร์สโค้ดบน explorer.
 *
 * 1. refund_* — ปฏิเสธคำขอที่จ่ายเงินแล้ว = ต้องคืนเต็มจำนวน
 *    คืนได้สองทาง (แอดมินเลือกในนโยบาย): แอดมินโอนเองแล้ววาง tx hash ให้ระบบตรวจบนเชน
 *    หรือระบบจ่ายอัตโนมัติจากกระเป๋าร้อนผ่านคิว treasury_payouts
 *    refund_tx_hash unique — ธุรกรรมหนึ่งใบใช้ยืนยันการคืนเงินได้ครั้งเดียว
 * 2. source_verify_tx_hash (unique) — ค่าธรรมเนียมยืนยันซอร์สที่ซื้อทีหลัง
 *    ธุรกรรมใบเดียวกันห้ามใช้ซ้ำทั้งเป็นค่าสร้างเหรียญและค่ายืนยันซอร์ส (ตรวจทั้งสองคอลัมน์)
 * 3. token_factory_fee_quotes.purpose — ใบเสนอราคาสร้างเหรียญ (create) กับยืนยันซอร์ส (source_verify)
 *    ใช้แทนกันไม่ได้
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('factory_tokens', function (Blueprint $table) {
            $table->string('refund_status', 20)->nullable()->after('reject_reason');
            $table->string('refund_mode', 10)->nullable()->after('refund_status');
            $table->string('refund_amount_wei', 80)->nullable()->after('refund_mode');
            $table->string('refund_tx_hash', 66)->nullable()->after('refund_amount_wei');
            $table->unsignedBigInteger('refund_payout_id')->nullable()->after('refund_tx_hash');
            $table->timestamp('refunded_at')->nullable()->after('refund_payout_id');
            $table->string('source_verify_tx_hash', 66)->nullable()->after('fee_quote_id');

            $table->unique('refund_tx_hash', 'factory_tokens_refund_tx_hash_unique');
            $table->unique('source_verify_tx_hash', 'factory_tokens_source_verify_tx_hash_unique');
            $table->index('refund_status');
        });

        Schema::table('token_factory_fee_quotes', function (Blueprint $table) {
            $table->string('purpose', 20)->default('create')->after('chain_id');
        });
    }

    public function down(): void
    {
        Schema::table('token_factory_fee_quotes', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });

        Schema::table('factory_tokens', function (Blueprint $table) {
            $table->dropUnique('factory_tokens_refund_tx_hash_unique');
            $table->dropUnique('factory_tokens_source_verify_tx_hash_unique');
            $table->dropIndex(['refund_status']);
            $table->dropColumn([
                'refund_status', 'refund_mode', 'refund_amount_wei', 'refund_tx_hash',
                'refund_payout_id', 'refunded_at', 'source_verify_tx_hash',
            ]);
        });
    }
};
