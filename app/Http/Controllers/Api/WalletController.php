<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function connect(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function balances(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function transactions(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function requestSignature(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }
}
