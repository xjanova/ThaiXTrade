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

        // Bitkub Chain (KUB)
        DB::table('chains')->insert([
            'name' => 'Bitkub Chain',
            'symbol' => 'KUB',
            'chain_id_hex' => '0x60',
            'rpc_url' => 'https://rpc.bitkubchain.io',
            'explorer_url' => 'https://www.bkcscan.com',
            'logo' => null,
            'is_testnet' => false,
            'is_active' => true,
            'native_currency_name' => 'KUB',
            'native_currency_symbol' => 'KUB',
            'native_currency_decimals' => 18,
            'block_confirmations' => 12,
            'sort_order' => 3,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        // Default Bitkub Chain tokens
        $bitkubChainId = DB::table('chains')->where('chain_id_hex', '0x60')->value('id');

        if ($bitkubChainId) {
            DB::table('tokens')->insert([
                [
                    'chain_id' => $bitkubChainId,
                    'name' => 'Wrapped KUB',
                    'symbol' => 'WKUB',
                    'contract_address' => '0x67eBD850304c70d983B2d1b93ea79c7CD6c3F6b5',
                    'decimals' => 18,
                    'logo' => null,
                    'coingecko_id' => 'bitkub-coin',
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'chain_id' => $bitkubChainId,
                    'name' => 'KUSDT',
                    'symbol' => 'KUSDT',
                    'contract_address' => '0x7d984C24d2499D840eB3b7016077164e15E5faA6',
                    'decimals' => 18,
                    'logo' => null,
                    'coingecko_id' => null,
                    'is_active' => true,
                    'sort_order' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'chain_id' => $bitkubChainId,
                    'name' => 'KUSDC',
                    'symbol' => 'KUSDC',
                    'contract_address' => '0x1bF36e8c5463d3E3B1abf03530C25F8a1895e893',
                    'decimals' => 18,
                    'logo' => null,
                    'coingecko_id' => null,
                    'is_active' => true,
                    'sort_order' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $bitkubChainId = DB::table('chains')->where('chain_id_hex', '0x60')->value('id');
        if ($bitkubChainId) {
            DB::table('tokens')->where('chain_id', $bitkubChainId)->delete();
        }
        DB::table('chains')->where('chain_id_hex', '0x60')->delete();
    }
};
