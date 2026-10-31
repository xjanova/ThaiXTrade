<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SystemAlert;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Services\BankTransferSaleService;
use App\Services\HotWalletSigner;
use App\Services\SaleClaimPayoutService;
use App\Services\StripePaymentService;
use App\Support\Wei;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeHotWalletSigner;
use Tests\Support\FakesStripeApi;
use Tests\TestCase;

/**
 * คืนโควตาของออเดอร์ที่ปิดไปแล้ว เมื่อแถวจ่ายที่ทำให้ต้องเก็บโควตาไว้จบโดยเงินไม่ออก.
 *
 * closeOrder เก็บโควตาไว้เมื่อหยุดแถวจ่ายไม่ทัน แต่ตอนนั้นยังไม่รู้ผลบนเชน
 * กฎที่คุม: คืนเมื่อพิสูจน์ได้ว่าไม่มีเหรียญออกไปเลยเท่านั้น และคืนได้ครั้งเดียว
 *
 * แถวจ่ายจบที่ tpix:treasury-payouts ตัวจริง: เชนเป็น Http::fake และตัวเซ็นเป็นของปลอม
 */
class SaleQuotaReleaseTest extends TestCase
{
    use FakesStripeApi, RefreshDatabase;

    private const BUYER = '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    private const HOT = '0x43d9000000000000000000000000000000009b96';

    private const SESSION = 'cs_test_paid';

    private const DISPUTE = 'dp_1';

    private SalePhase $phase;

    private TokenSale $sale;

    private FakeHotWalletSigner $signer;

    private string $keystore;

    /** @var array<string, array> tx hash → ใบเสร็จ */
    private array $receipts = [];

    /** @var array<string, array> tx hash → ธุรกรรมที่โหนดรู้จัก */
    private array $known = [];

