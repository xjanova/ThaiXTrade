<?php

namespace App\Services;

use App\Models\TradingPair;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MarketDataService.
 *
 * Fetches real-time market data from Binance public API.
 * Provides tickers, order books, trades, klines, and token prices.
 * All responses are cached briefly to avoid rate limits.
 */
class MarketDataService
{
    private string $baseUrl = 'https://api.binance.com/api/v3';

    /**
     * Get 24h ticker data for all symbols or a specific one.
     */
    public function getTickers(?string $symbol = null): array
    {
        $cacheKey = $symbol ? "market:ticker:{$symbol}" : 'market:tickers:all';
        $ttl = 10; // 10 seconds

        return Cache::remember($cacheKey, $ttl, function () use ($symbol) {
            try {
                $url = "{$this->baseUrl}/ticker/24hr";
                $params = $symbol ? ['symbol' => $this->toBinanceSymbol($symbol)] : [];

                $response = Http::timeout(10)->get($url, $params);

                if ($response->failed()) {
                    return [];
                }

                $data = $response->json();

                // Single symbol returns object, multiple returns array
                if ($symbol) {
                    return [$this->formatTicker($data)];
                }

                // Build allowlist of admin-configured pairs from DB.
                // Mobile + web markets list MUST mirror admin's TradingPair table —
                // not Binance's full list. Falls back to top-50 only when DB is empty
                // (initial deployment / no admin config yet).
                $adminPairs = TradingPair::active()
                    ->pluck('symbol')
                    ->map(fn ($s) => $this->toBinanceSymbol($s))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $collection = collect($data)
                    ->filter(fn ($t) => str_ends_with($t['symbol'], 'USDT'));

                if (! empty($adminPairs)) {
                    $allow = array_flip($adminPairs);
                    $collection = $collection->filter(fn ($t) => isset($allow[$t['symbol']]));
                } else {
                    // Fallback — top 50 by volume (legacy behavior, only when DB empty)
                    $collection = $collection
                        ->sortByDesc(fn ($t) => (float) $t['quoteVolume'])
                        ->take(50);
                }

                return $collection
                    ->values()
                    ->map(fn ($t) => $this->formatTicker($t))
                    ->all();
            } catch (\Exception $e) {
                Log::warning('Market tickers fetch failed', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Get order book depth for a symbol.
     */
    public function getOrderBook(string $symbol, int $limit = 20): array
    {
        $cacheKey = "market:orderbook:{$symbol}:{$limit}";

        return Cache::remember($cacheKey, 5, function () use ($symbol, $limit) {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/depth", [
                    'symbol' => $this->toBinanceSymbol($symbol),
                    'limit' => min($limit, 100),
                ]);

                if ($response->failed()) {
                    return ['bids' => [], 'asks' => []];
                }

                return $response->json();
            } catch (\Exception $e) {
                Log::warning('Order book fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);

                return ['bids' => [], 'asks' => []];
            }
        });
    }

    /**
     * Get recent trades for a symbol.
     */
    public function getRecentTrades(string $symbol, int $limit = 50): array
    {
        $cacheKey = "market:trades:{$symbol}:{$limit}";

        return Cache::remember($cacheKey, 5, function () use ($symbol, $limit) {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/trades", [
                    'symbol' => $this->toBinanceSymbol($symbol),
                    'limit' => min($limit, 500),
                ]);

                if ($response->failed()) {
                    return [];
                }

                return collect($response->json())->map(fn ($t) => [
                    'id' => $t['id'],
                    'price' => $t['price'],
                    'qty' => $t['qty'],
                    'quoteQty' => $t['quoteQty'],
                    'time' => $t['time'],
                    'isBuyerMaker' => $t['isBuyerMaker'],
                ])->all();
            } catch (\Exception $e) {
                Log::warning('Trades fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Get klines (candlestick) data.
     */
    public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
    {
        $cacheKey = "market:klines:{$symbol}:{$interval}:{$limit}";

        return Cache::remember($cacheKey, 30, function () use ($symbol, $interval, $limit) {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/klines", [
                    'symbol' => $this->toBinanceSymbol($symbol),
                    'interval' => $interval,
                    'limit' => min($limit, 500),
                ]);

                if ($response->failed()) {
                    return [];
                }

                return collect($response->json())->map(fn ($k) => [
                    'time' => $k[0],
                    'open' => $k[1],
                    'high' => $k[2],
                    'low' => $k[3],
                    'close' => $k[4],
                    'volume' => $k[5],
                    'closeTime' => $k[6],
                    'quoteVolume' => $k[7],
                    'trades' => $k[8],
                ])->all();
            } catch (\Exception $e) {
                Log::warning('Klines fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Get available trading pairs from Binance.
     */
    public function getPairs(): array
    {
        return Cache::remember('market:pairs', 300, function () {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/exchangeInfo");

                if ($response->failed()) {
                    return [];
                }

                return collect($response->json()['symbols'] ?? [])
                    ->filter(fn ($s) => $s['status'] === 'TRADING' && $s['quoteAsset'] === 'USDT')
                    ->sortBy('symbol')
                    ->take(100)
                    ->values()
                    ->map(fn ($s) => [
                        'symbol' => $s['symbol'],
                        'baseAsset' => $s['baseAsset'],
                        'quoteAsset' => $s['quoteAsset'],
                        'status' => $s['status'],
                    ])
                    ->all();
            } catch (\Exception $e) {
                Log::warning('Pairs fetch failed', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Get token price from Binance.
     */
    public function getTokenPrice(string $symbol): ?array
    {
        $cacheKey = "market:price:{$symbol}";

        return Cache::remember($cacheKey, 10, function () use ($symbol) {
            try {
                $binanceSymbol = strtoupper(str_replace(['-', '/'], '', $symbol));
                if (! str_ends_with($binanceSymbol, 'USDT')) {
                    $binanceSymbol .= 'USDT';
                }

                $response = Http::timeout(10)->get("{$this->baseUrl}/ticker/24hr", [
                    'symbol' => $binanceSymbol,
                ]);

                if ($response->failed()) {
                    return null;
                }

                return $this->formatTicker($response->json());
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /**
     * Get top gainers from Binance.
     */
    public function getTopGainers(int $limit = 4): array
    {
        $tickers = $this->getTickers();

        return collect($tickers)
            ->sortByDesc('priceChangePercent')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Get top volume tokens from Binance.
     */
    public function getTopVolume(int $limit = 4): array
    {
        $tickers = $this->getTickers();

        return collect($tickers)
            ->sortByDesc('quoteVolume')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Format a ticker response to standard format.
     */
    private function formatTicker(array $data): array
    {
        $rawSymbol = $data['symbol'] ?? '';
        // Strip trailing USDT to get base — canonicalize to BASE-USDT format
        // so client parsers (mobile + web) can split on "-" to separate base/quote.
        $baseAsset = str_ends_with($rawSymbol, 'USDT')
            ? substr($rawSymbol, 0, -4)
            : $rawSymbol;
        $canonicalSymbol = $baseAsset.'-USDT';

        return [
            'symbol' => $canonicalSymbol,         // "BTC-USDT" (canonical)
            'binance_symbol' => $rawSymbol,       // "BTCUSDT" (kept for WS subscribe)
            'baseAsset' => $baseAsset,
            'quoteAsset' => 'USDT',
            // Both camelCase + snake_case for client compat
            'price' => $data['lastPrice'] ?? '0',
            'lastPrice' => $data['lastPrice'] ?? '0',
            'last_price' => $data['lastPrice'] ?? '0',
            'priceChange' => $data['priceChange'] ?? '0',
            'price_change' => $data['priceChange'] ?? '0',
            'priceChangePercent' => $data['priceChangePercent'] ?? '0',
            'price_change_percent' => $data['priceChangePercent'] ?? '0',
            'high' => $data['highPrice'] ?? '0',
            'high_24h' => $data['highPrice'] ?? '0',
            'low' => $data['lowPrice'] ?? '0',
            'low_24h' => $data['lowPrice'] ?? '0',
            'volume' => $data['volume'] ?? '0',
            'volume_24h' => $data['volume'] ?? '0',
            'quoteVolume' => $data['quoteVolume'] ?? '0',
            'quote_volume_24h' => $data['quoteVolume'] ?? '0',
            'openPrice' => $data['openPrice'] ?? '0',
        ];
    }

    /**
     * Convert our symbol format to Binance format.
     * e.g. BTC-USDT or BTC/USDT -> BTCUSDT.
     */
    private function toBinanceSymbol(string $symbol): string
    {
        return strtoupper(str_replace(['-', '/'], '', $symbol));
    }
}
