<?php

/**
 * Migration: ตาราง whitelist_entries
 * รายชื่อ wallet ที่ได้รับอนุญาตให้ซื้อใน phase ที่ต้อง whitelist.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('whitelist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_phase_id')->constrained('sale_phases')->cascadeOnDelete();
            $table->string('wallet_address');
            $table->decimal('max_allocation', 30, 18)->nullable();     // จำกัดจำนวนซื้อสูงสุด
            $table->boolean('is_kyc_verified')->default(false);
            $table->timestamps();

            $table->unique(['sale_phase_id', 'wallet_address']);
            $table->index('wallet_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whitelist_entries');
    }
};
