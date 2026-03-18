<?php

namespace Database\Seeders;

use App\Models\Chain;
use App\Models\Token;
use App\Models\TradingPair;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — สร้าง TPIX token + USDT token + คู่เทรด TPIX/USDT
 * ตรวจสอบก่อนสร้าง — ถ้ามีอยู่แล้วจะข้าม
 * Developed by Xman Studio.
 */
class TpixTradingPairSeeder extends Seeder
{
    public function run(): void
    {
        // หา chain TPIX (4289) — ถ้าไม่มีใช้ chain แรก
        $chain = Chain::where('chain_id', 4289)->first()
            ?? Chain::first();

        if (! $chain) {
            $this->command?->warn('ไม่พบ chain ในระบบ — ข้าม seeder');

            return;
        }

        // สร้าง TPIX token (native coin)
        $tpix = Token::firstOrCreate(
            ['symbol' => 'TPIX', 'chain_id' => $chain->id],
            [
                'name' => 'Thaiprompt Index',
                'contract_address' => '0x0000000000000000000000000000000000000000',
                'decimals' => 18,
                'is_native' => true,
                'is_active' => true,
                'logo_url' => '/logo.png',
            ]
        );

        // สร้าง USDT token
        $usdt = Token::firstOrCreate(
            ['symbol' => 'USDT', 'chain_id' => $chain->id],
            [
                'name' => 'Tether USD',
                'contract_address' => '0x0000000000000000000000000000000000000001',
                'decimals' => 18,
                'is_native' => false,
                'is_active' => true,
            ]
        );

        // สร้างคู่เทรด TPIX/USDT
        TradingPair::firstOrCreate(
            [
                'base_token_id' => $tpix->id,
                'quote_token_id' => $usdt->id,
                'chain_id' => $chain->id,
            ],
            [
                'symbol' => 'TPIX-USDT',
                'is_active' => true,
                'price_precision' => 4,
                'amount_precision' => 2,
                'min_trade_amount' => 1,
                'max_trade_amount' => 10000000,
                'sort_order' => 1,
            ]
        );

        $this->command?->info('สร้างคู่เทรด TPIX/USDT สำเร็จ');
    }
}
