<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesStripeApi;
use Tests\TestCase;

/**
 * ป้าย Active Dispute(s) ที่หน้า Finance.
 *
 * sale_transactions.status ไม่มีค่า disputed ตัวนับเดิมจึงได้ 0 เสมอและป้ายไม่เคยขึ้น
 * กฎที่คุม: นับเฉพาะ dispute ที่ยังไม่รู้ผล ซึ่งเป็นเรื่องเดียวที่ป้ายส่งคนไปทำต่อใน Stripe Dashboard ได้
 * แถวในเทสต์นี้ผ่าน webhook จริง metadata จึงตรงกับของจริงไม่ใช่ที่เทสต์เขียนเอง
 */
class FinanceDisputeCountTest extends TestCase
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
        ]);

        $admin = AdminUser::create([
            'name' => 'owner',
            'email' => 'owner@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // เข้าระบบแบบเบราว์เซอร์ — actingAs() สลับ guard เริ่มต้น จึงบังบั๊กอ่านผิด guard ที่พังจริงบนโปรดักชัน
        Auth::forgetGuards();

        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);
    }

    protected function tearDown(): void
    {
        $this->tearDownStripe();

        parent::tearDown();
    }

    private function dispute(string $status, string $id = self::DISPUTE): array
    {
        return ['id' => $id, 'object' => 'dispute', 'payment_intent' => 'pi_1', 'amount' => 51500, 'reason' => 'fraudulent', 'status' => $status];
    }

    private function deliver(string $type, array $object, string $eventId): void
    {
        app(StripePaymentService::class)->handleWebhook(...$this->signedStripeEvent($eventId, $type, $object));
    }

    private function open(string $status = 'needs_response', string $id = self::DISPUTE, string $eventId = 'evt_created'): void
    {
        $this->deliver('charge.dispute.created', $this->dispute($status, $id), $eventId);
    }

    /** ผลที่ Stripe ถืออยู่จริง — โค้ดอ่านจากตรงนี้ ไม่เชื่อ payload ของ event */
    private function close(string $outcome): void
    {
        $this->stripeRoutes['GET /v1/disputes/'.self::DISPUTE] = fn (array $params) => [
            'id' => self::DISPUTE,
            'object' => 'dispute',
            'status' => $outcome,
            'charge' => in_array('charge', $params['expand'] ?? [], true)
                ? ['id' => 'ch_1', 'object' => 'charge', 'amount_refunded' => 0, 'refunded' => false]
                : 'ch_1',
        ];

        $this->deliver('charge.dispute.closed', $this->dispute($outcome), 'evt_closed');
    }

    private function order(array $overrides = []): SaleTransaction
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

    /** สรุปถูกแคช 60 วินาที ต้องล้างก่อนอ่านทุกครั้ง ไม่งั้นเทสต์ที่อ่านสองรอบเห็นค่าเดิม */
    private function assertActiveDisputes(int $expected): void
    {
        Cache::forget('admin:finance:summary');

        $this->get('/admin/finance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.total_disputes', $expected));
    }

    #[Test]
    public function an_open_dispute_is_counted(): void
    {
        $order = $this->order();

        $this->open();

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertActiveDisputes(1);
    }

    /** ลูกค้าได้เงินคืนแล้วแต่ยังดึงเงินซ้ำได้ ร้านต้องส่งหลักฐานใน Stripe ไม่งั้นเสียเงินสองรอบ */
    #[Test]
    public function a_dispute_after_a_refund_is_counted_while_it_is_open(): void
    {
        $order = $this->order(['status' => 'refunded']);

        $this->open();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertActiveDisputes(1);
    }

    #[Test]
    public function an_inquiry_ended_by_a_refund_stays_counted_until_stripe_closes_it(): void
    {
        $order = $this->order();

        $this->open('warning_needs_response');
        $this->deliver('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'refunded' => true, 'amount_refunded' => 51500], 'evt_refund');

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertActiveDisputes(1);

        $this->close('warning_closed');

        $this->assertActiveDisputes(0);
    }

    /** won และ warning_closed ยังต้องมีคนตัดสิน แต่ทำที่หน้า Token Sales ไม่ใช่ Stripe ป้ายนี้จึงไม่นับ */
    #[Test]
    #[DataProvider('outcomes')]
    public function a_closed_dispute_leaves_the_count(string $outcome): void
    {
        $order = $this->order();

        $this->open();
        $this->assertActiveDisputes(1);

        $this->close($outcome);

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertActiveDisputes(0);
    }

    public static function outcomes(): array
    {
        return [
            'lost' => ['lost'],
            'won' => ['won'],
            'warning_closed' => ['warning_closed'],
        ];
    }

    #[Test]
    public function a_restored_order_is_not_counted(): void
    {
        $order = $this->restoredOrder();

        $this->assertSame('confirmed', $order->status);
        $this->assertActiveDisputes(0);
    }

    #[Test]
    public function a_new_dispute_on_a_restored_order_is_counted(): void
    {
        $order = $this->restoredOrder();

        $this->open('needs_response', 'dp_2', 'evt_second');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertActiveDisputes(1);
    }

    /** แถวที่ปิดก่อนมีการจดผล dispute ไม่มี key นี้เลย — ไม่รู้ผลก็ต้องให้คนเห็นไว้ก่อน */
    #[Test]
    public function a_dispute_without_the_outcome_key_is_counted(): void
    {
        $this->order(['status' => 'failed', 'metadata' => ['dispute_id' => self::DISPUTE]]);

        $this->assertActiveDisputes(1);
    }

    #[Test]
    public function orders_without_a_dispute_are_not_counted(): void
    {
        $this->order(['tx_hash' => 'stripe_a']);
        $this->order(['tx_hash' => 'stripe_b', 'status' => 'failed']);
        $this->order(['tx_hash' => 'stripe_c', 'status' => 'refunded', 'metadata' => ['refund_id' => 're_1']]);

        $this->assertActiveDisputes(0);
    }

    #[Test]
    public function the_payment_list_marks_an_open_dispute_without_changing_the_status(): void
    {
        $this->order();

        $this->getJson('/admin/finance/recent-payments')
            ->assertOk()
            ->assertJsonPath('data.0.dispute_open', false);

        $this->open();

        $this->getJson('/admin/finance/recent-payments')
            ->assertJsonPath('data.0.dispute_open', true)
            ->assertJsonPath('data.0.status', 'failed');

        $this->close('lost');

        $this->getJson('/admin/finance/recent-payments')
            ->assertJsonPath('data.0.dispute_open', false)
            ->assertJsonPath('data.0.status', 'failed');
    }

    /** ออเดอร์ที่ชนะ dispute แล้ว super_admin กู้สำเร็จ */
    private function restoredOrder(): SaleTransaction
    {
        $order = $this->order();

        $this->open();
        $this->close('won');

        $this->stripeRoutes['GET /v1/disputes'] = ['object' => 'list', 'has_more' => false, 'data' => []];

        return app(StripePaymentService::class)->restoreDisputedOrder($order->fresh(), 'owner')->fresh();
    }
}
