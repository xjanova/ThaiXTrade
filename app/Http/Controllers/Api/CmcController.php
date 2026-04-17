<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kline;
use App\Models\TradingPair;
use App\Services\OrderMatchingService;
use App\Services\SupplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * CoinMarketCap / CoinGecko DEX API specification.
 *
 * Implements the endpoints required for listing on CMC + CG as a DEX:
 *
 *   GET /api/v1/cmc/summary              All pairs — condensed ticker info
 *   GET /api/v1/cmc/assets               All traded assets + metadata
 *   GET /api/v1/cmc/tickers              All pairs — full ticker with volume
 *   GET /api/v1/cmc/orderbook/{market}   Order book depth for a market
 *
 * Market identifier format: "BASE_QUOTE" (e.g. "TPIX_USDT").
 *
 * References:
 *   CoinMarketCap DEX spec:
 *     https://github.com/CoinMarketCap/dex-api-specification
 *   CoinGecko DEX spec:
 *     https://docs.coingecko.com/reference/exchange-rates-data-endpoints
 *
 * CORS: wide-open (these endpoints are meant to be scraped by CMC / CG / DeFiLlama).
 *
 * Developed by Xman Studio.
 */
class CmcController extends Controller
{
    public function __construct(
        private readonly OrderMatchingService $matching,
        private readonly SupplyService $supply,
    ) {
    }

    /**
     * GET /cmc/summary — condensed pair info (CMC spec).
     */
    public function summary(): JsonResponse
    {
        $payload = Cache::remember('cmc:summary', 30, function () {
            $pairs = $this->activePairs();
            $out = [];

            foreach ($pairs as $pair) {
                $data = $this->pairTicker($pair);
                if (! $data) continue;

                $market = $data['market'];
                $out[$market] = [
                    'trading_pairs' => $market,
                    'base_currency' => $data['base_symbol'],
                    'quote_currency' => $data['quote_symbol'],
                    'last_price' => $data['last_price'],
                    'lowest_ask' => $data['lowest_ask'],
                    'highest_bid' => $data['highest_bid'],
                    'base_volume' => $data['base_volume_24h'],
                    'quote_volume' => $data['quote_volume_24h'],
                    'price_change_percent_24h' => $data['price_change_percent_24h'],
                    'highest_price_24h' => $data['high_24h'],
                    'lowest_price_24h' => $data['low_24h'],
                ];
            }

            return $out;
        });

        return $this->corsJson($payload);
    }

    /**
     * GET /cmc/assets — list of traded assets with metadata.
     */
    public function assets(): JsonResponse
    {
        $payload = Cache::remember('cmc:assets', 300, function () {
            $supply = $this->supply->snapshot();

            return [
                'TPIX' => [
                    'name' => 'TPIX',
                    'unified_cryptoasset_id' => '', // CMC will fill after listing
                    'can_withdraw' => true,
                    'can_deposit' => true,
                    'min_withdraw' => '0.000001',
                    'max_withdraw' => $supply['total'],
                    'maker_fee' => '0.0025',
                    'taker_fee' => '0.003',
                    'total_supply' => $supply['total'],
                    'circulating_supply' => $supply['circulating'],
                    'max_supply' => $supply['max'],
                    'decimals' => (int) config('supply.decimals', 18),
                    'chain' => 'TPIX Chain',
                    'chain_id' => 4289,
                    'contract_address' => 'native', // TPIX is the native coin, no ERC-20 contract
                    'explorer' => 'https://explorer.tpix.online',
                ],
                'USDT' => [
                    'name' => 'Tether USD',
                    'unified_cryptoasset_id' => '825',
                    'can_withdraw' => true,
                    'can_deposit' => true,
                    'min_withdraw' => '1.0',
                    'max_withdraw' => '9999999999',
                    'maker_fee' => '0.0025',
                    'taker_fee' => '0.003',
                    'decimals' => 6,
                ],
            ];
        });

        return $this->corsJson($payload);
    }

