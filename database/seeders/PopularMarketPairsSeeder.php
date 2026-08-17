<?php

namespace Database\Seeders;

use App\Models\Chain;
use App\Models\Token;
use App\Models\TradingPair;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — เพิ่มตลาดยอดนิยมจาก Binance อีก 50 คู่ (โหมด index).
 *
 * "index" = ดูราคา กราฟ และเส้นกราฟย่อได้ครบ (ราคาสดจาก Binance) แต่ยังส่งคำสั่ง
 * ไม่ได้ เพราะเหรียญเหล่านี้ยังไม่มีสัญญาที่ตรวจสอบแล้วบนเชนที่เราเปิดเทรด (BSC)
 * ฟอร์มซื้อขายจะขึ้น "เปิดเร็วๆ นี้" เหมือนคู่ TPIX
 *
 * ⚠️ contract_address ของเหรียญกลุ่มนี้ตั้งใจใช้รูปแบบ `index:SYMBOL`
 *    ไม่ใช่ 0x… ปลอมแบบ seeder รุ่นเก่า — เพื่อให้ไม่มีทางเข้าใจผิดว่าเป็น
 *    address จริงแล้วเผลอเอาไปส่งธุรกรรม (บทเรียนจากคู่ placeholder ชุดก่อน)
 *
 * Idempotent — รันซ้ำได้ (updateOrCreate)
 *
 * Usage:
 *   php artisan db:seed --class=PopularMarketPairsSeeder
 *
 * Developed by Xman Studio.
 */
class PopularMarketPairsSeeder extends Seeder
{
    /**
     * ตลาด USDT ยอดนิยมบน Binance ที่ยังไม่มีในระบบ
     * [symbol, ชื่อเหรียญ, coingecko_id, ทศนิยมราคา].
     */
    private const MARKETS = [
        ['SUI', 'Sui', 'sui', 4],
        ['APT', 'Aptos', 'aptos', 4],
        ['ARB', 'Arbitrum', 'arbitrum', 4],
        ['OP', 'Optimism', 'optimism', 4],
        ['TIA', 'Celestia', 'celestia', 4],
        ['SEI', 'Sei', 'sei-network', 5],
        ['INJ', 'Injective', 'injective-protocol', 3],
        ['RUNE', 'THORChain', 'thorchain', 4],
        ['FIL', 'Filecoin', 'filecoin', 3],
        ['ETC', 'Ethereum Classic', 'ethereum-classic', 2],
        ['HBAR', 'Hedera', 'hedera-hashgraph', 5],
        ['VET', 'VeChain', 'vechain', 6],
        ['ICP', 'Internet Computer', 'internet-computer', 3],
        ['ALGO', 'Algorand', 'algorand', 5],
        ['XLM', 'Stellar', 'stellar', 5],
        ['AAVE', 'Aave', 'aave', 2],
        ['MKR', 'Maker', 'maker', 2],
        ['GRT', 'The Graph', 'the-graph', 5],
        ['SAND', 'The Sandbox', 'the-sandbox', 5],
        ['MANA', 'Decentraland', 'decentraland', 5],
        ['AXS', 'Axie Infinity', 'axie-infinity', 3],
        ['CHZ', 'Chiliz', 'chiliz', 6],
        ['EOS', 'EOS', 'eos', 4],
        ['FLOW', 'Flow', 'flow', 4],
        ['GALA', 'Gala', 'gala', 6],
        ['IMX', 'Immutable', 'immutable-x', 4],
        ['LDO', 'Lido DAO', 'lido-dao', 4],
        ['CRV', 'Curve DAO', 'curve-dao-token', 4],
        ['COMP', 'Compound', 'compound-governance-token', 2],
        ['SNX', 'Synthetix', 'havven', 4],
        ['ENS', 'Ethereum Name Service', 'ethereum-name-service', 3],
        ['DYDX', 'dYdX', 'dydx-chain', 4],
        ['STX', 'Stacks', 'blockstack', 4],
        ['KAVA', 'Kava', 'kava', 4],
        ['ZIL', 'Zilliqa', 'zilliqa', 6],
        ['IOTA', 'IOTA', 'iota', 5],
        ['NEO', 'Neo', 'neo', 3],
        ['QNT', 'Quant', 'quant-network', 2],
        ['THETA', 'Theta Network', 'theta-token', 4],
        ['EGLD', 'MultiversX', 'elrond-erd-2', 2],
        ['CAKE', 'PancakeSwap', 'pancakeswap-token', 4],
        ['XTZ', 'Tezos', 'tezos', 4],
        ['WIF', 'dogwifhat', 'dogwifcoin', 4],
        ['BONK', 'Bonk', 'bonk', 8],
        ['FLOKI', 'FLOKI', 'floki', 8],
        ['JUP', 'Jupiter', 'jupiter-exchange-solana', 4],
        ['PYTH', 'Pyth Network', 'pyth-network', 4],
        ['RENDER', 'Render', 'render-token', 3],
        ['ONDO', 'Ondo', 'ondo-finance', 4],
        ['ENA', 'Ethena', 'ethena', 4],
    ];

