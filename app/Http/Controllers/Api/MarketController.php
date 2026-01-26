<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MarketController extends Controller
{
    public function tickers(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function ticker(string $symbol): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function orderbook(string $symbol): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function trades(string $symbol): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function klines(string $symbol): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function pairs(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function tokenInfo(string $address): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function tokenPrice(string $address): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }
}
