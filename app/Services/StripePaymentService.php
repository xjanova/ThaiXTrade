<?php

namespace App\Services;

use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * StripePaymentService — ระบบชำระเงินด้วย Stripe สำหรับ ICO Token Sale.
 *
 * รองรับการซื้อ TPIX ด้วยบัตรเครดิต/เดบิต ผ่าน Stripe Checkout.
 * เหรียญ TPIX แลกเปลี่ยนได้กับ USDT เท่านั้น — ไม่มีการถอนเป็นเงินสด.
 *
 * Developed by Xman Studio.
 */
class StripePaymentService
{
    public function __construct(
        private readonly TokenSaleService $saleService,
    ) {
        // ใช้ key จาก admin SiteSetting ก่อน, fallback เป็น .env
        Stripe::setApiKey($this->secretKey());
    }

    /**
     * อ่านค่า Stripe จากหลังบ้าน — ลองกลุ่ม 'payment' ก่อน แล้วค่อย 'stripe'.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ★ บั๊กที่ทำให้คีย์ที่กรอกไว้แล้ว "หายไป" ทั้งที่อยู่ในฐานข้อมูลครบ
     * ═══════════════════════════════════════════════════════════════════════
     * หน้า /admin/settings แท็บ payment บันทึกลง **กลุ่ม `payment`**
     * (ชื่อกลุ่มมาจากท้าย path ของ route `settings/payment`)
     * แต่โค้ดทุกจุดที่อ่านค่ากลับไปอ่าน **กลุ่ม `stripe`** ซึ่งไม่มีอยู่จริง
     *
     * ผลที่เกิดจริงบน production: เจ้าของกรอก pk_live / sk_live / whsec_
     * และเปิดสวิตช์ไว้เรียบร้อยแล้ว แต่ระบบรายงานว่า "ยังไม่ได้ตั้งค่า Stripe"
     * เพราะหาผิดกลุ่มมาตลอด — ค่าไม่เคยหาย แค่ไม่มีใครไปอ่านที่ที่มันอยู่
     *
     * คงกลุ่ม 'stripe' ไว้เป็นทางสำรอง เผื่อมีสภาพแวดล้อมไหนเคยบันทึกไว้แบบนั้น
     */
    private function setting(string $key, mixed $default = null): mixed
    {
        return SiteSetting::get('payment', $key, null)
            ?? SiteSetting::get('stripe', $key, $default);
    }

    /** กุญแจลับของ Stripe — หลังบ้านมาก่อน แล้วค่อย .env */
    private function secretKey(): string
    {
        return (string) ($this->setting('stripe_secret_key')
            ?: config('services.stripe.secret', ''));
    }

    /**
     * ตรวจสอบว่า Stripe เปิดใช้งานอยู่หรือไม่.
     *
     * ★ ไม่มีกุญแจ = ปิด ไม่ว่าสวิตช์ในหลังบ้านจะเปิดไว้แค่ไหน
     * ─────────────────────────────────────────────────────────────────────
     * เดิมอ่านแค่สวิตช์ `stripe_enabled` ซึ่ง default เป็น true → ระบบบอกว่า
     * "จ่ายบัตรได้" ทั้งที่ยังไม่เคยตั้งกุญแจ พอผู้ใช้กดจริง Stripe จะโยน
     * exception ดิบออกมาเป็น 500 แทนที่จะเป็น 503 พร้อมข้อความสุภาพ
     * ผู้ใช้เห็นหน้าพัง ไม่ใช่เห็นว่า "ช่องทางนี้ยังไม่เปิด"
     */
    public function isEnabled(): bool
    {
        if ($this->secretKey() === '') {
            return false;
        }

        // เว็บจริงถือคีย์ทดสอบ = ปิด ตรวจตอนอ่านด้วยเพราะคีย์อาจไม่ได้มาจากหน้าตั้งค่า (.env, แก้ฐานข้อมูลตรง)
        if (app()->isProduction() && self::isTestKey($this->secretKey())) {
            return false;
        }

        return (bool) $this->setting('stripe_enabled', true);
    }

