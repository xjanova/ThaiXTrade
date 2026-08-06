<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ชั้นคลัง TPIX — คิวจ่ายเงิน, whitelist ปลายทาง, สมุดบัญชีคลัง
 *
 * จำนวนเงินเก็บเป็น **wei ในคอลัมน์ string** ทุกที่ ไม่ใช่ decimal/float
 * เพราะ 1 TPIX = 1e18 wei ซึ่ง DECIMAL(65,0) ยังไหวก็จริง แต่พอ PHP อ่าน
 * ออกมาเป็น string อยู่ดี และ float จะพังตั้งแต่ 10 TPIX (เกิน PHP_INT_MAX)
 * ใช้ App\Support\Wei ในการคำนวณทุกครั้ง ห้ามแปลงเป็น int/float
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ปลายทางที่อนุญาตให้โอนไปได้ ────────────────────────────────────
        Schema::create('treasury_whitelist', function (Blueprint $table) {
            $table->id();
            $table->string('address', 42)->unique();
            $table->string('label');
            $table->text('note')->nullable();

            // ผูกกับระบบเดิม: masternode payout / token sale delivery / อื่น ๆ
            $table->string('purpose', 32)->default('other');

            // วงเงินเฉพาะปลายทางนี้ (wei) — null = ใช้ค่ากลางจาก config
            $table->string('max_per_tx_wei', 80)->nullable();
            $table->string('max_per_day_wei', 80)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'purpose']);
        });

        // ── คิวจ่ายเงินจากกระเป๋าร้อน ──────────────────────────────────────
        Schema::create('treasury_payouts', function (Blueprint $table) {
            $table->id();

            // กันจ่ายซ้ำ: ผู้เรียกส่ง key มา ถ้าซ้ำให้คืนรายการเดิมแทนที่จะสร้างใหม่
            // กดปุ่มรัว ๆ หรือ retry จาก job ก็จะได้รายการเดียว
            $table->string('idempotency_key', 100)->unique();

            $table->string('to_address', 42);
            $table->string('amount_wei', 80);
            $table->string('purpose', 32)->default('other');
            $table->text('memo')->nullable();

            // pending -> approved -> broadcasting -> confirmed
            //         -> rejected / failed
            $table->string('status', 20)->default('pending');

            $table->string('tx_hash', 66)->nullable()->unique();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->unsignedInteger('confirmations')->default(0);
            $table->text('failure_reason')->nullable();

            // ยอดกระเป๋าร้อนตอนอนุมัติ — ไว้สืบย้อนว่าตอนนั้นเงินพอจริงไหม
            $table->string('hot_balance_at_approval_wei', 80)->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('broadcast_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('to_address');
        });

        // ── สมุดบัญชีคลัง — ทุกการเคลื่อนไหวที่ระบบรู้เห็น ────────────────
        Schema::create('treasury_ledger', function (Blueprint $table) {
            $table->id();

            // กระเป๋าที่เงินออก/เข้า — ใช้ key จาก config('treasury.pools')
            // หรือ 'hot_wallet'
            $table->string('wallet_key', 40);
            $table->string('wallet_address', 42);

            // debit = เงินออก, credit = เงินเข้า
            $table->string('direction', 8);
            $table->string('amount_wei', 80);

            $table->string('source', 32);              // payout / manual / sale / masternode
            $table->foreignId('payout_id')->nullable()->constrained('treasury_payouts')->nullOnDelete();
            $table->string('tx_hash', 66)->nullable();
            $table->unsignedBigInteger('block_number')->nullable();

            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_key', 'created_at']);
            $table->index('tx_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_ledger');
        Schema::dropIfExists('treasury_payouts');
        Schema::dropIfExists('treasury_whitelist');
    }
};
