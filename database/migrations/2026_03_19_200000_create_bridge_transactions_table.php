<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — Bridge Transactions
 * บันทึก cross-chain bridge: TPIX Chain ↔ BSC
 * lock/mint mechanism — lock ฝั่งต้นทาง, mint ฝั่งปลายทาง
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_address', 42);
            $table->enum('direction', ['bsc_to_tpix', 'tpix_to_bsc']);
            $table->decimal('amount', 36, 18);
            $table->decimal('fee', 36, 18)->default(0);
            $table->unsignedInteger('source_chain_id');
            $table->unsignedInteger('target_chain_id');
            $table->string('source_tx_hash', 66)->nullable();
            $table->string('target_tx_hash', 66)->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['wallet_address', 'status']);
            $table->index('source_tx_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_transactions');
    }
};
