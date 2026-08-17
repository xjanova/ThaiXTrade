<?php

namespace Database\Seeders;

use App\Models\Chain;
use App\Models\Token;
use App\Models\TradingPair;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — คู่เทรดหลักบน "เชนที่เทรดจริง" (BSC).
 *
 * ทำไมต้องมี seeder นี้:
 *   MajorTradingPairsSeeder เดิมสร้างคู่เทรดไว้บน TPIX Chain โดยใช้ contract address
 *   ปลอม (hex ของตัวอักษร symbol) เพราะตอนนั้นการเทรดยังเป็น index/proxy อย่างเดียว
 *   ตอนนี้ market order execute จริงบน BSC ผ่าน PancakeSwap แล้ว หลังบ้านจึงต้องเห็น
 *   คู่เทรดอยู่บน BSC พร้อม address จริง ไม่งั้นแอดมินเปิดดูแล้วเข้าใจผิดว่าเทรดบน TPIX
 *
 * address ทั้งหมดมาจาก config/bsc_trade_tokens.php ซึ่งถูกล็อกให้ตรงกับ
 * resources/js/Config/bscTradeTokens.js ด้วย BscTokenRegistrySyncTest
 *
 * Idempotent — รันซ้ำได้ (updateOrCreate ตาม [chain_id, contract_address])
 *
 * Usage:
 *   php artisan db:seed --class=BscMajorPairsSeeder
 *
 * Developed by Xman Studio.
 */
class BscMajorPairsSeeder extends Seeder
{
    public function run(): void
    {
        $chain = Chain::where('chain_id_hex', config('bsc_trade_tokens.chain_id_hex'))->first();

        if (! $chain) {
            $this->command?->warn('ไม่พบเชน BSC — ข้าม seeder (รัน AllChainsSeeder ก่อน)');

            return;
        }

        $tokens = $this->syncTokens($chain);
        $quote = $tokens[config('bsc_trade_tokens.quote')] ?? null;

        if (! $quote) {
            $this->command?->warn('ไม่พบเหรียญคู่ quote บน BSC — ข้าม seeder');

            return;
        }

        $pairs = $this->syncPairs($chain, $tokens, $quote);
        $retired = $this->retirePlaceholderPairs($chain);

        $this->command?->info("✅ BSC: เหรียญ {$tokens['_count']} · คู่เทรด {$pairs} คู่");

        if ($retired > 0) {
            $this->command?->info("♻️  ปิดคู่เทรด placeholder บน TPIX Chain {$retired} คู่ (ยังอยู่ในหลังบ้าน เปิดคืนได้)");
        }
    }

    /**
     * สร้าง/อัปเดตเหรียญบน BSC ให้ตรงกับทะเบียนที่ใช้เทรดจริง.
     *
     * ใช้ contract_address เป็นคีย์ (ไม่ใช่ symbol) เพราะ unique index ของตาราง
     * คือ [chain_id, contract_address] — ถ้าใช้ symbol จะชนกับเหรียญเดิมที่ address ต่างกัน
     *
     * @return array<string, Token|int>
     */
    private function syncTokens(Chain $chain): array
    {
        $result = ['_count' => 0];

        foreach (config('bsc_trade_tokens.tokens') as $symbol => $meta) {
            $token = Token::updateOrCreate(
                ['chain_id' => $chain->id, 'contract_address' => $meta['address']],
                [
                    'symbol' => $symbol,
                    'name' => $meta['name'],
                    'decimals' => $meta['decimals'],
                    'logo' => $meta['logo'],
                    'coingecko_id' => $meta['coingecko_id'],
                    'is_active' => true,
                    'sort_order' => $meta['sort_order'],
                ]
            );

            $result[$symbol] = $token;
            $result['_count']++;
        }

        return $result;
    }

    /**
     * สร้างคู่เทรด {SYMBOL}-USDT บน BSC (ยกเว้นตัว quote เอง).
     */
    private function syncPairs(Chain $chain, array $tokens, Token $quote): int
    {
        $quoteSymbol = config('bsc_trade_tokens.quote');
        $precisions = config('bsc_trade_tokens.price_precision', []);
        $count = 0;

        foreach (config('bsc_trade_tokens.tokens') as $symbol => $meta) {
            if ($symbol === $quoteSymbol) {
                continue;
            }

            TradingPair::updateOrCreate(
                [
                    'base_token_id' => $tokens[$symbol]->id,
                    'quote_token_id' => $quote->id,
                    'chain_id' => $chain->id,
                ],
                [
                    'symbol' => "{$symbol}-{$quoteSymbol}",
                    'is_active' => true,
                    'execution_mode' => 'onchain',
                    'price_precision' => $precisions[$symbol] ?? 2,
                    'amount_precision' => 6,
                    'min_trade_amount' => 0.0001,
                    'max_trade_amount' => 1000000,
                    'sort_order' => $meta['sort_order'],
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * ปิดคู่เทรด placeholder ที่ MajorTradingPairsSeeder เคยสร้างไว้บน TPIX Chain.
     *
     * ไม่ลบทิ้ง — แค่ปิดใช้งาน เพราะ (ก) อาจมีคำสั่งเก่าอ้างถึงอยู่ และ
     * (ข) เมื่อ DEX บน TPIX Chain เปิดจริง จะกลับมาเปิดคู่เหล่านี้ใหม่ได้
     * คู่ TPIX-USDT ไม่ถูกแตะ — เป็นคู่ของเชนตัวเองที่รอ DEX จริง
     */
    private function retirePlaceholderPairs(Chain $bsc): int
    {
        $tpix = Chain::where('symbol', 'TPIX')->first();

        if (! $tpix || $tpix->id === $bsc->id) {
            return 0;
        }

        $symbols = array_keys(config('bsc_trade_tokens.tokens'));

        // เฉพาะเหรียญที่ address เป็นรูปแบบ placeholder (hex ของ ASCII symbol pad ศูนย์)
        // — ไม่แตะเหรียญจริงบน TPIX Chain ที่อาจถูกเพิ่มภายหลัง
        $placeholderIds = Token::where('chain_id', $tpix->id)
            ->whereIn('symbol', $symbols)
            ->get()
            ->filter(fn (Token $t) => $t->contract_address === $this->placeholderAddress($t->symbol))
            ->pluck('id');

        if ($placeholderIds->isEmpty()) {
            return 0;
        }

        $retired = TradingPair::where('chain_id', $tpix->id)
            ->whereIn('base_token_id', $placeholderIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        Token::whereIn('id', $placeholderIds)->update(['is_active' => false]);

        return $retired;
    }

    /** BTC → 0x0000...425443 (hex ของ ASCII 'BTC' pad ซ้ายด้วยศูนย์) */
    private function placeholderAddress(string $symbol): string
    {
        return '0x'.str_pad(strtoupper(bin2hex($symbol)), 40, '0', STR_PAD_LEFT);
    }
}
