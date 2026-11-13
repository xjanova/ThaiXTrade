<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * คำสั่งตั้งราคา (limit order) ของคู่ที่เทรดบนเชนจริง
 *
 * AMM ไม่มีสมุดคำสั่ง — คำสั่งตั้งราคาจึงเป็น "คำสั่งรอราคา": เก็บไว้ที่เซิร์ฟเวอร์
 * พอราคาถึง หน้าเทรด/แอปให้เจ้าของกดยืนยัน แล้วส่งเป็นไม้ราคาตลาดที่ล็อก
 * "ได้รับอย่างน้อย" ตามราคาที่ตั้งไว้บนเชน · เราไม่ถือเหรียญของผู้ใช้เลย
 *
 * แยกตารางจาก `orders` (สมุดคำสั่งภายในที่ยังปิดอยู่) โดยตั้งใจ —
 * ถ้าใช้ตารางเดียวกัน วันที่เปิดสมุดคำสั่งภายใน ตัวจับคู่จะหยิบคำสั่งพวกนี้ไปจับคู่ในฐานข้อมูล
 *
 * ⚠️ เวลาทางธุรกิจใช้ dateTime ทั้งหมด — MariaDB บน production เติม ON UPDATE ให้
 *    TIMESTAMP NOT NULL ตัวแรกของตารางเอง (BusinessTimeColumnsTest)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limit_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('wallet_address', 42);
            $table->foreignId('trading_pair_id')->nullable()->constrained('trading_pairs')->nullOnDelete();
            // เก็บชื่อคู่ + เชนไว้กับแถว — คู่ถูกปิด/ลบทีหลัง ประวัติคำสั่งต้องยังอ่านรู้เรื่อง
            $table->string('pair', 32);
            $table->unsignedInteger('network_chain_id');
            $table->string('side', 4);
            $table->decimal('limit_price', 36, 18);
            // base = จำนวนเหรียญหลัก · quote = มูลค่าฝั่งเหรียญอ้างอิง (ซื้อ: ยอดที่จ่าย · ขาย: ยอดที่ต้องได้)
            $table->decimal('base_amount', 36, 18);
            $table->decimal('quote_amount', 36, 18);
            // ได้รับอย่างน้อย (หน่วยของเหรียญที่ได้รับ) — หน้าเว็บส่งเป็น minOut บนเชน
            $table->decimal('min_receive', 36, 18);
            $table->decimal('slippage', 5, 2)->nullable();
            $table->string('fee_method', 16)->nullable();
            $table->string('status', 16)->default('open');
            $table->string('tx_hash', 66)->nullable();
            $table->decimal('filled_base', 36, 18)->nullable();
            $table->decimal('filled_quote', 36, 18)->nullable();
            $table->string('close_reason', 64)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('filled_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_address', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('tx_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limit_orders');
    }
};
