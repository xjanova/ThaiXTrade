<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TradingController extends Controller
{
    /**
     * Create a new order (records the intent; actual execution is on-chain via frontend).
     */
    public function createOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'pair' => ['required', 'string'],
            'side' => ['required', 'string', 'in:buy,sell'],
            'type' => ['required', 'string', 'in:limit,market,stop-limit'],
            'price' => ['required_unless:type,market', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'chain_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid order parameters.',
                ],
            ], 422);
        }

        $validated = $validator->validated();

        // Record the order as a transaction
        $transaction = Transaction::create([
            'type' => 'order_'.$validated['side'],
            'wallet_address' => $validated['wallet_address'],
            'chain_id' => $validated['chain_id'],
            'from_token' => $validated['pair'],
            'to_token' => null,
            'from_amount' => $validated['amount'],
            'to_amount' => 0,
            'fee_amount' => 0,
            'status' => 'pending',
            'metadata' => [
                'pair' => $validated['pair'],
                'side' => $validated['side'],
                'order_type' => $validated['type'],
                'price' => $validated['price'] ?? null,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $transaction->uuid,
                'pair' => $validated['pair'],
                'side' => $validated['side'],
                'type' => $validated['type'],
                'price' => $validated['price'] ?? null,
                'amount' => $validated['amount'],
                'status' => 'pending',
                'created_at' => $transaction->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Cancel a pending order.
     */
    public function cancelOrder(string $orderId, Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address');

        // Wallet address is required to verify ownership
        if (! $walletAddress) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'WALLET_REQUIRED', 'message' => 'Wallet address is required to cancel an order.'],
            ], 422);
        }

        $transaction = Transaction::where('uuid', $orderId)
            ->where('status', 'pending')
            ->where('wallet_address', $walletAddress)
            ->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'ORDER_NOT_FOUND', 'message' => 'Order not found or already completed.'],
            ], 404);
        }

        $transaction->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $transaction->uuid,
                'status' => 'cancelled',
            ],
        ]);
    }

    /**
     * Get open orders for the authenticated wallet.
     */
    public function getOrders(Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address');

        if (! $walletAddress) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $orders = Transaction::where('wallet_address', $walletAddress)
            ->where('status', 'pending')
            ->whereIn('type', ['order_buy', 'order_sell'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->uuid,
                'pair' => $tx->metadata['pair'] ?? $tx->from_token,
                'side' => $tx->metadata['side'] ?? 'buy',
                'type' => $tx->metadata['order_type'] ?? 'limit',
                'price' => $tx->metadata['price'] ?? '0',
                'amount' => $tx->from_amount,
                'status' => $tx->status,
                'created_at' => $tx->created_at->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * Get a specific order.
     */
    public function getOrder(string $orderId, Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address');

        $query = Transaction::where('uuid', $orderId);

        // If wallet_address provided, enforce ownership check
        if ($walletAddress) {
            $query->where('wallet_address', $walletAddress);
        }

        $transaction = $query->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'ORDER_NOT_FOUND', 'message' => 'Order not found.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $transaction->uuid,
                'pair' => $transaction->metadata['pair'] ?? $transaction->from_token,
                'side' => $transaction->metadata['side'] ?? 'buy',
                'type' => $transaction->metadata['order_type'] ?? 'limit',
                'price' => $transaction->metadata['price'] ?? '0',
                'amount' => $transaction->from_amount,
                'status' => $transaction->status,
                'tx_hash' => $transaction->tx_hash,
                'created_at' => $transaction->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get trade history for a wallet.
     */
    public function getHistory(Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address');

        if (! $walletAddress) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $trades = Transaction::where('wallet_address', $walletAddress)
            ->whereIn('status', ['confirmed', 'completed'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->uuid,
                'type' => $tx->type,
                'pair' => $tx->metadata['pair'] ?? ($tx->from_token.'/'.$tx->to_token),
                'side' => $tx->metadata['side'] ?? $tx->type,
                'price' => $tx->metadata['price'] ?? '0',
                'amount' => $tx->from_amount,
                'total' => $tx->to_amount,
                'fee' => $tx->fee_amount,
                'tx_hash' => $tx->tx_hash,
                'status' => $tx->status,
                'created_at' => $tx->created_at->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $trades]);
    }

    /**
     * Get swap quote - delegates to SwapApiController's public endpoint.
     */
    public function getSwapQuote(Request $request): JsonResponse
    {
        return app(SwapApiController::class)->quote($request);
    }

    /**
     * Execute swap - delegates to SwapApiController.
     */
    public function executeSwap(Request $request): JsonResponse
    {
        return app(SwapApiController::class)->execute($request);
    }

    /**
     * Get swap routes - delegates to SwapApiController.
     */
    public function getSwapRoutes(): JsonResponse
    {
        return app(SwapApiController::class)->routes(request());
    }
}
