<?php

namespace Database\Seeders;

use App\Models\Chain;
use App\Models\Token;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — สร้าง tokens พื้นฐานสำหรับทุก chain
 * Native coin + USDT สำหรับทุก chain ที่ active
 * Developed by Xman Studio.
 */
class BaseTokensSeeder extends Seeder
{
    public function run(): void
    {
        // USDT contract addresses ต่อ chain
        $usdtContracts = [
            '0x1' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',    // ETH
            '0x38' => '0x55d398326f99059fF775485246999027B3197955',   // BSC
            '0x89' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',   // Polygon
            '0xA4B1' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9', // Arbitrum
            '0xA' => '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58',    // Optimism
            '0xA86A' => '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7', // Avalanche
            '0xFA' => '0x049d68029688eAbF473097a2fC38ef61633A3C7A',    // Fantom
            '0x2105' => '0xd9aAEc86B65D86f6A7B5B1b0c42FFA531710b6CA', // Base (USDbC)
            '0x144' => '0x493257fD37EDB34451f62EDf8D2a0C418852bA4C',   // zkSync
            '0x10C1' => '0x0000000000000000000000000000000000000001',   // TPIX Chain
        ];

        $chains = Chain::where('is_active', true)->get();

        foreach ($chains as $chain) {
            // Native coin
            Token::firstOrCreate(
                ['symbol' => $chain->native_currency_symbol, 'chain_id' => $chain->id],
                [
                    'name' => $chain->native_currency_name,
                    'contract_address' => '0x0000000000000000000000000000000000000000',
                    'decimals' => $chain->native_currency_decimals,
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );

            // USDT
            $hex = $chain->chain_id_hex;
            if (isset($usdtContracts[$hex])) {
                Token::firstOrCreate(
                    ['symbol' => 'USDT', 'chain_id' => $chain->id],
                    [
                        'name' => 'Tether USD',
                        'contract_address' => $usdtContracts[$hex],
                        'decimals' => $hex === '0x2105' ? 6 : 18,
                        'is_active' => true,
                        'sort_order' => 10,
                    ]
                );
            }
        }

        $tokenCount = Token::count();
        $this->command?->info("Tokens ทั้งหมด: {$tokenCount}");
    }
}
