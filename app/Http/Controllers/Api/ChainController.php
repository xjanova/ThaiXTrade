<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ChainController extends Controller
{
    /**
     * Get list of all supported chains.
     */
    public function index(): JsonResponse
    {
        $chains = config('chains.chains', []);

        return response()->json([
            'success' => true,
            'data' => array_values($chains),
        ]);
    }

    /**
     * Get specific chain information.
     */
    public function show(int $chainId): JsonResponse
    {
        $chain = config("chains.chains.{$chainId}");

        if (! $chain) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CHAIN_NOT_FOUND',
                    'message' => "Chain with ID {$chainId} not found",
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $chain,
        ]);
    }

    /**
     * Get tokens for a specific chain.
     */
    public function tokens(int $chainId): JsonResponse
    {
        $chain = config("chains.chains.{$chainId}");

        if (! $chain) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CHAIN_NOT_FOUND',
                    'message' => "Chain with ID {$chainId} not found",
                ],
            ], 404);
        }

        $tokens = $chain['tokens'] ?? [];

        return response()->json([
            'success' => true,
            'data' => $tokens,
        ]);
    }

    /**
     * Get current gas price for a chain.
     */
    public function gasPrice(int $chainId): JsonResponse
    {
        $chain = config("chains.chains.{$chainId}");

        if (! $chain) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CHAIN_NOT_FOUND',
                    'message' => "Chain with ID {$chainId} not found",
                ],
            ], 404);
        }

        // In production, this would fetch real-time gas prices from the blockchain
        return response()->json([
            'success' => true,
            'data' => [
                'chainId' => $chainId,
                'gasPrice' => '20000000000', // 20 Gwei
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
