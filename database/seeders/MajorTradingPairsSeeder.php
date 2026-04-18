<?php

namespace Database\Seeders;

use App\Models\Chain;
use App\Models\Token;
use App\Models\TradingPair;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — เพิ่มคู่เทรดยอดนิยม (BTC, ETH, BNB, SOL ฯลฯ) คู่กับ USDT
 *
 * ระบบ trade เป็น index/proxy → token entries เป็น virtual record
 * ราคาจริงดึงจาก Binance ผ่าน MarketDataService.getTokenPrice()
 * contract_address จึงใช้ deterministic placeholder (hex ของ ASCII symbol)
 * เพื่อให้ unique constraint [chain_id, contract_address] ผ่าน
 *
 * Idempotent — รันซ้ำได้ปลอดภัย (firstOrCreate)
 *
 * Usage:
 *   php artisan db:seed --class=MajorTradingPairsSeeder
 *
 * Developed by Xman Studio.
 */
class MajorTradingPairsSeeder extends Seeder
{
    /**
     * ลำดับเรียงตาม market cap (ปรับได้ตามต้องการ)
     * [symbol, name, decimals, logo_url, sort_order]
     *
     * Logos จาก trustwallet/assets (CDN public, มีรูปครบทุก major coin)
     */
    private const MAJOR_TOKENS = [
        ['BTC',   'Bitcoin',     8,  'https://assets-cdn.trustwallet.com/blockchains/bitcoin/info/logo.png',  10],
        ['ETH',   'Ethereum',    18, 'https://assets-cdn.trustwallet.com/blockchains/ethereum/info/logo.png', 11],
        ['BNB',   'BNB',         18, 'https://assets-cdn.trustwallet.com/blockchains/binance/info/logo.png',  12],
        ['SOL',   'Solana',      9,  'https://assets-cdn.trustwallet.com/blockchains/solana/info/logo.png',   13],
        ['XRP',   'XRP',         6,  'https://assets-cdn.trustwallet.com/blockchains/ripple/info/logo.png',   14],
        ['DOGE',  'Dogecoin',    8,  'https://assets-cdn.trustwallet.com/blockchains/doge/info/logo.png',     15],
        ['ADA',   'Cardano',     6,  'https://assets-cdn.trustwallet.com/blockchains/cardano/info/logo.png',  16],
        ['POL',   'Polygon',     18, 'https://assets-cdn.trustwallet.com/blockchains/polygon/info/logo.png',  17],
        ['AVAX',  'Avalanche',   18, 'https://assets-cdn.trustwallet.com/blockchains/avalanchec/info/logo.png', 18],
        ['DOT',   'Polkadot',    10, 'https://assets-cdn.trustwallet.com/blockchains/polkadot/info/logo.png', 19],
        ['LINK',  'Chainlink',   18, 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x514910771AF9Ca656af840dff83E8264EcF986CA/logo.png', 20],
        ['UNI',   'Uniswap',     18, 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x1f9840a85d5aF5bf1D1762F925BDADdC4201F984/logo.png', 21],
        ['LTC',   'Litecoin',    8,  'https://assets-cdn.trustwallet.com/blockchains/litecoin/info/logo.png', 22],
        ['TRX',   'TRON',        6,  'https://assets-cdn.trustwallet.com/blockchains/tron/info/logo.png',     23],
        ['ATOM',  'Cosmos',      6,  'https://assets-cdn.trustwallet.com/blockchains/cosmos/info/logo.png',   24],
        ['NEAR',  'NEAR',        24, 'https://assets-cdn.trustwallet.com/blockchains/near/info/logo.png',     25],
        ['SHIB',  'Shiba Inu',   18, 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x95aD61b0a150d79219dCF64E1E6Cc01f0B64C4cE/logo.png', 26],
        ['PEPE',  'Pepe',        18, 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x6982508145454Ce325dDbE47a25d4ec3d2311933/logo.png', 27],
    ];

    public function run(): void
    {
        // หา TPIX chain (เป็น "home chain" ของ index trading)
        $chain = Chain::where('symbol', 'TPIX')->first()
            ?? Chain::where('chain_id_hex', '0x10C1')->first()
            ?? Chain::where('name', 'like', '%TPIX%')->first();

        if (! $chain) {
            $this->command?->warn('ไม่พบ TPIX chain — ข้าม seeder (รัน AllChainsSeeder ก่อน)');

            return;
        }

        // หา USDT (ต้องมีแล้วจาก TpixTradingPairSeeder หรือ BaseTokensSeeder)
        $usdt = Token::where('symbol', 'USDT')
            ->where('chain_id', $chain->id)
            ->first();

        if (! $usdt) {
            // สร้าง USDT ถ้ายังไม่มี
            $usdt = Token::create([
                'symbol' => 'USDT',
                'name' => 'Tether USD',
                'chain_id' => $chain->id,
                'contract_address' => '0x0000000000000000000000000000000000000001',
                'decimals' => 18,
                'is_active' => true,
                'sort_order' => 2,
                'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0xdAC17F958D2ee523a2206206994597C13D831ec7/logo.png',
            ]);
            $this->command?->info('สร้าง USDT token');
        }

        $created = 0;
        $skipped = 0;

        foreach (self::MAJOR_TOKENS as [$symbol, $name, $decimals, $logo, $sortOrder]) {
            // Deterministic placeholder contract — hex ของ ASCII symbol pad ซ้าย
            // BTC → 0x...425443  (B=42 T=54 C=43)
            // ETH → 0x...455448
            $hex = strtoupper(bin2hex($symbol));
            $contract = '0x'.str_pad($hex, 40, '0', STR_PAD_LEFT);

            $token = Token::firstOrCreate(
                ['chain_id' => $chain->id, 'contract_address' => $contract],
                [
                    'symbol' => $symbol,
                    'name' => $name,
                    'decimals' => $decimals,
                    'logo' => $logo,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                    'coingecko_id' => strtolower($symbol === 'BTC' ? 'bitcoin'
                        : ($symbol === 'ETH' ? 'ethereum'
                        : ($symbol === 'POL' ? 'matic-network'
                        : strtolower($name)))),
                ]
            );

            $pair = TradingPair::firstOrCreate(
                [
                    'base_token_id' => $token->id,
                    'quote_token_id' => $usdt->id,
                    'chain_id' => $chain->id,
                ],
                [
                    'symbol' => "{$symbol}-USDT",
                    'is_active' => true,
                    'price_precision' => $this->precisionFor($symbol),
                    'amount_precision' => 6,
                    'min_trade_amount' => 0.0001,
                    'max_trade_amount' => 1000000,
                    'sort_order' => $sortOrder,
                ]
            );

            if ($pair->wasRecentlyCreated) {
                $created++;
            } else {
                $skipped++;
            }
        }

        $this->command?->info("เพิ่มคู่เทรดยอดนิยม: สร้างใหม่ {$created} | มีอยู่แล้ว {$skipped}");
        $this->command?->info('รวมคู่เทรดทั้งหมด: '.TradingPair::active()->count());
    }

    /**
     * Price precision ตามราคาจริง (ลดทศนิยมสำหรับเหรียญราคาสูง, เพิ่มสำหรับเหรียญถูก)
     */
    private function precisionFor(string $symbol): int
    {
        return match ($symbol) {
            'BTC', 'ETH' => 2,
            'BNB', 'SOL', 'AVAX', 'LTC', 'LINK', 'UNI', 'NEAR', 'ATOM', 'DOT' => 3,
            'XRP', 'ADA', 'POL', 'DOGE', 'TRX' => 5,
            'SHIB', 'PEPE' => 10,
            default => 4,
        };
    }
}