    private int $head = 9000;

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
            'GET /v1/disputes' => fn () => [
                'object' => 'list',
                'has_more' => false,
                'data' => [($this->stripeRoutes['GET /v1/disputes/'.self::DISPUTE])([])],
            ],
        ]);

        $this->atStripe('needs_response');

        // keystore ปลอมที่มีแค่ address ให้ด่าน readiness ผ่าน — ตัวเซ็นปลอมไม่อ่านไฟล์นี้
        $this->keystore = storage_path('framework/testing/quota-release-'.getmypid().'.json');
        if (! is_dir(dirname($this->keystore))) {
            mkdir(dirname($this->keystore), 0777, true);
        }
        file_put_contents($this->keystore, json_encode(['address' => substr(self::HOT, 2), 'version' => 3]));

        config([
            'treasury.payouts_enabled' => true,
            'treasury.hot_wallet.address' => self::HOT,
            'treasury.hot_wallet.keystore_path' => $this->keystore,
            'treasury.hot_wallet.passphrase' => 'test-only',
            'treasury.limits.per_transaction' => '1000000',
            'treasury.limits.per_day' => '10000000',
            'treasury.limits.require_whitelist' => true,
            'treasury.stuck_after_minutes' => 10,
        ]);

        $this->signer = new FakeHotWalletSigner(self::HOT);
        $this->app->instance(HotWalletSigner::class, $this->signer);

        Http::fake(function (Request $request) {
            $method = $request->data()['method'] ?? '';
            $hash = strtolower((string) ($request->data()['params'][0] ?? ''));
            $ok = fn ($result) => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);

            return match ($method) {
                'eth_getBalance' => $ok('0x422ca8b0a00a425000000'),
                'eth_blockNumber' => $ok('0x'.dechex($this->head)),
                'eth_getTransactionReceipt' => $ok($this->receipts[$hash] ?? null),
                'eth_getTransactionByHash' => $ok($this->known[$hash] ?? null),
                'eth_getTransactionCount' => $ok('0x0'),
                default => $ok(null),
            };
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownStripe();

        if (is_file($this->keystore)) {
            unlink($this->keystore);
        }

        parent::tearDown();
    }

    private function deliver(string $type, array $object, string $eventId): array
    {
        return app(StripePaymentService::class)->handleWebhook(...$this->signedStripeEvent($eventId, $type, $object));
    }

    private function atStripe(string $status): void
    {
        $this->stripeRoutes['GET /v1/disputes/'.self::DISPUTE] = fn (array $params) => [
            'id' => self::DISPUTE,
            'object' => 'dispute',
            'status' => $status,
            'charge' => in_array('charge', $params['expand'] ?? [], true)
                ? ['id' => 'ch_1', 'object' => 'charge', 'amount_refunded' => 0, 'refunded' => false]
                : 'ch_1',
        ];
    }

    private function dispute(string $status): array
    {
        return ['id' => self::DISPUTE, 'object' => 'dispute', 'payment_intent' => 'pi_1', 'amount' => 51500, 'reason' => 'fraudulent', 'status' => $status];
    }

    private function disputed(): void
    {
        $this->deliver('charge.dispute.created', $this->dispute('needs_response'), 'evt_created');
    }

    private function refunded(): void
    {
        $this->deliver('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'refunded' => true, 'amount_refunded' => 51500], 'evt_refunded');
    }

    private function closeDispute(string $outcome, string $eventId = 'evt_closed'): void
    {
        $this->atStripe($outcome);
        $this->deliver('charge.dispute.closed', $this->dispute($outcome), $eventId);
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

    /** แถวจ่ายที่ส่งไปแล้วและยังไม่รู้ผล — ปิดออเดอร์ตอนนี้จะหยุดไม่ทัน */
    private function payout(SaleTransaction $order, int $n = 1, array $attributes = []): TreasuryPayout
    {
        $payout = TreasuryPayout::create(array_merge([
            'idempotency_key' => "sale-claim-{$order->uuid}-{$n}",
            'to_address' => self::BUYER,
            'amount_wei' => Wei::toWei('1000'),
            'purpose' => 'token_sale',
            'origin' => TreasuryPayout::ORIGIN_SALE_CLAIM,
            'status' => TreasuryPayout::STATUS_BROADCASTING,
            'tx_hash' => '0x'.str_repeat((string) $n, 64),
            'nonce' => $n,
            'approved_at' => now(),
            'broadcast_at' => now(),
        ], $attributes));

        if ($payout->tx_hash !== null) {
            $this->known[strtolower($payout->tx_hash)] = ['hash' => $payout->tx_hash, 'blockNumber' => null];
        }

        return $payout;
    }

    private function mined(TreasuryPayout $payout, bool $success): void
    {
        $this->receipts[strtolower($payout->tx_hash)] = [
            'transactionHash' => $payout->tx_hash,
            'status' => $success ? '0x1' : '0x0',
            'blockNumber' => '0x'.dechex($this->head - 5),
        ];
    }

    private function payoutRound(): void
    {
        Artisan::call('tpix:treasury-payouts');
    }

    private function revert(TreasuryPayout ...$payouts): void
    {
        foreach ($payouts as $payout) {
            $this->mined($payout, false);
        }

        $this->payoutRound();

        foreach ($payouts as $payout) {
            $this->assertSame(TreasuryPayout::STATUS_FAILED, $payout->fresh()->status);
        }
    }

    private function release(TreasuryPayout $payout): bool
    {
        return app(SaleClaimPayoutService::class)->releaseKeptQuota($payout->fresh());
    }

    private function signIn(): void
    {
        $admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'super_admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);
    }

    private function restore(SaleTransaction $order): TestResponse
    {
        return $this->post("/admin/token-sales/dispute/{$order->id}/restore");
    }

    private function adminRefund(SaleTransaction $order): TestResponse
    {
        return $this->post("/admin/token-sales/refund/{$order->id}");
    }

    private function assertCounters(float $sold, float $raised): void
    {
        $this->assertEqualsWithDelta($sold, (float) $this->phase->fresh()->sold, 0.0001, 'phase.sold');
        $this->assertEqualsWithDelta($sold, (float) $this->sale->fresh()->total_sold, 0.0001, 'sale.total_sold');
        $this->assertEqualsWithDelta($raised, (float) $this->sale->fresh()->total_raised_usd, 0.0001, 'sale.total_raised_usd');
    }

    private function assertReleased(SaleTransaction $order): void
    {
        $this->assertCounters(0, 0);
        $this->assertTrue($order->fresh()->metadata['counters_reversed']);
    }

    private function assertKept(SaleTransaction $order): void
    {
        $this->assertCounters(5000, 500);
        $this->assertFalse($order->fresh()->metadata['counters_reversed']);
    }

    private function tokensLeftAlert(SaleTransaction $order): ?SystemAlert
    {
        return SystemAlert::where('alert_key', 'sale_card_tokens_left_'.$order->id)->first();
    }

    // ── คืนโควตา ─────────────────────────────────────────────────────────────

    public static function closingEvents(): array
    {
        return [
            'dispute เปิด' => ['disputed', 'failed'],
            'คืนเงินจาก dashboard ของ Stripe' => ['refunded', 'refunded'],
        ];
    }

    #[Test]
    #[DataProvider('closingEvents')]
    public function a_reverted_payout_releases_the_quota_of_a_closed_order(string $close, string $status): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->{$close}();
        $this->assertKept($order);
        $this->assertSame($status, $order->fresh()->status);
        $this->assertSame('active', $this->tokensLeftAlert($order)->status);

        $this->revert($payout);

        $this->assertReleased($order);
        $this->assertSame($status, $order->fresh()->status);
        $this->assertSame('resolved', $this->tokensLeftAlert($order)->status);
    }

    #[Test]
    public function a_second_close_after_a_restore_can_be_released_too(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->disputed();
        $this->closeDispute('won');
        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->closeDispute('lost', 'evt_lost');
        $this->assertKept($order);

        $this->revert($payout);

        $this->assertReleased($order);
    }

    public static function payoutKeys(): array
    {
        return [
            'claim' => ['sale-claim-%s-7', TreasuryPayout::ORIGIN_SALE_CLAIM],
            'แถวแทนจากการกู้ยอด' => ['sale-claim-%s-retry-12', TreasuryPayout::ORIGIN_SALE_CLAIM],
            'จ่ายครั้งแรกของออเดอร์โอนธนาคาร' => [BankTransferSaleService::PAYOUT_KEY_PREFIX.'%s-tge-1', TreasuryPayout::ORIGIN_SALE_BANK],
        ];
    }

    #[Test]
    #[DataProvider('payoutKeys')]
    public function every_payout_key_shape_finds_its_order(string $key, string $origin): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order, 1, ['idempotency_key' => sprintf($key, $order->uuid), 'origin' => $origin]);

        $this->disputed();
        $this->revert($payout);

        $this->assertReleased($order);
    }

    /** ตัวจ่ายจองแถวแล้วแต่ยังไม่มี hash: หยุดไม่ทันเหมือนแถวที่ส่งแล้ว แต่จบด้วย failUnsent ไม่ใช่ใบเสร็จ */
    #[Test]
    public function a_payout_that_failed_before_it_was_sent_releases_the_quota(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order, 1, ['tx_hash' => null, 'nonce' => null, 'broadcast_at' => null]);

        $this->signer->signOk = false;
        $this->signer->duringSign = function () use ($order) {
            $this->disputed();
            $this->assertKept($order);
        };

        $this->payoutRound();

        $this->assertSame(TreasuryPayout::STATUS_FAILED, $payout->fresh()->status);
        $this->assertNull($payout->fresh()->tx_hash);
        $this->assertReleased($order);
    }

    #[Test]
    public function a_row_stopped_at_the_close_does_not_block_the_release(): void
    {
        $order = $this->confirmedOrder();
        $inFlight = $this->payout($order);
        $waiting = $this->payout($order, 2, ['status' => TreasuryPayout::STATUS_PENDING, 'tx_hash' => null, 'nonce' => null]);

        $this->disputed();
        $this->assertSame(TreasuryPayout::STATUS_REJECTED, $waiting->fresh()->status);

        $this->revert($inFlight);

        $this->assertReleased($order);
    }

    // ── ยังคืนไม่ได้ ─────────────────────────────────────────────────────────

    #[Test]
    public function the_quota_stays_until_the_last_payout_is_proven_unpaid(): void
    {
        $order = $this->confirmedOrder();
        $first = $this->payout($order);
        $second = $this->payout($order, 2);

        $this->disputed();

        $this->revert($first);
        $this->assertKept($order);
        $this->assertSame('active', $this->tokensLeftAlert($order)->status);

        $this->revert($second);
        $this->assertReleased($order);
        $this->assertSame('resolved', $this->tokensLeftAlert($order)->status);
    }

    #[Test]
    public function a_payout_that_paid_keeps_the_quota_for_good(): void
    {
        $order = $this->confirmedOrder();
        $paid = $this->payout($order);
        $reverted = $this->payout($order, 2);

        $this->disputed();

        $this->mined($paid, true);
        $this->revert($reverted);

        $this->assertSame(TreasuryPayout::STATUS_CONFIRMED, $paid->fresh()->status);
        $this->assertKept($order);
        $this->assertSame('active', $this->tokensLeftAlert($order)->status);
    }

    /** failed แบบเก่าที่มี hash แต่ไม่มีเลขบล็อก อาจลงบล็อกไปแล้ว — พิสูจน์ไม่ได้ว่าเงินไม่ออก */
    #[Test]
    public function a_payout_that_may_have_gone_out_keeps_the_quota(): void
    {
        $order = $this->confirmedOrder();
        $this->payout($order, 1, ['status' => TreasuryPayout::STATUS_FAILED]);
        $reverted = $this->payout($order, 2);

        $this->disputed();
        $this->revert($reverted);

        $this->assertKept($order);
    }

    #[Test]
    public function a_payout_still_in_flight_releases_nothing(): void
    {
        $order = $this->confirmedOrder();
        $this->payout($order);

        $this->disputed();
        $this->payoutRound();

        $this->assertKept($order);
    }

    // ── ครั้งเดียว ───────────────────────────────────────────────────────────

    #[Test]
    public function the_release_happens_only_once(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->disputed();
        $this->revert($payout);
        $this->assertReleased($order);

        $payout->fresh()->update(['status' => TreasuryPayout::STATUS_REJECTED]);
        $this->assertCounters(0, 0);

        $this->assertFalse($this->release($payout));
        $this->assertCounters(0, 0);
    }

    #[Test]
    public function two_payouts_ending_in_one_round_release_once(): void
    {
        $order = $this->confirmedOrder();
        $first = $this->payout($order);
        $second = $this->payout($order, 2);

        $this->disputed();
        $this->revert($first, $second);

        $this->assertReleased($order);
    }

    #[Test]
    public function a_later_close_does_not_take_the_quota_again(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->disputed();
        $this->revert($payout);

        $this->refunded();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertReleased($order);
    }

    #[Test]
    public function a_payout_that_died_before_the_close_is_reversed_by_the_close_alone(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->revert($payout);
        $this->assertCounters(5000, 500);

        $this->disputed();
        $this->assertReleased($order);

        $this->assertFalse($this->release($payout));
        $this->assertCounters(0, 0);
    }

    // ── กู้ออเดอร์ ───────────────────────────────────────────────────────────

    #[Test]
    public function a_restore_after_the_release_counts_the_order_again(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->disputed();
        $this->revert($payout);
        $this->assertReleased($order);

        $this->closeDispute('won');

        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertCounters(5000, 500);
    }

    #[Test]
    public function a_restore_after_the_release_needs_the_quota_to_be_free(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->disputed();
        $this->revert($payout);
        $this->closeDispute('won');

        // โควตาที่คืนไปถูกขายต่อแล้ว
        $this->phase->update(['sold' => 999999]);

        $this->signIn();
        $this->restore($order)->assertSessionHas('error');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertEqualsWithDelta(999999, (float) $this->phase->fresh()->sold, 0.0001);
    }

    #[Test]
    public function a_restore_without_a_release_still_adds_nothing(): void
    {
        $order = $this->confirmedOrder();
        $this->payout($order);

        $this->disputed();
        $this->closeDispute('won');

        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->assertCounters(5000, 500);
    }

    /** ออเดอร์ที่กู้แล้วกลับมานับ ถ้าปิดซ้ำด้วยปุ่มคืนเงินหลังบ้าน ค่าที่จำจากรอบก่อนต้องไม่ทำให้ลดอีกรอบ */
    #[Test]
    public function a_restored_order_refunded_by_an_admin_is_not_released_again(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->disputed();
        $this->closeDispute('won');
        $this->signIn();
        $this->restore($order)->assertSessionHas('success');

        $this->revert($payout);
        $this->assertCounters(5000, 500);
        $this->assertSame('confirmed', $order->fresh()->status);

        $this->adminRefund($order)->assertSessionHas('success');
        $this->assertCounters(0, 0);

        $this->assertFalse($this->release($payout));
        $this->assertCounters(0, 0);
    }

    /** ค่าค้างจากการปิดรอบก่อนต้องไม่มีผลกับแถวที่นับอยู่ ตัวนับยังรวมแถวนี้โดยถูกต้อง */
    #[Test]
    public function an_order_still_counted_is_never_released(): void
    {
        $order = $this->confirmedOrder(['metadata' => ['counters_reversed' => false]]);
        $payout = $this->payout($order);

        $this->revert($payout);

        $this->assertFalse($this->release($payout));
        $this->assertCounters(5000, 500);
    }

    // ── ออเดอร์ที่ไม่เคยเก็บโควตาไว้ ─────────────────────────────────────────

    /** ปุ่มคืนเงินหลังบ้านปฏิเสธตอนเหรียญออกแล้ว ออเดอร์จึงยังนับอยู่ ไม่เคยเข้าสถานะเก็บโควตา */
    #[Test]
    public function a_bank_order_has_nothing_to_release(): void
    {
        $order = $this->confirmedOrder(['payment_currency' => 'USD_BANK', 'tx_hash' => null]);
        $payout = $this->payout($order, 1, [
            'idempotency_key' => BankTransferSaleService::PAYOUT_KEY_PREFIX.$order->uuid.'-tge-1',
            'origin' => TreasuryPayout::ORIGIN_SALE_BANK,
        ]);

        $this->signIn();
        $this->adminRefund($order)->assertSessionHas('error');
        $this->assertSame('confirmed', $order->fresh()->status);

        $this->revert($payout);
        $this->assertCounters(5000, 500);
        $this->assertSame('confirmed', $order->fresh()->status);

        $this->adminRefund($order)->assertSessionHas('success');
        $this->assertCounters(0, 0);

        $this->assertFalse($this->release($payout));
        $this->assertCounters(0, 0);
        $this->assertArrayNotHasKey('counters_reversed', $order->fresh()->metadata ?? []);
    }

    // ── จังหวะ commit ────────────────────────────────────────────────────────

    /** ตัวตามผลถือล็อกแถวจ่ายอยู่ ถ้าล็อกออเดอร์ตรงนั้นจะสลับลำดับกับ closeOrder แล้ว deadlock */
    #[Test]
    public function the_release_waits_for_the_payout_to_commit(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->disputed();

        DB::transaction(function () use ($order, $payout) {
            $payout->update(['status' => TreasuryPayout::STATUS_FAILED, 'block_number' => 10]);

            $this->assertKept($order);
        });

        $this->assertReleased($order);
    }

    #[Test]
    public function a_payout_rolled_back_releases_nothing(): void
    {
        $order = $this->confirmedOrder();
        $payout = $this->payout($order);

        $this->disputed();

        try {
            DB::transaction(function () use ($payout) {
                $payout->update(['status' => TreasuryPayout::STATUS_FAILED, 'block_number' => 10]);

                throw new RuntimeException('เขียนแถวจ่ายไม่สำเร็จ');
            });
        } catch (RuntimeException) {
            // ธุรกรรมย้อนแล้ว — สิ่งที่ตรวจคือโควตาด้านล่าง
        }

        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $payout->fresh()->status);
        $this->assertKept($order);
    }

    #[Test]
    public function a_failed_release_does_not_stop_the_payout_round(): void
    {
        Exceptions::fake();

        $order = $this->confirmedOrder();
        $reverted = $this->payout($order);
        $paid = $this->payout($order, 2);

        $this->disputed();

        $this->mock(SaleClaimPayoutService::class)
            ->shouldReceive('releaseKeptQuota')
            ->andThrow(new RuntimeException('เขียนตัวนับไม่สำเร็จ'));

        $this->mined($reverted, false);
        $this->mined($paid, true);
        $this->payoutRound();

        $this->assertSame(TreasuryPayout::STATUS_FAILED, $reverted->fresh()->status);
        $this->assertSame(TreasuryPayout::STATUS_CONFIRMED, $paid->fresh()->status);
        Exceptions::assertReported(RuntimeException::class);
    }
}
