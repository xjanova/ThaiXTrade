<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * WalletController.
 *
 * Handles wallet connection, balance queries, and transaction history
 * for the TPIX TRADE DEX platform.
 */
class WalletController extends Controller
{
    /**
     * Record a wallet connection event.
     *
     * POST /api/v1/wallet/connect
     */
    public function connect(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'chain_id' => ['required', 'integer'],
            'wallet_type' => ['nullable', 'string', 'in:metamask,trustwallet,coinbase,walletconnect,okx'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid wallet address format.',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        $validated = $validator->validated();

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $validated['wallet_address'],
                'chain_id' => $validated['chain_id'],
                'connected_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Record a wallet disconnection event.
     *
     * POST /api/v1/wallet/disconnect
     */
    public function disconnect(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'disconnected_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get token balances for a wallet address.
     *
     * GET /api/v1/wallet/balances?wallet_address=0x...&chain_id=56
     */
    public function balances(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'chain_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid parameters.',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        // Token balances are fetched on-chain by the frontend via ethers.js.
        // This endpoint serves as a placeholder for cached/indexed balance data.
        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $request->input('wallet_address'),
                'chain_id' => $request->input('chain_id', 56),
                'balances' => [],
                'note' => 'Real-time balances are fetched directly from the blockchain via ethers.js.',
            ],
        ]);
    }

    /**
     * Get transaction history for a wallet address.
     *
     * GET /api/v1/wallet/transactions?wallet_address=0x...
     */
    public function transactions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid parameters.',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        $limit = $request->input('limit', 20);
        $walletAddress = $request->input('wallet_address');

        $transactions = Transaction::where('wallet_address', $walletAddress)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->uuid ?? $tx->id,
                    'type' => $tx->type,
                    'from_token' => $tx->from_token,
                    'to_token' => $tx->to_token,
                    'from_amount' => $tx->from_amount,
                    'to_amount' => $tx->to_amount,
                    'fee_amount' => $tx->fee_amount,
                    'tx_hash' => $tx->tx_hash,
                    'status' => $tx->status,
                    'created_at' => $tx->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    /**
     * Generate a signature request message for wallet verification.
     *
     * POST /api/v1/wallet/sign
     */
    public function requestSignature(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid wallet address.',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        $nonce = bin2hex(random_bytes(16));
        $message = "TPIX TRADE: Sign this message to verify your wallet.\n\nNonce: {$nonce}\nTimestamp: ".now()->toIso8601String();

        return response()->json([
            'success' => true,
            'data' => [
                'message' => $message,
                'nonce' => $nonce,
            ],
        ]);
    }
}
