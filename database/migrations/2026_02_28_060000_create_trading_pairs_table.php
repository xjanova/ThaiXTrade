<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trading_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_token_id')->constrained('tokens')->cascadeOnDelete();
            $table->foreignId('quote_token_id')->constrained('tokens')->cascadeOnDelete();
            $table->foreignId('chain_id')->constrained('chains')->cascadeOnDelete();
            $table->string('symbol');
            $table->boolean('is_active')->default(true);
            $table->decimal('min_trade_amount', 20, 8)->nullable();
            $table->decimal('max_trade_amount', 20, 8)->nullable();
            $table->integer('price_precision')->default(2);
            $table->integer('amount_precision')->default(8);
            $table->decimal('maker_fee_override', 8, 4)->nullable();
            $table->decimal('taker_fee_override', 8, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['base_token_id', 'quote_token_id', 'chain_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trading_pairs');
    }
};
