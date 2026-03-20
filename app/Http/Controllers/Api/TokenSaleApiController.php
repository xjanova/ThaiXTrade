<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PriceFeedService;
use App\Services\StripePaymentService;
use App\Services\TokenSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * TokenSaleApiController — API สำหรับระบบขายเหรียญ TPIX.
 *
 * Endpoints:
 * - GET  /token-sale       → ข้อมูลรอบขาย + phases
 * - GET  /token-sale/stats → สถิติ (total sold, raised, buyers)
 * - POST /token-sale/purchase → ซื้อเหรียญ (ส่ง tx_hash)
 * - GET  /token-sale/purchases/{wallet} → รายการซื้อ
 * - GET  /token-sale/vesting/{wallet}   → vesting schedule
 * - POST /token-sale/preview → คำนวณ preview ก่อนซื้อ
 */
class TokenSaleApiController extends Controller
{
    public function __construct(
        private TokenSaleService $saleService,
        private PriceFeedService $priceFeed,
        private StripePaymentService $stripe,
    ) {
        //
    }

    /**
     * ดึงข้อมูลรอบขายที่ active พร้อม phases.
     */
    public function index(): JsonResponse
    {
        $sale = $this->saleService->getActiveSale();

        if (! $sale) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No active token sale.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $sale->id,
                'name' => $sale->name,
                'description' => $sale->description,
                'status' => $sale->status,
                'total_supply' => (float) $sale->total_supply_for_sale,
                'total_sold' => (float) $sale->total_sold,
                'total_raised_usd' => (float) $sale->total_raised_usd,
                'percent_sold' => $sale->percent_sold,
                'accept_currencies' => $sale->accept_currencies ?? ['BNB', 'USDT'],
                'starts_at' => $sale->starts_at?->toIso8601String(),
                'ends_at' => $sale->ends_at?->toIso8601String(),
                'phases' => $sale->phases->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'price_usd' => (float) $p->price_usd,
                    'allocation' => (float) $p->allocation,
                    'sold' => (float) $p->sold,
                    'percent_sold' => $p->percent_sold,
                    'remaining' => $p->remaining_allocation,
                    'min_purchase' => (float) $p->min_purchase,
                    'max_purchase' => (float) $p->max_purchase,
                    'status' => $p->status,
                    'starts_at' => $p->starts_at?->toIso8601String(),
                    'ends_at' => $p->ends_at?->toIso8601String(),
                    'vesting_tge_percent' => (float) $p->vesting_tge_percent,
                    'vesting_cliff_days' => $p->vesting_cliff_days,
                    'vesting_duration_days' => $p->vesting_duration_days,
                ]),
            ],
        ]);
    }

    /**
     * สถิติรอบขาย (public).
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->saleService->getSaleStats(),
        ]);
    }

    /**
     * คำนวณ preview ก่อนซื้อ (จำนวน TPIX ที่จะได้).
     */
    public function preview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phase_id' => ['required', 'integer', 'exists:sale_phases,id'],
            'currency' => ['required', 'string', 'in:BNB,USDT,BUSD'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid parameters.'],
            ], 422);
        }

        $data = $validator->validated();

        try {
            $preview = $this->saleService->calculatePurchasePreview(
                $data['phase_id'],
                $data['currency'],
                (float) $data['amount']
            );

            return response()->json(['success' => true, 'data' => $preview]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'PREVIEW_ERROR', 'message' => 'Operation failed. Please try again.'],
            ], 400);
        }
    }

    /**
     * ซื้อเหรียญ TPIX — ส่ง tx_hash จาก BSC มาให้ backend verify.
     */
    public function purchase(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'phase_id' => ['required', 'integer', 'exists:sale_phases,id'],
            'currency' => ['required', 'string', 'in:BNB,USDT,BUSD'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid purchase parameters.'],
            ], 422);
        }

        $data = $validator->validated();

        try {
            $transaction = $this->saleService->processPurchase(
                $data['wallet_address'],
                $data['phase_id'],
                $data['currency'],
                (float) $data['amount'],
                $data['tx_hash']
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $transaction->uuid,
                    'tpix_amount' => (float) $transaction->tpix_amount,
                    'payment_amount' => (float) $transaction->payment_amount,
                    'payment_currency' => $transaction->payment_currency,
                    'payment_usd_value' => (float) $transaction->payment_usd_value,
                    'status' => $transaction->status,
                    'created_at' => $transaction->created_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'PURCHASE_ERROR', 'message' => 'Operation failed. Please try again.'],
            ], 400);
        }
    }

    /**
     * รายการซื้อของ wallet.
     */
    public function purchases(string $walletAddress): JsonResponse
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Invalid wallet address.'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->saleService->getPurchases($walletAddress),
        ]);
    }

    /**
     * สร้าง Stripe Checkout Session — ซื้อ TPIX ด้วยบัตรเครดิต/เดบิต.
     */
    public function stripeCheckout(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'phase_id' => ['required', 'integer', 'exists:sale_phases,id'],
            'amount_usd' => ['required', 'numeric', 'min:5', 'max:50000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->errors()->first()],
            ], 422);
        }

        $data = $validator->validated();

        try {
            $result = $this->stripe->createCheckoutSession(
                (float) $data['amount_usd'],
                $data['wallet_address'],
                (int) $data['phase_id']
            );

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'STRIPE_ERROR', 'message' => 'Operation failed. Please try again.'],
            ], 400);
        }
    }

    /**
     * ตรวจสถานะ Stripe payment.
     */
    public function stripeStatus(string $sessionId): JsonResponse
    {
        try {
            $result = $this->stripe->getPaymentStatus($sessionId);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'STATUS_ERROR', 'message' => 'Operation failed. Please try again.'],
            ], 400);
        }
    }

    /**
     * Vesting schedule ของ wallet.
     */
    public function vesting(string $walletAddress): JsonResponse
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Invalid wallet address.'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->saleService->getVestingSchedule($walletAddress),
        ]);
    }
}
