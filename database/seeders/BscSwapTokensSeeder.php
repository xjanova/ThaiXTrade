<?php

namespace Database\Seeders;

use App\Models\Chain;
use App\Models\SwapConfig;
use App\Models\Token;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — Token สำหรับหน้า Swap บน BSC (PancakeSwap V2)
 *
 * หน้า /swap ฝั่ง frontend มี token list 8 ตัว (Swap.vue) แต่ DB production
 * มีแค่ BNB + USDT ทำให้ /api/v1/swap/quote ตอบ INVALID_TOKEN สำหรับคู่อื่น
 * → frontend fallback ไปใช้ fee rate default แทนค่าที่ตั้งใน admin
 *
 * Seeder นี้ upsert token ที่เหลือ + SwapConfig ของ PancakeSwap V2
 * (idempotent — รันซ้ำได้ ไม่ทับข้อมูลที่ admin แก้เอง)
 *
 * Developed by Xman Studio.
 */
class BscSwapTokensSeeder extends Seeder
{
    public function run(): void
    {
        $bsc = Chain::where('chain_id_hex', '0x38')->first();
        if (! $bsc) {
            $this->command?->warn('BSC chain ไม่พบใน DB — ข้าม BscSwapTokensSeeder');

            return;
        }

        // Address ต้องตรงกับ token list ใน resources/js/Pages/Swap.vue
        $tokens = [
            ['symbol' => 'USDC', 'name' => 'USD Coin', 'contract_address' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 'decimals' => 18, 'sort_order' => 11],
            ['symbol' => 'ETH', 'name' => 'Ethereum', 'contract_address' => '0x2170Ed0880ac9A755fd29B2688956BD959F933F8', 'decimals' => 18, 'sort_order' => 12],
            ['symbol' => 'BTC', 'name' => 'Bitcoin (BTCB)', 'contract_address' => '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c', 'decimals' => 18, 'sort_order' => 13],
            ['symbol' => 'SOL', 'name' => 'Solana', 'contract_address' => '0x570A5D26f7765Ecb712C0924E4De545B89fD43dF', 'decimals' => 18, 'sort_order' => 14],
            ['symbol' => 'DOGE', 'name' => 'Dogecoin', 'contract_address' => '0xbA2aE424d960c26247Dd6c32edC70B295c744C43', 'decimals' => 8, 'sort_order' => 15],
            ['symbol' => 'CAKE', 'name' => 'PancakeSwap', 'contract_address' => '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82', 'decimals' => 18, 'sort_order' => 16],
        ];

        foreach ($tokens as $token) {
            Token::firstOrCreate(
                ['symbol' => $token['symbol'], 'chain_id' => $bsc->id],
                [
                    'name' => $token['name'],
                    'contract_address' => $token['contract_address'],
                    'decimals' => $token['decimals'],
                    'is_active' => true,
                    'sort_order' => $token['sort_order'],
                ]
            );
        }

        // SwapConfig — PancakeSwap V2 router (ใช้โดย /swap/routes + default slippage)
        // protocol เป็น enum: uniswap_v2|uniswap_v3|pancakeswap|sushiswap|custom เท่านั้น
        SwapConfig::firstOrCreate(
            ['chain_id' => $bsc->id, 'protocol' => 'pancakeswap'],
            [
                'name' => 'PancakeSwap V2',
                'router_address' => '0x10ED43C718714eb63d5aA57B78B54704E256024E',
                'factory_address' => '0xcA143Ce32Fe78f1f7019d7d551a6402fC5350c73',
                'slippage_tolerance' => 0.5,
                'is_active' => true,
                'metadata' => ['dex_url' => 'https://pancakeswap.finance'],
            ]
        );

        $count = Token::where('chain_id', $bsc->id)->count();
        $this->command?->info("BSC swap tokens พร้อมใช้: {$count} tokens + PancakeSwap V2 config");
    }
}
