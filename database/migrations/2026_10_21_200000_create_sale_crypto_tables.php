<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ซื้อ TPIX ด้วย USDT จากเชนอื่น — ดู config/sale_crypto.php
 *
 * sale_crypto_orders   — คำสั่งซื้อที่ล็อกราคา + "ยอดเฉพาะ" ไว้ให้ผู้ซื้อโอนเข้ามา
 * sale_crypto_payments — Transfer เข้ากระเป๋ารับเงินที่บล็อกนิ่งแล้ว หนึ่งแถวต่อหนึ่ง log (ทุกยอดที่เกินฝุ่น)
 *
 * จำนวนเหรียญบนเชนเก็บเป็นหน่วยดิบแบบจำนวนเต็มในคอลัมน์ข้อความ แล้วคำนวณด้วย bcmath
 * (BSC 1 USDT = 1e18 เกิน int64 — SQLite เก็บเป็น REAL แล้วหลักท้ายเพี้ยนเงียบ ๆ)
 *
 * status เป็น string ไม่ใช่ ENUM โดยตั้งใจ — ค่านอก ENUM ผ่านบน SQLite ตอนเทสต์ แต่ MySQL strict ตายกลางคัน
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sale_crypto_orders')) {
            Schema::create('sale_crypto_orders', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('reference', 16)->unique();
                $table->foreignId('token_sale_id')->constrained('token_sales')->cascadeOnDelete();
                $table->foreignId('sale_phase_id')->constrained('sale_phases')->cascadeOnDelete();
                $table->string('wallet_address', 42)->index();
                $table->unsignedBigInteger('chain_id');
                $table->string('token_address', 42);
                $table->string('token_symbol', 16);
                $table->unsignedTinyInteger('token_decimals');
                // กระเป๋าที่ต้องโอนเข้า ณ ตอนสั่ง — ถ้าเจ้าของเปลี่ยนกระเป๋าภายหลัง คำสั่งซื้อเดิมยังจับคู่กับที่อยู่เดิม
                $table->string('pay_to_address', 42);
                // ราคาที่ล็อกไว้ — ค่าเหรียญเป็น USD (USDT 1:1) และจำนวน TPIX ที่จะได้
                $table->decimal('amount_usd', 20, 2);
                $table->decimal('tpix_amount', 36, 18);
                $table->decimal('price_per_tpix', 20, 8);
                // ยอดเฉพาะที่ต้องโอนเป๊ะ (หน่วยดิบ) = ค่าเหรียญ + เศษท้ายไม่ถึง 1 เซ็นต์ที่ไม่ซ้ำกับคำสั่งซื้ออื่น
                $table->string('pay_amount_raw', 80);
                $table->unsignedInteger('tag_micro');
                /*
                 * กุญแจจองยอดเฉพาะ "เชน:เหรียญ:ที่อยู่:ยอด" — unique ระดับฐานข้อมูล
                 * ปล่อยเป็น NULL เมื่อพ้นช่วงโอนช้า (late_window) ยอดเดิมจึงกลับมาใช้ได้ (NULL ซ้ำกันได้ทั้ง MySQL และ SQLite)
                 */
                $table->string('amount_lock', 200)->nullable()->unique();
                // awaiting_payment → paid | expired | needs_review
                $table->string('status', 24)->index();
                // DATETIME ไม่ใช่ TIMESTAMP โดยตั้งใจ — MariaDB ของโปรดักชัน (explicit_defaults_for_timestamp=0) เติม
                // ON UPDATE current_timestamp() ให้ TIMESTAMP NOT NULL คอลัมน์แรก เวลาหมดอายุจะขยับทุกครั้งที่อัปเดตแถว
                // แล้วยอดที่โอนสายกลายเป็นทันเวลา — SQLite ตอนเทสต์ไม่แสดงอาการนี้
                $table->dateTime('expires_at')->index();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('sale_transaction_id')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['wallet_address', 'status']);
            });
        }

        if (! Schema::hasTable('sale_crypto_payments')) {
            Schema::create('sale_crypto_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('chain_id');
                $table->string('tx_hash', 66);
                $table->unsignedInteger('log_index');
                $table->unsignedBigInteger('block_number');
                $table->unsignedBigInteger('block_timestamp');
                $table->string('from_address', 42)->index();
                $table->string('to_address', 42);
                $table->string('token_address', 42);
                $table->string('token_symbol', 16);
                $table->unsignedTinyInteger('token_decimals');
                $table->string('amount_raw', 80);
                $table->foreignId('order_id')->nullable()->constrained('sale_crypto_orders')->nullOnDelete();
                $table->unsignedBigInteger('sale_transaction_id')->nullable()->index();
                // detected → credited | needs_review → resolved
                $table->string('status', 24)->index();
                $table->string('review_reason', 48)->nullable();
                $table->text('review_note')->nullable();
                $table->string('resolved_by', 191)->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->string('last_error', 300)->nullable();
                $table->timestamps();

                $table->unique(['chain_id', 'tx_hash', 'log_index'], 'sale_crypto_payments_source_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_crypto_payments');
        Schema::dropIfExists('sale_crypto_orders');
    }
};
