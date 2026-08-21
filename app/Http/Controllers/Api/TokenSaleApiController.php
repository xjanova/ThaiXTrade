<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PurchaseException;
use App\Http\Controllers\Controller;
use App\Models\SaleTransaction;
use App\Models\TreasuryPayout;
use App\Services\BankTransferSaleService;
use App\Services\PriceFeedService;
use App\Services\StripePaymentService;
use App\Services\TokenSaleService;
use App\Support\Wei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    public function index(BankTransferSaleService $bank, StripePaymentService $stripe): JsonResponse
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
                'accept_currencies' => $sale->accept_currencies ?? [],

                /*
                 * เชนที่ผู้ซื้อต้องต่อกระเป๋าอยู่เพื่อรับเหรียญ (4289)
                 * หน้าเว็บใช้ค่านี้บังคับสลับเชนก่อนเปิดให้กดซื้อ
                 */
                'receive_chain_id' => (int) ($sale->accept_chain_id ?: 4289),

                /*
                 * ช่องทางชำระเงินที่ "เปิดใช้ได้จริงตอนนี้"
                 *
                 * แยกจาก accept_currencies เพราะช่องทางหนึ่งอาจถูกประกาศไว้
                 * แต่ยังตั้งค่าไม่ครบ (เช่น ยังไม่ใส่เลขบัญชี หรือยังไม่มีคีย์ Stripe)
                 * ถ้าโชว์ปุ่มทั้งที่ใช้ไม่ได้ ผู้ซื้อจะกดแล้วเจอ error เปล่าๆ
                 */
                'payment_methods' => [
                    'card' => $stripe->isEnabled(),
                    'bank' => $bank->isConfigured(),
                ],

                'sale_wallet_address' => $sale->sale_wallet_address,
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
        } catch (PurchaseException $e) {
            /*
             * เฟสปิด/ยังไม่เปิด/รอบขายไม่ active — ต้องบอกเหตุผลจริง ไม่ใช่ "Operation failed"
             *
             * หน้าเว็บใช้โค้ด PHASE_CLOSED นี้ปิดปุ่มซื้อทันที ก่อนที่ผู้ใช้จะกดจ่ายเงิน
             * ถ้ากลบเป็นข้อความรวม หน้าเว็บจะแยกไม่ออกว่า "ระบบล่ม" กับ "รอบขายปิด"
             * แล้วปล่อยให้กดจ่ายเงินต่อได้ ซึ่งคือจังหวะที่เงินหายจริง
             *
             * ข้อความจาก PurchaseException ตั้งใจเขียนให้ผู้ซื้ออ่านได้อยู่แล้ว
             */
            return response()->json([
                'success' => false,
                'error' => ['code' => 'PHASE_CLOSED', 'message' => $e->getMessage()],
            ], 409);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'PREVIEW_ERROR', 'message' => 'Operation failed. Please try again.'],
            ], 400);
        }
    }

    /**
     * ตรวจล่วงหน้าก่อนผู้ใช้จ่ายเงินจริง — ไม่แตะฐานข้อมูล ไม่สร้างรายการ.
     *
     * ★ อยู่ในกลุ่มที่ผ่าน VerifyWalletOwnership + KYC เหมือน purchase โดยตั้งใจ
     *   /preview เป็นปลายทางสาธารณะ จึงตรวจไม่ได้ว่าเซสชันกระเป๋ายังใช้ได้อยู่ไหม
     *   ผู้ใช้ที่เปิดหน้าค้างไว้จนเซสชันหมดอายุจะผ่าน preview แล้วโอนเงินจริง
     *   ก่อนจะเจอ 403 ตอนยื่น tx_hash — เงินออกไปแล้วโดยไม่มีแถวบันทึกใดๆ
     *
     * POST /api/v1/token-sale/precheck
     */
    public function precheck(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'phase_id' => ['required', 'integer', 'exists:sale_phases,id'],
            'currency' => ['required', 'string', 'in:BNB,USDT,BUSD'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->errors()->first()],
            ], 422);
        }

        $data = $validator->validated();

        try {
            return response()->json([
                'success' => true,
                'data' => $this->saleService->assertPurchasable(
                    $data['wallet_address'],
                    (int) $data['phase_id'],
                    $data['currency'],
                    (float) $data['amount'],
                ),
            ]);
        } catch (PurchaseException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_PURCHASABLE', 'message' => $e->getMessage()],
            ], 409);
        } catch (\Exception $e) {
            Log::warning('token-sale: precheck ไม่สำเร็จ', [
                'wallet' => $data['wallet_address'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'PRECHECK_ERROR', 'message' => 'Unable to verify this purchase right now.'],
            ], 400);
        }
    }

    /**
     * สั่งซื้อโดยโอนเงินเข้าบัญชี — คืนรหัสอ้างอิง + เลขบัญชีให้ผู้ซื้อ.
     *
     * ยังไม่นับเป็นยอดขายและยังไม่จองโควตา จนกว่าทีมงานจะยืนยันว่าเงินเข้าจริง
     * (ดูเหตุผลใน BankTransferSaleService)
     *
     * POST /api/v1/token-sale/bank-order
     */
    public function bankOrder(Request $request, BankTransferSaleService $bank): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'phase_id' => ['required', 'integer', 'exists:sale_phases,id'],
            'amount_usd' => ['required', 'numeric', 'min:1', 'max:1000000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->errors()->first()],
            ], 422);
        }

        $data = $validator->validated();

        try {
            return response()->json([
                'success' => true,
                'data' => $bank->createOrder(
                    $data['wallet_address'],
                    (int) $data['phase_id'],
                    (float) $data['amount_usd'],
                ),
            ], 201);
        } catch (PurchaseException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_PURCHASABLE', 'message' => $e->getMessage()],
            ], 409);
        } catch (\Exception $e) {
            Log::warning('token-sale: สร้างคำสั่งซื้อทางโอนเงินไม่สำเร็จ', [
                'wallet' => $data['wallet_address'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'ORDER_ERROR', 'message' => 'Unable to create the order right now.'],
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
            'tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/', 'unique:sale_transactions,tx_hash'],
            'signature' => ['nullable', 'string'],
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
        } catch (PurchaseException $e) {
            // ข้อความจากคลาสนี้เขียนไว้ให้ผู้ซื้ออ่านโดยเฉพาะ — ต้องส่งถึงเขาจริง
            // ไม่งั้นคนที่โอนเงินแล้วแต่ต้องรอ confirmation จะเห็นแค่ "ล้มเหลว"
            // แล้วคิดว่าเงินหาย
            return response()->json([
                'success' => false,
                'error' => ['code' => 'PURCHASE_REJECTED', 'message' => $e->getMessage()],
            ], 422);
        } catch (\Exception $e) {
            Log::warning('token-sale: purchase ล้มเหลวโดยไม่คาดคิด', [
                'wallet' => $data['wallet_address'] ?? null,
                'error' => $e->getMessage(),
            ]);

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
        // ตรวจสอบว่า Stripe เปิดใช้งาน
        if (! $this->stripe->isEnabled()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'STRIPE_DISABLED', 'message' => 'Card payments are currently unavailable.'],
            ], 503);
        }

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

    /**
     * Claim TPIX ที่ปลดล็อคจาก vesting.
     *
     * POST /api/v1/token-sale/claim
     */
    public function claim(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'transaction_id' => ['required', 'string', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $validator->errors()->first()],
            ], 422);
        }

        $data = $validator->validated();

        try {
            // ทั้งก้อนอยู่ใน transaction + ล็อกแถว เพราะเดิมอ่าน claimable แล้วค่อย
            // increment โดยไม่ล็อก สองคำขอที่มาพร้อมกันจะผ่านด่านตรวจได้ทั้งคู่
            // แล้ว claim เกินสิทธิ์ (TOCTOU)
            $result = DB::transaction(function () use ($data) {
                // ใช้ UUID แทน integer ID เพื่อความปลอดภัย
                $tx = SaleTransaction::where('uuid', $data['transaction_id'])
                    ->where('wallet_address', strtolower($data['wallet_address']))
                    ->where('status', 'confirmed')
                    ->lockForUpdate()
                    ->firstOrFail();

                // คำนวณ claimable ใหม่ภายใต้ล็อก
                $claimable = $tx->claimable_amount;
                $requestedAmount = (float) $data['amount'];

                if ($requestedAmount > $claimable) {
                    return ['error' => [
                        'code' => 'INSUFFICIENT_CLAIMABLE',
                        'message' => "Only {$claimable} TPIX available to claim.",
                    ]];
                }

                // ด่านซ้อน: ยอดเคลมสะสมต้องไม่เกินยอดที่ซื้อไว้ ไม่ว่ากรณีใด
                // (claimable_amount คำนวณถูกแล้ว แต่ถ้าสูตรนั้นพลาดอีกครั้ง
                //  ด่านนี้จะกันไม่ให้เหรียญไหลออกเกินสิ่งที่ลูกค้าจ่ายมาจริง)
                if ((float) $tx->claimed_amount + $requestedAmount > (float) $tx->tpix_amount) {
                    return ['error' => [
                        'code' => 'EXCEEDS_PURCHASE',
                        'message' => 'Claim exceeds the amount purchased.',
                    ]];
                }

                $tx->increment('claimed_amount', $requestedAmount);
                $tx->refresh();

                // เข้าคิวจ่ายเงินจากกระเป๋าร้อน แทนที่จะรอแอดมินส่งเหรียญเอง
                //
                // idempotency key ผูกกับยอดสะสมหลัง claim ครั้งนี้ ทำให้การกดซ้ำ
                // ที่ให้ผลลัพธ์เดียวกันได้รายการเดิม ส่วนการ claim ครั้งถัดไป
                // (ยอดสะสมต่างออกไป) ได้รายการใหม่ตามที่ควร
                $payout = TreasuryPayout::firstOrCreate(
                    ['idempotency_key' => 'sale-claim-'.$tx->uuid.'-'.$tx->claimed_amount],
                    [
                        'to_address' => strtolower($data['wallet_address']),
                        'amount_wei' => Wei::toWei((string) $requestedAmount),
                        'purpose' => 'token_sale',
                        'memo' => 'claim vesting จากรายการซื้อ '.$tx->uuid,
                        'status' => TreasuryPayout::STATUS_PENDING,
                    ],
                );

                return ['tx' => $tx, 'payout' => $payout, 'claimed' => $requestedAmount];
            });

            if (isset($result['error'])) {
                return response()->json(['success' => false, 'error' => $result['error']], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'claimed_amount' => $result['claimed'],
                    'total_claimed' => (float) $result['tx']->claimed_amount,
                    'remaining_claimable' => $result['tx']->claimable_amount,
                    'payout_status' => $result['payout']->status,
                    'message' => 'Claim recorded. TPIX will be sent to your wallet.',
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('token-sale: claim ไม่สำเร็จ', [
                'wallet' => $data['wallet_address'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'CLAIM_ERROR', 'message' => 'Unable to process claim.'],
            ], 400);
        }
    }
}
