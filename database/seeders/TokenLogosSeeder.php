<?php

namespace Database\Seeders;

use App\Models\Token;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — Update token logos สำหรับ token ที่มีอยู่ใน DB อยู่แล้ว
 *
 * ทำไมต้องแยก seeder: TpixTradingPairSeeder/MajorTradingPairsSeeder ใช้
 * `firstOrCreate` ซึ่งจะ skip การ update logo ถ้า record มีอยู่แล้ว →
 * tokens ที่ seed ไปก่อนหน้านี้ (เช่น USDT จาก TpixTradingPairSeeder รุ่นเก่า)
 * จะติดสภาพ "logo = null" ตลอดจนกว่าจะมีคน update มือ
 *
 * Seeder นี้ทำงานเป็น **upsert logo only** — ไม่สร้าง token ใหม่,
 * แค่ overwrite logo field ของ token ที่ symbol ตรง (per chain TPIX 4289)
 *
 * Idempotent — รันซ้ำได้ปลอดภัย
 *
 * Usage:
 *   php artisan db:seed --class=TokenLogosSeeder
 *
 * Developed by Xman Studio.
 */
class TokenLogosSeeder extends Seeder
{
    /**
     * symbol → logo URL (canonical default)
     *
     * Logos จาก trustwallet/assets — public CDN ที่เสถียร
     * TPIX ใช้ logo ภายใน /tpixlogo.webp (admin upload override ได้)
     */
    private const LOGOS = [
        'TPIX' => '/tpixlogo.webp',
        'USDT' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0xdAC17F958D2ee523a2206206994597C13D831ec7/logo.png',
        'BTC' => 'https://assets-cdn.trustwallet.com/blockchains/bitcoin/info/logo.png',
        'ETH' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/info/logo.png',
        'BNB' => 'https://assets-cdn.trustwallet.com/blockchains/binance/info/logo.png',
        'SOL' => 'https://assets-cdn.trustwallet.com/blockchains/solana/info/logo.png',
        'XRP' => 'https://assets-cdn.trustwallet.com/blockchains/ripple/info/logo.png',
        'DOGE' => 'https://assets-cdn.trustwallet.com/blockchains/doge/info/logo.png',
        'ADA' => 'https://assets-cdn.trustwallet.com/blockchains/cardano/info/logo.png',
        'POL' => 'https://assets-cdn.trustwallet.com/blockchains/polygon/info/logo.png',
        'MATIC' => 'https://assets-cdn.trustwallet.com/blockchains/polygon/info/logo.png',
        'AVAX' => 'https://assets-cdn.trustwallet.com/blockchains/avalanchec/info/logo.png',
        'DOT' => 'https://assets-cdn.trustwallet.com/blockchains/polkadot/info/logo.png',
        'LINK' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x514910771AF9Ca656af840dff83E8264EcF986CA/logo.png',
        'UNI' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x1f9840a85d5aF5bf1D1762F925BDADdC4201F984/logo.png',
        'LTC' => 'https://assets-cdn.trustwallet.com/blockchains/litecoin/info/logo.png',
        'TRX' => 'https://assets-cdn.trustwallet.com/blockchains/tron/info/logo.png',
        'ATOM' => 'https://assets-cdn.trustwallet.com/blockchains/cosmos/info/logo.png',
        'NEAR' => 'https://assets-cdn.trustwallet.com/blockchains/near/info/logo.png',
        'SHIB' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x95aD61b0a150d79219dCF64E1E6Cc01f0B64C4cE/logo.png',
        'PEPE' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x6982508145454Ce325dDbE47a25d4ec3d2311933/logo.png',
    ];

    public function run(): void
    {
        $updated = 0;
        $alreadySet = 0;
        $notFound = 0;

        foreach (self::LOGOS as $symbol => $logo) {
            $tokens = Token::where('symbol', $symbol)->get();

            if ($tokens->isEmpty()) {
                $notFound++;

                continue;
            }

            foreach ($tokens as $token) {
                // ไม่ override ถ้า admin upload logo ผ่าน /admin/tokens แล้ว
                // (admin upload จะเป็น relative path "tokens/xxx.png" — ไม่ใช่ trustwallet URL)
                $isAdminUpload = $token->logo
                    && ! str_starts_with($token->logo, 'http')
                    && ! str_starts_with($token->logo, '/tpixlogo')
                    && ! str_starts_with($token->logo, '/logo');

                if ($isAdminUpload) {
                    $alreadySet++;

                    continue;
                }

                // เปลี่ยนเฉพาะถ้าค่าต่างจาก default
                if ($token->logo === $logo) {
                    $alreadySet++;

                    continue;
                }

                $token->update(['logo' => $logo]);
                $updated++;
            }
        }

        $this->command?->info("Token logos: updated {$updated} | already-set {$alreadySet} | not-in-DB {$notFound}");
    }
}
