<?php

/**
 * Migration: ตาราง token_sales
 * เก็บข้อมูลรอบการขายเหรียญ TPIX (ICO/IDO)
 * แต่ละรอบมีหลาย phase (Private, Pre-Sale, Public).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('token_sales', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                    // ชื่อรอบขาย เช่น "TPIX Genesis Sale"
            $table->string('slug')->unique();                          // URL slug
            $table->text('description')->nullable();                   // รายละเอียด
            $table->decimal('total_supply_for_sale', 30, 18);         // จำนวน TPIX ทั้งหมดที่ขาย
            $table->decimal('total_sold', 30, 18)->default(0);        // จำนวนที่ขายไปแล้ว
            $table->decimal('total_raised_usd', 18, 2)->default(0);   // ยอดเงินที่ระดมได้ (USD)
            $table->json('accept_currencies')->nullable();             // สกุลเงินที่รับ ["BNB","USDT","BUSD"]
            $table->integer('accept_chain_id')->default(56);           // Chain ที่รับชำระ (BSC)
            $table->string('sale_wallet_address')->nullable();         // กระเป๋ารับเงิน
            $table->string('sale_contract_address')->nullable();       // On-chain sale contract
            $table->enum('status', ['draft', 'upcoming', 'active', 'paused', 'completed'])->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();                      // ข้อมูลเพิ่มเติม
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_sales');
    }
};
