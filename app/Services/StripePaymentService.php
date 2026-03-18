<?php

namespace App\Services;

use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * StripePaymentService — ระบบชำระเงินด้วย Stripe สำหรับ ICO Token Sale.
 *
 * รองรับการซื้อ TPIX ด้วยบัตรเครดิต/เดบิต ผ่าน Stripe Checkout.
 * เหรียญ TPIX แลกเปลี่ยนได้กับ USDT เท่านั้น — ไม่มีการถอนเป็นเงินสด.
 */
class StripePaymentService
{
    public function __construct(
        private PriceFeedService $priceFeed,
    ) {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * สร้าง Stripe Checkout Session สำหรับซื้อ TPIX.
     *
     * @param  float   $amountUsd     จำนวนเงิน USD ที่ต้องการจ่าย
     * @param  string  $walletAddress wallet address ของผู้ซื้อ
     * @param  int     $phaseId       ID ของ sale phase
     * @return array   ['session_id' => string, 'url' => string]
     */
    public function createCheckoutSession(float $amountUsd, string $walletAddress, int $phaseId): array
    {
        $phase = SalePhase::findOrFail($phaseId);
        $sale = $phase->tokenSale;

        // คำนวณจำนวน TPIX ที่จะได้รับ
        $tpixAmount = $amountUsd / $phase->price_usd;

        // ตรวจสอบ allocation เหลือพอ
        $remaining = $phase->allocation - $phase->sold;
        if ($tpixAmount > $remaining) {
            throw new \RuntimeException('Insufficient allocation remaining in this phase.');
        }

        // ตรวจสอบ min/max purchase
        if ($tpixAmount < $phase->min_purchase) {
            throw new \RuntimeException("Minimum purchase is {$phase->min_purchase} TPIX.");
        }
        if ($phase->max_purchase > 0 && $tpixAmount > $phase->max_purchase) {
            throw new \RuntimeException("Maximum purchase is {$phase->max_purchase} TPIX.");
        }

        // สร้าง Stripe Checkout Session
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => "TPIX Token - {$phase->name}",
                        'description' => number_format($tpixAmount, 2) . ' TPIX @ $' . $phase->price_usd . '/token',
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
     * จัดการ Stripe Webhook — เมื่อชำระเงินสำเร็จจะสร้าง SaleTransaction.
     */
    public function handleWebhook(string $payload, string $signature): array
    {
        $webhookSecret = config('services.stripe.webhook_secret');

        $event = Webhook::constructEvent($payload, $signature, $webhookSecret);

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            // ดึงข้อมูลจาก metadata
            $walletAddress = $session->metadata->wallet_address ?? '';
            $phaseId = $session->metadata->phase_id ?? 0;
            $saleId = $session->metadata->sale_id ?? 0;
            $tpixAmount = (float) ($session->metadata->tpix_amount ?? 0);
            $pricePerTpix = (float) ($session->metadata->price_per_tpix ?? 0);
            $amountPaid = $session->amount_total / 100; // cents → USD

            // ตรวจซ้ำ — ถ้า session ID นี้มีอยู่แล้ว ข้าม
            $existing = SaleTransaction::where('tx_hash', 'stripe_' . $session->id)->first();
            if ($existing) {
                return ['status' => 'duplicate', 'transaction_id' => $existing->uuid];
            }

            // สร้าง SaleTransaction
            $transaction = SaleTransaction::create([
                'token_sale_id' => $saleId,
                'sale_phase_id' => $phaseId,
                'wallet_address' => $walletAddress,
                'payment_currency' => 'USD_STRIPE',
                'payment_amount' => $amountPaid,
                'payment_usd_value' => $amountPaid,
                'tpix_amount' => $tpixAmount,
                'price_per_tpix' => $pricePerTpix,
                'tx_hash' => 'stripe_' . $session->id,
                'status' => 'confirmed',
                'vesting_start_at' => now(),
            ]);

            // อัปเดต phase + sale counters
            $phase = SalePhase::find($phaseId);
            if ($phase) {
                $phase->increment('sold', $tpixAmount);
            }
            $sale = TokenSale::find($saleId);
            if ($sale) {
                $sale->increment('total_sold', $tpixAmount);
                $sale->increment('total_raised_usd', $amountPaid);
            }

            Log::info('Stripe ICO purchase confirmed', [
                'session_id' => $session->id,
                'wallet' => $walletAddress,
                'tpix' => $tpixAmount,
                'usd' => $amountPaid,
            ]);

            return ['status' => 'success', 'transaction_id' => $transaction->uuid];
        }

        return ['status' => 'ignored', 'event_type' => $event->type];
    }

    /**
     * ตรวจสถานะ Stripe Checkout Session.
     */
    public function getPaymentStatus(string $sessionId): array
    {
        $session = Session::retrieve($sessionId);

        return [
            'status' => $session->payment_status, // paid, unpaid, no_payment_required
            'amount_usd' => $session->amount_total / 100,
            'wallet_address' => $session->metadata->wallet_address ?? '',
            'tpix_amount' => (float) ($session->metadata->tpix_amount ?? 0),
        ];
    }
}
