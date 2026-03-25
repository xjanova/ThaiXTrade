<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPIX TRADE - Trades table for recording matched orders.
 * Each trade links a maker (limit) and taker (market/limit) order.
 * Developed by Xman Studio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trading_pair_id')->constrained('trading_pairs');
            $table->foreignId('chain_id')->constrained('chains');
            $table->foreignId('maker_order_id')->constrained('orders');
            $table->foreignId('taker_order_id')->constrained('orders');
            $table->string('maker_wallet', 42)->index();
            $table->string('taker_wallet', 42)->index();
            $table->enum('side', ['buy', 'sell'])->comment('Taker side');
            $table->decimal('price', 36, 18)->comment('Execution price');
            $table->decimal('amount', 36, 18)->comment('Base token amount traded');
            $table->decimal('total', 36, 18)->comment('Quote token total');
            $table->decimal('maker_fee', 36, 18)->default(0);
            $table->decimal('taker_fee', 36, 18)->default(0);
            $table->string('tx_hash', 66)->nullable()->comment('On-chain tx hash if settled');
            $table->timestamps();

            // Indexes for trade history & kline aggregation
            $table->index(['trading_pair_id', 'created_at'], 'idx_pair_trades');
            $table->index(['created_at'], 'idx_trades_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
