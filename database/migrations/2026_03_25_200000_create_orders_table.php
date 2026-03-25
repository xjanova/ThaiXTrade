<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPIX TRADE - Orders table for internal order book.
 * Supports limit, market, stop-limit orders on TPIX chain.
 * Developed by Xman Studio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trading_pair_id')->constrained('trading_pairs');
            $table->foreignId('chain_id')->constrained('chains');
            $table->string('wallet_address', 42)->index();
            $table->enum('side', ['buy', 'sell']);
            $table->enum('type', ['limit', 'market', 'stop-limit']);
            $table->decimal('price', 36, 18)->default(0)->comment('Order price (0 for market orders)');
            $table->decimal('amount', 36, 18)->comment('Base token amount');
            $table->decimal('filled_amount', 36, 18)->default(0)->comment('Amount already filled');
            $table->decimal('remaining_amount', 36, 18)->comment('Amount remaining to fill');
            $table->decimal('total', 36, 18)->default(0)->comment('Total quote value');
            $table->decimal('trigger_price', 36, 18)->nullable()->comment('Stop-limit trigger price');
            $table->decimal('fee_rate', 8, 4)->default(0)->comment('Fee rate at time of order');
            $table->decimal('fee_amount', 36, 18)->default(0)->comment('Accumulated fee');
            $table->enum('status', ['open', 'partially_filled', 'filled', 'cancelled', 'expired', 'triggered'])
                ->default('open')->index();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indexes for order matching
            $table->index(['trading_pair_id', 'side', 'status', 'price'], 'idx_order_matching');
            $table->index(['wallet_address', 'status'], 'idx_wallet_orders');
            $table->index(['trading_pair_id', 'status', 'created_at'], 'idx_pair_orders');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
