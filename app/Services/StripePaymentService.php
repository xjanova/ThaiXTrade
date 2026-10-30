<?php

namespace App\Services;

use App\Exceptions\PurchaseException;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\SystemAlert;
use App\Models\TokenSale;
use App\Support\Wei;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Dispute;
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
    /** อายุ Checkout Session — Stripe ปฏิเสธถ้าสั้นกว่า 30 นาที จึงเผื่อเวลาเดินทางของคำขอไว้ 1 นาที */
    private const CHECKOUT_TTL_MINUTES = 31;

    /** พักการ claim ของบัตรเป็นค่าเริ่มต้น — แก้ได้ที่ /admin/settings แท็บ Payment */
    private const DEFAULT_CARD_HOLD_DAYS = 60;

    private const MAX_CARD_HOLD_DAYS = 180;

    private const ALERT_NODE = 'token-sale';

    /** ผลที่ร้านยังถือเงินอยู่ — warning_closed คือ inquiry ที่จบโดยเงินไม่เคยถูกดึง แต่ created ปิดออเดอร์ไปแล้วเหมือนกัน */
    private const DISPUTE_KEPT_STATUSES = [Dispute::STATUS_WON, Dispute::STATUS_WARNING_CLOSED];

    public function __construct(
        private readonly TokenSaleService $saleService,
        private readonly SaleClaimPayoutService $payouts,
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
     * จำนวนวันที่พัก claim ของการซื้อด้วยบัตร (ออดิท 2026-10-29 L3).
     *
     * ผู้ถือบัตรดึงเงินคืนได้หลังรูด ถ้าเหรียญออกไปก่อนจะเสียทั้งเงินและเหรียญ
     * ค่านี้ถูกล็อกลงออเดอร์ตอนซื้อ (metadata.claim_hold_until) แก้ทีหลังไม่กระทบออเดอร์เก่า
     */
    public function cardClaimHoldDays(): int
    {
        $raw = $this->setting('card_claim_hold_days', self::DEFAULT_CARD_HOLD_DAYS);
        $days = is_numeric($raw) ? (int) $raw : self::DEFAULT_CARD_HOLD_DAYS;

        return max(0, min($days, self::MAX_CARD_HOLD_DAYS));
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
         *
         * assertPurchasable ครอบเฟส, whitelist, เพดานต่อกระเป๋า, ขั้นต่ำ/สูงสุด และโควตา
         * 'USD' ผ่านถ้ารอบขายรับ CARD หรือ BANK อย่างใดอย่างหนึ่ง จึงต้องถาม CARD ซ้ำเอง
         */
        $check = $this->saleService->assertPurchasable($walletAddress, $phaseId, 'USD', $amountUsd);
        $this->saleService->assertCurrencyAccepted($sale, 'CARD');

        $tpixAmount = (float) $check['tpix_amount'];

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
            // ค่าเริ่มต้นของ Stripe คือ 24 ชั่วโมง = เปิดค้างไว้ได้เกือบวันแล้วจ่ายราคาเฟสเก่าหลังราคาขึ้น
            'expires_at' => now()->addMinutes(self::CHECKOUT_TTL_MINUTES)->timestamp,
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

        // Stripe ส่งซ้ำได้ตามสัญญาของมัน — ตัวจัดการทุกตัวกันซ้ำด้วยสถานะอยู่แล้ว ด่านนี้แค่ตัดงานซ้ำทิ้งก่อนถาม Stripe
        if (DB::table('stripe_webhook_events')->where('event_id', $event->id)->exists()) {
            return ['status' => 'duplicate_event', 'event_type' => $event->type];
        }

        $result = match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'checkout.session.expired' => $this->handleCheckoutExpired($event->data->object),
            'charge.refunded' => $this->handleChargeRefunded($event->data->object),
            'charge.dispute.created' => $this->handleDisputeCreated($event->data->object),
            'charge.dispute.closed', 'charge.dispute.funds_reinstated' => $this->handleDisputeClosed($event->data->object),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
            default => ['status' => 'ignored', 'event_type' => $event->type],
        };

        // บันทึกหลังทำสำเร็จเท่านั้น — ตัวจัดการที่ล้มต้องเหลือทางให้ Stripe ส่งซ้ำแล้วทำใหม่ได้
        DB::table('stripe_webhook_events')->insertOrIgnore([
            'event_id' => $event->id,
            'type' => $event->type,
            'processed_at' => now(),
        ]);

        return $result;
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
     *
     * ต้องหยุดแถวจ่ายและยืนยันว่าเหรียญยังไม่ออก "ก่อน" เงินเคลื่อน: เงินที่คืนไปแล้วเรียกกลับไม่ได้
     * ถ้า Stripe ล้มหลังขั้นนี้ แถวจ่ายที่ถูกหยุดกู้คืนได้ด้วยปุ่มเข้าคิวใหม่ของแอดมิน
     *
     * @throws PurchaseException เหรียญออกไปแล้ว — ต้องเรียกเหรียญคืนหรือตัดสินใจเอง ระบบคืนเงินให้ไม่ได้
     */
    public function refundTransaction(SaleTransaction $transaction): array
    {
        $halted = DB::transaction(function () use ($transaction) {
            $locked = SaleTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            // ป้องกัน double refund — failed คือออเดอร์ที่ถูก chargeback ไปแล้ว Stripe คืนซ้ำไม่ได้
            if (in_array($locked->status, ['refunded', 'failed'], true)) {
                return false;
            }

            $tokensLeftWei = $this->payouts->haltPayouts($locked, "คืนเงินบัตรของรายการซื้อ {$locked->uuid}");

            // ปฏิเสธ = ไม่มีอะไรเปลี่ยน โยนใต้ธุรกรรมเพื่อย้อนแถวที่เพิ่งหยุด ไม่งั้นเหรียญส่วนที่เหลือของผู้ซื้อหายเงียบ ๆ
            if (bccomp($tokensLeftWei, '0', 0) > 0) {
                throw new PurchaseException('เหรียญของรายการนี้ออกจากคลังแล้ว '.Wei::format($tokensLeftWei).' TPIX — ระบบไม่คืนเงินให้ ต้องตรวจเหรียญก่อนคืนใน Stripe เอง');
            }

            return true;
        });

        if (! $halted) {
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

        // ★ Reverse TPIX allocation ทันที (ไม่รอ webhook) — webhook อาจมาถึงก่อนบรรทัดนี้ด้วยซ้ำ
        $this->alertIfTokensLeft($transaction, $this->closeOrder($transaction, 'refunded', [], 'คืนเงินบัตร'), 'refund');
        $transaction->refresh();

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

    /**
     * ปิดออเดอร์ที่เงินกลับไปหาผู้จ่ายแล้ว — คืนเงิน, คืนอัตโนมัติ, webhook และ dispute ต้องจบที่นี่ทางเดียว.
     *
     * ทั้งสี่ทางชนกันได้ เช่น webhook มาถึงก่อน Refund API ตอบ ทุกอย่างจึงตัดสินใต้ล็อกแถว
     * ตัวนับย้อนเฉพาะแถวที่เคยนับ และแถวที่คืนเงินแล้วคงสถานะ refunded แม้ dispute จะตามมา
     * เหรียญออกไปแล้วไม่คืนโควตา ไม่งั้นขายเหรียญก้อนเดียวกันซ้ำ
     *
     * closed_from กับ counters_reversed จำไว้เฉพาะรอบที่ปิดแถวที่ยังนับอยู่: แถว failed มีทั้งที่ย้อนยอดแล้วและยังไม่ย้อน
     * การกู้ต้องรู้ว่าเป็นแบบไหน ส่วน dispute รอบสองบนแถวที่ปิดแล้วต้องไม่ทับสิ่งที่รอบแรกจำไว้
     *
     * @return string ยอด wei ที่หยุดไม่ทัน
     */
    private function closeOrder(SaleTransaction $transaction, string $status, array $metadata, string $reason): string
    {
        return DB::transaction(function () use ($transaction, $status, $metadata, $reason) {
            $locked = SaleTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            $tokensLeftWei = $this->payouts->haltPayouts($locked, "{$reason} ของรายการซื้อ {$locked->uuid}");
            $reverses = bccomp($tokensLeftWei, '0', 0) === 0;

            $closure = $this->saleService->isCounted($locked)
                ? ['closed_from' => $locked->status, 'counters_reversed' => $reverses]
                : [];

            if ($reverses) {
                $this->saleService->reverseCounters($locked);
            }

            $locked->update([
                'status' => $locked->status === 'refunded' ? 'refunded' : $status,
                'metadata' => array_merge($locked->metadata ?? [], $metadata, $closure),
            ]);

            return $tokensLeftWei;
        });
    }

    /**
     * เหตุที่กู้ออเดอร์นี้ไม่ได้ — null = กู้ได้ หน้าแอดมินกับตอนกดจริงถามที่เดียวกัน.
     *
     * ยังไม่รวมโควตาและเพดานกระเป๋า: สองอย่างนั้นตัดสินได้ใต้ล็อกเท่านั้น
     */
    public function disputeRestoreRefusal(SaleTransaction $transaction): ?string
    {
        return match (true) {
            ! $this->isAwaitingRestore($transaction) => 'ออเดอร์นี้ไม่ได้รอกู้จาก chargeback',
            ! isset($transaction->metadata['closed_from']) => 'ไม่มีบันทึกว่าออเดอร์นี้เคยนับเป็นยอดขาย จึงกู้ไม่ได้ — ต้องคืนเงินใน Stripe',
            default => null,
        };
    }

    /** @return Collection<int, SaleTransaction> ออเดอร์ที่ร้านถือเงินอยู่แต่ผู้ซื้อยังไม่ได้เหรียญ พร้อมเหตุถ้ากู้ไม่ได้ */
    public function ordersAwaitingRestore(): Collection
    {
        return SaleTransaction::where('status', 'failed')
            ->whereIn('metadata->dispute_closed_status', self::DISPUTE_KEPT_STATUSES)
            ->latest()
            ->get()
            ->each(fn (SaleTransaction $tx) => $tx->setAttribute('restore_refusal', $this->disputeRestoreRefusal($tx)));
    }

    /**
     * กู้ออเดอร์ที่ร้านยังถือเงินอยู่กลับเป็นยอดขาย — ทางเดียวที่แถว failed กลับมานับได้.
     *
     * webhook ทำแทนไม่ได้: โควตาอาจขายต่อไปแล้วและเพดานกระเป๋าอาจเปลี่ยน ต้องมีคนตัดสินว่าจะกู้หรือคืนเงิน
     *
     * @throws PurchaseException ปฏิเสธ — ข้อความบอกเหตุและออเดอร์ยังเหมือนเดิมทุกอย่าง
     */
    public function restoreDisputedOrder(SaleTransaction $transaction, ?string $restoredBy): SaleTransaction
    {
        $refusal = $this->disputeRestoreRefusal($transaction);

        if ($refusal !== null) {
            throw new PurchaseException($refusal);
        }

        $disputeId = (string) ($transaction->metadata['dispute_id'] ?? '');

        // ถาม Stripe นอกล็อก ไม่งั้น webhook ของออเดอร์เดียวกันต้องรอเน็ตไปด้วย
        $this->assertPaymentKept($disputeId);

        $order = DB::transaction(function () use ($transaction, $disputeId, $restoredBy) {
            $locked = SaleTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            // กดซ้ำ คืนเงิน และ dispute ใหม่แทรกระหว่างถาม Stripe ได้ทั้งหมด จึงตัดสินซ้ำใต้ล็อก
            $refusal = $this->disputeRestoreRefusal($locked)
                ?? (($locked->metadata['dispute_id'] ?? null) === $disputeId ? null : 'มี dispute ใหม่เข้ามาระหว่างตรวจ — ออเดอร์ยังเหมือนเดิม');

            if ($refusal !== null) {
                throw new PurchaseException($refusal);
            }

            $this->saleService->restoreCounters($locked, (bool) ($locked->metadata['counters_reversed'] ?? false));

            $locked->update([
                'status' => $locked->metadata['closed_from'],
                'metadata' => array_merge($locked->metadata, [
                    'dispute_restored_at' => now()->toIso8601String(),
                    'dispute_restored_by' => $restoredBy,
                ]),
            ]);

            return $locked;
        });

        SystemAlert::resolveKeys(
            self::ALERT_NODE,
            [$this->restoreAlertKey($order), 'sale_card_tokens_left_'.$order->id],
            $restoredBy ?? 'admin',
        );

        // กู้แล้วเฟสอาจหมดพอดี → เปิดขั้นถัดไปหลัง commit เหมือนการซื้อ
        if ($order->phase) {
            $this->saleService->rollOverIfExhausted($order->phase);
        }

        Log::warning('Stripe dispute: order restored', [
            'transaction_id' => $order->uuid,
            'dispute_id' => $disputeId,
            'restored_by' => $restoredBy,
        ]);

        return $order;
    }

    /**
     * ถาม Stripe ซ้ำก่อนคืนเหรียญ — ถามไม่ได้ถือว่าไม่ผ่าน.
     *
     * ระหว่างรอคนกด ผลอาจพลิกหรือถูกคืนเงินใน dashboard ไปแล้ว ถ้าเชื่อที่จำไว้ผู้ซื้อจะได้ทั้งเงินคืนและเหรียญ
     * รายการเดียวมีได้หลาย dispute จึงถามทุกตัว: ตัวล่าสุดชนะไม่ได้แปลว่าตัวก่อนหน้าไม่ได้ดึงเงินไปแล้ว
     */
    private function assertPaymentKept(string $disputeId): void
    {
        try {
            $dispute = Dispute::retrieve(['id' => $disputeId, 'expand' => ['charge']]);
            $onCharge = Dispute::all(['charge' => $dispute->charge->id, 'limit' => 100]);
        } catch (\Throwable $e) {
            Log::error('stripe: ตรวจผล dispute กับ Stripe ไม่สำเร็จ', ['dispute_id' => $disputeId, 'error' => $e->getMessage()]);

            throw new PurchaseException('ตรวจผล dispute กับ Stripe ไม่สำเร็จ — ออเดอร์ยังเหมือนเดิม ลองใหม่อีกครั้ง', 0, $e);
        }

        foreach ([$dispute, ...$onCharge->data] as $each) {
            if (! in_array($each->status, self::DISPUTE_KEPT_STATUSES, true)) {
                throw new PurchaseException('Stripe แจ้งว่าผลของ dispute '.$each->id.' ตอนนี้คือ '.$each->status.' — กู้ไม่ได้');
            }
        }

        if ((int) ($dispute->charge->amount_refunded ?? -1) !== 0) {
            throw new PurchaseException('รายการนี้ถูกคืนเงินใน Stripe แล้ว — กู้ไม่ได้ ถ้ายังคืนไม่ครบให้คืนส่วนที่เหลือใน Stripe เพื่อปิดออเดอร์');
        }
    }

    private function isAwaitingRestore(SaleTransaction $transaction): bool
    {
        return $transaction->status === 'failed'
            && in_array($transaction->metadata['dispute_closed_status'] ?? null, self::DISPUTE_KEPT_STATUSES, true);
    }

    /** กู้กลับมานับยอดแล้ว — ถ้าเงินหายทีหลัง ไม่มีใครปิดออเดอร์นี้ให้นอกจาก webhook */
    private function wasRestored(SaleTransaction $transaction): bool
    {
        return $this->saleService->isCounted($transaction) && isset($transaction->metadata['dispute_restored_at']);
    }

    private function restoreAlertKey(SaleTransaction $transaction): string
    {
        return 'sale_card_dispute_won_'.$transaction->id;
    }

    /** เงินกลับไปแล้วแต่เหรียญออกไปด้วย — เสียทั้งคู่ ระบบแก้เองไม่ได้ ต้องให้คนเห็นทันที */
    private function alertIfTokensLeft(SaleTransaction $transaction, string $tokensLeftWei, string $cause): void
    {
        if (bccomp($tokensLeftWei, '0', 0) <= 0) {
            return;
        }

        SystemAlert::raise(
            self::ALERT_NODE,
            'sale_card_tokens_left_'.$transaction->id,
            'critical',
            'เงินบัตรของรายการซื้อ '.$transaction->uuid.' ถูกดึงกลับ ('.$cause.') แต่เหรียญออกไปแล้ว '.Wei::format($tokensLeftWei).' TPIX',
            ['transaction_id' => $transaction->id, 'wallet' => $transaction->wallet_address, 'cause' => $cause],
        );
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

            // ต้องล้มเป็น 500: ตอบ 200 แล้ว Stripe จะไม่ส่งซ้ำ ผู้ซื้อจ่ายเงินแล้วแต่ไม่ได้เหรียญ
            throw new RuntimeException('Stripe session verification failed.', 0, $e);
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
         * (ล้มเป็น 500 เพื่อให้ Stripe ส่ง webhook ซ้ำ) ไม่ใช่เดาจากยอดรวม
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

            throw new RuntimeException('Stripe line items could not be read.', 0, $e);
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
            // คืนเงินรอบก่อนล้ม — รอบส่งซ้ำของ Stripe คือโอกาสเดียวที่จะลองใหม่เอง
            return $existing->status === 'pending' && ($existing->metadata['needs_refund'] ?? false)
                ? $this->autoRefund($existing)
                : ['status' => 'duplicate', 'transaction_id' => $existing->uuid];
        }

        $holdDays = $this->cardClaimHoldDays();

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
            $session,
            $holdDays
        ) {
            // ล็อค phase เพื่อตรวจ allocation
            $phase = SalePhase::lockForUpdate()->find($phaseId);

            // ด่านตอนสร้าง session ไม่พอ: หลาย session ที่เปิดพร้อมกันผ่านด่านนั้นได้ทุกใบ ต้องตัดสินซ้ำใต้ล็อก
            $refusal = match (true) {
                ! $phase, $tpixAmount > $phase->remaining_allocation => 'insufficient_allocation',
                $this->saleService->exceedsWalletCap($phase, $walletAddress, $tpixAmount) => 'wallet_cap_exceeded',
                default => null,
            };

            if ($refusal !== null) {
                Log::error('Stripe checkout: refused at confirm time', [
                    'session_id' => $session->id,
                    'reason' => $refusal,
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
                    'metadata' => ['needs_refund' => true, 'reason' => $refusal, 'service_fee_usd' => $serviceFeeUsd],
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
                'metadata' => ['service_fee_usd' => $serviceFeeUsd] + ($holdDays > 0
                    ? ['claim_hold_until' => now()->addDays($holdDays)->toIso8601String()]
                    : []),
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
            return $this->autoRefund($transaction);
        }

        return ['status' => $transaction->status === 'confirmed' ? 'success' : 'pending_refund', 'transaction_id' => $transaction->uuid];
    }

    /**
     * คืนเงินออเดอร์ที่ออกเหรียญไม่ได้ — ล้มต้องตอบ 500 ไม่งั้นผู้ซื้อไม่ได้ทั้งเหรียญและเงิน โดยไม่มีใครรู้.
     */
    private function autoRefund(SaleTransaction $transaction): array
    {
        $alertKey = 'sale_card_refund_failed_'.$transaction->id;

        try {
            $this->refundTransaction($transaction);
        } catch (\Throwable $e) {
            Log::critical('Auto-refund FAILED — manual intervention required', [
                'transaction_id' => $transaction->uuid,
                'error' => $e->getMessage(),
            ]);
            SystemAlert::raise(
                self::ALERT_NODE,
                $alertKey,
                'critical',
                'คืนเงินอัตโนมัติของรายการซื้อ '.$transaction->uuid.' ไม่สำเร็จ ผู้ซื้อจ่ายแล้วแต่ไม่ได้เหรียญ — ระบบลองใหม่เมื่อ Stripe ส่งซ้ำ ถ้ายังไม่หายต้องคืนใน Stripe เอง',
                ['transaction_id' => $transaction->id, 'wallet' => $transaction->wallet_address],
            );

            throw new RuntimeException('Stripe auto-refund failed.', 0, $e);
        }

        SystemAlert::resolveKeys(self::ALERT_NODE, [$alertKey]);
        Log::warning('Auto-refund issued — allocation exhausted at confirm time', ['transaction_id' => $transaction->uuid]);

        return ['status' => 'auto_refunded', 'transaction_id' => $transaction->uuid];
    }

    /**
     * ออเดอร์ของ session นี้ — session ของรอบขายที่ยังไม่มีออเดอร์ต้องล้มให้ Stripe ส่ง event นี้ซ้ำ.
     *
     * เงินถูกดึงกลับก่อน checkout.session.completed ทำสำเร็จได้ (completed ล้มแล้วรอส่งซ้ำ)
     * ถ้าจบตรงนี้ event จะถูกจดว่าเสร็จ แล้ว completed รอบถัดไปสร้างออเดอร์ confirmed
     * ผู้ซื้อได้ทั้งเงินคืนและเหรียญ เพราะ payment_status ยังเป็น paid หลังคืนเงิน
     */
    private function orderForSession(object $session): ?SaleTransaction
    {
        $order = SaleTransaction::where('tx_hash', 'stripe_'.$session->id)->first();

        if (! $order && ! empty($session->metadata->sale_id)) {
            throw new RuntimeException('Stripe order is not recorded yet.');
        }

        return $order;
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

        $transaction = $this->orderForSession($sessions->data[0]);

        // ออเดอร์ที่ dispute ปิดไปแล้วแต่เงินคืนครบ: inquiry จบได้ด้วยการคืนเงิน และร้านที่ชนะก็เลือกคืนแทนการกู้ได้ — ต้องปิดทางกู้และเหตุที่รออยู่ด้วย
        if ($transaction && ($charge->refunded ?? false) === true && $transaction->status === 'failed' && isset($transaction->metadata['dispute_id'])) {
            $this->alertIfTokensLeft($transaction, $this->closeOrder($transaction, 'refunded', [], 'คืนเงินหลังปิด dispute'), 'charge.refunded');
            SystemAlert::resolveKeys(self::ALERT_NODE, [$this->restoreAlertKey($transaction)]);

            return ['status' => 'refund_processed', 'transaction_id' => $transaction->uuid];
        }

        if (! $transaction || in_array($transaction->status, ['refunded', 'failed'])) {
            return ['status' => 'already_refunded_or_not_found'];
        }

        /*
         * event นี้มาทุกครั้งที่มีการคืน รวมถึงคืนบางส่วนจาก dashboard
         * ออเดอร์ยังถือเหรียญเต็มจำนวน จะตัดกี่เหรียญเป็นการตัดสินของคน
         * อ่านไม่ได้ว่าคืนครบไหมก็ลงทางนี้: เตือนคนเสียเวลาน้อยกว่าย้อนยอดผิด
         */
        if (($charge->refunded ?? false) !== true) {
            return $this->recordPartialRefund($transaction, (int) ($charge->amount_refunded ?? 0));
        }

        // Reverse allocation ใต้ pessimistic lock ป้องกัน double-reverse (admin refund + webhook racing)
        $this->alertIfTokensLeft(
            $transaction,
            $this->closeOrder($transaction, 'refunded', [], 'คืนเงินจาก Stripe'),
            'charge.refunded',
        );

        Log::warning('Stripe refund processed — TPIX allocation reversed', [
            'transaction_id' => $transaction->uuid,
            'tpix_reversed' => $transaction->tpix_amount,
            'usd_refunded' => $transaction->payment_usd_value,
        ]);

        return ['status' => 'refund_processed', 'transaction_id' => $transaction->uuid];
    }

    /** ยอดที่ Stripe ส่งมาเป็นยอดสะสมของ charge จึงเขียนทับได้ ส่งซ้ำก็ได้ค่าเดิม */
    private function recordPartialRefund(SaleTransaction $transaction, int $refundedCents): array
    {
        DB::transaction(function () use ($transaction, $refundedCents) {
            $locked = SaleTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            $locked->update(['metadata' => array_merge($locked->metadata ?? [], ['refunded_cents' => $refundedCents])]);
        });

        SystemAlert::raise(
            self::ALERT_NODE,
            'sale_card_partial_refund_'.$transaction->id,
            'warning',
            'รายการซื้อ '.$transaction->uuid.' ถูกคืนเงินบางส่วน $'.number_format($refundedCents / 100, 2).' แต่ยังถือเหรียญเต็มจำนวน',
            ['transaction_id' => $transaction->id, 'wallet' => $transaction->wallet_address],
        );

        return ['status' => 'partial_refund', 'transaction_id' => $transaction->uuid];
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
            $transaction = $this->orderForSession($sessions->data[0]);

            if ($transaction) {
                $seen = $transaction->metadata ?? [];

                // created ส่งซ้ำหลังรู้ผลแล้ว: รอบก่อนปิดออเดอร์แล้วล้มก่อนบันทึก event ถ้าทำซ้ำจะลบผลและย้อนออเดอร์ที่กู้ไปแล้ว
                if (($seen['dispute_id'] ?? null) === $dispute->id && ($seen['dispute_closed_status'] ?? null) !== null) {
                    return ['status' => 'dispute_flagged', 'dispute_id' => $dispute->id];
                }

                // ใช้ 'failed' เพราะ 'disputed' ไม่อยู่ใน DB enum — dispute = เสียเงินแน่ จึงย้อนยอดและหยุดเหรียญ
                // บันทึก dispute info ลง metadata
                $this->alertIfTokensLeft($transaction, $this->closeOrder($transaction, 'failed', [
                    'dispute_id' => $dispute->id,
                    'dispute_reason' => $dispute->reason ?? 'unknown',
                    'dispute_amount' => ($dispute->amount ?? 0) / 100,
                    'disputed_at' => now()->toIso8601String(),
                    'dispute_closed_status' => null,
                    'dispute_closed_at' => null,
                ], 'chargeback'), 'charge.dispute.created');

                // ผลของ dispute เก่าใช้กับรอบนี้ไม่ได้ — เหตุเดิมบอกให้กดกู้ซึ่งกดไม่ได้แล้ว
                SystemAlert::resolveKeys(self::ALERT_NODE, [$this->restoreAlertKey($transaction)]);

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
     * Dispute closed — ร้านเสียเงินถาวร หรือยังถือเงินอยู่แต่ผู้ซื้อไม่มีเหรียญ.
     *
     * กรณีหลังออเดอร์ยัง failed และตัวนับไม่ขยับ: ต้องมีคนเห็นเหตุแล้วตัดสิน ดู restoreDisputedOrder
     * กู้ไปแล้วแต่เงินหายทีหลังต้องปิดออเดอร์กลับ ไม่งั้นผู้ซื้อได้ทั้งเงินและเหรียญ
     *
     * ผลอ่านจาก Stripe ทุกครั้ง: event ไม่เรียงลำดับและส่งซ้ำได้ ตัวที่มาทีหลังอาจเก่ากว่าความจริง อ่านไม่ได้ให้ล้มเพื่อให้ Stripe ส่งซ้ำ
     * funds_reinstated ลงที่นี่ด้วย: late win พลิก lost เป็น won ได้ และไม่มีใครรับประกันว่า closed จะมาอีกรอบ
     */
    private function handleDisputeClosed(object $dispute): array
    {
        $sessions = Session::all([
            'payment_intent' => $dispute->payment_intent,
            'limit' => 1,
        ]);

        $transaction = empty($sessions->data) ? null : $this->orderForSession($sessions->data[0]);

        if (! $transaction) {
            return ['status' => 'no_matching_order', 'dispute_id' => $dispute->id];
        }

        $outcome = (string) Dispute::retrieve($dispute->id)->status;
        $kept = in_array($outcome, self::DISPUTE_KEPT_STATUSES, true);

        $order = DB::transaction(function () use ($transaction, $dispute, $outcome, $kept) {
            $locked = SaleTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $current = $locked->metadata['dispute_id'] ?? null;

            // ผลมาถึงก่อน created: ปล่อยผ่านแล้ว created จะปิดออเดอร์ตามมาโดยไม่มีใครรู้ว่าร้านชนะแล้ว
            if ($current === null) {
                throw new RuntimeException('Stripe dispute is not recorded yet.');
            }

            // dispute อื่นของรายการเดียวกันไม่แตะรอบปัจจุบัน เว้นแต่ทำให้ออเดอร์ที่กู้แล้วเสียเงิน
            if ($current !== $dispute->id && ($kept || ! $this->wasRestored($locked))) {
                return null;
            }

            $locked->update(['metadata' => array_merge($locked->metadata, [
                'dispute_id' => $dispute->id,
                'dispute_closed_status' => $outcome,
                'dispute_closed_at' => now()->toIso8601String(),
            ])]);

            return $locked;
        });

        Log::warning('Stripe dispute closed', [
            'transaction_id' => $transaction->uuid,
            'dispute_id' => $dispute->id,
            'outcome' => $outcome,
            'current' => $order !== null,
        ]);

        $closed = ['status' => 'dispute_closed', 'dispute_id' => $dispute->id, 'outcome' => $outcome];

        if ($order === null) {
            return $closed;
        }

        if (! $kept && $this->wasRestored($order)) {
            $this->alertIfTokensLeft($order, $this->closeOrder($order, 'failed', [], 'chargeback หลังกู้ออเดอร์'), 'charge.dispute.closed');

            Log::critical('Stripe dispute lost after restore — order closed again', [
                'transaction_id' => $order->uuid,
                'dispute_id' => $dispute->id,
                'outcome' => $outcome,
            ]);
        }

        if (! $this->isAwaitingRestore($order)) {
            // ผลอาจเพิ่งพลิกจากที่ร้านถือเงินอยู่ — เหตุเดิมบอกให้กดปุ่มที่ไม่มีแล้ว
            SystemAlert::resolveKeys(self::ALERT_NODE, [$this->restoreAlertKey($order)]);

            return $closed;
        }

        SystemAlert::raise(
            self::ALERT_NODE,
            $this->restoreAlertKey($order),
            'critical',
            'ร้านถือเงินของรายการซื้อ '.$order->uuid.' ต่อแล้ว (dispute '.$outcome.') แต่ผู้ซื้อยังไม่ได้เหรียญ '
                .number_format((float) $order->tpix_amount, 2).' TPIX — '
                .($this->disputeRestoreRefusal($order) ?? 'super_admin ต้องกดกู้ออเดอร์ที่หน้า Token Sales หรือคืนเงินใน Stripe'),
            ['transaction_id' => $order->id, 'wallet' => $order->wallet_address, 'dispute_id' => $dispute->id],
        );

        return ['status' => 'dispute_restore_required', 'transaction_id' => $order->uuid];
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
