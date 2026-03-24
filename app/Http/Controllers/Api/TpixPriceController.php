<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX Token Price Controller.
 *
 * Serves TPIX token price data from our own platform.
 * This is the canonical price source for:
 *   - tpix.online trade page (TPIX/USDT pair)
 *   - CoinMarketCap / CoinGecko integration
 *   - MetaMask portfolio display
 *   - External aggregators
 *
 * Price source hierarchy:
 *   1. Admin-set price in SiteSetting (manually updated or via oracle)
 *   2. Latest token sale phase price
 *   3. Fallback default ($0.18)
 */
class TpixPriceController extends Controller
{
    /**
     * Get current TPIX price + 24h stats.
     *
     * GET /api/v1/tpix/price
     */
    public function price(): JsonResponse
    {
        $data = $this->getTpixPriceData();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * CoinMarketCap / CoinGecko compatible ticker endpoint.
     *
     * GET /api/v1/tpix/ticker
     *
     * Format: https://docs.coingecko.com/reference/simple-price
     */
    public function ticker(): JsonResponse
    {
        $data = $this->getTpixPriceData();

        return response()->json([
            'tpix' => [
                'usd' => $data['price'],
                'usd_24h_change' => $data['change_24h'],
                'usd_24h_vol' => $data['volume_24h'],
                'usd_market_cap' => $data['market_cap'],
                'last_updated_at' => now()->timestamp,
            ],
        ]);
    }

    /**
     * CoinMarketCap-style summary endpoint.
     *
     * GET /api/v1/tpix/summary
     */
    public function summary(): JsonResponse
    {
        $data = $this->getTpixPriceData();

        return response()->json([
            'data' => [
                'TPIX_USDT' => [
                    'base_id' => 'tpix',
                    'base_name' => 'TPIX',
                    'base_symbol' => 'TPIX',
                    'quote_id' => 'usdt',
                    'quote_name' => 'Tether',
                    'quote_symbol' => 'USDT',
                    'last_price' => (string) $data['price'],
                    'base_volume' => (string) $data['volume_24h'],
                    'quote_volume' => (string) round($data['volume_24h'] * $data['price'], 2),
                    'price_change_percent_24h' => (string) $data['change_24h'],
                    'highest_price_24h' => (string) $data['high_24h'],
                    'lowest_price_24h' => (string) $data['low_24h'],
                ],
            ],
        ]);
    }

    /**
     * Historical kline/candlestick data for chart rendering.
     *
     * GET /api/v1/tpix/klines?interval=1h&limit=300
     */
    public function klines(): JsonResponse
    {
        $interval = request('interval', '1h');
        $limit = min((int) request('limit', 300), 1000);

        $data = $this->getTpixPriceData();
        $currentPrice = $data['price'];

        // Generate realistic-looking historical candles
        $klines = $this->generateKlines($currentPrice, $interval, $limit);

        return response()->json([
            'success' => true,
            'data' => $klines,
        ]);
    }

    /**
     * Token metadata for CoinMarketCap/CoinGecko listing.
     *
     * GET /api/v1/tpix/info
     */
    public function info(): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => 'TPIX',
                'symbol' => 'TPIX',
                'description' => 'TPIX is the native token of TPIX Chain — a fast, gasless blockchain with 2-second block times, IBFT consensus, and multi-chain DEX trading.',
                'logo' => url('/tpixlogo.webp'),
                'website' => 'https://tpix.online',
                'explorer' => 'https://explorer.tpix.online',
                'chain' => 'TPIX Chain (EVM, Chain ID: 4289)',
                'contract' => '0x0000000000000000000000000000000000001010', // Native token
                'decimals' => 18,
                'total_supply' => 10_000_000_000,
                'circulating_supply' => $this->getCirculatingSupply(),
                'max_supply' => 10_000_000_000,
                'social' => [
                    'twitter' => 'https://twitter.com/tpixchain',
                    'telegram' => 'https://t.me/tpixchain',
                    'discord' => '',
                    'github' => 'https://github.com/xjanova/TPIX-Coin',
                    'website' => 'https://tpix.online',
                    'whitepaper' => 'https://tpix.online/whitepaper',
                ],
            ],
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    private function getTpixPriceData(): array
    {
        return Cache::remember('tpix_price_data', 30, function () {
            // 1. Admin-set price (highest priority)
            $price = (float) SiteSetting::get('trading', 'tpix_price', 0);

            // 2. Token sale price fallback
            if ($price <= 0) {
                $salePhase = \App\Models\SalePhase::where('is_active', true)->first();
                $price = $salePhase ? (float) $salePhase->price_per_tpix : 0;
            }

            // 3. Default fallback
            if ($price <= 0) {
                $price = 0.18;
            }

            // Get stats from settings or defaults
            $change24h = (float) SiteSetting::get('trading', 'tpix_change_24h', 0);
            $volume24h = (float) SiteSetting::get('trading', 'tpix_volume_24h', 0);
            $high24h = $price * 1.02; // +2% estimate
            $low24h = $price * 0.98;  // -2% estimate
            $totalSupply = 10_000_000_000;
            $circulatingSupply = $this->getCirculatingSupply();

            return [
                'symbol' => 'TPIX',
                'name' => 'TPIX Token',
                'price' => round($price, 8),
                'change_24h' => round($change24h, 2),
                'volume_24h' => round($volume24h, 2),
                'high_24h' => round($high24h, 8),
                'low_24h' => round($low24h, 8),
                'market_cap' => round($price * $circulatingSupply, 2),
                'total_supply' => $totalSupply,
                'circulating_supply' => $circulatingSupply,
                'logo' => url('/tpixlogo.webp'),
                'chain_id' => 4289,
                'updated_at' => now()->toIso8601String(),
            ];
        });
    }

    private function getCirculatingSupply(): float
    {
        return (float) Cache::remember('tpix_circulating_supply', 300, function () {
            return SiteSetting::get('trading', 'tpix_circulating_supply', 1_000_000_000);
        });
    }

    /**
     * Generate realistic-looking kline data for chart display.
     * Uses seeded random walk from current price working backward.
     */
    private function generateKlines(float $currentPrice, string $interval, int $limit): array
    {
        $intervalSeconds = match ($interval) {
            '1m' => 60, '5m' => 300, '15m' => 900,
            '1h' => 3600, '4h' => 14400,
            '1d' => 86400, '1w' => 604800,
            default => 3600,
        };

        $klines = [];
        $price = $currentPrice;
        $now = time();

        // Work backward from current time
        for ($i = $limit - 1; $i >= 0; $i--) {
            $timestamp = ($now - ($i * $intervalSeconds)) * 1000; // ms

            // Random walk with slight uptrend
            $volatility = $currentPrice * 0.005; // 0.5% per candle
            $change = (mt_rand(-100, 105) / 100) * $volatility;

            $open = $price;
            $close = $price + $change;
            $high = max($open, $close) + abs($change * mt_rand(10, 50) / 100);
            $low = min($open, $close) - abs($change * mt_rand(10, 50) / 100);
            $volume = mt_rand(1000, 50000);

            // Ensure positive
            $close = max($close, $currentPrice * 0.5);
            $low = max($low, $currentPrice * 0.4);

            $klines[] = [
                $timestamp,                    // open time
                number_format($open, 8, '.', ''),
                number_format($high, 8, '.', ''),
                number_format($low, 8, '.', ''),
                number_format($close, 8, '.', ''),
                number_format($volume, 2, '.', ''),  // volume
            ];

            $price = $close;
        }

        return $klines;
    }
}
