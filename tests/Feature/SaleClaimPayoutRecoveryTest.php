<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWhitelist;
use App\Services\BankTransferSaleService;
use App\Services\SaleClaimPayoutService;
use App\Support\Wei;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * ยอดของ /token-sale/claim ที่รายการจ่ายจบโดยเงินไม่ออกต้องกู้กลับมาได้ โดยไม่จ่ายซ้ำ.
 *
 * claimed_amount แปลว่า "เข้าคิวจ่ายแล้ว" ไม่ใช่ "จ่ายแล้วบนเชน" แถวที่ถูกปฏิเสธจึงทำให้เหรียญค้าง
 * ผู้ซื้อ claim ซ้ำไม่ได้ และเดิมแอดมินก็ไม่มีทางคืนให้
 *
 * เทสต์ชุดนี้คุมสองเรื่อง:
 *   1. claim ที่เกินวงเงินของปลายทางต้องตกตั้งแต่ด่านแรก ไม่ใช่เข้าคิวแล้วค้าง
 *   2. การกู้ยอดสร้างแถวแทนได้เฉพาะแถวที่พิสูจน์ได้ว่าเงินไม่ออก และแถวละครั้งเดียว
 *
 * Developed by Xman Studio.
 */
class SaleClaimPayoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER = '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    protected function setUp(): void
    {
        parent::setUp();

        config(['treasury.limits.per_transaction' => '1000000', 'treasury.limits.per_day' => '0']);
    }

    /** TGE 100% = ซื้อแล้วปลดล็อกทั้งก้อน ไม่มี vesting มาปนกับยอดที่เทสต์ต้องการดู */
    private function confirmedOrder(array $overrides = []): SaleTransaction
    {
        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale-'.uniqid(),
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
        ]);

        $phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Public Sale',
            'slug' => 'public-sale-'.uniqid(),
            'phase_order' => 1,
            'price_usd' => 0.10,
            'allocation' => 10000000,
            'vesting_tge_percent' => 100,
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 0,
            'status' => 'active',
        ]);

        return SaleTransaction::create(array_merge([
            'token_sale_id' => $sale->id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => self::BUYER,
            'payment_currency' => 'USD',
            'payment_amount' => 100,
            'payment_usd_value' => 100,
            'tpix_amount' => 1000,
            'price_per_tpix' => 0.10,
            'tx_hash' => 'stripe_'.uniqid(),
            'status' => 'confirmed',
            'vesting_start_at' => now(),
        ], $overrides));
    }

    /** ปิด middleware เฉพาะคำขอนี้ — ถ้าปล่อยค้าง ด่าน admin.role ของเทสต์เดียวกันจะหายไปด้วย */
    private function claim(SaleTransaction $tx, float $amount): TestResponse
    {
        $this->withoutMiddleware();

        $response = $this->postJson('/api/v1/token-sale/claim', [
            'wallet_address' => self::BUYER,
            'transaction_id' => $tx->uuid,
            'amount' => $amount,
        ]);

        $this->withMiddleware();

        return $response;
    }

    /** claim จริงผ่าน endpoint แล้วปิดแถวนั้นเป็นสถานะที่ระบุ */
    private function claimedThenEnded(SaleTransaction $tx, float $amount, array $ending): TreasuryPayout
    {
        $this->claim($tx, $amount)->assertOk();

        $payout = TreasuryPayout::latest('id')->firstOrFail();
        $payout->update($ending);

        return $payout;
    }

    private function rejected(): array
    {
        return ['status' => TreasuryPayout::STATUS_REJECTED, 'failure_reason' => 'wrong wallet'];
    }

    private function actingAsAdmin(string $role = 'super_admin'): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function requeue(SaleTransaction $tx): TestResponse
    {
        return $this->post("/admin/token-sales/claims/{$tx->id}/requeue");
    }

    private function service(): SaleClaimPayoutService
    {
        return app(SaleClaimPayoutService::class);
    }

    /** แถวที่ยังอาจจ่ายหรือจ่ายแล้ว รวมกันต้องเท่ายอดใน claimed_amount เสมอ ไม่งั้นมีเหรียญหายหรือจ่ายซ้ำ */
    private function assertClaimedMatchesLivePayouts(SaleTransaction $tx): void
    {
        $live = TreasuryPayout::all()->reject(fn (TreasuryPayout $p) => $p->endedWithoutPayment());

        $this->assertSame(
            Wei::sum($live->pluck('amount_wei')->all()),
            Wei::toWei((string) $tx->fresh()->claimed_amount),
        );
    }

    private function assertRequeueOffer(?string $amount, ?string $returned = null): void
    {
        Http::fake();
        $this->withoutVite();

        $this->get('/admin/token-sales')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('transactions.data.0.requeue_claim_tpix', $amount)
                ->where('transactions.data.0.requeue_claim_returned_tpix', $returned)
        );
    }

    // =========================================================================
    // วงเงินต่อครั้งของปลายทาง
    // =========================================================================

    /**
     * ★ เดิมเช็กแค่ค่ากลาง claim ที่เกินวงเงินของ whitelist จึงเข้าคิวได้ แล้ว validatePayout ไม่ให้ผ่าน
     * ทั้งที่ claimed_amount ถูกบวกไปแล้ว = เหรียญค้าง.
     */
    public function test_claim_above_the_recipients_own_limit_is_refused_before_touching_the_order(): void
    {
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'is_active' => true, 'max_per_tx_wei' => Wei::toWei('150')]);
        $tx = $this->confirmedOrder();

        $this->claim($tx, 200)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'EXCEEDS_PAYOUT_LIMIT')
            ->assertJsonPath('error.message', 'A single claim can be at most 150 TPIX. Please claim in smaller parts.');

        $this->assertEquals(0, (float) $tx->fresh()->claimed_amount);
        $this->assertSame(0, TreasuryPayout::count());

        $this->claim($tx, 150)->assertOk();
        $this->assertSame(Wei::toWei('150'), (string) TreasuryPayout::sole()->amount_wei);
    }

    /** วงเงินของปลายทางแทนค่ากลางทั้งสองทาง — ตั้งสูงกว่าก็ต้อง claim ได้ตรงกับที่ validatePayout ยอม */
    public function test_a_recipient_limit_above_the_global_one_is_honoured(): void
    {
        config(['treasury.limits.per_transaction' => '100']);
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'is_active' => true, 'max_per_tx_wei' => Wei::toWei('500')]);
        $tx = $this->confirmedOrder();

        $this->claim($tx, 500)->assertOk();

        $this->assertSame(Wei::toWei('500'), (string) TreasuryPayout::sole()->amount_wei);
    }

    /** whitelist ที่ปิดใช้งานแล้วไม่มีผล — กลับไปใช้ค่ากลาง */
    public function test_an_inactive_whitelist_entry_falls_back_to_the_global_limit(): void
    {
        config(['treasury.limits.per_transaction' => '100']);
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'is_active' => false, 'max_per_tx_wei' => Wei::toWei('500')]);
        $tx = $this->confirmedOrder();

        $this->claim($tx, 200)->assertStatus(400)->assertJsonPath('error.code', 'EXCEEDS_PAYOUT_LIMIT');
    }

    // =========================================================================
    // กู้ยอดของแถวที่จบโดยเงินไม่ออก
    // =========================================================================

    /** ★ ทางออกของเหรียญที่ค้าง: ได้แถวใหม่ยอดเดิม ยังต้องรออนุมัติ และผู้ซื้อ claim ก้อนเดิมซ้ำไม่ได้ */
    public function test_admin_requeues_a_rejected_claim(): void
    {
        $admin = $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $dead = $this->claimedThenEnded($tx, 300, $this->rejected());

        $this->requeue($tx)->assertRedirect()->assertSessionHas('success');

        $replacement = TreasuryPayout::latest('id')->firstOrFail();
        $this->assertNotSame($dead->id, $replacement->id);
        $this->assertSame(TreasuryPayout::STATUS_PENDING, $replacement->status);
        $this->assertNull($replacement->approved_by);
        $this->assertSame(Wei::toWei('300'), (string) $replacement->amount_wei);
        $this->assertSame(self::BUYER, $replacement->to_address);
        $this->assertSame('token_sale', $replacement->purpose);
        $this->assertSame(TreasuryPayout::ORIGIN_SALE_CLAIM, $replacement->origin);
        $this->assertSame($admin->id, $replacement->requested_by);

        // ยอดนี้ยังกันอยู่ใน claimed_amount — ผู้ซื้อกด claim ก้อนเดียวกันซ้ำไม่ได้
        $this->assertEquals(300, (float) $tx->fresh()->claimed_amount);
        $this->claim($tx, 701)->assertStatus(400)->assertJsonPath('error.code', 'INSUFFICIENT_CLAIMABLE');
        $this->assertClaimedMatchesLivePayouts($tx);
    }

    /** ปลายทางไม่อยู่ใน whitelist ก็ต้องอนุมัติได้ เพราะแถวแทนยังเป็นรายการที่ระบบสร้างจาก claim */
    public function test_the_replacement_can_pass_treasury_approval(): void
    {
        Http::fake();
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $this->rejected());

        $this->requeue($tx);

        $replacement = TreasuryPayout::latest('id')->firstOrFail();
        $this->postJson("/admin/treasury/payouts/{$replacement->id}/approve")->assertOk();
        $this->assertSame(TreasuryPayout::STATUS_APPROVED, $replacement->fresh()->status);
    }

    public static function endingsThatProveNoMoneyLeft(): array
    {
        return [
            'แอดมินปฏิเสธ' => [['status' => TreasuryPayout::STATUS_REJECTED]],
            'ตกกฎก่อนเซ็น ไม่มี hash' => [['status' => TreasuryPayout::STATUS_FAILED, 'failure_reason' => 'ตกกฎ']],
            'เชน revert มีใบเสร็จ' => [[
                'status' => TreasuryPayout::STATUS_FAILED,
                'tx_hash' => '0x'.str_repeat('ab', 32),
                'block_number' => 9000,
            ]],
        ];
    }

    #[DataProvider('endingsThatProveNoMoneyLeft')]
    public function test_requeue_covers_every_ending_that_proves_no_money_left(array $ending): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $ending);

        $this->requeue($tx)->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, TreasuryPayout::count());
        $this->assertClaimedMatchesLivePayouts($tx);
    }

    public static function payoutsThatMayStillPay(): array
    {
        $hash = '0x'.str_repeat('cd', 32);

        return [
            'รออนุมัติ' => [['status' => TreasuryPayout::STATUS_PENDING]],
            'อนุมัติแล้ว' => [['status' => TreasuryPayout::STATUS_APPROVED]],
            'รอเซ็น' => [['status' => TreasuryPayout::STATUS_BROADCASTING]],
            'ส่งแล้วรอบล็อก' => [['status' => TreasuryPayout::STATUS_BROADCASTING, 'tx_hash' => $hash]],
            'จ่ายสำเร็จ' => [['status' => TreasuryPayout::STATUS_CONFIRMED, 'tx_hash' => $hash, 'block_number' => 9000]],
            'failed แบบเก่า มี hash ไม่มีใบเสร็จ' => [['status' => TreasuryPayout::STATUS_FAILED, 'tx_hash' => $hash]],
        ];
    }

    /** ★ ใจความของงานนี้ — ไม่มีหลักฐานว่าเงินไม่ออก = ห้ามสร้างแถวใหม่ ไม่งั้นจ่ายสองรอบ */
    #[DataProvider('payoutsThatMayStillPay')]
    public function test_requeue_refuses_a_payout_that_may_still_pay(array $state): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $state);

        $this->requeue($tx)->assertRedirect()->assertSessionHasErrors('claim');

        $this->assertSame(1, TreasuryPayout::count());
        $this->assertEquals(300, (float) $tx->fresh()->claimed_amount);
    }

    /** ★ กดซ้ำหรือหน้าเก่าค้างจอ — แถวตายหนึ่งแถวมีแถวแทนได้แถวเดียว */
    public function test_requeueing_twice_queues_one_replacement(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $this->rejected());

        $this->requeue($tx)->assertSessionHas('success');
        $this->requeue($tx)->assertRedirect()->assertSessionHasErrors('claim');

        $this->assertSame(2, TreasuryPayout::count());
        $this->assertClaimedMatchesLivePayouts($tx);
    }

    /** โมเดลที่ถือไว้ก่อนกดรอบแรกต้องไม่พาค่าเก่าเข้ามา — ตัดสินใหม่ใต้ล็อกเสมอ */
    public function test_requeue_with_a_stale_model_uses_the_locked_row(): void
    {
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $this->rejected());
        $stale = $tx->fresh();

        $this->assertCount(1, $this->service()->requeueDeadPayouts($stale));
        $this->assertCount(0, $this->service()->requeueDeadPayouts($stale));

        $this->assertSame(2, TreasuryPayout::count());
    }

    /** แถวแทนก็ถูกปฏิเสธได้อีก — ต้องกู้ต่อได้เป็นทอด ๆ และมีแถวที่ยังไม่จบแถวเดียวเสมอ */
    public function test_a_dead_replacement_can_be_requeued_again(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $this->rejected());

        $this->requeue($tx)->assertSessionHas('success');
        TreasuryPayout::latest('id')->firstOrFail()->update($this->rejected());

        $this->requeue($tx)->assertSessionHas('success');

        $this->assertSame(3, TreasuryPayout::count());
        $this->assertSame(1, TreasuryPayout::where('status', TreasuryPayout::STATUS_PENDING)->count());
        $this->assertClaimedMatchesLivePayouts($tx);
    }

    /** ออเดอร์เดียวมีหลาย claim — กู้เฉพาะแถวที่ตาย แถวละก้อนตามยอดของมัน */
    public function test_requeue_recovers_only_the_dead_claims_of_the_order(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 100, $this->rejected());
        $this->claim($tx, 200)->assertOk();
        $paid = TreasuryPayout::latest('id')->firstOrFail();
        $this->claimedThenEnded($tx, 300, ['status' => TreasuryPayout::STATUS_FAILED, 'failure_reason' => 'ตกกฎ']);

        $this->requeue($tx)->assertSessionHas('success');

        $replacements = TreasuryPayout::where('id', '>', $paid->id + 1)->orderBy('id')->pluck('amount_wei')->all();
        $this->assertSame([Wei::toWei('100'), Wei::toWei('300')], array_map('strval', $replacements));
        $this->assertSame(TreasuryPayout::STATUS_PENDING, $paid->fresh()->status);
        $this->assertClaimedMatchesLivePayouts($tx);
    }

    /** แถวที่อาจโอนไปแล้วต้องไม่ฉุดแถวอื่น แต่ตัวมันเองต้องไม่ถูกแตะ */
    public function test_requeue_skips_the_row_that_may_be_live_and_recovers_the_others(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $ambiguous = $this->claimedThenEnded($tx, 100, ['status' => TreasuryPayout::STATUS_FAILED, 'tx_hash' => '0x'.str_repeat('ef', 32)]);
        $this->claimedThenEnded($tx, 200, $this->rejected());

        $this->requeue($tx)->assertSessionHas('success');

        $this->assertSame(3, TreasuryPayout::count());
        $this->assertSame(Wei::toWei('200'), (string) TreasuryPayout::latest('id')->firstOrFail()->amount_wei);
        $this->assertSame(TreasuryPayout::STATUS_FAILED, $ambiguous->fresh()->status);
    }

    /** แถวที่สร้างก่อนแก้ใช้คีย์รูปแบบเดิม — ต้องกู้ได้เหมือนกัน */
    public function test_requeue_recovers_a_row_with_the_legacy_key(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder(['claimed_amount' => 300]);
        TreasuryPayout::create([
            'idempotency_key' => 'sale-claim-'.$tx->uuid.'-300.000000000000000000',
            'to_address' => self::BUYER,
            'amount_wei' => Wei::toWei('300'),
            'purpose' => 'token_sale',
            'origin' => TreasuryPayout::ORIGIN_SALE_CLAIM,
            'status' => TreasuryPayout::STATUS_REJECTED,
        ]);

        $this->requeue($tx)->assertSessionHas('success');

        $this->assertSame(2, TreasuryPayout::count());
        $this->assertClaimedMatchesLivePayouts($tx);
    }

    // =========================================================================
    // แถวตายที่เกินวงเงิน — ส่งยอดเดิมซ้ำก็ตกกฎเดิม
    // =========================================================================

    /** ★ claim ที่ค้างเพราะเกินวงเงิน: แถวแทนต้องผ่าน validatePayout ได้จริง ส่วนเกินคืนเป็นสิทธิ์ claim */
    public function test_requeue_caps_at_the_recipients_limit_and_returns_the_rest(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, ['status' => TreasuryPayout::STATUS_FAILED, 'failure_reason' => 'เกินวงเงินต่อครั้ง']);
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'is_active' => true, 'max_per_tx_wei' => Wei::toWei('120')]);

        $this->requeue($tx)->assertSessionHas('success');

        $this->assertSame(Wei::toWei('120'), (string) TreasuryPayout::latest('id')->firstOrFail()->amount_wei);
        $this->assertEquals(120, (float) $tx->fresh()->claimed_amount);
        $this->assertEquals(880, $tx->fresh()->claimable_amount);
        $this->assertClaimedMatchesLivePayouts($tx);
    }

    /** หลายแถวเกินวงเงินพร้อมกัน — ต้องหักยอดสะสมทีละแถว ไม่ใช่เขียนทับด้วยยอดของแถวสุดท้าย */
    public function test_requeue_returns_the_excess_of_every_row_above_the_limit(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $this->rejected());
        $this->claimedThenEnded($tx, 200, $this->rejected());
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'is_active' => true, 'max_per_tx_wei' => Wei::toWei('120')]);

        $this->assertRequeueOffer('240', '260');
        $this->requeue($tx)->assertSessionHas('success');

        $this->assertEquals(240, (float) $tx->fresh()->claimed_amount);
        $this->assertClaimedMatchesLivePayouts($tx);
    }

    /**
     * ★ คีย์เดิมผูกกับยอดสะสม พอคืนยอดแล้ว claim ใหม่ ยอดสะสมจะวนกลับมาชนคีย์ของแถวเก่า
     * firstOrCreate คืนแถวที่ตายไปแล้ว = บวก claimed_amount แต่ไม่มีแถวจ่าย เหรียญค้างซ้ำอีกรอบ.
     */
    public function test_a_returned_amount_can_be_claimed_again_without_hitting_an_old_key(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, ['status' => TreasuryPayout::STATUS_FAILED, 'failure_reason' => 'เกินวงเงินต่อครั้ง']);
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'is_active' => true, 'max_per_tx_wei' => Wei::toWei('200')]);
        $this->requeue($tx)->assertSessionHas('success');

        // 200 ที่เข้าคิวแทน + 100 ที่ผู้ซื้อ claim คืน = ยอดสะสมกลับมาเป็น 300 เท่าคีย์ของแถวแรก
        $this->claim($tx, 100)->assertOk();

        $this->assertSame(3, TreasuryPayout::count());
        $newest = TreasuryPayout::latest('id')->firstOrFail();
        $this->assertSame(TreasuryPayout::STATUS_PENDING, $newest->status);
        $this->assertSame(Wei::toWei('100'), (string) $newest->amount_wei);
        $this->assertClaimedMatchesLivePayouts($tx);
    }

    /** คืนยอดกับสร้างแถวต้องสำเร็จพร้อมกัน — ล้มกลางทางแล้วยอดหาย = ผู้ซื้อเสียเหรียญ หรือได้สองทาง */
    public function test_requeue_keeps_the_claimed_amount_when_the_replacement_cannot_be_created(): void
    {
        $tx = $this->confirmedOrder();
        $dead = $this->claimedThenEnded($tx, 300, $this->rejected());
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'is_active' => true, 'max_per_tx_wei' => Wei::toWei('100')]);

        $broken = true;
        TreasuryPayout::creating(function () use (&$broken) {
            if ($broken) {
                throw new RuntimeException('connection lost');
            }
        });

        try {
            $this->service()->requeueDeadPayouts($tx);
            $this->fail('ต้องโยน exception ออกมา');
        } catch (RuntimeException $e) {
            $this->assertSame('connection lost', $e->getMessage());
        }

        $this->assertEquals(300, (float) $tx->fresh()->claimed_amount);
        $this->assertSame(1, TreasuryPayout::count());

        // แถวตายยังไม่มีแถวแทน กดใหม่ได้
        $broken = false;
        $this->assertCount(1, $this->service()->requeueDeadPayouts($tx));
        $this->assertEquals(100, (float) $tx->fresh()->claimed_amount);
        $this->assertSame(TreasuryPayout::STATUS_REJECTED, $dead->fresh()->status);
    }

    // =========================================================================
    // ด่านของ route
    // =========================================================================

    /** ★ ออเดอร์ที่คืนเงินไปแล้ว ถ้ากู้ยอดได้ ผู้ซื้อจะได้ทั้งเงินคืนและเหรียญ */
    public function test_requeue_refuses_an_order_that_is_no_longer_confirmed(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $this->rejected());
        $tx->update(['status' => 'refunded']);

        $this->requeue($tx)->assertRedirect()->assertSessionHasErrors('claim');

        $this->assertSame(1, TreasuryPayout::count());
    }

    /**
     * ★ แอดมินกรอก idempotency_key เองได้ แถวที่ตั้งคีย์เลียนแบบ claim ต้องไม่กลายเป็นแถวแทน
     * ที่ origin เป็น sale_claim — ไม่งั้นเป็นทางลัดข้าม whitelist.
     */
    public function test_requeue_ignores_an_admin_row_with_a_claim_shaped_key(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        TreasuryPayout::create([
            'idempotency_key' => 'sale-claim-'.$tx->uuid.'-manual',
            'to_address' => '0x'.str_repeat('66', 20),
            'amount_wei' => Wei::toWei('300'),
            'purpose' => 'token_sale',
            'origin' => TreasuryPayout::ORIGIN_ADMIN,
            'status' => TreasuryPayout::STATUS_REJECTED,
        ]);

        $this->requeue($tx)->assertRedirect()->assertSessionHasErrors('claim');

        $this->assertSame(1, TreasuryPayout::count());
    }

    public static function systemKeys(): array
    {
        return [
            'claim' => ['sale-claim-b2f3c1de-0000-4000-8000-000000000007-2'],
            'แถวแทน' => ['sale-claim-b2f3c1de-0000-4000-8000-000000000007-retry-9'],
            'ออเดอร์โอน' => ['sale-bank-b2f3c1de-0000-4000-8000-000000000007-tge-1'],
            'คืนค่าธรรมเนียมสร้างเหรียญ' => ['token_factory_refund:1:1'],
            'ตัวพิมพ์ใหญ่ — MySQL เทียบคีย์แบบไม่สนตัวพิมพ์' => ['SALE-CLAIM-b2f3c1de-0000-4000-8000-000000000007-2'],
        ];
    }

    /**
     * ★ รายการกรอกมือที่ใช้คีย์ของระบบจะชนกับแถวที่ระบบกำลังจะสร้าง
     * claim หรือการกู้ยอดของออเดอร์นั้นล้มด้วย unique index ซ้ำไปเรื่อย ๆ จนกว่าจะมีคนแก้แถวนั้น.
     */
    #[DataProvider('systemKeys')]
    public function test_a_manual_payout_cannot_take_a_system_key(string $key): void
    {
        $this->actingAsAdmin();

        $this->postJson('/admin/treasury/payouts', [
            'to_address' => self::BUYER,
            'amount' => '100',
            'purpose' => 'other',
            'idempotency_key' => $key,
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(0, TreasuryPayout::count());
    }

    /** รายการจ่ายครั้งแรกของออเดอร์โอนมีปุ่มของมันเอง — ปุ่มนี้ต้องไม่ไปแตะ */
    public function test_requeue_leaves_bank_payouts_to_their_own_button(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder(['metadata' => ['method' => 'bank_transfer', 'reference' => 'TPIX-ABC234']]);
        $bankPayout = app(BankTransferSaleService::class)->queueInitialPayout($tx);
        $bankPayout->update(['status' => TreasuryPayout::STATUS_REJECTED]);

        $this->requeue($tx)->assertRedirect()->assertSessionHasErrors('claim');

        $this->assertSame(1, TreasuryPayout::count());
    }

    /** สร้างรายการจ่ายจากกระเป๋าร้อนได้ — สิทธิ์ต้องเท่ากับหน้าคลัง */
    public function test_requeue_route_needs_a_super_admin(): void
    {
        $this->actingAsAdmin('admin');
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $this->rejected());

        $this->requeue($tx)->assertForbidden();

        $this->assertSame(1, TreasuryPayout::count());
    }

    public function test_requeue_route_reports_when_there_is_nothing_to_recover(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();

        $this->requeue($tx)->assertRedirect()->assertSessionHasErrors('claim');

        $this->assertSame(0, TreasuryPayout::count());
    }

    // =========================================================================
    // หน้าแอดมิน
    // =========================================================================

    /** บอกยอดก่อนกด และปุ่มหายไปเมื่อกู้แล้ว */
    public function test_admin_page_offers_the_requeue_only_for_unrecovered_dead_claims(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claim($tx, 100)->assertOk();
        $this->assertRequeueOffer(null);

        TreasuryPayout::sole()->update($this->rejected());
        $this->assertRequeueOffer('100');

        $this->requeue($tx);
        $this->assertRequeueOffer(null);
    }

    /** แถวเกินวงเงิน: แอดมินต้องเห็นก่อนกดว่ายอดไหนเข้าคิว และยอดไหนคืนให้ผู้ซื้อ */
    public function test_admin_page_shows_what_goes_back_to_the_buyer(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $this->rejected());
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'is_active' => true, 'max_per_tx_wei' => Wei::toWei('120')]);

        $this->assertRequeueOffer('120', '180');
    }

    /** แถวที่อาจโอนไปแล้วต้องไม่มีปุ่มล่อให้กด */
    public function test_admin_page_does_not_offer_a_row_that_may_still_pay(): void
    {
        $this->actingAsAdmin();
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, ['status' => TreasuryPayout::STATUS_FAILED, 'tx_hash' => '0x'.str_repeat('ef', 32)]);

        $this->assertRequeueOffer(null);
    }

    /** แอดมินทั่วไปกดไม่ได้อยู่แล้ว (403) จึงไม่ควรเห็นปุ่มให้ล่อกด */
    public function test_admin_page_hides_the_requeue_from_other_roles(): void
    {
        $this->actingAsAdmin('admin');
        $tx = $this->confirmedOrder();
        $this->claimedThenEnded($tx, 300, $this->rejected());

        $this->assertRequeueOffer(null);
    }
}
