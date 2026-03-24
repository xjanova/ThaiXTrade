<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Update chain logo URLs from cryptologos.cc (blocked by browsers)
 * to TrustWallet Assets CDN (reliable, CORS-friendly).
 *
 * Note: chains table uses 'logo' column (not 'icon').
 * The API transforms this to 'icon' in the response.
 */
return new class() extends Migration
{
    private array $logoMap = [
        'Ethereum' => 'https://assets.trustwalletapp.com/blockchains/ethereum/info/logo.png',
        'BNB Smart Chain' => 'https://assets.trustwalletapp.com/blockchains/smartchain/info/logo.png',
        'Polygon' => 'https://assets.trustwalletapp.com/blockchains/polygon/info/logo.png',
        'Arbitrum One' => 'https://assets.trustwalletapp.com/blockchains/arbitrum/info/logo.png',
        'Optimism' => 'https://assets.trustwalletapp.com/blockchains/optimism/info/logo.png',
        'Avalanche' => 'https://assets.trustwalletapp.com/blockchains/avalanchec/info/logo.png',
        'Fantom' => 'https://assets.trustwalletapp.com/blockchains/fantom/info/logo.png',
        'Base' => 'https://assets.trustwalletapp.com/blockchains/base/info/logo.png',
        'zkSync Era' => 'https://assets.trustwalletapp.com/blockchains/zksync/info/logo.png',
        'TPIX Chain' => '/logo.webp',
    ];

    public function up(): void
    {
        foreach ($this->logoMap as $name => $logo) {
            DB::table('chains')
                ->where('name', $name)
                ->update(['logo' => $logo]);
        }
    }

    public function down(): void
    {
        // No-op: old URLs were broken anyway
    }
};
