<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — ค่าบริการวางไม้ที่เก็บก่อน ไม่ใช่เก็บทีหลัง.
 *
 * เจ้าของกำหนดโมเดล: ผู้ใช้ต้องมี TPIX ในคลังของเว็บก่อนถึงจะวางไม้ได้
 * ค่าบริการเป็นจำนวน TPIX คงที่ตามขั้นบันไดขนาดไม้ (ไม่แปรผันตามราคาเหรียญ)
 * คนที่ไม่มี TPIX ยังเทรดได้ แต่จ่ายแพงกว่าและคืนเงินยากกว่า
 *
 *   trading_credits      — คลัง TPIX ของแต่ละกระเป๋า (ledger เติมอย่างเดียว)
 *   trading_fee_tiers    — ขั้นบันไดค่าบริการที่แอดมินตั้งเองได้ทุกขั้น
 *   trading_order_tickets — ใบอนุญาตวางไม้ 1 ใบต่อ 1 ไม้ + วงจรคืนเงิน
 *
 * ⚠️ ใช้ dateTime ไม่ใช่ timestamp สำหรับคอลัมน์ที่ไม่ nullable
 *    MySQL แจก DEFAULT '0000-00-00 00:00:00' ให้ TIMESTAMP NOT NULL ที่ไม่ระบุ default
 *    ซึ่ง sql_mode ที่มี NO_ZERO_DATE ปฏิเสธ → error 1067 · SQLite ตอน dev ไม่มีข้อจำกัดนี้
 *    บั๊กจึงโผล่เฉพาะบนโปรดักชัน (เกิดจริงมาแล้ว 2026-08-17)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        // ── คลัง TPIX ของผู้ใช้ ──────────────────────────────────────────────
        //
        // แหล่งความจริงเดียวของยอดคงเหลือ — ไม่มีคอลัมน์ balance เก็บซ้ำที่อื่น
        // ยอด = balance_after ของแถวล่าสุด (เขียนใต้ transaction + lockForUpdate)
        // โครงเดียวกับ ai_bot_credits ที่ใช้งานจริงมาแล้ว ไม่คิดใหม่
        if (! Schema::hasTable('trading_credits')) {
            Schema::create('trading_credits', function (Blueprint $table) {
                $table->id();
                $table->string('wallet_address', 42)->index();
                $table->enum('type', ['topup', 'charge', 'refund', 'bonus', 'adjustment']);
                $table->decimal('amount', 30, 8)->comment('บวก = เพิ่ม, ลบ = ตัด');
                $table->decimal('balance_after', 30, 8);
                $table->string('reference', 120)->nullable()->comment('เช่น ticket:42, topup:0xabc…');
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['wallet_address', 'created_at']);
                // เรียกซ้ำจาก retry ของ client ต้องไม่หัก/เพิ่มสองรอบ
                $table->unique(['wallet_address', 'reference'], 'idx_trading_credit_reference');
            });
        }

        // ── ขั้นบันไดค่าบริการ ───────────────────────────────────────────────
        //
        // ค่าบริการเป็น "จำนวน TPIX คงที่ต่อไม้" ตามช่วงมูลค่าไม้ ไม่ใช่เปอร์เซ็นต์
        // เจ้าของเลือกแบบนี้เพราะคิดเป็น % แล้วไม้ใหญ่จ่ายแพงจนไม่มีใครใช้
        if (! Schema::hasTable('trading_fee_tiers')) {
            Schema::create('trading_fee_tiers', function (Blueprint $table) {
                $table->id();
                $table->string('label', 60)->nullable();
                // ช่วงมูลค่าไม้เป็น USD — เทียบข้ามคู่เทรดได้ตัวเดียว
                $table->decimal('min_order_usd', 20, 2)->default(0);
                // null = ไม่มีเพดาน (ขั้นบนสุด)
                $table->decimal('max_order_usd', 20, 2)->nullable();
                $table->decimal('fee_tpix', 20, 8)->comment('จำนวน TPIX คงที่ต่อไม้');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'min_order_usd']);
            });
        }

        // ── ใบอนุญาตวางไม้ ──────────────────────────────────────────────────
        //
        // เหตุที่ต้องมี: เส้นทางเทรดจริงบน BSC ผู้ใช้เซ็นกับ PancakeSwap ตรงๆ
        // เว็บเราไม่ได้อยู่ในเส้นทางของเหรียญ จึงบังคับเก็บเงิน "ตอนส่งคำสั่ง" ไม่ได้
        // ต้องเก็บตอน "ขออนุญาตวางไม้" แทน แล้วค่อยปล่อยให้ swap
        //
        // ⚠️ ออกตั๋วแล้วผู้ใช้กดยกเลิกในกระเป๋าได้เสมอ — ต้องคืนเงินให้
        //    ไม่งั้นเสีย TPIX เพราะกดผิดปุ่มใน MetaMask ซึ่งไม่มีใครยอมรับได้
        if (! Schema::hasTable('trading_order_tickets')) {
            Schema::create('trading_order_tickets', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('wallet_address', 42)->index();
                $table->string('pair', 32);
                $table->enum('side', ['buy', 'sell']);
                $table->decimal('order_value_usd', 20, 8)->default(0);

                // tpix_credit = หักจากคลัง คืนเต็มเมื่อยกเลิก
                // onchain     = จ่ายเหรียญจริงก่อนวางไม้ คืนโดยหักค่าแก๊ส
                $table->enum('fee_method', ['tpix_credit', 'onchain']);
                $table->decimal('fee_amount', 30, 8);
                $table->string('fee_currency', 20)->default('TPIX');
                $table->foreignId('trading_fee_tier_id')->nullable()->constrained('trading_fee_tiers')->nullOnDelete();
                $table->string('fee_tx_hash', 66)->nullable()->comment('เฉพาะ onchain — ธุรกรรมที่ผู้ใช้จ่ายค่าบริการ');

                $table->enum('status', ['issued', 'consumed', 'refunded', 'expired'])->default('issued');
                $table->string('order_tx_hash', 66)->nullable()->comment('ธุรกรรมของไม้ที่ใช้ตั๋วนี้');
                $table->decimal('refund_amount', 30, 8)->nullable();
                $table->decimal('gas_deducted', 30, 8)->nullable()->comment('ค่าแก๊สที่หักก่อนคืน (เฉพาะ onchain)');
                $table->string('refund_tx_hash', 66)->nullable();
                $table->text('note')->nullable();

                $table->dateTime('expires_at');
                $table->timestamps();

                $table->index(['wallet_address', 'status']);
                $table->index(['status', 'expires_at']);
                // ธุรกรรมจ่ายค่าบริการ 1 ใบ ใช้ได้ตั๋วเดียว — กันนำ tx เดิมมาขอตั๋วซ้ำ
                $table->unique('fee_tx_hash', 'idx_ticket_fee_tx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_order_tickets');
        Schema::dropIfExists('trading_fee_tiers');
        Schema::dropIfExists('trading_credits');
    }
};
