<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PriceFeedService — บริการดึงราคาสกุลเงินดิจิทัลแบบ real-time.
 *
 * ใช้แปลงยอดที่ลูกค้าจ่าย (BNB/USDT) เป็น USD เพื่อคำนวณจำนวน TPIX ที่ได้
 *
 * ⚠️ บริการนี้อยู่บนเส้นทางออกเหรียญโดยตรง — ราคาที่คืนผิดเท่ากับออกเหรียญผิด
 *    จึงมีกฎ 3 ข้อที่ห้ามถอด:
 *      1. ห้ามแคชค่าที่ล้มเหลว (เดิมแคช 0 ไว้ 30 วิ → ปิดรับเงินทั้งระบบเงียบๆ)
 *      2. ต้องมีเพดานความสมเหตุสมผล — ราคาที่กระโดดผิดปกติให้ปฏิเสธ ไม่ใช่ใช้เลย
 *      3. ล้มเหลวต้องโยน exception ให้ผู้เรียกรู้ ไม่ใช่คืน 0 เงียบๆ
 *         แล้วปล่อยให้ไปตกด่านอื่นด้วยข้อความที่ไม่เกี่ยวกัน
 */
class PriceFeedService
{
    private string $baseUrl = 'https://api.binance.com/api/v3';

    /** เหรียญที่ผูกกับดอลลาร์ — ไม่ต้องถามใคร */
    private const STABLECOINS = ['USDT', 'BUSD', 'USDC', 'DAI'];

    /** ราคาที่ดีล่าสุดเก็บไว้นานกว่าปกติ เพื่อใช้ประคองตอนแหล่งราคาล่ม */
    private const LAST_GOOD_TTL = 86400; // 24 ชั่วโมง

    /** ราคาต่างจากค่าดีล่าสุดเกินเท่านี้ถือว่าผิดปกติ */
    private const MAX_DEVIATION = 0.35; // 35%

    /**
     * ดึงราคาปัจจุบันของ symbol (เช่น BNB) เป็น USD.
     *
     * @throws \RuntimeException เมื่อหาราคาที่เชื่อถือได้ไม่ได้เลย
     */
    public function getPrice(string $symbol): float
    {
        $symbol = strtoupper($symbol);

        if (in_array($symbol, self::STABLECOINS, true)) {
            return 1.0;
        }

        $cacheKey = "price_feed:{$symbol}_usd";
        $lastGoodKey = "price_feed:{$symbol}_last_good";

        $cached = Cache::get($cacheKey);
        if (is_numeric($cached) && $cached > 0) {
            return (float) $cached;
        }

        $lastGood = Cache::get($lastGoodKey);
        $lastGood = is_numeric($lastGood) && $lastGood > 0 ? (float) $lastGood : null;

        $fetched = $this->fetchFromSource($symbol);

        if ($fetched !== null) {
            // ราคาที่กระโดดจากค่าดีล่าสุดผิดปกติ = อย่าเพิ่งเชื่อ
            // (ฟีดเพี้ยนชั่วขณะเกิดได้จริง และที่นี่แปลว่าออกเหรียญผิดทันที)
            if ($lastGood !== null && abs($fetched - $lastGood) / $lastGood > self::MAX_DEVIATION) {
                Log::warning('price-feed: ราคากระโดดผิดปกติ — ใช้ค่าดีล่าสุดแทน', [
                    'symbol' => $symbol,
                    'fetched' => $fetched,
                    'last_good' => $lastGood,
                ]);

                return $lastGood;
            }

            // แคชสั้นสำหรับใช้งานปกติ + เก็บเป็นค่าดีล่าสุดไว้ประคองตอนฟีดล่ม
            Cache::put($cacheKey, $fetched, 30);
            Cache::put($lastGoodKey, $fetched, self::LAST_GOOD_TTL);

            return $fetched;
        }

        // ดึงไม่ได้ — ใช้ค่าดีล่าสุดถ้ายังมี (ไม่แคชความล้มเหลวไว้)
        if ($lastGood !== null) {
            Log::warning('price-feed: ดึงราคาไม่ได้ ใช้ค่าดีล่าสุดแทน', [
                'symbol' => $symbol,
                'last_good' => $lastGood,
            ]);

            return $lastGood;
        }

        throw new \RuntimeException("ไม่สามารถดึงราคาของ {$symbol} ได้ในขณะนี้");
    }

    /**
     * ยิงถามแหล่งราคา — คืน null ถ้าไม่ได้ราคาที่ใช้ได้.
     */
    private function fetchFromSource(string $symbol): ?float
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/ticker/price", [
                'symbol' => "{$symbol}USDT",
            ]);

            if (! $response->successful()) {
                return null;
            }

            $price = (float) $response->json('price');

            return $price > 0 ? $price : null;
        } catch (\Throwable $e) {
            Log::warning('price-feed: เรียกแหล่งราคาไม่สำเร็จ', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * คำนวณจำนวน TPIX ที่จะได้จากการจ่ายด้วยสกุลเงินที่ระบุ.
     *
     * @param  float  $paymentAmount  จำนวนที่จ่าย (เช่น 1.5 BNB)
     * @param  string  $currency  สกุลเงินที่จ่าย (เช่น BNB, USDT)
     * @param  float  $tpixPriceUsd  ราคา TPIX ต่อเหรียญ (USD)
     * @return array{tpix_amount: float, usd_value: float, rate: float}
     *
     * @throws \RuntimeException เมื่อหาราคาที่เชื่อถือได้ไม่ได้ หรือราคา TPIX ไม่ถูกต้อง
     */
    public function convertToTpix(float $paymentAmount, string $currency, float $tpixPriceUsd): array
    {
        if ($tpixPriceUsd <= 0) {
            throw new \RuntimeException('ราคา TPIX ของรอบขายนี้ตั้งค่าไม่ถูกต้อง');
        }

        $currencyPriceUsd = $this->getPrice($currency);
        $usdValue = $paymentAmount * $currencyPriceUsd;

        return [
            'tpix_amount' => round($usdValue / $tpixPriceUsd, 8),
            'usd_value' => round($usdValue, 2),
            'rate' => $currencyPriceUsd,
        ];
    }
}
