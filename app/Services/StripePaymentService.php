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
    public function __construct()
    {
        // ใช้ key จาก admin SiteSetting ก่อน, fallback เป็น .env
        $secretKey = SiteSetting::get('stripe', 'stripe_secret_key')
            ?: config('services.stripe.secret');

        Stripe::setApiKey($secretKey);
    }

    /**
     * ตรวจสอบว่า Stripe เปิดใช้งานอยู่หรือไม่.
     */
    public function isEnabled(): bool
    {
        return (bool) SiteSetting::get('stripe', 'stripe_enabled', true);
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

        $phase = SalePhase::findOrFail($phaseId);
        $sale = $phase->tokenSale;

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

        // สร้าง Stripe Checkout Session
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => "TPIX Token - {$phase->name}",
                        'description' => number_format($tpixAmount, 2).' TPIX @ $'.$phase->price_usd.'/token',
                    ],
                    'unit_amount' => (int) round($amountUsd * 100), // Stripe ใช้ cents
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => url('/token-sale?payment=success&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => url('/token-sale?payment=cancelled'),
            'metadata' => [
                'wallet_address' => $walletAddress,
                'phase_id' => $phaseId,
                'sale_id' => $sale->id,
                'tpix_amount' => $tpixAmount,
                'price_per_tpix' => $phase->price_usd,
            ],
        ]);

        return [
            'session_id' => $session->id,
            'url' => $session->url,
        ];
    }

    /**
     * จัดการ Stripe Webhook — รองรับหลาย event types.
     */
    public function handleWebhook(string $payload, string $signature): array
    {
        $webhookSecret = SiteSetting::get('stripe', 'stripe_webhook_secret')
            ?: config('services.stripe.webhook_secret');

        $event = Webhook::constructEvent($payload, $signature, $webhookSecret);

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
        $walletAddress = $session->metadata->wallet_address ?? '';
        $phaseId = (int) ($session->metadata->phase_id ?? 0);
        $saleId = (int) ($session->metadata->sale_id ?? 0);
        $tpixAmount = (float) ($session->metadata->tpix_amount ?? 0);
        $pricePerTpix = (float) ($session->metadata->price_per_tpix ?? 0);
        $amountPaid = $session->amount_total / 100;

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
            $saleId, $phaseId, $walletAddress, $amountPaid, $tpixAmount, $pricePerTpix, $session
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
                    'payment_usd_value' => $amountPaid,
                    'tpix_amount' => $tpixAmount,
                    'price_per_tpix' => $pricePerTpix,
                    'tx_hash' => 'stripe_'.$session->id,
                    'status' => 'pending',
                    'metadata' => ['needs_refund' => true, 'reason' => 'insufficient_allocation'],
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
                'payment_usd_value' => $amountPaid,
                'tpix_amount' => $tpixAmount,
                'price_per_tpix' => $pricePerTpix,
                'tx_hash' => 'stripe_'.$session->id,
                'status' => 'confirmed',
                'vesting_start_at' => $vestingStart,
            ]);

            // อัปเดต counters atomically (ใช้ locked phase)
            $phase->increment('sold', $tpixAmount);

            if ($sale) {
                $sale->increment('total_sold', $tpixAmount);
                $sale->increment('total_raised_usd', $amountPaid);
            }

            return $tx;
        });

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
