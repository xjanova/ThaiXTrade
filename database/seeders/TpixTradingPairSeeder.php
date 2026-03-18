<?php

namespace Database\Seeders;

use App\Models\TradingPair;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — สร้างคู่เทรด TPIX/USDT
 * Developed by Xman Studio.
 */
class TpixTradingPairSeeder extends Seeder
{
    public function run(): void
    {
        // สร้างคู่เทรด TPIX/USDT ถ้ายังไม่มี
        TradingPair::firstOrCreate(
            ['symbol' => 'TPIX-USDT'],
            [
                'base_asset' => 'TPIX',
                'quote_asset' => 'USDT',
                'chain_id' => 4289,
                'price_decimals' => 4,
                'amount_decimals' => 2,
                'min_amount' => 1,
                'max_amount' => 10000000,
                'min_price' => 0.0001,
                'max_price' => 1000,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
    }
}
