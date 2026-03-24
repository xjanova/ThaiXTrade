<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Update chain icon URLs from cryptologos.cc (blocked by browsers)
 * to TrustWallet Assets CDN (reliable, CORS-friendly).
 */
return new class extends Migration
{
    private array $iconMap = [
        1 => 'https://assets.trustwalletapp.com/blockchains/ethereum/info/logo.png',
        56 => 'https://assets.trustwalletapp.com/blockchains/smartchain/info/logo.png',
        137 => 'https://assets.trustwalletapp.com/blockchains/polygon/info/logo.png',
        42161 => 'https://assets.trustwalletapp.com/blockchains/arbitrum/info/logo.png',
        10 => 'https://assets.trustwalletapp.com/blockchains/optimism/info/logo.png',
        43114 => 'https://assets.trustwalletapp.com/blockchains/avalanchec/info/logo.png',
        250 => 'https://assets.trustwalletapp.com/blockchains/fantom/info/logo.png',
        8453 => 'https://assets.trustwalletapp.com/blockchains/base/info/logo.png',
        324 => 'https://assets.trustwalletapp.com/blockchains/zksync/info/logo.png',
        4289 => '/logo.webp',
    ];

    public function up(): void
    {
        foreach ($this->iconMap as $chainId => $icon) {
            DB::table('chains')
                ->where('chain_id', $chainId)
                ->update(['icon' => $icon]);
        }
    }

    public function down(): void
    {
        // Revert to cryptologos
        $oldIcons = [
            1 => 'https://cryptologos.cc/logos/ethereum-eth-logo.svg',
            56 => 'https://cryptologos.cc/logos/bnb-bnb-logo.svg',
            137 => 'https://cryptologos.cc/logos/polygon-matic-logo.svg',
            42161 => 'https://cryptologos.cc/logos/arbitrum-arb-logo.svg',
            10 => 'https://cryptologos.cc/logos/optimism-ethereum-op-logo.svg',
            43114 => 'https://cryptologos.cc/logos/avalanche-avax-logo.svg',
            250 => 'https://cryptologos.cc/logos/fantom-ftm-logo.svg',
            8453 => 'https://raw.githubusercontent.com/base-org/brand-kit/main/logo/symbol/Base_Symbol_Blue.svg',
            324 => 'https://cryptologos.cc/logos/zksync-zks-logo.svg',
            4289 => '/logo.png',
        ];

        foreach ($oldIcons as $chainId => $icon) {
            DB::table('chains')
                ->where('chain_id', $chainId)
                ->update(['icon' => $icon]);
        }
    }
};
