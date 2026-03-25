<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เพิ่ม indexes สำหรับ query ที่ใช้บ่อยใน token sale system
        if (Schema::hasTable('sale_transactions')) {
            Schema::table('sale_transactions', function (Blueprint $table) {
                if (! $this->hasIndex('sale_transactions', 'sale_transactions_wallet_address_index')) {
                    $table->index('wallet_address');
                }
                if (! $this->hasIndex('sale_transactions', 'sale_transactions_token_sale_id_status_index')) {
                    $table->index(['token_sale_id', 'status']);
                }
                if (! $this->hasIndex('sale_transactions', 'sale_transactions_wallet_address_status_index')) {
                    $table->index(['wallet_address', 'status']);
                }
            });
        }

        if (Schema::hasTable('sale_phases')) {
            Schema::table('sale_phases', function (Blueprint $table) {
                if (! $this->hasIndex('sale_phases', 'sale_phases_token_sale_id_status_index')) {
                    $table->index(['token_sale_id', 'status']);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_transactions')) {
            Schema::table('sale_transactions', function (Blueprint $table) {
                $table->dropIndex(['wallet_address']);
                $table->dropIndex(['token_sale_id', 'status']);
                $table->dropIndex(['wallet_address', 'status']);
            });
        }

        if (Schema::hasTable('sale_phases')) {
            Schema::table('sale_phases', function (Blueprint $table) {
                $table->dropIndex(['token_sale_id', 'status']);
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);
        foreach ($indexes as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
};
