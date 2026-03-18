<?php

/**
 * TPIX TRADE — ตารางประวัติการเชื่อมต่อ Wallet
 * บันทึกทุกครั้งที่ user connect wallet (chain, type, เวลา)
 * Developed by Xman Studio.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('wallet_address', 42);
            $table->unsignedInteger('chain_id')->default(56);
            $table->string('wallet_type', 20)->default('metamask'); // metamask, trustwallet, coinbase, okx, tpix_wallet
            $table->boolean('is_primary')->default(false);
            $table->timestamp('connected_at')->useCurrent();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'chain_id']);
            $table->index('wallet_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_connections');
    }
};
