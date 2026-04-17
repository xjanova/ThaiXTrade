<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kline;
use App\Models\SalePhase;
use App\Models\SiteSetting;
use App\Models\TradingPair;
use App\Services\OrderMatchingService;
use App\Services\SupplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX Token Price Controller.
 *
 * Serves TPIX token price data from real internal trades.
 * This is the canonical price source for:
 *   - tpix.online trade page (TPIX/USDT pair)
 *   - CoinMarketCap / CoinGecko integration
 *   - MetaMask portfolio display
 *   - External aggregators
 *
 * Price source hierarchy:
 *   1. Last trade price from internal order book
 *   2. Admin-set price in SiteSetting
 *   3. Latest token sale phase price
 *   4. Fallback default ($0.18)
 *
 * Developed by Xman Studio.
 */
class TpixPriceController extends Controller
{
    public function __construct(
        private OrderMatchingService $matchingService,
        private SupplyService $supplyService,
    ) {}

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
     * Historical kline/candlestick data from real trades.
     *
     * GET /api/v1/tpix/klines?interval=1h&limit=300
     */
    public function klines(): JsonResponse
    {
        $interval = request('interval', '1h');
        $limit = min((int) request('limit', 300), 1000);

        $pair = $this->getTpixPair();

        if (! $pair) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Try real klines from DB first
        $klines = Kline::forPair($pair->id)
            ->forInterval($interval)
            ->orderBy('open_time')
            ->limit($limit)
            ->get()
            ->map(fn (Kline $k) => $k->toKlineArray())
            ->toArray();

        // If no real klines yet, generate from current price as placeholder
        if (empty($klines)) {
            $data = $this->getTpixPriceData();
            $klines = $this->generateInitialKlines($data['price'], $interval, $limit);
        }

        return response()->json([
            'success' => true,
            'data' => $klines,
        ]);
    }

    /**
     * Public order book for TPIX/USDT pair.
     *
     * GET /api/v1/tpix/orderbook
     */
    public function orderbook(): JsonResponse
    {
        $pair = $this->getTpixPair();
        $limit = min((int) request('limit', 25), 100);

        if (! $pair) {
            return response()->json([
                'success' => true,
                'data' => ['bids' => [], 'asks' => []],
            ]);
        }

        $book = $this->matchingService->getOrderBook($pair->id, $limit);

        return response()->json([
            'success' => true,
            'data' => $book,
        ]);
    }

    /**
     * Recent trades for TPIX/USDT pair.
     *
     * GET /api/v1/tpix/trades
     */
    public function trades(): JsonResponse
    {
        $pair = $this->getTpixPair();
        $limit = min((int) request('limit', 50), 200);

        if (! $pair) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $trades = $this->matchingService->getRecentTrades($pair->id, $limit);

        return response()->json([
            'success' => true,
            'data' => $trades,
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
                'contract' => '0x0000000000000000000000000000000000001010',
                'decimals' => 18,
                'total_supply' => 7_000_000_000,
                'circulating_supply' => $this->getCirculatingSupply(),
                'max_supply' => 7_000_000_000,
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
        return Cache::remember('tpix_price_data', 10, function () {
            $pair = $this->getTpixPair();

            // 1. Real trade data (highest priority)
            if ($pair) {
                $ticker = $this->matchingService->getTicker24h($pair->id);
                if ($ticker['price'] > 0) {
                    $circulatingSupply = $this->getCirculatingSupply();

                    return [
                        'symbol' => 'TPIX',
                        'name' => 'TPIX Token',
                        'price' => round($ticker['price'], 8),
                        'change_24h' => round($ticker['change_24h'], 2),
                        'volume_24h' => round($ticker['volume'], 2),
                        'high_24h' => round($ticker['high'], 8),
                        'low_24h' => round($ticker['low'], 8),
                        'market_cap' => round($ticker['price'] * $circulatingSupply, 2),
                        'total_supply' => 7_000_000_000,
                        'circulating_supply' => $circulatingSupply,
                        'logo' => url('/tpixlogo.webp'),
                        'chain_id' => 4289,
                        'source' => 'trades',
                        'updated_at' => now()->toIso8601String(),
                    ];
                }
            }

            // 2. Admin-set price
            $price = (float) SiteSetting::get('trading', 'tpix_price', 0);

            // 3. Token sale price fallback
            if ($price <= 0) {
                $salePhase = SalePhase::where('is_active', true)->first();
                $price = $salePhase ? (float) $salePhase->price_per_tpix : 0;
            }

            // 4. Default fallback
            if ($price <= 0) {
                $price = 0.18;
            }

            $change24h = (float) SiteSetting::get('trading', 'tpix_change_24h', 0);
            $volume24h = (float) SiteSetting::get('trading', 'tpix_volume_24h', 0);
            $circulatingSupply = $this->getCirculatingSupply();

            return [
                'symbol' => 'TPIX',
                'name' => 'TPIX Token',
                'price' => round($price, 8),
                'change_24h' => round($change24h, 2),
                'volume_24h' => round($volume24h, 2),
                'high_24h' => round($price * 1.02, 8),
                'low_24h' => round($price * 0.98, 8),
                'market_cap' => round($price * $circulatingSupply, 2),
                'total_supply' => 7_000_000_000,
                'circulating_supply' => $circulatingSupply,
                'logo' => url('/tpixlogo.webp'),
                'chain_id' => 4289,
                'source' => 'admin',
                'updated_at' => now()->toIso8601String(),
            ];
        });
    }

    private function getTpixPair(): ?TradingPair
    {
        return Cache::remember('tpix_trading_pair', 300, function () {
            return TradingPair::where('symbol', 'TPIX-USDT')
                ->active()
                ->first();
        });
    }

    /**
     * Get circulating supply (whole TPIX).
     *
     * Delegates to SupplyService which computes it on-chain:
     *   circulating = total_supply - sum(balance of genesis-locked addresses)
     *
     * Fully verifiable by third parties against TPIX RPC. See config/supply.php.
     */
    private function getCirculatingSupply(): float
    {
        $snapshot = $this->supplyService->snapshot();
        return (float) $snapshot['circulating'];
    }

    /**
     * Generate initial kline data when no real trades exist yet.
     * Uses the current price with minimal variation to show a flat line.
     */
    private function generateInitialKlines(float $currentPrice, string $interval, int $limit): array
    {
        $intervalSeconds = match ($interval) {
            '1m' => 60, '5m' => 300, '15m' => 900,
            '1h' => 3600, '4h' => 14400,
            '1d' => 86400, '1w' => 604800,
            default => 3600,
        };

        $klines = [];
        $now = time();

        for ($i = $limit - 1; $i >= 0; $i--) {
            $timestamp = ($now - ($i * $intervalSeconds)) * 1000;

            $klines[] = [
                $timestamp,
                number_format($currentPrice, 8, '.', ''),
                number_format($currentPrice, 8, '.', ''),
                number_format($currentPrice, 8, '.', ''),
                number_format($currentPrice, 8, '.', ''),
                '0.00',
            ];
        }

        return $klines;
    }
}