    public function run(): void
    {
        // ตลาด index ผูกไว้กับเชนของแพลตฟอร์มเอง — ไม่ใช่ BSC เพราะไม่มีสัญญาจริงที่นั่น
        $chain = Chain::where('symbol', 'TPIX')->first();

        if (! $chain) {
            $this->command?->warn('ไม่พบ TPIX chain — ข้าม seeder (รัน AllChainsSeeder ก่อน)');

            return;
        }

        $quote = $this->resolveQuoteToken($chain);
        $created = 0;
        $sortOrder = 100;

        foreach (self::MARKETS as [$symbol, $name, $coingeckoId, $precision]) {
            // เหรียญที่เทรดจริงบน BSC อยู่แล้ว ไม่ต้องสร้างซ้ำเป็น index
            if (array_key_exists($symbol, config('bsc_trade_tokens.tokens', []))) {
                continue;
            }

            $token = Token::updateOrCreate(
                ['chain_id' => $chain->id, 'contract_address' => "index:{$symbol}"],
                [
                    'symbol' => $symbol,
                    'name' => $name,
                    'decimals' => 18,
                    'coingecko_id' => $coingeckoId,
                    'logo' => 'https://assets.coincap.io/assets/icons/'.strtolower($symbol).'@2x.png',
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ]
            );

            TradingPair::updateOrCreate(
                [
                    'base_token_id' => $token->id,
                    'quote_token_id' => $quote->id,
                    'chain_id' => $chain->id,
                ],
                [
                    'symbol' => "{$symbol}-USDT",
                    'is_active' => true,
                    'execution_mode' => 'index',
                    'price_precision' => $precision,
                    'amount_precision' => 6,
                    'min_trade_amount' => 0.0001,
                    'max_trade_amount' => 1000000,
                    'sort_order' => $sortOrder,
                ]
            );

            $created++;
            $sortOrder++;
        }

        $this->command?->info("✅ ตลาดยอดนิยม (โหมดดูราคา): {$created} คู่");
        $this->command?->info('รวมคู่เทรดที่เปิดใช้งาน: '.TradingPair::active()->count());
    }

    /**
     * หา USDT บนเชนของแพลตฟอร์ม — สร้างให้ถ้ายังไม่มี.
     */
    private function resolveQuoteToken(Chain $chain): Token
    {
        return Token::firstOrCreate(
            ['chain_id' => $chain->id, 'symbol' => 'USDT'],
            [
                'name' => 'Tether USD',
                'contract_address' => 'index:USDT',
                'decimals' => 18,
                'coingecko_id' => 'tether',
                'logo' => 'https://assets.coincap.io/assets/icons/usdt@2x.png',
                'is_active' => true,
                'sort_order' => 2,
            ]
        );
    }
}
