<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * PriceFeedService — บริการดึงราคาสกุลเงินดิจิทัลแบบ real-time.
 *
 * ใช้ Binance API สำหรับแปลง BNB/USDT เป็น USD
 * Cache ราคาไว้ 30 วินาทีเพื่อลด API calls
 * ใช้ในระบบขายเหรียญเพื่อคำนวณจำนวน TPIX ที่ผู้ซื้อจะได้
 */
class PriceFeedService
{
    private string $baseUrl = 'https://api.binance.com/api/v3';

    /**
     * ดึงราคาปัจจุบันของ symbol (เช่น BNB, ETH) เป็น USD.
     */
    public function getPrice(string $symbol): float
    {
        $symbol = strtoupper($symbol);

        // USDT, BUSD ถือว่า = 1 USD
        if (in_array($symbol, ['USDT', 'BUSD', 'USDC', 'DAI'])) {
            return 1.0;
        }

        $cacheKey = "price_feed:{$symbol}_usd";

        return (float) Cache::remember($cacheKey, 30, function () use ($symbol) {
            try {
                $response = Http::timeout(5)->get("{$this->baseUrl}/ticker/price", [
                    'symbol' => "{$symbol}USDT",
                ]);

                if ($response->successful()) {
                    return (float) $response->json('price');
                }
            } catch (\Throwable $e) {
                // Fallback: ใช้ราคาล่าสุดจาก cache หรือ 0
            }

            return 0.0;
        });
    }

    /**
     * คำนวณจำนวน TPIX ที่จะได้จากการจ่ายด้วยสกุลเงินที่ระบุ.
     *
     * @param  float  $paymentAmount  จำนวนที่จ่าย (เช่น 1.5 BNB)
     * @param  string  $currency  สกุลเงินที่จ่าย (เช่น BNB, USDT)
     * @param  float  $tpixPriceUsd  ราคา TPIX ต่อเหรียญ (USD)
     * @return array{tpix_amount: float, usd_value: float, rate: float}
     */
    public function convertToTpix(float $paymentAmount, string $currency, float $tpixPriceUsd): array
    {
        $currencyPriceUsd = $this->getPrice($currency);
        $usdValue = $paymentAmount * $currencyPriceUsd;
        $tpixAmount = $tpixPriceUsd > 0 ? $usdValue / $tpixPriceUsd : 0;

        return [
            'tpix_amount' => round($tpixAmount, 8),
            'usd_value' => round($usdValue, 2),
            'rate' => $currencyPriceUsd,
        ];
    }
}
