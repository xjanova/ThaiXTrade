<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Run the migrations.
     *
     * Inserts trading-related site settings for the fee collection system.
     */
    public function up(): void
    {
        $now = now();

        DB::table('site_settings')->insert([
            [
                'group' => 'trading',
                'key' => 'fee_collector_wallet',
                'value' => '',
                'type' => 'string',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'trading',
                'key' => 'default_fee_rate',
                'value' => '0.3',
                'type' => 'number',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'trading',
                'key' => 'max_fee_rate',
                'value' => '5.0',
                'type' => 'number',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * Removes the trading fee collection settings.
     */
    public function down(): void
    {
        DB::table('site_settings')
            ->where('group', 'trading')
            ->whereIn('key', [
                'fee_collector_wallet',
                'default_fee_rate',
                'max_fee_rate',
            ])
            ->delete();
    }
};
