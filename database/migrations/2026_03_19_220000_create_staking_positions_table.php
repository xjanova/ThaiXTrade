<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — Staking Positions
 * แต่ละ stake ของผู้ใช้ — amount, pool, rewards, unlock date
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('staking_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staking_pool_id')->constrained()->cascadeOnDelete();
            $table->string('wallet_address', 42);
            $table->decimal('amount', 36, 18);
            $table->decimal('reward_earned', 36, 18)->default(0);
            $table->timestamp('staked_at');
            $table->timestamp('unlock_at')->nullable();
            $table->timestamp('last_reward_at')->nullable();
            $table->enum('status', ['active', 'completed', 'withdrawn'])->default('active');
            $table->string('tx_hash', 66)->nullable();
            $table->string('withdraw_tx_hash', 66)->nullable();
            $table->timestamps();

            $table->index(['wallet_address', 'status']);
            $table->index('staking_pool_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staking_positions');
    }
};
