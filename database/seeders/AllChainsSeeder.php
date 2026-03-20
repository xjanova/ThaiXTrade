<?php

namespace Database\Seeders;

use App\Models\Chain;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — สร้าง chains ทั้งหมดใน DB ให้ตรงกับ config/chains.php
 * ใช้ firstOrCreate — ถ้ามีอยู่แล้วจะข้าม
 * Developed by Xman Studio.
 */
class AllChainsSeeder extends Seeder
{
    public function run(): void
    {
        $chains = [
            ['name' => 'Ethereum', 'symbol' => 'ETH', 'chain_id_hex' => '0x1', 'rpc_url' => 'https://eth.llamarpc.com', 'explorer_url' => 'https://etherscan.io', 'native_currency_name' => 'Ether', 'native_currency_symbol' => 'ETH', 'native_currency_decimals' => 18, 'sort_order' => 1],
            ['name' => 'BNB Smart Chain', 'symbol' => 'BNB', 'chain_id_hex' => '0x38', 'rpc_url' => 'https://bsc-dataseed1.binance.org', 'explorer_url' => 'https://bscscan.com', 'native_currency_name' => 'BNB', 'native_currency_symbol' => 'BNB', 'native_currency_decimals' => 18, 'sort_order' => 2],
            ['name' => 'Polygon', 'symbol' => 'MATIC', 'chain_id_hex' => '0x89', 'rpc_url' => 'https://polygon-rpc.com', 'explorer_url' => 'https://polygonscan.com', 'native_currency_name' => 'MATIC', 'native_currency_symbol' => 'MATIC', 'native_currency_decimals' => 18, 'sort_order' => 3],
            ['name' => 'Arbitrum One', 'symbol' => 'ARB', 'chain_id_hex' => '0xA4B1', 'rpc_url' => 'https://arb1.arbitrum.io/rpc', 'explorer_url' => 'https://arbiscan.io', 'native_currency_name' => 'Ether', 'native_currency_symbol' => 'ETH', 'native_currency_decimals' => 18, 'sort_order' => 4],
            ['name' => 'Optimism', 'symbol' => 'OP', 'chain_id_hex' => '0xA', 'rpc_url' => 'https://mainnet.optimism.io', 'explorer_url' => 'https://optimistic.etherscan.io', 'native_currency_name' => 'Ether', 'native_currency_symbol' => 'ETH', 'native_currency_decimals' => 18, 'sort_order' => 5],
            ['name' => 'Avalanche C-Chain', 'symbol' => 'AVAX', 'chain_id_hex' => '0xA86A', 'rpc_url' => 'https://api.avax.network/ext/bc/C/rpc', 'explorer_url' => 'https://snowtrace.io', 'native_currency_name' => 'AVAX', 'native_currency_symbol' => 'AVAX', 'native_currency_decimals' => 18, 'sort_order' => 6],
            ['name' => 'Fantom', 'symbol' => 'FTM', 'chain_id_hex' => '0xFA', 'rpc_url' => 'https://rpc.ftm.tools', 'explorer_url' => 'https://ftmscan.com', 'native_currency_name' => 'FTM', 'native_currency_symbol' => 'FTM', 'native_currency_decimals' => 18, 'sort_order' => 7],
            ['name' => 'Base', 'symbol' => 'BASE', 'chain_id_hex' => '0x2105', 'rpc_url' => 'https://mainnet.base.org', 'explorer_url' => 'https://basescan.org', 'native_currency_name' => 'Ether', 'native_currency_symbol' => 'ETH', 'native_currency_decimals' => 18, 'sort_order' => 8],
            ['name' => 'zkSync Era', 'symbol' => 'ZKSYNC', 'chain_id_hex' => '0x144', 'rpc_url' => 'https://mainnet.era.zksync.io', 'explorer_url' => 'https://explorer.zksync.io', 'native_currency_name' => 'Ether', 'native_currency_symbol' => 'ETH', 'native_currency_decimals' => 18, 'sort_order' => 9],
            ['name' => 'TPIX Chain', 'symbol' => 'TPIX', 'chain_id_hex' => '0x10C1', 'rpc_url' => 'https://rpc.tpix.online', 'explorer_url' => 'https://explorer.tpix.online', 'native_currency_name' => 'TPIX', 'native_currency_symbol' => 'TPIX', 'native_currency_decimals' => 18, 'sort_order' => 10],
        ];

        foreach ($chains as $data) {
            Chain::firstOrCreate(
                ['chain_id_hex' => $data['chain_id_hex']],
                array_merge($data, ['is_active' => true])
            );
        }

        $this->command?->info('Chains ทั้งหมด: '.Chain::count());
    }
}
