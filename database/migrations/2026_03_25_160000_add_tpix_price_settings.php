<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/*
 * Add TPIX token price settings for internal price feed.
 * These are used by TpixPriceController for:
 *   - Trade page (TPIX/USDT pair)
 *   - CoinMarketCap / CoinGecko integration
 *   - MetaMask portfolio display
 */
return new class() extends Migration
{
    public function up(): void
    {
        $settings = [
            ['group' => 'trading', 'key' => 'tpix_price', 'value' => '0.18', 'type' => 'string'],
            ['group' => 'trading', 'key' => 'tpix_change_24h', 'value' => '0', 'type' => 'string'],
            ['group' => 'trading', 'key' => 'tpix_volume_24h', 'value' => '0', 'type' => 'string'],
            ['group' => 'trading', 'key' => 'tpix_circulating_supply', 'value' => '1000000000', 'type' => 'string'],
        ];

        foreach ($settings as $setting) {
            SiteSetting::firstOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                ['value' => $setting['value'], 'type' => $setting['type']],
            );
        }
    }

    public function down(): void
    {
        SiteSetting::where('group', 'trading')
            ->whereIn('key', ['tpix_price', 'tpix_change_24h', 'tpix_volume_24h', 'tpix_circulating_supply'])
            ->delete();
    }
};
