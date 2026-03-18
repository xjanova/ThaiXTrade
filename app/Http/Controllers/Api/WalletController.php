<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\UserWalletService;
use App\Services\Web3BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * WalletController.
 *
 * Handles wallet connection, real balance queries from blockchain RPC,
 * and transaction history for the TPIX TRADE DEX platform.
 */
class WalletController extends Controller
{
    public function __construct(
        private Web3BalanceService $balanceService,
        private UserWalletService $userWalletService,
    ) {}

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
            'wallet_type' => ['nullable', 'string', 'in:metamask,trustwallet,coinbase,walletconnect,okx,tpix_wallet'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid wallet address format.',
                ],
            ], 422);
        }

        $validated = $validator->validated();

        // สมัครสมาชิกอัตโนมัติ (หรือหา user ที่มีอยู่)
        $user = $this->userWalletService->findOrCreateByWallet(
            $validated['wallet_address'],
            $validated['chain_id'],
            $validated['wallet_type'] ?? 'metamask',
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $validated['wallet_address'],
                'chain_id' => $validated['chain_id'],
                'connected_at' => now()->toIso8601String(),
                'user_id' => $user->id,
                'is_new' => $user->wasRecentlyCreated,
                'referral_code' => $user->referral_code,
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
     * Get real token balances for a wallet address from blockchain RPC.
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
                ],
            ], 422);
        }

        $walletAddress = $request->input('wallet_address');
        $chainId = $request->integer('chain_id', 56);

        $balances = $this->balanceService->getWalletBalances($walletAddress, $chainId);

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $walletAddress,
                'chain_id' => $chainId,
                'balances' => $balances,
                'fetched_at' => now()->toIso8601String(),
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
     * Stores nonce in cache for validation against replay attacks.
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
                ],
            ], 422);
        }

        $walletAddress = $request->input('wallet_address');
        $nonce = bin2hex(random_bytes(16));
        $timestamp = now()->toIso8601String();
        $message = "TPIX TRADE: Sign this message to verify your wallet.\n\nNonce: {$nonce}\nTimestamp: {$timestamp}";

        // Invalidate any previous nonce for this wallet, then store new one
        $previousNonce = Cache::get("wallet_active_nonce:{$walletAddress}");
        if ($previousNonce) {
            Cache::forget("wallet_nonce:{$walletAddress}:{$previousNonce}");
        }
        Cache::put("wallet_active_nonce:{$walletAddress}", $nonce, 300);
        Cache::put("wallet_nonce:{$walletAddress}:{$nonce}", $timestamp, 300);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => $message,
                'nonce' => $nonce,
            ],
        ]);
    }
}