    /**
     * คีย์ของโหมดทดสอบหรือไม่.
     *
     * เว็บจริงที่ถือคีย์ทดสอบ = ใครก็ใช้บัตรทดสอบ 4242 ซื้อเหรียญจริงได้ฟรี
     * โดยไม่มีเงินเข้าสักบาท และไม่มีอะไรฟ้องเพราะทุกขั้นตอนดูปกติหมด
     */
    public static function isTestKey(string $key): bool
    {
        return (bool) preg_match('/^(sk|rk|pk)_test_/', $key);
    }

    /** product metadata ที่ใช้แยกบรรทัดค่าบริการออกจากบรรทัดค่าเหรียญตอน webhook */
    public const FEE_LINE_KIND = 'card_service_fee';

    /**
     * ค่าบริการชำระด้วยบัตร (เปอร์เซ็นต์ของค่าเหรียญ) — เจ้าของกำหนด 3% (2026-10-21)
     *
     * บวกเพิ่มเป็นบรรทัดแยกในหน้า Stripe ไม่ได้ลดจำนวนเหรียญ ผู้ซื้อเห็นยอดก่อนจ่าย
     * แก้ได้ที่ /admin/settings แท็บ Payment (payment.card_service_fee_percent) · จำกัด 0–50 กันพิมพ์ผิด
     */
    public function cardFeePercent(): float
    {
        $raw = $this->setting('card_service_fee_percent', 3);
        $percent = is_numeric($raw) ? (float) $raw : 3.0;

        return max(0.0, min($percent, 50.0));
    }

    public static function feeCents(int $baseCents, float $percent): int
    {
        return (int) round($baseCents * $percent / 100);
    }

    /**
     * ยอดค่าเหรียญ (เซ็นต์) จากรายการสินค้าของ session — ทุกบรรทัดยกเว้นค่าบริการ
     *
     * ต้องใช้ตัวนี้แทน amount_total ตอนออกเหรียญ: amount_total รวมค่าบริการอยู่ด้วย
     * ถ้าเอาไปหารราคาเหรียญ ผู้ซื้อจะได้เหรียญเกินไปเท่ากับค่าบริการ
     * session เก่าก่อนมีค่าบริการไม่มีบรรทัดค่าบริการ → ได้ยอดเต็มเหมือนเดิม
     *
     * @param  iterable<object|array<string, mixed>>  $lineItems  ผลของ Session::allLineItems (expand data.price.product)
     */
    public static function tokenCentsFromLineItems(iterable $lineItems): int
    {
        $cents = 0;

        foreach ($lineItems as $item) {
            $item = json_decode(json_encode($item), true) ?: [];
            $product = $item['price']['product'] ?? null;
            $kind = is_array($product) ? ($product['metadata']['kind'] ?? null) : null;

            if ($kind === self::FEE_LINE_KIND) {
                continue;
            }

            $cents += (int) ($item['amount_total'] ?? 0);
        }

        return $cents;
    }

