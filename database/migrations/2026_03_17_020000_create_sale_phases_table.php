<?php

/**
 * Migration: ตาราง sale_phases
 * แต่ละ phase ของการขาย เช่น Private Sale, Pre-Sale, Public Sale
 * มีราคา, จำนวน, vesting schedule แยกกัน.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sale_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_sale_id')->constrained('token_sales')->cascadeOnDelete();
            $table->string('name');                                     // ชื่อ phase เช่น "Private Sale"
            $table->string('slug');
            $table->integer('phase_order')->default(1);                 // ลำดับ phase
            $table->decimal('price_usd', 18, 8);                       // ราคาต่อ TPIX (USD)
            $table->decimal('allocation', 30, 18);                     // จำนวน TPIX ที่จัดสรร
            $table->decimal('sold', 30, 18)->default(0);               // จำนวนที่ขายแล้ว
            $table->decimal('min_purchase', 30, 18)->default(1);       // ซื้อขั้นต่ำ (TPIX)
            $table->decimal('max_purchase', 30, 18)->default(10000000); // ซื้อสูงสุด (TPIX)
            $table->integer('vesting_cliff_days')->default(0);         // Cliff period (วัน)
            $table->integer('vesting_duration_days')->default(0);      // ระยะเวลา vesting (วัน)
            $table->decimal('vesting_tge_percent', 5, 2)->default(100); // % ที่ปล่อยทันทีเมื่อ TGE
            $table->boolean('whitelist_only')->default(false);         // ต้อง whitelist ไหม
            $table->enum('status', ['upcoming', 'active', 'completed', 'cancelled'])->default('upcoming');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['token_sale_id', 'phase_order']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_phases');
    }
};
