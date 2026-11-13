<?php

namespace App\Services\AiBot\Live;

/**
 * TPIX TRADE — คู่เหรียญที่บอทเทรดเงินจริงได้ และที่อยู่สัญญาของแต่ละฝั่ง.
 *
 * ที่อยู่สัญญามาจาก config เท่านั้น (ทะเบียน BSC เดียวกับหน้าเทรด) — ตัวส่งธุรกรรมใช้คลาสนี้
 * คำนวณเหรียญของคำสั่งใหม่ทุกครั้ง แล้วเทียบกับที่บันทึกไว้ในแถวคำสั่ง แถวที่ถูกแก้ในฐานข้อมูล
 * จึงพาบอทไปสวอปเหรียญนอกรายการไม่ได้
 *
 * Developed by Xman Studio.
 */
class LivePairs
{
    /**
     * @return array{pair: string, base: string, base_symbol: string, base_address: string, base_decimals: int,
     *               quote: string, quote_address: string, quote_decimals: int}|null
     */
    public function resolve(string $pair): ?array
    {
        $parts = explode('/', strtoupper(trim(str_replace('-', '/', $pair))));

        if (count($parts) !== 2) {
            return null;
        }

        [$base, $quote] = $parts;
        $quoteAsset = strtoupper((string) config('aibot.live.quote_asset', 'USDT'));

        if ($quote !== $quoteAsset || ! in_array($base, (array) config('aibot.live.bases', []), true)) {
            return null;
        }

        $quoteSpec = (array) config("aibot.bot_wallet.assets.{$quoteAsset}", []);
        $quoteAddress = (string) ($quoteSpec['address'] ?? '');

        if (($quoteSpec['type'] ?? '') !== 'erc20' || ! $this->isAddress($quoteAddress)) {
            return null;
        }

        $token = (array) config("bsc_trade_tokens.tokens.{$base}", []);

        if ($token === []) {
            return null;
        }

        // เหรียญหลักของเชน (BNB) เทรดเป็น WBNB — โทเคนธรรมดา ไม่ปนกับ BNB ที่กันไว้จ่ายค่าแก๊ส
        $native = (bool) ($token['native'] ?? false);
        $address = $native ? (string) config('aibot.live.contracts.wbnb', '') : (string) ($token['address'] ?? '');

        if (! $this->isAddress($address) || strcasecmp($address, $quoteAddress) === 0) {
            return null;
        }

        return [
            'pair' => "{$base}/{$quote}",
            'base' => $base,
            'base_symbol' => $native ? 'W'.$base : $base,
            'base_address' => strtolower($address),
            'base_decimals' => (int) ($token['decimals'] ?? 18),
            'quote' => $quote,
            'quote_address' => strtolower($quoteAddress),
            'quote_decimals' => (int) ($quoteSpec['decimals'] ?? 18),
        ];
    }

    public function supports(string $pair): bool
    {
        return $this->resolve($pair) !== null;
    }

    /** @return array<int, string> เช่น ['BTC/USDT', 'ETH/USDT'] */
    public function supported(): array
    {
        $quote = strtoupper((string) config('aibot.live.quote_asset', 'USDT'));

        return array_values(array_filter(
            array_map(fn (string $base) => "{$base}/{$quote}", (array) config('aibot.live.bases', [])),
            fn (string $pair) => $this->supports($pair),
        ));
    }

    private function isAddress(string $value): bool
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $value) === 1;
    }
}