    /**
     * สร้าง Stripe Checkout Session สำหรับซื้อ TPIX.
     */
    public function createCheckoutSession(float $amountUsd, string $walletAddress, int $phaseId): array
    {
        // ตรวจสอบว่า Stripe เปิดใช้งาน
        if (! $this->isEnabled()) {
            throw new RuntimeException('Stripe payments are currently disabled.');
        }

        $phase = SalePhase::with('tokenSale')->findOrFail($phaseId);
        $sale = $phase->tokenSale;

        /*
         * ★ ด่านเดียวกับทางโอนคริปโต — ต้องอยู่ "ก่อน" สร้าง session เสมอ
         *
         * เดิมทางบัตรตรวจแค่ allocation กับ min/max ไม่เคยดู status หรือช่วงวันเลย
         * ใครยิง phase_id=1 (Private Sale $0.05) เข้ามาก็ซื้อราคานั้นได้ตลอดกาล
         * ทั้งที่รอบจริงเดินไปถึง Public Sale ($0.10) แล้ว = ส่วนลด 50% ฟรี
         *
         * ที่ต้องกันตรงนี้ ไม่ใช่ตอน webhook เพราะ webhook มาหลังลูกค้ารูดบัตรแล้ว
         * ปฏิเสธตอนนั้นเท่ากับรับเงินไปโดยไม่ให้เหรียญ
         */
        $this->saleService->assertPhaseOpen($phase);

        // คำนวณจำนวน TPIX ที่จะได้รับ
        $tpixAmount = $amountUsd / $phase->price_usd;

        // ใช้ pessimistic lock เพื่อป้องกัน race condition
        $remaining = DB::transaction(function () use ($phase, $tpixAmount) {
            $lockedPhase = SalePhase::lockForUpdate()->find($phase->id);
            $remaining = $lockedPhase->allocation - $lockedPhase->sold;

            if ($tpixAmount > $remaining) {
                throw new RuntimeException('Insufficient allocation remaining in this phase.');
            }

            return $remaining;
        });

        // ตรวจสอบ min/max purchase
        if ($tpixAmount < $phase->min_purchase) {
            throw new RuntimeException("Minimum purchase is {$phase->min_purchase} TPIX.");
        }
        if ($phase->max_purchase > 0 && $tpixAmount > $phase->max_purchase) {
            throw new RuntimeException("Maximum purchase is {$phase->max_purchase} TPIX.");
        }

        $baseCents = (int) round($amountUsd * 100); // Stripe ใช้ cents
        $feePercent = $this->cardFeePercent();
        $feeCents = self::feeCents($baseCents, $feePercent);

        $lineItems = [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => "TPIX Token - {$phase->name}",
                    'description' => number_format($tpixAmount, 2).' TPIX @ $'.$phase->price_usd.'/token',
                    'metadata' => ['kind' => 'tpix_token'],
                ],
                'unit_amount' => $baseCents,
            ],
            'quantity' => 1,
        ]];

        if ($feeCents > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'Card service fee ('.rtrim(rtrim(number_format($feePercent, 2), '0'), '.').'%)',
                        'metadata' => ['kind' => self::FEE_LINE_KIND],
                    ],
                    'unit_amount' => $feeCents,
                ],
                'quantity' => 1,
            ];
        }

        // สร้าง Stripe Checkout Session
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => url('/token-sale?payment=success&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => url('/token-sale?payment=cancelled'),
            'metadata' => [
                'wallet_address' => $walletAddress,
                'phase_id' => $phaseId,
                'sale_id' => $sale->id,
                'tpix_amount' => $tpixAmount,
                'price_per_tpix' => $phase->price_usd,
                'service_fee_percent' => $feePercent,
            ],
        ]);

        return [
            'session_id' => $session->id,
            'url' => $session->url,
            'token_usd' => $baseCents / 100,
            'service_fee_usd' => $feeCents / 100,
            'total_usd' => ($baseCents + $feeCents) / 100,
        ];
    }

    /**
     * จัดการ Stripe Webhook — รองรับหลาย event types.
     */
    public function handleWebhook(string $payload, string $signature): array
    {
        $webhookSecret = $this->setting('stripe_webhook_secret')
            ?: config('services.stripe.webhook_secret');

        /*
         * ═══════════════════════════════════════════════════════════════════
         * FAIL-CLOSED: ไม่มี secret = ไม่รับ webhook เด็ดขาด
         * ═══════════════════════════════════════════════════════════════════
         * Stripe\Webhook::constructEvent ไม่เคยตรวจว่า secret ว่างหรือไม่ —
         * มันคำนวณ HMAC ด้วยกุญแจว่างได้ตามปกติ แปลว่าใครก็เซ็น header เองแล้ว
         * ส่ง payload ปลอมเข้ามาสร้างรายการซื้อสถานะ confirmed ได้ฟรี
         * โดยไม่ต้องจ่ายเงินและไม่ต้องผ่านด่านตรวจ BSC เลยแม้แต่นิดเดียว
         *
         * ห้ามถอดด่านนี้ออกไม่ว่ากรณีใด
         */
        if (! is_string($webhookSecret) || ! str_starts_with($webhookSecret, 'whsec_')) {
            Log::error('stripe: ปฏิเสธ webhook เพราะยังไม่ได้ตั้ง webhook secret');

            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        $event = Webhook::constructEvent($payload, $signature, $webhookSecret);

        // ด่านเดียวกับ isEnabled(): เหตุการณ์จากโหมดทดสอบห้ามออกเหรียญจริงบนเว็บจริง
        if (app()->isProduction() && ! $event->livemode) {
            Log::warning('stripe: ไม่ประมวลผล webhook โหมดทดสอบบนเว็บจริง', ['event_id' => $event->id]);

            return ['status' => 'ignored', 'event_type' => $event->type];
        }

        return match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'checkout.session.expired' => $this->handleCheckoutExpired($event->data->object),
            'charge.refunded' => $this->handleChargeRefunded($event->data->object),
            'charge.dispute.created' => $this->handleDisputeCreated($event->data->object),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
            default => ['status' => 'ignored', 'event_type' => $event->type],
        };
    }

    /**
     * ตรวจสถานะ Stripe Checkout Session.
     */
    public function getPaymentStatus(string $sessionId): array
    {
        // Validate session ID format ป้องกัน API call ที่ไม่จำเป็น
        if (! preg_match('/^cs_(test_|live_)[a-zA-Z0-9]+$/', $sessionId)) {
            throw new RuntimeException('Invalid session ID format.');
        }

        $session = Session::retrieve($sessionId);

        return [
            'status' => $session->payment_status,
            'amount_usd' => $session->amount_total / 100,
        ];
    }

    /**
     * Issue a real Stripe refund for a transaction.
     * Reverse TPIX allocation ทันที + เรียก Stripe Refund API.
     */
    public function refundTransaction(SaleTransaction $transaction): array
    {
        // ป้องกัน double refund
        if ($transaction->status === 'refunded') {
            throw new RuntimeException('This transaction has already been refunded.');
        }

        $stripeSessionId = str_replace('stripe_', '', $transaction->tx_hash);

        // Retrieve the Stripe session to get payment_intent
        $session = Session::retrieve($stripeSessionId);

        if (! $session->payment_intent) {
            throw new RuntimeException('No payment intent found for this session.');
        }

        // Issue refund via Stripe API
        $refund = Refund::create([
            'payment_intent' => $session->payment_intent,
            'reason' => 'requested_by_customer',
        ]);

        // ★ Reverse TPIX allocation ทันที (ไม่รอ webhook)
        DB::transaction(function () use ($transaction) {
            $transaction->update(['status' => 'refunded']);

            $phase = SalePhase::find($transaction->sale_phase_id);
            if ($phase) {
                $phase->decrement('sold', $transaction->tpix_amount);
            }
            $sale = TokenSale::find($transaction->token_sale_id);
            if ($sale) {
                $sale->decrement('total_sold', $transaction->tpix_amount);
                $sale->decrement('total_raised_usd', $transaction->payment_usd_value);
            }
        });

        Log::info('Stripe refund issued + allocation reversed', [
            'transaction_id' => $transaction->uuid,
            'refund_id' => $refund->id,
            'amount' => $refund->amount / 100,
            'tpix_reversed' => $transaction->tpix_amount,
        ]);

        return [
            'refund_id' => $refund->id,
            'status' => $refund->status,
            'amount' => $refund->amount / 100,
        ];
    }

    // =========================================================================
    // Webhook Event Handlers
    // =========================================================================

    /**
     * ชำระเงินสำเร็จ — สร้าง SaleTransaction + อัปเดต counters.
     */
    private function handleCheckoutCompleted(object $session): array
    {
        /*
         * ถาม Stripe กลับไปว่า session นี้จ่ายจริงไหม — ห้ามเชื่อ payload
         *
         * payload ที่เข้ามาเป็นข้อความที่ผู้ส่งเขียนเองได้ ต่อให้ลายเซ็นถูกต้อง
         * ก็ยืนยันได้แค่ "ใครส่ง" ไม่ได้ยืนยัน "จ่ายเงินจริงเท่าไร" ยอดและ
         * metadata ที่ใช้ออกเหรียญจึงต้องมาจากคำตอบของ Stripe เท่านั้น
         */
        try {
            $verified = Session::retrieve($session->id);
        } catch (\Throwable $e) {
            Log::error('stripe: ตรวจสอบ session กับ Stripe ไม่สำเร็จ', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'verification_failed', 'session_id' => $session->id];
        }

        // ต้องจ่ายเงินสำเร็จจริงเท่านั้น
        if (($verified->payment_status ?? '') !== 'paid') {
            Log::warning('stripe: session ยังไม่ได้จ่ายเงิน — ไม่ออกเหรียญ', [
                'session_id' => $session->id,
                'payment_status' => $verified->payment_status ?? null,
            ]);

            return ['status' => 'not_paid', 'session_id' => $session->id];
        }

        $session = $verified;

        $walletAddress = strtolower((string) ($session->metadata->wallet_address ?? ''));
        $phaseId = (int) ($session->metadata->phase_id ?? 0);
        $saleId = (int) ($session->metadata->sale_id ?? 0);
        $pricePerTpix = (float) ($session->metadata->price_per_tpix ?? 0);
        $amountPaid = ((float) ($session->amount_total ?? 0)) / 100;

        /*
         * แยกค่าเหรียญออกจากค่าบริการบัตร — อ่านรายการสินค้าจาก Stripe เอง
         *
         * amount_total รวมค่าบริการบัตรอยู่ด้วย ถ้าเอาไปหารราคาเหรียญตรงๆ
         * ผู้ซื้อจะได้เหรียญเกินไปเท่ากับค่าบริการ อ่านไม่ได้ = ไม่ออกเหรียญ
         * (Stripe ส่ง webhook ซ้ำให้เอง) ไม่ใช่เดาจากยอดรวม
         */
        try {
            $lineItems = Session::allLineItems($session->id, [
                'limit' => 20,
                'expand' => ['data.price.product'],
            ]);
            $tokenUsd = self::tokenCentsFromLineItems($lineItems->data ?? []) / 100;
        } catch (\Throwable $e) {
            Log::error('stripe: อ่านรายการสินค้าของ session ไม่สำเร็จ — ยังไม่ออกเหรียญ', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'verification_failed', 'session_id' => $session->id];
        }

        // กันค่าแปลก: ค่าเหรียญต้องไม่เกินยอดที่จ่ายจริง
        $tokenUsd = min($tokenUsd, $amountPaid);
        $serviceFeeUsd = round($amountPaid - $tokenUsd, 2);

        /*
         * คำนวณจำนวนเหรียญใหม่จากค่าเหรียญที่ Stripe บอกว่าจ่ายจริง หารด้วยราคาของเฟส
         * ไม่ใช้ tpix_amount ที่ฝากมาใน metadata — ค่านั้นเป็นแค่ตัวเลขที่เคย
         * คำนวณตอนสร้าง session และแก้ได้จากภายนอก
         */
        $phaseForPricing = SalePhase::find($phaseId);
        $unitPrice = $phaseForPricing ? (float) $phaseForPricing->price_usd : $pricePerTpix;
        $tpixAmount = $unitPrice > 0 ? round($tokenUsd / $unitPrice, 8) : 0.0;
        $pricePerTpix = $unitPrice;

        // Validate metadata — ป้องกัน corrupted webhook
        if (empty($walletAddress) || $phaseId <= 0 || $saleId <= 0 || $tpixAmount <= 0) {
            Log::error('Stripe checkout completed with invalid metadata', [
                'session_id' => $session->id,
                'metadata' => (array) $session->metadata,
            ]);

            return ['status' => 'invalid_metadata', 'session_id' => $session->id];
        }

        // ตรวจซ้ำ — idempotency check
        $existing = SaleTransaction::where('tx_hash', 'stripe_'.$session->id)->first();
        if ($existing) {
            return ['status' => 'duplicate', 'transaction_id' => $existing->uuid];
        }

        // ใช้ DB::transaction() + pessimistic lock ป้องกัน oversell
        $transaction = DB::transaction(function () use (
            $saleId,
            $phaseId,
            $walletAddress,
            $amountPaid,
            $tokenUsd,
            $serviceFeeUsd,
            $tpixAmount,
            $pricePerTpix,
            $session
        ) {
            // ล็อค phase เพื่อตรวจ allocation
            $phase = SalePhase::lockForUpdate()->find($phaseId);
            if (! $phase || $tpixAmount > $phase->remaining_allocation) {
                Log::error('Stripe checkout: insufficient allocation at confirm time', [
                    'session_id' => $session->id,
                    'requested' => $tpixAmount,
                    'remaining' => $phase?->remaining_allocation ?? 0,
                ]);

                // ยังบันทึก transaction ไว้เป็น pending เพื่อ refund ภายหลัง
                return SaleTransaction::create([
                    'token_sale_id' => $saleId,
                    'sale_phase_id' => $phaseId,
                    'wallet_address' => $walletAddress,
                    'payment_currency' => 'USD_STRIPE',
                    'payment_amount' => $amountPaid,
                    'payment_usd_value' => $tokenUsd,
                    'tpix_amount' => $tpixAmount,
                    'price_per_tpix' => $pricePerTpix,
                    'tx_hash' => 'stripe_'.$session->id,
                    'status' => 'pending',
                    'metadata' => ['needs_refund' => true, 'reason' => 'insufficient_allocation', 'service_fee_usd' => $serviceFeeUsd],
                ]);
            }

            // ดึง vesting start จาก phase/sale (ไม่ใช่ now())
            $sale = TokenSale::find($saleId);
            $vestingStart = $phase->ends_at ?? $sale?->ends_at ?? now();

            $tx = SaleTransaction::create([
                'token_sale_id' => $saleId,
                'sale_phase_id' => $phaseId,
                'wallet_address' => $walletAddress,
                'payment_currency' => 'USD_STRIPE',
                'payment_amount' => $amountPaid,
                'payment_usd_value' => $tokenUsd,
                'tpix_amount' => $tpixAmount,
                'price_per_tpix' => $pricePerTpix,
                'tx_hash' => 'stripe_'.$session->id,
                'status' => 'confirmed',
                'vesting_start_at' => $vestingStart,
                'metadata' => ['service_fee_usd' => $serviceFeeUsd],
            ]);

            // อัปเดต counters atomically (ใช้ locked phase)
            $phase->increment('sold', $tpixAmount);

            if ($sale) {
                $sale->increment('total_sold', $tpixAmount);
                $sale->increment('total_raised_usd', $tokenUsd);
            }

            return $tx;
        });

        // ขายหมดขั้นนี้แล้ว → เปิดขั้นราคาถัดไปทันที (หลัง commit เท่านั้น — ดู rollOverIfExhausted)
        if ($transaction->status === 'confirmed' && $transaction->phase) {
            $this->saleService->rollOverIfExhausted($transaction->phase);
        }

        Log::info('Stripe ICO purchase confirmed', [
            'session_id' => $session->id,
            'wallet' => $walletAddress,
            'tpix' => $tpixAmount,
            'usd' => $amountPaid,
            'status' => $transaction->status,
        ]);

        // ★ Auto-refund ถ้า allocation หมดตอน confirm (ผู้ใช้จ่ายแล้วแต่ได้ไม่ได้เหรียญ)
        if ($transaction->status === 'pending' && ($transaction->metadata['needs_refund'] ?? false)) {
            try {
                $this->refundTransaction($transaction);
                Log::warning('Auto-refund issued — allocation exhausted at confirm time', [
                    'transaction_id' => $transaction->uuid,
                    'session_id' => $session->id,
                ]);

                return ['status' => 'auto_refunded', 'transaction_id' => $transaction->uuid];
            } catch (\Throwable $e) {
                Log::critical('Auto-refund FAILED — manual intervention required', [
                    'transaction_id' => $transaction->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['status' => $transaction->status === 'confirmed' ? 'success' : 'pending_refund', 'transaction_id' => $transaction->uuid];
    }

    /**
     * Checkout session expired — ลูกค้าไม่ชำระเงินทันเวลา.
     */
    private function handleCheckoutExpired(object $session): array
    {
        Log::info('Stripe checkout session expired', [
            'session_id' => $session->id,
            'wallet' => $session->metadata->wallet_address ?? 'unknown',
        ]);

        return ['status' => 'expired', 'session_id' => $session->id];
    }

    /**
     * Charge refunded — คืนเงินจาก Stripe (auto หรือ manual).
     * Reverse TPIX allocation อัตโนมัติ.
     */
    private function handleChargeRefunded(object $charge): array
    {
        // หา transaction จาก payment_intent
        $paymentIntentId = $charge->payment_intent;

        // ค้นหา checkout session ที่ใช้ payment intent นี้
        $sessions = Session::all([
            'payment_intent' => $paymentIntentId,
            'limit' => 1,
        ]);

        if (empty($sessions->data)) {
            Log::warning('Stripe refund: no matching session found', [
                'payment_intent' => $paymentIntentId,
            ]);

            return ['status' => 'no_matching_session'];
        }

        $session = $sessions->data[0];
        $transaction = SaleTransaction::where('tx_hash', 'stripe_'.$session->id)->first();

        if (! $transaction || in_array($transaction->status, ['refunded', 'failed'])) {
            return ['status' => 'already_refunded_or_not_found'];
        }

        // Reverse allocation ใน DB::transaction() — ใช้ pessimistic lock ป้องกัน double-reverse
        DB::transaction(function () use ($transaction) {
            $lockedTx = SaleTransaction::lockForUpdate()->find($transaction->id);
            if (in_array($lockedTx->status, ['refunded', 'failed'])) {
                return; // Already processed (admin refund + webhook racing)
            }

            $lockedTx->update(['status' => 'refunded']);

            $phase = SalePhase::find($lockedTx->sale_phase_id);
            if ($phase) {
                $phase->decrement('sold', $lockedTx->tpix_amount);
            }
            $sale = TokenSale::find($lockedTx->token_sale_id);
            if ($sale) {
                $sale->decrement('total_sold', $lockedTx->tpix_amount);
                $sale->decrement('total_raised_usd', $lockedTx->payment_usd_value);
            }
        });

        Log::warning('Stripe refund processed — TPIX allocation reversed', [
            'transaction_id' => $transaction->uuid,
            'tpix_reversed' => $transaction->tpix_amount,
            'usd_refunded' => $transaction->payment_usd_value,
        ]);

        return ['status' => 'refund_processed', 'transaction_id' => $transaction->uuid];
    }

    /**
     * Dispute/chargeback created — อาจสูญเสียเงิน.
     * Flag transaction และ log alert.
     */
    private function handleDisputeCreated(object $dispute): array
    {
        $paymentIntentId = $dispute->payment_intent;

        $sessions = Session::all([
            'payment_intent' => $paymentIntentId,
            'limit' => 1,
        ]);

        if (! empty($sessions->data)) {
            $session = $sessions->data[0];
            $transaction = SaleTransaction::where('tx_hash', 'stripe_'.$session->id)->first();

            if ($transaction) {
                // ใช้ 'failed' เพราะ 'disputed' ไม่อยู่ใน DB enum
                // บันทึก dispute info ลง metadata
                $transaction->update([
                    'status' => 'failed',
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'dispute_id' => $dispute->id,
                        'dispute_reason' => $dispute->reason ?? 'unknown',
                        'dispute_amount' => ($dispute->amount ?? 0) / 100,
                        'disputed_at' => now()->toIso8601String(),
                    ]),
                ]);

                // Reverse allocation เพราะ dispute = เสียเงินแน่
                $phase = SalePhase::find($transaction->sale_phase_id);
                if ($phase) {
                    $phase->decrement('sold', $transaction->tpix_amount);
                }
                $sale = TokenSale::find($transaction->token_sale_id);
                if ($sale) {
                    $sale->decrement('total_sold', $transaction->tpix_amount);
                    $sale->decrement('total_raised_usd', $transaction->payment_usd_value);
                }

                Log::critical('Stripe DISPUTE created — allocation reversed, requires immediate action', [
                    'transaction_id' => $transaction->uuid,
                    'dispute_id' => $dispute->id,
                    'amount' => ($dispute->amount ?? 0) / 100,
                    'reason' => $dispute->reason ?? 'unknown',
                    'wallet' => $transaction->wallet_address,
                ]);
            }
        }

        return ['status' => 'dispute_flagged', 'dispute_id' => $dispute->id];
    }

    /**
     * Payment failed — log for debugging.
     */
    private function handlePaymentFailed(object $paymentIntent): array
    {
        Log::warning('Stripe payment failed', [
            'payment_intent' => $paymentIntent->id,
            'error' => $paymentIntent->last_payment_error->message ?? 'Unknown error',
            'wallet' => $paymentIntent->metadata->wallet_address ?? 'unknown',
        ]);

        return ['status' => 'payment_failed', 'payment_intent' => $paymentIntent->id];
    }
}