    /**
     * GET /cmc/tickers — full ticker data per pair (CMC spec).
     */
    public function tickers(): JsonResponse
    {
        $payload = Cache::remember('cmc:tickers', 15, function () {
            $pairs = $this->activePairs();
            $out = [];

            foreach ($pairs as $pair) {
                $data = $this->pairTicker($pair);
                if (! $data) continue;

                $market = $data['market'];
                $out[$market] = [
                    'base_id' => $data['base_symbol'],
                    'quote_id' => $data['quote_symbol'],
                    'last_price' => $data['last_price'],
                    'base_volume' => $data['base_volume_24h'],
                    'quote_volume' => $data['quote_volume_24h'],
                    'isFrozen' => $pair->is_active ? '0' : '1',
                ];
            }

            return $out;
        });

        return $this->corsJson($payload);
    }

    /**
     * GET /cmc/orderbook/{market} — bid/ask book for one pair.
     */
    public function orderbook(string $market, Request $request): JsonResponse
    {
        $depth = max(1, min($request->integer('depth', 100), 500));
        $pair = $this->findPairByMarket($market);
        if (! $pair) {
            return response()->json(['error' => "Unknown market: {$market}"], 404, [
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        $payload = Cache::remember("cmc:orderbook:{$market}:{$depth}", 5, function () use ($pair, $depth) {
            $book = $this->matching->getOrderBook($pair->id, $depth);

            // CMC spec: [["price","amount"], ...] with string values
            $bids = collect($book['bids'] ?? [])
                ->map(fn ($row) => [(string) ($row['price'] ?? '0'), (string) ($row['amount'] ?? '0')])
                ->all();
            $asks = collect($book['asks'] ?? [])
                ->map(fn ($row) => [(string) ($row['price'] ?? '0'), (string) ($row['amount'] ?? '0')])
                ->all();

            return [
                'timestamp' => (int) (microtime(true) * 1000),
                'bids' => $bids,
                'asks' => $asks,
            ];
        });

        return $this->corsJson($payload);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TradingPair>
     */
    private function activePairs()
    {
        return Cache::remember('cmc:active_pairs', 60, function () {
            return TradingPair::query()
                ->where('is_active', true)
                ->with(['baseToken', 'quoteToken'])
                ->orderBy('sort_order')
                ->get();
        });
    }

    /**
     * Normalize a TradingPair into a ticker data array.
     *
     * @return array<string,mixed>|null
     */
    private function pairTicker(TradingPair $pair): ?array
    {
        $base = $pair->baseToken?->symbol ?? null;
        $quote = $pair->quoteToken?->symbol ?? null;
        if (! $base || ! $quote) return null;

        $market = "{$base}_{$quote}";

        // OrderMatchingService takes trading_pair_id (int), not symbol
        $ticker = $this->matching->getTicker24h($pair->id) ?? [];

        $lastPrice = number_format((float) ($ticker['price'] ?? 0), 8, '.', '');
        $high24h = number_format((float) ($ticker['high'] ?? 0), 8, '.', '');
        $low24h = number_format((float) ($ticker['low'] ?? 0), 8, '.', '');
        $baseVol = number_format((float) ($ticker['volume'] ?? 0), 8, '.', '');
        $quoteVol = number_format((float) ($ticker['quote_volume'] ?? 0), 2, '.', '');
        $change24h = number_format((float) ($ticker['change_24h'] ?? 0), 2, '.', '');

        // Best bid/ask from top of the order book
        $book = $this->matching->getOrderBook($pair->id, 1);
        $highestBid = (string) ($book['bids'][0]['price'] ?? '0');
        $lowestAsk = (string) ($book['asks'][0]['price'] ?? '0');

        return [
            'market' => $market,
            'pair_id' => $pair->id,
            'base_symbol' => $base,
            'quote_symbol' => $quote,
            'last_price' => $lastPrice,
            'highest_bid' => $highestBid,
            'lowest_ask' => $lowestAsk,
            'base_volume_24h' => $baseVol,
            'quote_volume_24h' => $quoteVol,
            'price_change_percent_24h' => $change24h,
            'high_24h' => $high24h,
            'low_24h' => $low24h,
        ];
    }

    /**
     * "TPIX_USDT" → TradingPair|null
     */
    private function findPairByMarket(string $market): ?TradingPair
    {
        $parts = explode('_', strtoupper($market));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return null;

        [$base, $quote] = $parts;

        return $this->activePairs()->first(fn ($p) => strtoupper($p->baseToken?->symbol ?? '') === $base
            && strtoupper($p->quoteToken?->symbol ?? '') === $quote);
    }

    private function corsJson(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=15',
        ]);
    }
}
