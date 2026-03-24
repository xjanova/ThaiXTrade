<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\Transaction;
use App\Services\FeeCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TradingController extends Controller
{
    public function __construct(
        private FeeCalculationService $feeCalculationService,
    ) {}

    /**
     * Create a new order with proper fee calculation.
     * Market orders: frontend executes on-chain immediately after this.
     * Limit orders: stored as pending, matched when price hits.
     * Stop-Limit orders: stored with trigger_price, activated when price hits trigger.
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
            'total' => ['nullable', 'numeric', 'gte:0'],
            'trigger_price' => ['required_if:type,stop-limit', 'numeric', 'gt:0'],
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

        // Calculate fees
        $totalValue = $validated['total']
            ?? ($validated['amount'] * ($validated['price'] ?? 0));
        $feeData = $this->feeCalculationService->calculateSwapFee(
            $totalValue,
            (int) $validated['chain_id'],
        );

        // Get fee collector wallet
        $feeCollector = SiteSetting::get('trading', 'fee_collector_wallet', '');

        // Record the order
        $transaction = Transaction::create([
            'type' => 'order_'.$validated['side'],
            'wallet_address' => $validated['wallet_address'],
            'chain_id' => $validated['chain_id'],
            'from_token' => $validated['pair'],
            'to_token' => null,
            'from_amount' => $validated['amount'],
            'to_amount' => $totalValue,
            'fee_amount' => $feeData['fee_amount'],
            'status' => $validated['type'] === 'market' ? 'executing' : 'pending',
            'metadata' => [
                'pair' => $validated['pair'],
                'side' => $validated['side'],
                'order_type' => $validated['type'],
                'price' => $validated['price'] ?? null,
                'trigger_price' => $validated['trigger_price'] ?? null,
                'fee_rate' => $feeData['fee_rate'],
                'fee_collector' => $feeCollector,
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
                'total' => $totalValue,
                'fee_amount' => $feeData['fee_amount'],
                'fee_rate' => $feeData['fee_rate'],
                'fee_collector' => $feeCollector,
                'status' => $transaction->status,
                'created_at' => $transaction->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Confirm an order after on-chain execution (market orders).
     * Called by frontend after tx is mined.
     */
    public function confirmOrder(string $orderId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
            'actual_amount_out' => ['nullable', 'numeric', 'gte:0'],
            'actual_fee' => ['nullable', 'numeric', 'gte:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid parameters.'],
            ], 422);
        }

        $validated = $validator->validated();

        $transaction = Transaction::where('uuid', $orderId)
            ->whereIn('status', ['pending', 'executing'])
            ->where('wallet_address', $validated['wallet_address'])
            ->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'ORDER_NOT_FOUND', 'message' => 'Order not found or already completed.'],
            ], 404);
        }

        $transaction->update([
            'status' => 'confirmed',
            'tx_hash' => $validated['tx_hash'],
            'to_amount' => $validated['actual_amount_out'] ?? $transaction->to_amount,
            'fee_amount' => $validated['actual_fee'] ?? $transaction->fee_amount,
        ]);

        Log::info('Order confirmed on-chain', [
            'order_id' => $orderId,
            'tx_hash' => $validated['tx_hash'],
            'wallet' => $validated['wallet_address'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $transaction->uuid,
                'status' => 'confirmed',
                'tx_hash' => $validated['tx_hash'],
            ],
        ]);
    }

    /**
     * Mark an order as failed (frontend reports tx failure).
     */
    public function failOrder(string $orderId, Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address');

        $transaction = Transaction::where('uuid', $orderId)
            ->whereIn('status', ['pending', 'executing'])
            ->where('wallet_address', $walletAddress)
            ->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'ORDER_NOT_FOUND', 'message' => 'Order not found.'],
            ], 404);
        }

        $transaction->update([
            'status' => 'failed',
            'metadata' => array_merge($transaction->metadata ?? [], [
                'failure_reason' => $request->input('reason', 'Transaction failed'),
            ]),
        ]);

        return response()->json([
            'success' => true,
            'data' => ['order_id' => $transaction->uuid, 'status' => 'failed'],
        ]);
    }

    /**
     * Cancel a pending order.
     */
    public function cancelOrder(string $orderId, Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address');

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
            'data' => ['order_id' => $transaction->uuid, 'status' => 'cancelled'],
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
            ->whereIn('status', ['pending', 'executing'])
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
                'total' => $tx->to_amount,
                'fee' => $tx->fee_amount,
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
                'total' => $transaction->to_amount,
                'fee' => $transaction->fee_amount,
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
     * Get fee info for the connected wallet/chain.
     */
    public function getFeeInfo(Request $request): JsonResponse
    {
        $chainId = $request->input('chain_id', 56);
        $feeRate = $this->feeCalculationService->getEffectiveFeeRate('swap', (int) $chainId);
        $feeCollector = SiteSetting::get('trading', 'fee_collector_wallet', '');

        return response()->json([
            'success' => true,
            'data' => [
                'fee_rate' => $feeRate,
                'fee_collector' => $feeCollector,
                'max_fee_rate' => (float) SiteSetting::get('trading', 'max_fee_rate', 5.0),
            ],
        ]);
    }

    /**
     * Get swap quote - delegates to SwapApiController.
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
