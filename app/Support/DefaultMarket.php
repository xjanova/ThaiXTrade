<?php

namespace App\Support;

use App\Models\TradingPair;
use App\Services\ChainResolver;
use Illuminate\Support\Facades\Cache;

/**
 * DefaultMarket — คู่เทรดที่คนเปิด /trade เฉย ๆ ควรเจอ.
 *
 * เจ้าของสั่ง (2026-09-05): เมื่อเชน TPIX เปิดใช้ได้แล้ว ให้ทุกอย่างปริยายเป็นเชน TPIX
 * คู่ปริยายจึงต้องเดินตามเชนปริยาย ไม่ใช่ค้างที่ BTC-USDT ของ Binance ตลอดไป
 *
 * เงื่อนไขที่จะเป็น TPIX-USDT: เชน TPIX เป็น live **และ** คู่นั้นส่งคำสั่งได้จริง
 * (`execution_mode = onchain` + `is_active`) ซึ่ง dex:sync ตั้งให้ตามพูลบนเชนจริง
 * — ถ้าพูลว่างหรือ DEX ยังไม่ deploy จะตกกลับไป BTC-USDT เอง ไม่พาคนไปหน้าที่เทรดไม่ได้
 *
 * Developed by Xman Studio
 */
class DefaultMarket
{
    public const FALLBACK = 'BTC-USDT';

    public const TPIX = 'TPIX-USDT';

    public static function pair(?ChainResolver $chains = null): string
    {
        $chains ??= app(ChainResolver::class);

        return Cache::remember('market:default-pair', 60, function () use ($chains) {
            $tpixChainId = (int) config('blockchain.tpix_chain_id', 4289);
            $chain = $chains->resolveActive($tpixChainId);

            if ($chain === null || ! $chains->isLive($tpixChainId)) {
                return self::FALLBACK;
            }

            $tradable = TradingPair::query()
                ->where('chain_id', $chain->id)
                ->where('symbol', self::TPIX)
                ->where('execution_mode', 'onchain')
                ->where('is_active', true)
                ->exists();

            return $tradable ? self::TPIX : self::FALLBACK;
        });
    }

    public static function forget(): void
    {
        Cache::forget('market:default-pair');
    }
}
