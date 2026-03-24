<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand transactions.status enum to include 'executing' and 'confirmed'.
 *
 * - executing: market order sent to chain, waiting for tx confirmation
 * - confirmed: tx confirmed on-chain (swap/trade completed)
 *
 * MySQL: ALTER COLUMN MODIFY to expand enum
 * SQLite: Recreate with string column (enum not enforced in SQLite)
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('pending','executing','confirming','confirmed','completed','failed','cancelled') NOT NULL DEFAULT 'pending'");
        } else {
            // SQLite: change column type to string (enum not supported)
            Schema::table('transactions', function ($table) {
                $table->string('status', 20)->default('pending')->change();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('pending','confirming','completed','failed','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }
};
