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
        Schema::create('chains', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('symbol');
            $table->string('chain_id_hex')->nullable();
            $table->text('rpc_url');
            $table->string('explorer_url')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_testnet')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('native_currency_name');
            $table->string('native_currency_symbol');
            $table->integer('native_currency_decimals')->default(18);
            $table->integer('block_confirmations')->default(12);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Add deferred foreign key for fee_configs.chain_id -> chains.id
        Schema::table('fee_configs', function (Blueprint $table) {
            $table->foreign('chain_id')->references('id')->on('chains')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fee_configs', function (Blueprint $table) {
            $table->dropForeign(['chain_id']);
        });

        Schema::dropIfExists('chains');
    }
};
