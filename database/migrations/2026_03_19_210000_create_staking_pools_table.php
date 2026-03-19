<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — Staking Pools
 * กำหนด pool แต่ละ lock period + APY
 * 5 pools: Flexible(5%), 30d(25%), 90d(60%), 180d(100%), 365d(200%)
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('staking_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('lock_days')->default(0);
            $table->decimal('apy_percent', 8, 2);
            $table->decimal('min_stake', 36, 18)->default(10);
            $table->decimal('max_stake', 36, 18)->default(10000000);
            $table->decimal('total_staked', 36, 18)->default(0);
            $table->decimal('total_rewards_paid', 36, 18)->default(0);
            $table->decimal('max_pool_size', 36, 18)->default(280000000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staking_pools');
    }
};
