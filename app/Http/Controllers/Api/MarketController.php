<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Token;
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

    public function pairs(): JsonResponse
    {
        $data = $this->marketDataService->getPairs();

        return response()->json(['success' => true, 'data' => $data]);
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
