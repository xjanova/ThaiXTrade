<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\MarketDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    public function __construct(
        private MarketDataService $marketDataService,
    ) {}

    public function tickers(): JsonResponse
    {
        $data = $this->marketDataService->getTickers();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function ticker(string $symbol): JsonResponse
    {
        $data = $this->marketDataService->getTokenPrice($symbol);

        if (! $data) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'SYMBOL_NOT_FOUND', 'message' => "Ticker data not found for {$symbol}"],
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function orderbook(string $symbol, Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 20);
        $data = $this->marketDataService->getOrderBook($symbol, $limit);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function trades(string $symbol, Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 50);
        $data = $this->marketDataService->getRecentTrades($symbol, $limit);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function klines(string $symbol, Request $request): JsonResponse
    {
        $interval = $request->input('interval', '1h');
        $limit = $request->integer('limit', 100);
        $data = $this->marketDataService->getKlines($symbol, $interval, $limit);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * List trading pairs available for trading.
     *
     * Source of truth: trading_pairs table (admin-managed via TradingPairController).
     * Falls back to Binance pairs only if DB is empty (migration safety net).
     *
     * Returns symbols in canonical "BASE-QUOTE" format (e.g., "BTC-USDT", "TPIX-USDT")
     * which matches what TradingController expects on order submission.
     */
    public function pairs(): JsonResponse
    {
        $dbPairs = TradingPair::active()
            ->with(['baseToken:id,symbol,logo', 'quoteToken:id,symbol'])
            ->orderBy('sort_order')
            ->orderBy('symbol')
            ->get()
            ->map(fn (TradingPair $p) => [
                'symbol' => $p->symbol,
                'base_asset' => $p->baseToken?->symbol ?? explode('-', $p->symbol)[0],
                'quote_asset' => $p->quoteToken?->symbol ?? 'USDT',
                'base_logo' => $p->baseToken?->logo,
                'min_trade_amount' => (float) $p->min_trade_amount,
                'max_trade_amount' => (float) $p->max_trade_amount,
                'price_precision' => $p->price_precision,
                'amount_precision' => $p->amount_precision,
                'fee_rate' => $p->taker_fee_override !== null
                    ? (float) $p->taker_fee_override
                    : null,
                'chain_id' => $p->chain_id,
                'is_active' => true,
            ])
            ->all();

        // Fallback to Binance only if DB is empty (initial deployment / no admin config)
        if (empty($dbPairs)) {
            return response()->json([
                'success' => true,
                'data' => $this->marketDataService->getPairs(),
            ]);
        }

        return response()->json(['success' => true, 'data' => $dbPairs]);
    }

    public function tokenInfo(string $address): JsonResponse
    {
        $token = Token::active()
            ->where('contract_address', $address)
            ->with('chain')
            ->first();

        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'TOKEN_NOT_FOUND', 'message' => 'Token not found'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'address' => $token->contract_address,
                'name' => $token->name,
                'symbol' => $token->symbol,
                'decimals' => $token->decimals,
                'logo' => $token->logo,
                'chain' => [
                    'id' => $token->chain->id,
                    'name' => $token->chain->name,
                ],
            ],
        ]);
    }

    public function tokenPrice(string $address): JsonResponse
    {
        $token = Token::active()
            ->where('contract_address', $address)
            ->first();

        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'TOKEN_NOT_FOUND', 'message' => 'Token not found'],
            ], 404);
        }

        $price = $this->marketDataService->getTokenPrice($token->symbol.'USDT');

        return response()->json([
            'success' => true,
            'data' => [
                'address' => $token->contract_address,
                'symbol' => $token->symbol,
                'price' => $price,
            ],
        ]);
    }
}
