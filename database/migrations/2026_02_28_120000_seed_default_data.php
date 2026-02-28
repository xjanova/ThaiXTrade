<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        // -------------------------------------------------------------------
        // Default Site Settings
        // -------------------------------------------------------------------
        DB::table('site_settings')->insert([
            // General
            ['group' => 'general', 'key' => 'site_name', 'value' => 'TPIX TRADE', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'general', 'key' => 'site_description', 'value' => 'Decentralized Exchange Platform', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'general', 'key' => 'logo', 'value' => null, 'type' => 'image', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'general', 'key' => 'favicon', 'value' => null, 'type' => 'image', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'general', 'key' => 'primary_color', 'value' => '#0ea5e9', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],

            // SEO
            ['group' => 'seo', 'key' => 'meta_title', 'value' => 'TPIX TRADE - Decentralized Exchange', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'seo', 'key' => 'meta_description', 'value' => 'Trade securely from your own wallet', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'seo', 'key' => 'og_image', 'value' => null, 'type' => 'image', 'created_at' => $now, 'updated_at' => $now],

            // Trading
            ['group' => 'trading', 'key' => 'default_slippage', 'value' => '0.5', 'type' => 'number', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'trading', 'key' => 'max_slippage', 'value' => '50', 'type' => 'number', 'created_at' => $now, 'updated_at' => $now],

            // Security
            ['group' => 'security', 'key' => 'turnstile_enabled', 'value' => 'false', 'type' => 'boolean', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'security', 'key' => 'turnstile_site_key', 'value' => '', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'security', 'key' => 'turnstile_secret_key', 'value' => '', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'security', 'key' => 'max_login_attempts', 'value' => '5', 'type' => 'number', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'security', 'key' => 'lockout_duration', 'value' => '15', 'type' => 'number', 'created_at' => $now, 'updated_at' => $now],

            // Social
            ['group' => 'social', 'key' => 'twitter', 'value' => '', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'social', 'key' => 'telegram', 'value' => '', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'social', 'key' => 'discord', 'value' => '', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'social', 'key' => 'github', 'value' => '', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // -------------------------------------------------------------------
        // Default Languages
        // -------------------------------------------------------------------
        DB::table('languages')->insert([
            [
                'code' => 'en',
                'name' => 'English',
                'native_name' => 'English',
                'flag_emoji' => null,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'th',
                'name' => 'Thai',
                'native_name' => 'ไทย',
                'flag_emoji' => null,
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // -------------------------------------------------------------------
        // Default Fee Config
        // -------------------------------------------------------------------
        DB::table('fee_configs')->insert([
            'name' => 'Default Trading Fee',
            'type' => 'trading',
            'maker_fee' => 0.1,
            'taker_fee' => 0.1,
            'min_amount' => null,
            'max_amount' => null,
            'chain_id' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // -------------------------------------------------------------------
        // Default Chains
        // -------------------------------------------------------------------
        DB::table('chains')->insert([
            [
                'name' => 'Ethereum',
                'symbol' => 'ETH',
                'chain_id_hex' => '0x1',
                'rpc_url' => 'https://eth.llamarpc.com',
                'explorer_url' => 'https://etherscan.io',
                'logo' => null,
                'is_testnet' => false,
                'is_active' => true,
                'native_currency_name' => 'Ether',
                'native_currency_symbol' => 'ETH',
                'native_currency_decimals' => 18,
                'block_confirmations' => 12,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'name' => 'BNB Smart Chain',
                'symbol' => 'BNB',
                'chain_id_hex' => '0x38',
                'rpc_url' => 'https://bsc-dataseed.binance.org',
                'explorer_url' => 'https://bscscan.com',
                'logo' => null,
                'is_testnet' => false,
                'is_active' => true,
                'native_currency_name' => 'BNB',
                'native_currency_symbol' => 'BNB',
                'native_currency_decimals' => 18,
                'block_confirmations' => 15,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'name' => 'Polygon',
                'symbol' => 'MATIC',
                'chain_id_hex' => '0x89',
                'rpc_url' => 'https://polygon-rpc.com',
                'explorer_url' => 'https://polygonscan.com',
                'logo' => null,
                'is_testnet' => false,
                'is_active' => true,
                'native_currency_name' => 'MATIC',
                'native_currency_symbol' => 'MATIC',
                'native_currency_decimals' => 18,
                'block_confirmations' => 128,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('chains')->whereIn('chain_id_hex', ['0x1', '0x38', '0x89'])->delete();
        DB::table('fee_configs')->where('name', 'Default Trading Fee')->delete();
        DB::table('languages')->whereIn('code', ['en', 'th'])->delete();
        DB::table('site_settings')->whereIn('group', ['general', 'seo', 'trading', 'security', 'social'])->delete();
    }
};
