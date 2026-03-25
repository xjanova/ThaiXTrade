<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand token_type enum เพื่อรองรับ NFT, Governance, Stablecoin etc.
 * เพิ่ม factory settings ใน site_settings สำหรับ admin กำหนดค่าสร้างเหรียญ.
 */
return new class() extends Migration
{
    public function up(): void
    {
        // 1. Expand token_type enum
        DB::statement("ALTER TABLE factory_tokens MODIFY COLUMN token_type ENUM(
            'standard',
            'mintable',
            'burnable',
            'mintable_burnable',
            'nft',
            'nft_collection',
            'governance',
            'stablecoin',
            'utility',
            'reward'
        ) DEFAULT 'standard'");

        // 2. Add token_category column สำหรับจัดกลุ่ม
        Schema::table('factory_tokens', function (Blueprint $table) {
            $table->enum('token_category', ['fungible', 'nft', 'special'])
                ->default('fungible')
                ->after('token_type');
        });

        // 3. Seed factory settings
        $settings = [
            ['group' => 'factory', 'key' => 'creation_fee_tpix', 'value' => '100', 'type' => 'number'],
            ['group' => 'factory', 'key' => 'creation_fee_usd', 'value' => '10', 'type' => 'number'],
            ['group' => 'factory', 'key' => 'fee_payment_method', 'value' => 'tpix', 'type' => 'string'],
            ['group' => 'factory', 'key' => 'fee_wallet', 'value' => '', 'type' => 'string'],
            ['group' => 'factory', 'key' => 'nft_enabled', 'value' => '1', 'type' => 'boolean'],
            ['group' => 'factory', 'key' => 'max_supply_limit', 'value' => '999999999999999', 'type' => 'number'],
            ['group' => 'factory', 'key' => 'auto_approve', 'value' => '0', 'type' => 'boolean'],
            ['group' => 'factory', 'key' => 'creation_enabled', 'value' => '1', 'type' => 'boolean'],
        ];

        foreach ($settings as $s) {
            SiteSetting::firstOrCreate(
                ['group' => $s['group'], 'key' => $s['key']],
                ['value' => $s['value'], 'type' => $s['type']]
            );
        }
    }

    public function down(): void
    {
        Schema::table('factory_tokens', function (Blueprint $table) {
            $table->dropColumn('token_category');
        });

        DB::statement("ALTER TABLE factory_tokens MODIFY COLUMN token_type ENUM(
            'standard',
            'mintable',
            'burnable',
            'mintable_burnable'
        ) DEFAULT 'standard'");

        SiteSetting::where('group', 'factory')->delete();
    }
};
