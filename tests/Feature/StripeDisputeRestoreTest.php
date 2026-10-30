<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SystemAlert;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Models\WhitelistEntry;
use App\Services\SaleClaimPayoutService;
use App\Services\StripePaymentService;
use App\Support\Wei;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakesStripeApi;
use Tests\TestCase;

/**
 * ทางกลับของออเดอร์บัตรที่ร้านยังถือเงินอยู่หลัง chargeback.
 *
 * กฎที่คุม: webhook ห้ามคืนออเดอร์เอง เพราะโควตาอาจขายต่อไปแล้วและเพดานกระเป๋าอาจเปลี่ยน
 * การกู้ต้องเป็นคำสั่งของ super_admin ที่ตรวจซ้ำใต้ล็อก และถ้าปฏิเสธต้องไม่มีอะไรเปลี่ยน
 */
class StripeDisputeRestoreTest extends TestCase
{
    use FakesStripeApi, RefreshDatabase;

    private const BUYER = '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    private const SESSION = 'cs_test_paid';

    private const DISPUTE = 'dp_1';

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
            'status' => 'active',
        ]);

        $session = [
            'id' => self::SESSION,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_1',
            'amount_total' => 51500,
            'metadata' => [
                'wallet_address' => self::BUYER,
                'phase_id' => $this->phase->id,
                'sale_id' => $this->sale->id,
            ],
        ];

        $this->fakeStripe([
            'GET /v1/checkout/sessions' => ['object' => 'list', 'data' => [$session], 'has_more' => false],
            'GET /v1/disputes/'.self::DISPUTE => $this->stripeDispute('won'),
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

    /** dispute ตอน Stripe ตอบกลับตรง ๆ — ไม่ขอ expand ได้แค่ id ของ charge เหมือนของจริง */
    private function stripeDispute(string $status, int $refundedCents = 0, string $id = self::DISPUTE): callable
    {
        return fn (array $params) => [
            'id' => $id,
            'object' => 'dispute',
            'status' => $status,
            'charge' => in_array('charge', $params['expand'] ?? [], true)
                ? ['id' => 'ch_1', 'object' => 'charge', 'amount_refunded' => $refundedCents, 'refunded' => $refundedCents > 0]
                : 'ch_1',
        ];
    }

    private function dispute(string $status, string $id = self::DISPUTE): array
    {
        return ['id' => $id, 'object' => 'dispute', 'payment_intent' => 'pi_1', 'amount' => 51500, 'reason' => 'fraudulent', 'status' => $status];
    }

    private function deliver(string $type, array $object, string $eventId): array
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

    /** ออเดอร์ที่ผ่าน webhook จริงทั้งสอง event — metadata จึงตรงกับของจริงไม่ใช่ที่เทสต์เขียนเอง */
    private function closedDisputeOrder(string $outcome = 'won', array $overrides = []): SaleTransaction
    {
        $order = $this->confirmedOrder($overrides);

        $this->deliver('charge.dispute.created', $this->dispute('needs_response'), 'evt_created');
        $this->deliver('charge.dispute.closed', $this->dispute($outcome), 'evt_closed');

        return $order->fresh();
    }

    private function restore(SaleTransaction $order): TestResponse
    {
        return $this->post("/admin/token-sales/dispute/{$order->id}/restore");
    }

    private function signIn(string $role = 'super_admin'): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);

        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);

        return $admin;
    }

    private function assertCounters(float $sold, float $raised): void
    {
        $this->assertEqualsWithDelta($sold, (float) $this->phase->fresh()->sold, 0.0001, 'phase.sold');
        $this->assertEqualsWithDelta($sold, (float) $this->sale->fresh()->total_sold, 0.0001, 'sale.total_sold');
        $this->assertEqualsWithDelta($raised, (float) $this->sale->fresh()->total_raised_usd, 0.0001, 'sale.total_raised_usd');
    }

    private function restoreAlert(SaleTransaction $order): ?SystemAlert
    {
        return SystemAlert::where('alert_key', 'sale_card_dispute_won_'.$order->id)->first();
    }

    /** ปฏิเสธแล้วต้องไม่มีอะไรเปลี่ยน — ทั้งสถานะ ตัวนับ และเหตุที่ยังต้องมีคนตัดสิน */
    private function assertLeftAsItWas(SaleTransaction $before, float $sold, float $raised): void
    {
        $order = $before->fresh();

        $this->assertSame('failed', $order->status);
        $this->assertSame($before->metadata, $order->metadata);
        $this->assertCounters($sold, $raised);
        $this->assertSame('active', $this->restoreAlert($order)->status);
        $this->assertSame(0, AuditLog::where('action', 'token_sale.dispute_restore')->count());
    }

    // ── webhook ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_won_dispute_raises_a_critical_alert_without_reinstating_the_order(): void
    {
        $order = $this->closedDisputeOrder();

        $this->assertSame('failed', $order->status, 'webhook ห้ามคืนออเดอร์เอง');
        $this->assertSame('won', $order->metadata['dispute_closed_status']);
        $this->assertCounters(0, 0);

        $alert = $this->restoreAlert($order);
        $this->assertNotNull($alert, 'ต้องมีคนเห็นว่าร้านถือเงินอยู่แต่ผู้ซื้อไม่มีเหรียญ');
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString($order->uuid, $alert->message);
    }

    #[Test]
    public function an_inquiry_that_closes_without_a_chargeback_gets_the_same_way_back(): void
    {
        $this->stripeRoutes['GET /v1/disputes/'.self::DISPUTE] = $this->stripeDispute('warning_closed');
        $order = $this->closedDisputeOrder('warning_closed');

        $this->assertSame('active', $this->restoreAlert($order)->status);

        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    #[Test]
    public function a_lost_dispute_is_recorded_and_stays_closed(): void
    {
        $order = $this->closedDisputeOrder('lost');

        $this->assertSame('failed', $order->status);
        $this->assertSame('lost', $order->metadata['dispute_closed_status']);
        $this->assertNull($this->restoreAlert($order));
        $this->assertCounters(0, 0);
    }

    #[Test]
    public function a_win_after_a_loss_opens_the_way_back(): void
    {
        $order = $this->closedDisputeOrder('lost');

        $this->deliver('charge.dispute.closed', $this->dispute('won'), 'evt_late_win');

        $this->assertSame('active', $this->restoreAlert($order)->status);
    }

    #[Test]
    public function a_closed_event_that_outruns_created_fails_so_stripe_redelivers_it(): void
    {
        $order = $this->confirmedOrder();

        try {
            $this->deliver('charge.dispute.closed', $this->dispute('won'), 'evt_closed');
            $this->fail('ต้องล้มให้ Stripe ส่งซ้ำ');
        } catch (RuntimeException $e) {
            $this->assertSame('Stripe dispute is not recorded yet.', $e->getMessage());
        }

        $this->assertSame(0, DB::table('stripe_webhook_events')->where('event_id', 'evt_closed')->count());

        $this->deliver('charge.dispute.created', $this->dispute('needs_response'), 'evt_created');
        $this->deliver('charge.dispute.closed', $this->dispute('won'), 'evt_closed');

        $this->assertSame('active', $this->restoreAlert($order)->status, 'ส่งซ้ำรอบที่สองต้องจบเหมือนมาตามลำดับ');
    }

    #[Test]
    public function the_outcome_of_an_older_dispute_does_not_touch_the_current_one(): void
    {
        $order = $this->confirmedOrder();
        $this->deliver('charge.dispute.created', $this->dispute('needs_response'), 'evt_created');
        $this->deliver('charge.dispute.created', $this->dispute('needs_response', 'dp_2'), 'evt_created_2');

        $this->deliver('charge.dispute.closed', $this->dispute('won'), 'evt_closed');

        $this->assertArrayNotHasKey('dispute_closed_status', array_filter($order->fresh()->metadata));
        $this->assertNull($this->restoreAlert($order));
    }

    #[Test]
    public function a_new_dispute_withdraws_the_offer_of_the_old_one(): void
    {
        $order = $this->closedDisputeOrder('warning_closed');

        $this->deliver('charge.dispute.created', $this->dispute('needs_response', 'dp_2'), 'evt_created_2');

        $this->signIn();
        $this->restore($order)->assertSessionHas('error');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame('resolved', $this->restoreAlert($order)->status, 'เหตุเดิมบอกให้กดกู้ ซึ่งกดไม่ได้แล้ว');
        $this->assertCounters(0, 0);
    }

    #[Test]
    public function a_refunded_order_has_nothing_to_restore_after_a_win(): void
    {
        $order = $this->confirmedOrder(['status' => 'refunded']);

        $this->deliver('charge.dispute.created', $this->dispute('needs_response'), 'evt_created');
        $this->deliver('charge.dispute.closed', $this->dispute('won'), 'evt_closed');

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertNull($this->restoreAlert($order));
    }

    #[Test]
    public function a_full_refund_after_the_win_closes_the_order_for_good(): void
    {
        $order = $this->closedDisputeOrder();

        $this->deliver('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'refunded' => true, 'amount_refunded' => 51500], 'evt_refund');

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('resolved', $this->restoreAlert($order)->status);
        $this->assertCounters(0, 0);

        $this->signIn();
        $this->restore($order)->assertSessionHas('error');

        $this->assertSame('refunded', $order->fresh()->status);
    }

    // ── กู้สำเร็จ ─────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_restores_the_order_and_its_counters(): void
    {
        $order = $this->closedDisputeOrder();
        $admin = $this->signIn();

        $this->restore($order)->assertRedirect()->assertSessionHas('success');

        $fresh = $order->fresh();
        $this->assertSame('confirmed', $fresh->status);
        $this->assertSame('super_admin@tpix.test', $fresh->metadata['dispute_restored_by']);
        $this->assertCounters(5000, 500);
        $this->assertSame('resolved', $this->restoreAlert($order)->status);

        $audit = AuditLog::where('action', 'token_sale.dispute_restore')->first();
        $this->assertSame($admin->id, $audit->admin_id);
        $this->assertSame(['status' => 'failed'], $audit->old_values);
    }

    #[Test]
    public function the_buyer_can_claim_again_once_restored(): void
    {
        $order = $this->confirmedOrder(['claimed_amount' => 1000]);
        TreasuryPayout::create([
            'idempotency_key' => "sale-claim-{$order->uuid}-1",
            'to_address' => self::BUYER,
            'amount_wei' => Wei::toWei('1000'),
            'purpose' => 'token_sale',
            'origin' => TreasuryPayout::ORIGIN_SALE_CLAIM,
            'status' => TreasuryPayout::STATUS_PENDING,
        ]);
        $this->deliver('charge.dispute.created', $this->dispute('needs_response'), 'evt_created');
        $this->deliver('charge.dispute.closed', $this->dispute('won'), 'evt_closed');

        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $offer = app(SaleClaimPayoutService::class)->requeueOffer($order->fresh());
        $this->assertSame('1000', $offer['amount_tpix'], 'แถวจ่ายที่ถูกหยุดต้องกู้ต่อได้จากปุ่มเดิม');
    }

    #[Test]
    public function restoring_the_last_of_the_quota_moves_the_sale_to_the_next_phase(): void
    {
        $this->phase->update(['allocation' => 5000]);
        $next = SalePhase::create([
            'token_sale_id' => $this->sale->id,
            'name' => 'Next',
            'slug' => 'next',
            'phase_order' => 2,
            'price_usd' => 0.20,
            'allocation' => 1000,
            'min_purchase' => 1,
            'max_purchase' => 1000,
            'status' => 'upcoming',
        ]);
        $order = $this->closedDisputeOrder();

        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->assertSame('completed', $this->phase->fresh()->status);
        $this->assertSame('active', $next->fresh()->status);
    }

    #[Test]
    public function an_order_that_exactly_fills_the_quota_is_not_refused_by_rounding(): void
    {
        // คู่เลขนี้เทียบแบบ float แล้วเกินโควตา ทั้งที่เลขฐานสิบเท่ากันเป๊ะ
        $this->phase->update(['allocation' => '1000000.10000000', 'sold' => '1000000.10000000']);
        $this->sale->update(['total_sold' => '1000000.10000000']);
        $order = $this->closedDisputeOrder('won', ['tpix_amount' => '3333.33333333']);

        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertEqualsWithDelta(1000000.1, (float) $this->phase->fresh()->sold, 0.0001);
    }

    #[Test]
    public function the_order_itself_does_not_count_against_the_wallet_cap(): void
    {
        $this->phase->update(['whitelist_only' => true]);
        WhitelistEntry::create(['sale_phase_id' => $this->phase->id, 'wallet_address' => self::BUYER, 'max_allocation' => 5000]);
        $order = $this->closedDisputeOrder();

        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    #[Test]
    public function an_order_whose_tokens_left_is_not_counted_twice(): void
    {
        $order = $this->confirmedOrder();
        TreasuryPayout::create([
            'idempotency_key' => "sale-claim-{$order->uuid}-1",
            'to_address' => self::BUYER,
            'amount_wei' => Wei::toWei('1000'),
            'purpose' => 'token_sale',
            'origin' => TreasuryPayout::ORIGIN_SALE_CLAIM,
            'status' => TreasuryPayout::STATUS_CONFIRMED,
            'tx_hash' => '0x'.str_repeat('a', 64),
            'block_number' => 10,
        ]);

        $this->deliver('charge.dispute.created', $this->dispute('needs_response'), 'evt_created');
        $this->assertCounters(5000, 500); // เหรียญออกไปแล้ว โควตาจึงไม่ถูกคืนตอนปิด

        $this->deliver('charge.dispute.closed', $this->dispute('won'), 'evt_closed');

        // โควตาเต็มแล้ว แต่แถวนี้ยังกินโควตาของตัวเองอยู่ ต้องกู้ได้โดยไม่บวกซ้ำ
        $this->phase->update(['allocation' => 5000]);

        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertCounters(5000, 500);
        $this->assertSame('resolved', SystemAlert::where('alert_key', 'sale_card_tokens_left_'.$order->id)->first()->status);
    }

    // ── ปฏิเสธ ───────────────────────────────────────────────────────────────

    #[Test]
    public function refused_when_the_quota_was_resold(): void
    {
        $order = $this->closedDisputeOrder();
        $this->phase->update(['sold' => 998000]); // เหลือ 2,000 ไม่พอ 5,000
        $this->sale->update(['total_sold' => 998000, 'total_raised_usd' => 99800]);

        $this->signIn();
        $this->restore($order)->assertSessionHas('error', fn (string $why) => str_contains($why, 'โควตา'));

        $this->assertLeftAsItWas($order, 998000, 99800);
    }

    #[Test]
    public function refused_when_the_wallet_cap_no_longer_fits(): void
    {
        $this->phase->update(['whitelist_only' => true]);
        WhitelistEntry::create(['sale_phase_id' => $this->phase->id, 'wallet_address' => self::BUYER, 'max_allocation' => 6000]);
        $order = $this->closedDisputeOrder();
        $this->confirmedOrder(['tx_hash' => 'stripe_cs_test_other', 'tpix_amount' => 3000, 'payment_usd_value' => 300]);

        $this->signIn();
        $this->restore($order)->assertSessionHas('error', fn (string $why) => str_contains($why, 'เพดาน'));

        $this->assertLeftAsItWas($order, 0, 0);
    }

    #[Test]
    public function refused_when_the_wallet_left_the_whitelist(): void
    {
        $this->phase->update(['whitelist_only' => true]);
        $entry = WhitelistEntry::create(['sale_phase_id' => $this->phase->id, 'wallet_address' => self::BUYER]);
        $order = $this->closedDisputeOrder();
        $entry->delete();

        $this->signIn();
        $this->restore($order)->assertSessionHas('error', fn (string $why) => str_contains($why, 'whitelist'));

        $this->assertLeftAsItWas($order, 0, 0);
    }

    #[Test]
    public function refused_when_the_phase_is_gone(): void
    {
        $order = $this->closedDisputeOrder();
        $this->phase->delete();

        $this->signIn();
        $this->restore($order)->assertSessionHas('error', fn (string $why) => str_contains($why, 'เฟส'));

        $order = $order->fresh();
        $this->assertSame('failed', $order->status);
        $this->assertSame('active', $this->restoreAlert($order)->status);
    }

    /** @return array<string, array{string, int}> */
    public static function changedAtStripe(): array
    {
        return [
            'ผลพลิกเป็นแพ้' => ['lost', 0],
            'ถูกเปิดใหม่' => ['needs_response', 0],
            'คืนเงินเต็มใน dashboard' => ['won', 51500],
            'คืนเงินบางส่วน' => ['won', 100],
        ];
    }

    #[Test]
    #[DataProvider('changedAtStripe')]
    public function refused_when_stripe_no_longer_agrees(string $status, int $refundedCents): void
    {
        $order = $this->closedDisputeOrder();
        $this->stripeRoutes['GET /v1/disputes/'.self::DISPUTE] = $this->stripeDispute($status, $refundedCents);

        $this->signIn();
        $this->restore($order)->assertSessionHas('error');

        $this->assertLeftAsItWas($order, 0, 0);
    }

    #[Test]
    public function refused_when_stripe_cannot_be_reached(): void
    {
        $order = $this->closedDisputeOrder();
        unset($this->stripeRoutes['GET /v1/disputes/'.self::DISPUTE]);

        $this->signIn();
        $this->restore($order)->assertSessionHas('error', fn (string $why) => ! str_contains($why, 'no fake'));

        $this->assertLeftAsItWas($order, 0, 0);
    }

    #[Test]
    public function an_order_that_was_never_counted_has_nothing_to_restore(): void
    {
        $order = $this->confirmedOrder(['status' => 'pending', 'metadata' => ['needs_refund' => true, 'reason' => 'insufficient_allocation']]);
        $this->phase->update(['sold' => 0]);
        $this->sale->update(['total_sold' => 0, 'total_raised_usd' => 0]);

        $this->deliver('charge.dispute.created', $this->dispute('needs_response'), 'evt_created');
        $this->deliver('charge.dispute.closed', $this->dispute('won'), 'evt_closed');

        $this->signIn();
        $this->restore($order)->assertSessionHas('error', fn (string $why) => str_contains($why, 'Stripe'));

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertCounters(0, 0);
        $this->assertStringContainsString('คืนเงินใน Stripe', $this->restoreAlert($order)->message);
    }

    #[Test]
    public function a_failed_order_with_no_dispute_cannot_be_restored(): void
    {
        $order = $this->confirmedOrder(['status' => 'failed', 'payment_currency' => 'USD_BANK', 'tx_hash' => null]);

        $this->signIn();
        $this->restore($order)->assertSessionHas('error');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertCounters(5000, 500);
    }

    // ── เหตุชนกัน ────────────────────────────────────────────────────────────

    #[Test]
    public function a_second_press_counts_nothing(): void
    {
        $order = $this->closedDisputeOrder();
        $this->signIn();

        $this->restore($order)->assertSessionHas('success');
        $this->restore($order)->assertSessionHas('error');

        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function a_second_dispute_on_a_closed_order_keeps_what_the_first_one_remembered(): void
    {
        $order = $this->confirmedOrder();
        $this->stripeRoutes['GET /v1/disputes/dp_2'] = $this->stripeDispute('won', 0, 'dp_2');

        $this->deliver('charge.dispute.created', $this->dispute('needs_response'), 'evt_created');
        $this->deliver('charge.dispute.created', $this->dispute('needs_response', 'dp_2'), 'evt_created_2');
        $this->deliver('charge.dispute.closed', $this->dispute('won', 'dp_2'), 'evt_closed');

        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function a_restored_order_can_be_disputed_again(): void
    {
        $order = $this->closedDisputeOrder();
        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->deliver('charge.dispute.created', $this->dispute('needs_response', 'dp_2'), 'evt_created_2');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertCounters(0, 0);

        $this->restore($order)->assertSessionHas('error');

        $this->assertCounters(0, 0);
    }

    // ── หน้าแอดมิน ───────────────────────────────────────────────────────────

    #[Test]
    public function the_admin_page_lists_orders_awaiting_a_decision_for_super_admin_only(): void
    {
        $order = $this->closedDisputeOrder();
        $this->stripeCalls = [];

        $this->signIn();
        $this->get('/admin/token-sales')->assertOk()->assertInertia(fn ($page) => $page
            ->has('disputesAwaitingRestore', 1)
            ->where('disputesAwaitingRestore.0.id', $order->id)
            ->where('disputesAwaitingRestore.0.restore_refusal', null));

        $this->assertSame([], $this->stripeCalls, 'เปิดหน้าต้องไม่ยิง Stripe');
    }

    #[Test]
    public function a_lower_admin_sees_no_restore_offer_and_is_refused_at_the_route(): void
    {
        $order = $this->closedDisputeOrder();
        $this->signIn('admin');

        $this->get('/admin/token-sales')->assertOk()->assertInertia(fn ($page) => $page->has('disputesAwaitingRestore', 0));
        $this->restore($order)->assertForbidden();

        $this->assertSame('failed', $order->fresh()->status);
    }

    #[Test]
    public function a_guest_cannot_restore(): void
    {
        $order = $this->closedDisputeOrder();

        $this->restore($order)->assertRedirect();

        $this->assertSame('failed', $order->fresh()->status);
    }
}
