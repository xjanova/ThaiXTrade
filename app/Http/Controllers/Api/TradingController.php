<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\TradingPair;
use App\Models\Transaction;
use App\Services\FeeCalculationService;
use App\Services\OrderMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TradingController extends Controller
{
    public function __construct(
        private FeeCalculationService $feeCalculationService,
        private OrderMatchingService $orderMatchingService,
    ) {}

    /**
     * Create a new order.
     * TPIX pairs: use internal order book matching.
     * Other pairs: record in transactions (legacy behavior).
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
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid order parameters.'],
            ], 422);
        }

        $validated = $validator->validated();
        $pairSymbol = str_replace('/', '-', $validated['pair']);

        // Check if this is a TPIX internal trading pair
        $tradingPair = TradingPair::where('symbol', $pairSymbol)
            ->active()
            ->first();

        if ($tradingPair) {
            // ตรวจว่า chain_id ตรงกับ pair.chain_id (ป้องกัน order ไปเชนผิด)
            if ((int) $validated['chain_id'] !== $tradingPair->chain_id) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CHAIN_MISMATCH',
                        'message' => 'Chain ID does not match the trading pair chain.',
                    ],
                ], 422);
            }

            return $this->createInternalOrder($tradingPair, $validated);
        }

        // Legacy: non-TPIX pairs (Binance-based display)
        return $this->createLegacyOrder($validated);
    }

    /**
     * Create order on internal order book (TPIX pairs).
     */
    private function createInternalOrder(TradingPair $pair, array $validated): JsonResponse
    {
        $price = $validated['price'] ?? 0;
        $amount = $validated['amount'];

        $result = $this->orderMatchingService->placeOrder(
            pair: $pair,
            walletAddress: $validated['wallet_address'],
            side: $validated['side'],
            type: $validated['type'],
            amount: $amount,
            price: $price,
            triggerPrice: $validated['trigger_price'] ?? null,
        );

        $order = $result['order'];
        $trades = $result['trades'];

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->uuid,
                'pair' => $validated['pair'],
                'side' => $order->side,
                'type' => $order->type,
                'price' => $order->price,
                'amount' => $order->amount,
                'filled_amount' => $order->filled_amount,
                'remaining_amount' => $order->remaining_amount,
                'total' => $order->total,
                'fee_rate' => $order->fee_rate,
                'fee_amount' => $order->fee_amount,
                'status' => $order->status,
                'trades_count' => count($trades),
                'created_at' => $order->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Legacy order creation for non-TPIX pairs (stored in transactions).
     */
    private function createLegacyOrder(array $validated): JsonResponse
    {
        // Market order ไม่มี price → ใช้ amount เป็นฐานคำนวณ fee แทน (ป้องกัน fee = 0)
        $price = $validated['price'] ?? 0;
        $totalValue = $validated['total'] ?? ($validated['amount'] * $price);

        // ถ้า total = 0 (market order ไม่มี price) ใช้ amount เป็นฐานคำนวณ fee
        $feeBase = $totalValue > 0 ? $totalValue : (float) $validated['amount'];
        $feeData = $this->feeCalculationService->calculateSwapFee(
            $feeBase,
            (int) $validated['chain_id'],
        );

        $feeCollector = SiteSetting::get('trading', 'fee_collector_wallet', '');

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
     * Mark an order as failed.
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
     * Supports both internal orders and legacy transactions.
     */
    public function cancelOrder(string $orderId, Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address');

        if (! $walletAddress) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'WALLET_REQUIRED', 'message' => 'Wallet address is required.'],
            ], 422);
        }

        // Try internal order first (รวม 'triggered' สำหรับ stop-limit ที่ยังไม่ถูก activate)
        $order = Order::where('uuid', $orderId)
            ->where('wallet_address', strtolower($walletAddress))
            ->whereIn('status', ['open', 'partially_filled', 'triggered'])
            ->first();

        if ($order) {
            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['order_id' => $order->uuid, 'status' => 'cancelled'],
            ]);
        }

        // Legacy transaction (ใช้ lowercase เหมือน internal)
        $transaction = Transaction::where('uuid', $orderId)
            ->where('status', 'pending')
            ->where('wallet_address', strtolower($walletAddress))
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
     * Merges internal orders and legacy transactions.
     */
    public function getOrders(Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address');

        if (! $walletAddress) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Internal orders (รวม 'triggered' = stop-limit ที่รอ trigger)
        $internalOrders = Order::where('wallet_address', strtolower($walletAddress))
            ->whereIn('status', ['open', 'partially_filled', 'triggered'])
            ->with('tradingPair')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->uuid,
                'pair' => $o->tradingPair?->symbol ?? 'TPIX-USDT',
                'side' => $o->side,
                'type' => $o->type,
                'price' => $o->price,
                'amount' => $o->amount,
                'filled_amount' => $o->filled_amount,
                'remaining_amount' => $o->remaining_amount,
                'total' => $o->total,
                'fee' => $o->fee_amount,
                'status' => $o->status,
                'source' => 'internal',
                'created_at' => $o->created_at->toIso8601String(),
            ]);

        // Legacy orders
        $legacyOrders = Transaction::where('wallet_address', $walletAddress)
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
                'filled_amount' => '0',
                'remaining_amount' => $tx->from_amount,
                'total' => $tx->to_amount,
                'fee' => $tx->fee_amount,
                'status' => $tx->status,
                'source' => 'legacy',
                'created_at' => $tx->created_at->toIso8601String(),
            ]);

        $allOrders = $internalOrders->merge($legacyOrders)
            ->sortByDesc('created_at')
            ->values();

        return response()->json(['success' => true, 'data' => $allOrders]);
    }

    /**
     * Get a specific order.
     */
    public function getOrder(string $orderId, Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address');

        if (! $walletAddress) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'WALLET_REQUIRED', 'message' => 'Wallet address is required.'],
            ], 422);
        }

        $walletLower = strtolower($walletAddress);

        // Try internal order (ต้องตรวจ wallet ownership เพื่อป้องกัน data leak)
        $order = Order::where('uuid', $orderId)
            ->where('wallet_address', $walletLower)
            ->first();
        if ($order) {
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $order->uuid,
                    'pair' => $order->tradingPair?->symbol ?? 'TPIX-USDT',
                    'side' => $order->side,
                    'type' => $order->type,
                    'price' => $order->price,
                    'amount' => $order->amount,
                    'filled_amount' => $order->filled_amount,
                    'remaining_amount' => $order->remaining_amount,
                    'total' => $order->total,
                    'fee' => $order->fee_amount,
                    'status' => $order->status,
                    'created_at' => $order->created_at->toIso8601String(),
                ],
            ]);
        }

        // Try legacy
        $transaction = Transaction::where('uuid', $orderId)
            ->where('wallet_address', $walletLower)
            ->first();

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

        $walletLower = strtolower($walletAddress);

        // Internal trades
        $internalTrades = \App\Models\Trade::where(function ($q) use ($walletLower) {
            $q->where('maker_wallet', $walletLower)
                ->orWhere('taker_wallet', $walletLower);
        })
            ->with('tradingPair')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (\App\Models\Trade $t) => [
                'id' => $t->uuid,
                'type' => 'trade',
                'pair' => $t->tradingPair?->symbol ?? 'TPIX-USDT',
                'side' => $t->side,
                'price' => $t->price,
                'amount' => $t->amount,
                'total' => $t->total,
                'fee' => $t->taker_wallet === $walletLower ? $t->taker_fee : $t->maker_fee,
                'role' => $t->taker_wallet === $walletLower ? 'taker' : 'maker',
                'status' => 'completed',
                'source' => 'internal',
                'created_at' => $t->created_at->toIso8601String(),
            ]);

        // Legacy trades (ใช้ lowercase เหมือน internal)
        $legacyTrades = Transaction::where('wallet_address', $walletLower)
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
                'role' => 'taker',
                'status' => $tx->status,
                'tx_hash' => $tx->tx_hash,
                'source' => 'legacy',
                'created_at' => $tx->created_at->toIso8601String(),
            ]);

        $allTrades = $internalTrades->merge($legacyTrades)
            ->sortByDesc('created_at')
            ->values();

        return response()->json(['success' => true, 'data' => $allTrades]);
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
