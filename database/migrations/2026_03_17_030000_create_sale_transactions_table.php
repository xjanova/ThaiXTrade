<?php

/**
 * Migration: ตาราง sale_transactions
 * บันทึกการซื้อเหรียญ TPIX แต่ละรายการ
 * เชื่อมกับ tx_hash บน BSC เพื่อตรวจสอบการชำระเงินจริง.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sale_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('token_sale_id')->constrained('token_sales')->cascadeOnDelete();
            $table->foreignId('sale_phase_id')->constrained('sale_phases')->cascadeOnDelete();
            $table->string('wallet_address')->index();                  // กระเป๋าผู้ซื้อ
            $table->string('payment_currency', 20);                     // BNB, USDT, BUSD
            $table->decimal('payment_amount', 30, 18);                  // จำนวนที่จ่าย
            $table->decimal('payment_usd_value', 18, 2);                // มูลค่าเป็น USD
            $table->decimal('tpix_amount', 30, 18);                     // จำนวน TPIX ที่ได้
            $table->decimal('price_per_tpix', 18, 8);                   // ราคาต่อ TPIX ณ เวลาซื้อ
            $table->string('tx_hash')->nullable()->index();             // Payment tx hash บน BSC
            $table->string('claim_tx_hash')->nullable();                // Claim tx hash บน TPIX Chain
            $table->enum('status', ['pending', 'confirmed', 'claimed', 'refunded', 'failed'])->default('pending');
            $table->timestamp('vesting_start_at')->nullable();          // เริ่ม vesting เมื่อไร
            $table->decimal('claimed_amount', 30, 18)->default(0);      // จำนวนที่ claim ไปแล้ว
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['wallet_address', 'status']);
            $table->index(['token_sale_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_transactions');
    }
};
