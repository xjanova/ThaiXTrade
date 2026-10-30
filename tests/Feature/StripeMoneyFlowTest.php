<?php

namespace Tests\Feature;

use App\Exceptions\PurchaseException;
use App\Models\AdminUser;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\SystemAlert;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Models\WhitelistEntry;
use App\Services\SaleClaimPayoutService;
use App\Services\StripePaymentService;
use App\Support\Wei;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakesStripeApi;
use Tests\TestCase;

/**
 * เส้นเงินของบัตร: ตัวนับยอดขาย, การคืนเงิน, chargeback และกติการอบขาย (ออดิท 2026-10-29 L2 L3 L5 L6).
 *
 * กฎที่คุม: แถวหนึ่งนับยอดขายครั้งเดียว ย้อนได้ครั้งเดียว ไม่ว่า webhook จะมาซ้ำหรือชนกันแบบไหน
 * และเหรียญต้องไม่ออกจากออเดอร์ที่เงินถูกดึงกลับไปแล้ว
 */
class StripeMoneyFlowTest extends TestCase
{
    use FakesStripeApi, RefreshDatabase;

    private const BUYER = '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    private const SESSION = 'cs_test_paid';

    private SalePhase $phase;

    private TokenSale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'accept_currencies' => ['CARD'],
            'accept_chain_id' => 4289,
            'total_sold' => 5000,
            'total_raised_usd' => 500,
        ]);

        $this->phase = SalePhase::create([
            'token_sale_id' => $this->sale->id,
            'name' => 'Public Sale',
            'slug' => 'public-sale',
            'phase_order' => 1,
            'price_usd' => 0.10,
            'allocation' => 1000000,
            'sold' => 5000,
            'min_purchase' => 100,
            'max_purchase' => 1000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'active',
        ]);

        $this->fakeStripe([
            'GET /v1/checkout/sessions/'.self::SESSION => $this->paidSession(),
            'GET /v1/checkout/sessions/'.self::SESSION.'/line_items' => $this->lineItems(1000),
            'GET /v1/checkout/sessions' => ['object' => 'list', 'data' => [$this->paidSession()], 'has_more' => false],
            'POST /v1/refunds' => ['id' => 're_1', 'object' => 'refund', 'amount' => 1030, 'status' => 'succeeded'],
            'POST /v1/checkout/sessions' => ['id' => 'cs_test_new', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/new'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownStripe();

        parent::tearDown();
    }

    private function stripe(): StripePaymentService
    {
        return app(StripePaymentService::class);
    }

    private function paidSession(array $overrides = []): array
    {
        return array_merge([
            'id' => self::SESSION,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_1',
            'amount_total' => 1030,
            'metadata' => [
                'wallet_address' => self::BUYER,
                'phase_id' => $this->phase->id,
                'sale_id' => $this->sale->id,
                'price_per_tpix' => '0.10',
            ],
        ], $overrides);
    }

    private function lineItems(int $tokenCents): array
    {
        return ['object' => 'list', 'has_more' => false, 'data' => [
            ['object' => 'item', 'amount_total' => $tokenCents, 'price' => ['product' => ['metadata' => ['kind' => 'tpix_token']]]],
        ]];
    }

    private function deliver(string $type, array $object, string $eventId = 'evt_1'): array
    {
        return $this->stripe()->handleWebhook(...$this->signedStripeEvent($eventId, $type, $object));
    }

    /** ออเดอร์บัตรที่นับยอดแล้ว — ยอดตั้งต้นของเฟสรวมแถวนี้อยู่แล้ว (5,000 TPIX ที่ $0.10) */
    private function confirmedOrder(array $overrides = []): SaleTransaction
    {
        return SaleTransaction::create(array_merge([
            'token_sale_id' => $this->sale->id,
            'sale_phase_id' => $this->phase->id,
            'wallet_address' => self::BUYER,
            'payment_currency' => 'USD_STRIPE',
            'payment_amount' => 515,
            'payment_usd_value' => 500,
            'tpix_amount' => 5000,
            'price_per_tpix' => 0.10,
            'tx_hash' => 'stripe_'.self::SESSION,
            'status' => 'confirmed',
        ], $overrides));
    }

    private function assertCounters(float $sold, float $raised): void
    {
        $this->assertEqualsWithDelta($sold, (float) $this->phase->fresh()->sold, 0.0001, 'phase.sold');
        $this->assertEqualsWithDelta($sold, (float) $this->sale->fresh()->total_sold, 0.0001, 'sale.total_sold');
        $this->assertEqualsWithDelta($raised, (float) $this->sale->fresh()->total_raised_usd, 0.0001, 'sale.total_raised_usd');
    }

    private function payout(SaleTransaction $tx, array $overrides = [], string $suffix = '1'): TreasuryPayout
    {
        return TreasuryPayout::create(array_merge([
            'idempotency_key' => "sale-claim-{$tx->uuid}-{$suffix}",
            'to_address' => $tx->wallet_address,
            'amount_wei' => Wei::toWei('1000'),
            'purpose' => 'token_sale',
            'origin' => TreasuryPayout::ORIGIN_SALE_CLAIM,
            'status' => TreasuryPayout::STATUS_PENDING,
        ], $overrides));
    }

    // ── L2: ตัวนับยอดขาย ──────────────────────────────────────────────────

    #[Test]
    public function auto_refund_of_an_uncounted_order_leaves_the_counters_alone(): void
    {
        $this->phase->update(['allocation' => 5005]); // เหลือ 5 TPIX ไม่พอ 100

        $result = $this->deliver('checkout.session.completed', $this->paidSession());

        $this->assertSame('auto_refunded', $result['status']);
        $this->assertSame('refunded', SaleTransaction::first()->status);
        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function refund_racing_the_webhook_reverses_the_counters_once(): void
    {
        $order = $this->confirmedOrder();

        // webhook ของ Stripe มาถึงก่อน API ตอบ — เกิดได้จริงเพราะ Stripe ยิงทันทีที่คืนเงิน
        $this->stripeRoutes['POST /v1/refunds'] = function () {
            $this->deliver('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 1030, 'amount_refunded' => 1030, 'refunded' => true], 'evt_race');

            return ['id' => 're_1', 'object' => 'refund', 'amount' => 1030, 'status' => 'succeeded'];
        };

        $this->stripe()->refundTransaction($order);

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertCounters(0, 0);
    }

    #[Test]
    public function dispute_delivered_twice_reverses_the_counters_once(): void
    {
        $order = $this->confirmedOrder();
        $dispute = ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_1', 'amount' => 1030, 'reason' => 'fraudulent'];

        $this->deliver('charge.dispute.created', $dispute, 'evt_a');
        $this->deliver('charge.dispute.created', $dispute, 'evt_b');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame('dp_1', $order->fresh()->metadata['dispute_id']);
        $this->assertCounters(0, 0);
    }

    #[Test]
    public function dispute_after_a_refund_keeps_the_refunded_status_and_counters(): void
    {
        $order = $this->confirmedOrder(['status' => 'refunded']);
        $this->phase->update(['sold' => 0]);
        $this->sale->update(['total_sold' => 0, 'total_raised_usd' => 0]);

        $this->deliver('charge.dispute.created', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_1', 'amount' => 1030]);

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('dp_1', $order->fresh()->metadata['dispute_id']);
        $this->assertCounters(0, 0);
    }

    #[Test]
    public function partial_refund_is_recorded_without_treating_the_order_as_refunded(): void
    {
        $order = $this->confirmedOrder();

        $result = $this->deliver('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 1030, 'amount_refunded' => 300, 'refunded' => false]);

        $this->assertSame('partial_refund', $result['status']);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(300, $order->fresh()->metadata['refunded_cents']);
        $this->assertCounters(5000, 500);
        $this->assertTrue(SystemAlert::where('alert_key', 'sale_card_partial_refund_'.$order->id)->where('status', 'active')->exists());
    }

    #[Test]
    public function the_same_event_id_is_processed_once(): void
    {
        $this->confirmedOrder();
        $charge = ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 1030, 'amount_refunded' => 1030, 'refunded' => true];

        $this->deliver('charge.refunded', $charge, 'evt_same');
        $before = count($this->stripeCalls);
        $second = $this->deliver('charge.refunded', $charge, 'evt_same');

        $this->assertSame('duplicate_event', $second['status']);
        $this->assertCount($before, $this->stripeCalls, 'event ซ้ำต้องไม่ถาม Stripe อีก');
        $this->assertCounters(0, 0);
    }

    #[Test]
    public function a_refund_that_beats_the_order_is_retried_so_tokens_never_outlive_the_money(): void
    {
        $charge = ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 1030, 'amount_refunded' => 1030, 'refunded' => true];

        // completed ล้มรอบแรก แล้วซัพพอร์ตคืนเงินไปก่อน — event นี้ต้องไม่ถูกจดว่าเสร็จ
        $this->assertThrows(fn () => $this->deliver('charge.refunded', $charge, 'evt_early'), RuntimeException::class);

        $this->deliver('checkout.session.completed', $this->paidSession(), 'evt_completed');
        $this->assertSame('refund_processed', $this->deliver('charge.refunded', $charge, 'evt_early')['status']);

        $this->assertSame('refunded', SaleTransaction::sole()->status);
        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function a_dispute_that_beats_the_order_is_retried_too(): void
    {
        $dispute = ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_1', 'amount' => 1030];

        $this->assertThrows(fn () => $this->deliver('charge.dispute.created', $dispute, 'evt_early'), RuntimeException::class);

        $this->deliver('checkout.session.completed', $this->paidSession(), 'evt_completed');
        $this->deliver('charge.dispute.created', $dispute, 'evt_early');

        $this->assertSame('failed', SaleTransaction::sole()->status);
        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function a_refund_of_a_session_that_is_not_a_token_sale_stays_a_no_op(): void
    {
        $this->stripeRoutes['GET /v1/checkout/sessions'] = ['object' => 'list', 'data' => [$this->paidSession(['metadata' => []])], 'has_more' => false];

        $result = $this->deliver('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 1030, 'amount_refunded' => 1030, 'refunded' => true]);

        $this->assertSame('already_refunded_or_not_found', $result['status']);
    }

    #[Test]
    public function a_failed_auto_refund_alerts_and_the_stripe_retry_finishes_it(): void
    {
        $this->phase->update(['allocation' => 5000]);
        $refund = $this->stripeRoutes['POST /v1/refunds'];
        unset($this->stripeRoutes['POST /v1/refunds']);

        $this->assertThrows(fn () => $this->deliver('checkout.session.completed', $this->paidSession(), 'evt_paid'), RuntimeException::class);

        $order = SaleTransaction::sole();
        $this->assertSame('pending', $order->status);
        $alert = fn () => SystemAlert::where('alert_key', 'sale_card_refund_failed_'.$order->id)->where('status', 'active');
        $this->assertSame('critical', $alert()->first()?->severity);

        $this->stripeRoutes['POST /v1/refunds'] = $refund;

        $this->assertSame('auto_refunded', $this->deliver('checkout.session.completed', $this->paidSession(), 'evt_paid')['status']);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertFalse($alert()->exists());
    }

    #[Test]
    public function a_failed_read_from_stripe_stays_retryable(): void
    {
        unset($this->stripeRoutes['GET /v1/checkout/sessions/'.self::SESSION]);

        // ต้องล้มเป็น exception เพื่อให้ webhook ตอบ 500 แล้ว Stripe ส่งซ้ำ — ตอบ 200 แล้วเงินเข้าแต่ไม่มีเหรียญ
        $this->assertThrows(
            fn () => $this->deliver('checkout.session.completed', $this->paidSession(), 'evt_retry'),
            RuntimeException::class,
        );

        $this->assertSame(0, SaleTransaction::count());

        $this->stripeRoutes['GET /v1/checkout/sessions/'.self::SESSION] = $this->paidSession();

        $this->assertSame('success', $this->deliver('checkout.session.completed', $this->paidSession(), 'evt_retry')['status']);
        $this->assertSame('confirmed', SaleTransaction::first()->status);
    }

    // ── L3: chargeback หลังได้เหรียญ ─────────────────────────────────────

    #[Test]
    public function card_purchase_cannot_claim_until_the_hold_ends(): void
    {
        SiteSetting::set('payment', 'card_claim_hold_days', '60');
        $this->phase->update(['vesting_cliff_days' => 365]); // ให้ยอดหลังพักเหลือส่วน TGE ล้วน

        $this->deliver('checkout.session.completed', $this->paidSession());

        $order = SaleTransaction::first();
        $this->assertSame(0.0, (float) $order->claimable_amount, 'TGE ของบัตรต้องยังไม่ปลดระหว่างช่วง chargeback');

        $this->travel(61)->days();

        $this->assertEqualsWithDelta(20.0, (float) $order->fresh()->claimable_amount, 0.0001);
    }

    #[Test]
    public function the_hold_is_fixed_at_purchase_time_and_can_be_switched_off(): void
    {
        SiteSetting::set('payment', 'card_claim_hold_days', '0');

        $this->deliver('checkout.session.completed', $this->paidSession());

        $this->assertEqualsWithDelta(20.0, (float) SaleTransaction::first()->claimable_amount, 0.0001);
    }

    #[Test]
    public function refund_stops_payouts_that_were_not_signed_yet(): void
    {
        $order = $this->confirmedOrder();
        $pending = $this->payout($order);
        $approved = $this->payout($order, ['status' => TreasuryPayout::STATUS_APPROVED], '2');
        $waiting = $this->payout($order, ['status' => TreasuryPayout::STATUS_BROADCASTING], '3');

        $this->stripe()->refundTransaction($order);

        foreach ([$pending, $approved, $waiting] as $payout) {
            $this->assertSame(TreasuryPayout::STATUS_REJECTED, $payout->fresh()->status);
        }
        $this->assertSame('refunded', $order->fresh()->status);
    }

    private function signInAdmin(): void
    {
        $admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);
    }

    #[Test]
    public function admin_refund_stops_payouts_that_were_not_signed_yet(): void
    {
        $this->signInAdmin();
        $order = $this->confirmedOrder();
        $pending = $this->payout($order);

        $this->post("/admin/token-sales/refund/{$order->id}")->assertSessionHas('success');

        $this->assertSame(TreasuryPayout::STATUS_REJECTED, $pending->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertCounters(0, 0);
    }

    #[Test]
    public function admin_refund_is_refused_when_tokens_already_left(): void
    {
        $this->signInAdmin();
        $order = $this->confirmedOrder();
        $this->payout($order, ['status' => TreasuryPayout::STATUS_CONFIRMED, 'tx_hash' => '0x'.str_repeat('a', 64), 'block_number' => 10]);

        $this->post("/admin/token-sales/refund/{$order->id}")->assertSessionHas('error');

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function a_refused_admin_refund_leaves_the_remaining_payouts_alone(): void
    {
        $this->signInAdmin();
        $order = $this->confirmedOrder();
        $this->payout($order, ['status' => TreasuryPayout::STATUS_CONFIRMED, 'tx_hash' => '0x'.str_repeat('a', 64), 'block_number' => 10]);
        $pending = $this->payout($order, [], '2');

        $this->post("/admin/token-sales/refund/{$order->id}")->assertSessionHas('error');

        $this->assertSame(TreasuryPayout::STATUS_PENDING, $pending->fresh()->status);
    }

    #[Test]
    public function finance_refund_says_why_when_tokens_already_left(): void
    {
        $this->signInAdmin();
        $order = $this->confirmedOrder();
        $this->payout($order, ['status' => TreasuryPayout::STATUS_CONFIRMED, 'tx_hash' => '0x'.str_repeat('a', 64), 'block_number' => 10]);

        $this->postJson('/admin/finance/refund', ['transaction_id' => $order->id])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame([], $this->stripeCallsTo('POST /v1/refunds'));
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    #[Test]
    public function a_refused_refund_leaves_the_remaining_payouts_alone(): void
    {
        $order = $this->confirmedOrder();
        $this->payout($order, ['status' => TreasuryPayout::STATUS_CONFIRMED, 'tx_hash' => '0x'.str_repeat('a', 64), 'block_number' => 10]);
        $pending = $this->payout($order, [], '2');

        $this->assertThrows(fn () => $this->stripe()->refundTransaction($order), PurchaseException::class);

        $this->assertSame(TreasuryPayout::STATUS_PENDING, $pending->fresh()->status);
    }

    #[Test]
    public function quota_stays_sold_when_the_money_came_back_after_the_tokens_left(): void
    {
        $order = $this->confirmedOrder();
        $this->payout($order, ['status' => TreasuryPayout::STATUS_CONFIRMED, 'tx_hash' => '0x'.str_repeat('a', 64), 'block_number' => 10]);

        $this->deliver('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 1030, 'amount_refunded' => 1030, 'refunded' => true]);

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function refund_is_refused_before_money_moves_when_tokens_already_left(): void
    {
        $order = $this->confirmedOrder();
        $this->payout($order, ['status' => TreasuryPayout::STATUS_CONFIRMED, 'tx_hash' => '0x'.str_repeat('a', 64), 'block_number' => 10]);

        $this->assertThrows(fn () => $this->stripe()->refundTransaction($order), PurchaseException::class);

        $this->assertSame([], $this->stripeCallsTo('POST /v1/refunds'));
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function payout_being_signed_counts_as_gone_and_stale_rows_are_not_touched(): void
    {
        $order = $this->confirmedOrder();
        $signing = $this->payout($order, ['status' => TreasuryPayout::STATUS_BROADCASTING, 'claimed_at' => now(), 'claim_token' => 'tok']);
        $dead = $this->payout($order, ['status' => TreasuryPayout::STATUS_REJECTED, 'failure_reason' => 'เดิม'], '2');

        $left = app(SaleClaimPayoutService::class)->haltPayouts($order, 'ทดสอบ');

        $this->assertSame(Wei::toWei('1000'), $left);
        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $signing->fresh()->status);
        $this->assertSame('เดิม', $dead->fresh()->failure_reason);
    }

    #[Test]
    public function dispute_stops_unsigned_payouts_and_raises_an_alert_for_tokens_that_left(): void
    {
        $order = $this->confirmedOrder();
        $pending = $this->payout($order);
        $this->payout($order, ['status' => TreasuryPayout::STATUS_CONFIRMED, 'tx_hash' => '0x'.str_repeat('b', 64), 'block_number' => 10], '2');

        $this->deliver('charge.dispute.created', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_1', 'amount' => 1030]);

        $this->assertSame(TreasuryPayout::STATUS_REJECTED, $pending->fresh()->status);
        $alert = SystemAlert::where('alert_key', 'sale_card_tokens_left_'.$order->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
    }

    #[Test]
    public function dashboard_refund_webhook_stops_unsigned_payouts_too(): void
    {
        $order = $this->confirmedOrder();
        $pending = $this->payout($order);

        $this->deliver('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 1030, 'amount_refunded' => 1030, 'refunded' => true]);

        $this->assertSame(TreasuryPayout::STATUS_REJECTED, $pending->fresh()->status);
    }

    // ── L5: กติการอบขายของบัตร ───────────────────────────────────────────

    #[Test]
    public function checkout_refuses_a_sale_that_does_not_accept_cards(): void
    {
        $this->sale->update(['accept_currencies' => ['BANK']]);

        $this->expectException(PurchaseException::class);

        try {
            $this->stripe()->createCheckoutSession(100, self::BUYER, $this->phase->id);
        } finally {
            $this->assertSame([], $this->stripeCallsTo('POST /v1/checkout/sessions'));
        }
    }

    #[Test]
    public function checkout_refuses_a_wallet_outside_the_whitelist(): void
    {
        $this->phase->update(['whitelist_only' => true]);

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('not whitelisted');

        $this->stripe()->createCheckoutSession(100, self::BUYER, $this->phase->id);
    }

    #[Test]
    public function checkout_refuses_a_purchase_over_the_wallet_allocation(): void
    {
        $this->phase->update(['whitelist_only' => true]);
        WhitelistEntry::create(['sale_phase_id' => $this->phase->id, 'wallet_address' => self::BUYER, 'max_allocation' => 1500]);
        $this->confirmedOrder(['tpix_amount' => 1000]);

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('exceeds your allocation');

        $this->stripe()->createCheckoutSession(100, self::BUYER, $this->phase->id); // 1,000 TPIX
    }

    #[Test]
    public function refunded_purchases_do_not_use_up_the_wallet_allocation(): void
    {
        $this->phase->update(['whitelist_only' => true]);
        WhitelistEntry::create(['sale_phase_id' => $this->phase->id, 'wallet_address' => self::BUYER, 'max_allocation' => 1500]);
        $this->confirmedOrder(['tpix_amount' => 1000, 'status' => 'refunded']);

        $result = $this->stripe()->createCheckoutSession(100, self::BUYER, $this->phase->id);

        $this->assertSame('cs_test_new', $result['session_id']);
    }

    #[Test]
    public function webhook_over_the_wallet_allocation_refunds_instead_of_issuing(): void
    {
        $this->phase->update(['whitelist_only' => true]);
        WhitelistEntry::create(['sale_phase_id' => $this->phase->id, 'wallet_address' => self::BUYER, 'max_allocation' => 50]);

        $result = $this->deliver('checkout.session.completed', $this->paidSession());

        $this->assertSame('auto_refunded', $result['status']);
        $order = SaleTransaction::first();
        $this->assertSame('refunded', $order->status);
        $this->assertSame('wallet_cap_exceeded', $order->metadata['reason']);
        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function the_api_shows_the_rule_that_refused_the_purchase(): void
    {
        $this->phase->update(['whitelist_only' => true]);
        SiteSetting::set('payment', 'stripe_enabled', '1', 'boolean');

        $this->withoutMiddleware()->postJson('/api/v1/token-sale/stripe/checkout', [
            'wallet_address' => self::BUYER,
            'phase_id' => $this->phase->id,
            'amount_usd' => 100,
        ])->assertStatus(409)->assertJsonPath('error.code', 'NOT_PURCHASABLE');
    }

    // ── L6: ล็อกราคาเก่า ─────────────────────────────────────────────────

    #[Test]
    public function checkout_session_expires_soon_so_a_price_cannot_be_held_for_a_day(): void
    {
        $this->stripe()->createCheckoutSession(100, self::BUYER, $this->phase->id);

        $params = $this->stripeCallsTo('POST /v1/checkout/sessions')[0]['params'];
        $this->assertArrayHasKey('expires_at', $params);
        // Stripe ปฏิเสธถ้าสั้นกว่า 30 นาที — ต้องเผื่อเวลาเดินทางของคำขอไว้ด้วย
        $this->assertGreaterThan(now()->addMinutes(30)->timestamp, (int) $params['expires_at']);
        $this->assertLessThanOrEqual(now()->addMinutes(35)->timestamp, (int) $params['expires_at']);
    }
}
