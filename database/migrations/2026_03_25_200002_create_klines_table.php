<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPIX TRADE - Klines (candlestick) table.
 * Aggregated from real trades for chart rendering.
 * Developed by Xman Studio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('klines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_pair_id')->constrained('trading_pairs');
            $table->enum('interval', ['1m', '5m', '15m', '1h', '4h', '1d', '1w']);
            $table->timestamp('open_time')->comment('Candle open timestamp');
            $table->decimal('open', 36, 18);
            $table->decimal('high', 36, 18);
            $table->decimal('low', 36, 18);
            $table->decimal('close', 36, 18);
            $table->decimal('volume', 36, 18)->default(0)->comment('Base token volume');
            $table->decimal('quote_volume', 36, 18)->default(0)->comment('Quote token volume');
            $table->unsignedInteger('trade_count')->default(0);
            $table->timestamps();

            $table->unique(['trading_pair_id', 'interval', 'open_time'], 'uk_kline');
            $table->index(['trading_pair_id', 'interval', 'open_time'], 'idx_kline_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('klines');
    }
};
