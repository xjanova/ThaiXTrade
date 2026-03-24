<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Add performance indexes for frequently queried columns.
 */
return new class() extends Migration
{
    public function up(): void
    {
        // Users: referral lookups
        if (Schema::hasColumn('users', 'referred_by')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('referred_by');
            });
        }

        // Transactions: chain + date range queries (admin reports)
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['chain_id', 'created_at']);
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'referred_by')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['referred_by']);
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['chain_id', 'created_at']);
            $table->dropIndex(['status', 'type']);
        });
    }
};
