<?php

namespace Tests\Feature;

use App\Exceptions\PurchaseException;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWhitelist;
use App\Services\BankTransferSaleService;
use App\Services\StripePaymentService;
use App\Services\TokenSaleService;
use App\Support\TreasuryWallet;
use App\Support\Wei;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — ขายด้วยเงินสด ส่งมอบบนเชน TPIX เท่านั้น.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * โมเดลที่เจ้าของกำหนด (21 ส.ค. 2026)
 * ═══════════════════════════════════════════════════════════════════════════
 *   รับเงิน : บัตรเครดิต / โอนเงินเข้าบัญชี — ไม่รับคริปโตแล้ว
 *   ส่งมอบ  : TPIX เนทีฟบนเชน 4289
 *   กระเป๋า : ใบเดียวกับที่รับค่าบริการ
 *
 * เทสต์ชุดนี้คุมสองเรื่องที่พลาดแล้วเสียเงินจริง:
 *   1. คำสั่งซื้อทางโอนเงินต้อง **ไม่นับเป็นยอดขาย** จนกว่าทีมงานจะยืนยัน
 *      ไม่งั้นใครก็กดสั่งซื้อรัวๆ จนโควตาเต็มโดยไม่ต้องจ่ายเงินสักบาท
 *   2. ทางจ่ายคริปโตต้องถูกปิดจริงที่หลังบ้าน ไม่ใช่แค่ซ่อนปุ่มในหน้าเว็บ
 *      เพราะกระเป๋ารับเงิน BSC เดิมยังพิสูจน์ไม่ได้ว่ามีใครถือกุญแจ
 *
 * Developed by Xman Studio.
 */
class BankTransferSaleTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER = '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    protected function setUp(): void
    {
        parent::setUp();

        // บัญชีรับโอน — ถ้าไม่ตั้ง ทางโอนเงินจะปิดอยู่โดยอัตโนมัติ
        SiteSetting::set('sale', 'bank_name', 'ธนาคารกสิกรไทย');
        SiteSetting::set('sale', 'bank_account_name', 'Xman Studio');
        SiteSetting::set('sale', 'bank_account_no', '123-4-56789-0');
    }

    private function makeOpenPhase(array $saleOverrides = []): SalePhase
    {
        $sale = TokenSale::create(array_merge([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'accept_currencies' => ['CARD', 'BANK'],
            'accept_chain_id' => 4289,
        ], $saleOverrides));

        return SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Public Sale',
            'slug' => 'public-sale',
            'phase_order' => 1,
            'price_usd' => 0.10,
            'allocation' => 1000000,
            'min_purchase' => 100,
            'max_purchase' => 1000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);
    }

    private function bank(): BankTransferSaleService
    {
        return app(BankTransferSaleService::class);
    }

    // =========================================================================
    // สร้างคำสั่งซื้อ
    // =========================================================================

    /** สั่งซื้อแล้วต้องได้รหัสอ้างอิง + เลขบัญชี และรายการเป็น pending */
    public function test_creating_an_order_does_not_count_as_a_sale_yet(): void
    {
        $phase = $this->makeOpenPhase();

        $order = $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);

        $this->assertMatchesRegularExpression('/^TPIX-[A-Z2-9]{6}$/', $order['reference']);
        $this->assertSame(1000.0, $order['tpix_amount']);
        $this->assertSame('123-4-56789-0', $order['bank']['account_no']);

        $tx = SaleTransaction::where('uuid', $order['transaction_id'])->firstOrFail();
        $this->assertSame('pending', $tx->status);

        /*
         * ★ หัวใจของเรื่อง — ยังไม่แตะยอดขายและยังไม่จองโควตา
         * ถ้าจองตั้งแต่ตอนนี้ คนเดียวกดสั่งซื้อรัวๆ ปิดรอบขายได้ฟรีโดยไม่ต้องจ่ายเงิน
         */
        $this->assertSame(0.0, (float) $phase->fresh()->sold);
        $this->assertSame(0.0, (float) $phase->tokenSale->fresh()->total_sold);
    }

    /** ยังไม่ตั้งเลขบัญชี = ทางโอนเงินต้องปิดไว้ ไม่ใช่ให้สั่งซื้อแล้วค้าง */
    public function test_order_is_refused_when_bank_account_is_not_configured(): void
    {
        SiteSetting::set('sale', 'bank_account_no', '');
        $phase = $this->makeOpenPhase();

        $this->expectException(PurchaseException::class);

        $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);
    }

    /**
     * ★ รอบขายที่ประกาศรับแค่บัตร ต้องปฏิเสธการสั่งซื้อทางโอนเงิน.
     *
     * เจ้าของสั่งให้ใช้ Stripe อย่างเดียว การมีเลขบัญชีค้างอยู่ในระบบ
     * ต้องไม่ทำให้ทางโอนเงินเปิดขึ้นมาเองเงียบๆ — นโยบายมาจาก accept_currencies
     * ไม่ใช่จากผลข้างเคียงของการตั้งค่า
     */
    public function test_bank_order_is_refused_when_sale_accepts_card_only(): void
    {
        $phase = $this->makeOpenPhase(['accept_currencies' => ['CARD']]);

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('This payment method is not accepted');

        $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);
    }

    /** แต่ทางบัตรยังต้องซื้อได้ตามปกติในรอบขายเดียวกัน */
    public function test_card_still_works_when_sale_accepts_card_only(): void
    {
        $phase = $this->makeOpenPhase(['accept_currencies' => ['CARD']]);

        $result = app(TokenSaleService::class)->assertPurchasable(self::BUYER, $phase->id, 'USD', 100.0);

        $this->assertSame(1000.0, $result['tpix_amount']);
    }

    /** เฟสปิดแล้วสั่งซื้อไม่ได้ — ใช้ด่านเดียวกับทุกช่องทาง */
    public function test_order_is_refused_when_phase_is_closed(): void
    {
        $phase = $this->makeOpenPhase();
        $phase->update(['ends_at' => now()->subDay()]);

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('This phase has already ended.');

        $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);
    }

    /** รหัสอ้างอิงต้องไม่ซ้ำกัน ไม่งั้นทีมงานจับคู่รายการโอนผิดคน */
    public function test_references_are_unique(): void
    {
        $phase = $this->makeOpenPhase();

        $refs = [];
        for ($i = 0; $i < 5; $i++) {
            $refs[] = $this->bank()->createOrder(self::BUYER, $phase->id, 100.0)['reference'];
        }

        $this->assertCount(5, array_unique($refs));
    }

    // =========================================================================
    // ทีมงานยืนยัน
    // =========================================================================

    /** ยืนยันแล้วยอดขายถึงจะขยับ และคิวจ่ายเหรียญถูกสร้าง */
    public function test_confirming_records_the_sale_and_queues_the_payout(): void
    {
        $phase = $this->makeOpenPhase();
        $order = $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);
        $tx = SaleTransaction::where('uuid', $order['transaction_id'])->firstOrFail();

        $confirmed = $this->bank()->confirm($tx, 'admin@tpix.test');
        $payout = $this->bank()->queueInitialPayout($confirmed);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('admin@tpix.test', $confirmed->metadata['confirmed_by']);
        $this->assertSame(1000.0, (float) $phase->fresh()->sold);
        $this->assertSame(100.0, (float) $phase->tokenSale->fresh()->total_raised_usd);

        // TGE 20% ปลดล็อกทันที → ต้องเข้าคิวจ่าย 200 TPIX
        $this->assertNotNull($payout);
        $this->assertSame(strtolower(self::BUYER), $payout->to_address);
        $this->assertSame(TreasuryPayout::STATUS_PENDING, $payout->status);
    }

    /** กดยืนยันซ้ำต้องไม่นับยอดสองรอบ */
    public function test_confirming_twice_is_refused(): void
    {
        $phase = $this->makeOpenPhase();
        $order = $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);
        $tx = SaleTransaction::where('uuid', $order['transaction_id'])->firstOrFail();

        $this->bank()->confirm($tx);

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('no longer pending');

        $this->bank()->confirm($tx->fresh());
    }

    /** คิวจ่ายต้องกดซ้ำแล้วไม่เกิดรายการจ่ายซ้ำ */
    public function test_queueing_the_payout_twice_creates_only_one_row(): void
    {
        $phase = $this->makeOpenPhase();
        $order = $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);
        $tx = SaleTransaction::where('uuid', $order['transaction_id'])->firstOrFail();

        $confirmed = $this->bank()->confirm($tx);
        $this->bank()->queueInitialPayout($confirmed);
        $this->bank()->queueInitialPayout($confirmed->fresh());

        $this->assertSame(1, TreasuryPayout::where('to_address', strtolower(self::BUYER))->count());
    }

    // =========================================================================
    // จ่ายครั้งแรกจากออเดอร์โอน — ห้ามจ่ายซ้ำ (ออดิท 2026-10-29 C6)
    // =========================================================================

    /** ยอดรวมของทุกแถวในคิว = เหรียญที่จะออกจากกระเป๋าร้อนจริงถ้าอนุมัติครบ */
    private function assertQueuedTpix(string $expected): void
    {
        $this->assertSame(Wei::toWei($expected), Wei::sum(TreasuryPayout::pluck('amount_wei')->all()));
    }

    private function confirmedOrder(): SaleTransaction
    {
        $phase = $this->makeOpenPhase();
        $order = $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);

        return $this->bank()->confirm(SaleTransaction::where('uuid', $order['transaction_id'])->firstOrFail());
    }

    private function claim(SaleTransaction $tx, float $amount): TestResponse
    {
        $this->withoutMiddleware();

        return $this->postJson('/api/v1/token-sale/claim', [
            'wallet_address' => self::BUYER,
            'transaction_id' => $tx->uuid,
            'amount' => $amount,
        ]);
    }

    private function runBackfill(): void
    {
        (require database_path('migrations/2026_10_30_100000_count_bank_sale_payouts_as_claimed.php'))->up();
    }

    private function legacyPayout(SaleTransaction $tx, string $tpix, array $overrides = []): TreasuryPayout
    {
        return TreasuryPayout::create(array_merge([
            'idempotency_key' => 'sale-bank-'.$tx->uuid.'-'.$tpix,
            'to_address' => self::BUYER,
            'amount_wei' => Wei::toWei($tpix),
            'purpose' => 'token_sale',
            'origin' => TreasuryPayout::ORIGIN_SALE_BANK,
            'status' => TreasuryPayout::STATUS_PENDING,
        ], $overrides));
    }

    /**
     * ★ คีย์เดิมมียอด claimable ปนอยู่ พอ vesting ปลดเพิ่มยอดก็เปลี่ยน คีย์ก็เปลี่ยน
     * กดซ้ำจึงได้แถวใหม่ทั้งที่ส่วน TGE เข้าคิวไปแล้ว = จ่าย 200 แรกซ้ำ.
     */
    public function test_requeue_after_more_vesting_unlocked_does_not_queue_a_second_payout(): void
    {
        $confirmed = $this->confirmedOrder();
        $first = $this->bank()->queueInitialPayout($confirmed);

        // ปิดเฟส +30 วัน + cliff 30 วัน = ปลดเพิ่มหลังวันที่ 60
        $this->travel(100)->days();
        $this->assertGreaterThan(0, $confirmed->fresh()->claimable_amount);

        $again = $this->bank()->queueInitialPayout($confirmed->fresh());

        $this->assertSame($first->id, $again->id);
        $this->assertQueuedTpix('200');
    }

    /** ★ ยอดที่เข้าคิวแล้วต้องหักออกจากสิทธิ์ claim ไม่งั้นผู้ซื้อเรียก /claim ได้ยอดเดิมอีกรอบ */
    public function test_buyer_cannot_claim_what_the_bank_path_already_queued(): void
    {
        $confirmed = $this->confirmedOrder();
        $this->bank()->queueInitialPayout($confirmed);

        $fresh = $confirmed->fresh();
        $this->assertEquals(200, (float) $fresh->claimed_amount);
        $this->assertEquals(0, $fresh->claimable_amount);

        $this->claim($fresh, 200)->assertStatus(400)->assertJsonPath('error.code', 'INSUFFICIENT_CLAIMABLE');

        $this->bank()->queueInitialPayout($fresh);
        $this->assertSame(1, TreasuryPayout::count());
        $this->assertQueuedTpix('200');
    }

    /** ★ ผู้ซื้อ claim ไปก่อนบางส่วน → คิวจ่ายเฉพาะส่วนที่เหลือ และกดซ้ำก็ไม่เพิ่ม */
    public function test_claim_then_requeue_cannot_pay_the_same_tokens_twice(): void
    {
        $confirmed = $this->confirmedOrder();

        $this->claim($confirmed, 50)->assertOk();

        $this->bank()->queueInitialPayout($confirmed->fresh());
        $this->bank()->queueInitialPayout($confirmed->fresh());

        $fresh = $confirmed->fresh();
        $this->assertEquals(200, (float) $fresh->claimed_amount);
        $this->claim($fresh, 150)->assertStatus(400)->assertJsonPath('error.code', 'INSUFFICIENT_CLAIMABLE');

        $this->assertSame(2, TreasuryPayout::count());
        $this->assertQueuedTpix('200');
    }

    /** claim ครบก่อนแอดมินกดเข้าคิว = ไม่มีอะไรเหลือให้จ่าย */
    public function test_requeue_after_the_buyer_claimed_everything_queues_nothing(): void
    {
        $confirmed = $this->confirmedOrder();

        $this->claim($confirmed, 200)->assertOk();

        $this->assertNull($this->bank()->queueInitialPayout($confirmed->fresh()));
        $this->assertQueuedTpix('200');
    }

    /** ได้ model ค้างมาจากก่อนหน้าก็ต้องอ่านยอดจริงใต้ล็อก ไม่ใช่เชื่อค่าที่ถือมา */
    public function test_requeue_with_a_stale_model_uses_the_locked_row(): void
    {
        $stale = $this->confirmedOrder();

        $this->claim($stale, 200)->assertOk();

        $this->assertNull($this->bank()->queueInitialPayout($stale));
        $this->assertQueuedTpix('200');
    }

    /** ★ คิวล้มกลางทาง → ยอดกันไว้ต้องม้วนกลับ และกดใหม่ต้องได้แถวเดียวตามปกติ */
    public function test_requeue_works_after_the_queue_attempt_failed(): void
    {
        $confirmed = $this->confirmedOrder();

        $broken = true;
        TreasuryPayout::creating(function () use (&$broken) {
            if ($broken) {
                throw new RuntimeException('connection lost');
            }
        });

        try {
            $this->bank()->queueInitialPayout($confirmed);
            $this->fail('ต้องโยน exception ต่อไปให้ผู้เรียกเห็น');
        } catch (RuntimeException $e) {
            $this->assertSame('connection lost', $e->getMessage());
        }

        $this->assertSame(0, TreasuryPayout::count());
        $this->assertEquals(0, (float) $confirmed->fresh()->claimed_amount);

        $broken = false;
        $this->bank()->queueInitialPayout($confirmed->fresh());

        $this->assertSame(1, TreasuryPayout::count());
        $this->assertQueuedTpix('200');
        $this->assertEquals(200, (float) $confirmed->fresh()->claimed_amount);
    }

    /**
     * ★ รายการที่จบโดยพิสูจน์ได้ว่าเงินไม่ออก → กดใหม่ได้แถวใหม่ยอดเดิม
     * และยอดกันไว้ต้องไม่ถูกบวกซ้ำ ไม่งั้นผู้ซื้อเสียสิทธิ์สองเท่า.
     */
    public function test_requeue_replaces_a_payout_that_provably_sent_nothing(): void
    {
        $confirmed = $this->confirmedOrder();
        $first = $this->bank()->queueInitialPayout($confirmed);
        $first->update(['status' => TreasuryPayout::STATUS_REJECTED, 'failure_reason' => 'wrong wallet']);

        // ระหว่างรอ vesting ปลดเพิ่ม — รอบใหม่ต้องยังเป็นยอดเดิม ส่วนที่ปลดเพิ่มเป็นสิทธิ์ claim
        $this->travel(100)->days();
        $second = $this->bank()->queueInitialPayout($confirmed->fresh());

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame((string) $first->amount_wei, (string) $second->amount_wei);
        $this->assertSame(TreasuryPayout::STATUS_PENDING, $second->status);
        $this->assertEquals(200, (float) $confirmed->fresh()->claimed_amount);

        // กดซ้ำอีกรอบ → มีแถวที่ยังไม่จบอยู่แล้ว ต้องคืนแถวเดิม
        $this->assertSame($second->id, $this->bank()->queueInitialPayout($confirmed->fresh())->id);
        $this->assertSame(2, TreasuryPayout::count());
    }

    /** ธุรกรรมที่ revert ลงบล็อกแล้วนับว่าเงินไม่ออก จ่ายรอบใหม่ได้ */
    public function test_requeue_replaces_a_payout_that_reverted_on_chain(): void
    {
        $confirmed = $this->confirmedOrder();
        $first = $this->bank()->queueInitialPayout($confirmed);
        $first->update(['status' => TreasuryPayout::STATUS_FAILED, 'tx_hash' => '0x'.str_repeat('a', 64), 'block_number' => 10]);

        $second = $this->bank()->queueInitialPayout($confirmed->fresh());

        $this->assertNotSame($first->id, $second->id);
        $this->assertEquals(200, (float) $confirmed->fresh()->claimed_amount);
    }

    /** failed ที่มี hash แต่ไม่มีใบเสร็จ อาจลงบล็อกไปแล้ว — จ่ายรอบใหม่ = จ่ายซ้ำ */
    public function test_requeue_refuses_when_the_earlier_payout_may_have_gone_out(): void
    {
        $confirmed = $this->confirmedOrder();
        $first = $this->bank()->queueInitialPayout($confirmed);
        $first->update(['status' => TreasuryPayout::STATUS_FAILED, 'tx_hash' => '0x'.str_repeat('a', 64)]);

        $again = $this->bank()->queueInitialPayout($confirmed->fresh());

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, TreasuryPayout::count());
    }

    /** ออเดอร์ที่ไม่ได้อยู่ในสถานะ confirmed ต้องไม่ออกเหรียญ ไม่ว่าใครเรียกมา */
    public function test_only_confirmed_orders_can_be_queued(): void
    {
        $confirmed = $this->confirmedOrder();
        $confirmed->update(['status' => 'refunded']);

        $this->assertNull($this->bank()->queueInitialPayout($confirmed->fresh()));
        $this->assertSame(0, TreasuryPayout::count());
        $this->assertEquals(0, (float) $confirmed->fresh()->claimed_amount);
    }

    /**
     * ยอดที่เกินวงเงินต่อครั้งเป็นรายการที่ validatePayout ไม่มีวันผ่าน
     * พอหักสิทธิ์ claim ไปแล้วผู้ซื้อจะติดอยู่ตรงนั้น จึงจ่ายเต็มวงเงินแล้วให้ claim ส่วนที่เหลือเอง.
     */
    public function test_initial_payout_stops_at_the_per_transaction_limit(): void
    {
        config(['treasury.limits.per_transaction' => '150']);
        $confirmed = $this->confirmedOrder();

        $this->bank()->queueInitialPayout($confirmed);

        $this->assertQueuedTpix('150');
        $fresh = $confirmed->fresh();
        $this->assertEquals(150, (float) $fresh->claimed_amount);
        $this->assertEquals(50, $fresh->claimable_amount);

        $this->claim($fresh, 50)->assertOk();
        $this->assertQueuedTpix('200');
    }

    /** ปลายทางที่ตั้งวงเงินไว้ต่ำกว่าค่ากลาง validatePayout ใช้ค่าของปลายทาง — ทางนี้ต้องใช้เกณฑ์เดียวกัน */
    public function test_initial_payout_stops_at_the_recipient_limit(): void
    {
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'max_per_tx_wei' => Wei::toWei('150')]);
        $confirmed = $this->confirmedOrder();

        $this->bank()->queueInitialPayout($confirmed);

        $this->assertQueuedTpix('150');
        $this->assertEquals(50, $confirmed->fresh()->claimable_amount);
    }

    /** วงเงินของปลายทางแทนที่ค่ากลาง ไม่ใช่ซ้อนกัน — ตั้งไว้สูงกว่าก็จ่ายได้เต็มวงเงินนั้น */
    public function test_recipient_limit_replaces_the_global_limit(): void
    {
        config(['treasury.limits.per_transaction' => '100']);
        TreasuryWhitelist::create(['address' => self::BUYER, 'label' => 'Buyer', 'max_per_tx_wei' => Wei::toWei('150')]);
        $confirmed = $this->confirmedOrder();

        $this->bank()->queueInitialPayout($confirmed);

        $this->assertQueuedTpix('150');
    }

    /** ★ แถวเก่าที่ยังไม่จบไม่เคยถูกหักสิทธิ์ claim — migration ต้องหักให้ ไม่งั้น /claim จ่ายซ้ำได้ */
    public function test_backfill_counts_a_live_legacy_payout_against_claimable(): void
    {
        $confirmed = $this->confirmedOrder();
        $legacy = $this->legacyPayout($confirmed, '200');

        $this->runBackfill();

        $this->assertEquals(200, (float) $confirmed->fresh()->claimed_amount);
        $this->claim($confirmed->fresh(), 200)->assertStatus(400)->assertJsonPath('error.code', 'INSUFFICIENT_CLAIMABLE');

        // กดเข้าคิวซ้ำต้องเห็นแถวเก่าด้วย แม้รูปคีย์จะต่างจากแถวใหม่
        $this->assertSame($legacy->id, $this->bank()->queueInitialPayout($confirmed->fresh())->id);
        $this->assertSame(1, TreasuryPayout::count());

        // แถวเก่าจบโดยเงินไม่ออกทีหลัง → จ่ายใหม่ยอดเดิมโดยไม่หักสิทธิ์ซ้ำ
        $legacy->update(['status' => TreasuryPayout::STATUS_REJECTED]);
        $retry = $this->bank()->queueInitialPayout($confirmed->fresh());

        $this->assertNotSame($legacy->id, $retry->id);
        $this->assertSame((string) $legacy->amount_wei, (string) $retry->amount_wei);
        $this->assertEquals(200, (float) $confirmed->fresh()->claimed_amount);
    }

    /** แถวเก่าที่จบโดยเงินไม่ออกไม่เคยกินสิทธิ์ claim — ห้ามหัก และเข้าคิวใหม่ได้ตามยอดจริง */
    public function test_backfill_ignores_a_legacy_payout_that_sent_nothing(): void
    {
        $confirmed = $this->confirmedOrder();
        $this->legacyPayout($confirmed, '200', ['status' => TreasuryPayout::STATUS_REJECTED]);

        $this->runBackfill();

        $this->assertEquals(0, (float) $confirmed->fresh()->claimed_amount);

        $retry = $this->bank()->queueInitialPayout($confirmed->fresh());

        $this->assertSame(Wei::toWei('200'), (string) $retry->amount_wei);
        $this->assertEquals(200, (float) $confirmed->fresh()->claimed_amount);
    }

    /** แถวเก่าซ้ำกันอยู่แล้ว → หักทุกแถวแต่ไม่เกินยอดซื้อ และเตือนให้คนตัดสินว่าจะปฏิเสธแถวไหน */
    public function test_backfill_caps_claimed_at_the_purchase_and_flags_duplicates(): void
    {
        Log::spy();
        $confirmed = $this->confirmedOrder();
        $this->legacyPayout($confirmed, '800');
        $this->legacyPayout($confirmed, '300');

        $this->runBackfill();

        $this->assertEquals(1000, (float) $confirmed->fresh()->claimed_amount);
        $this->assertSame(2, TreasuryPayout::where('status', TreasuryPayout::STATUS_PENDING)->count());
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * โควตาหมดระหว่างรอโอนเงิน → ห้ามออกเหรียญ ต้องทำเครื่องหมายว่าต้องคืนเงิน.
     *
     * ใช้สถานะ failed ไม่ใช่ cancelled — คอลัมน์ status เป็น ENUM ที่ไม่มี cancelled
     * การเขียนค่านอกชุดจะผ่านบน SQLite แต่ทำให้ MySQL strict ตายกลางคัน
     */
    public function test_confirming_after_allocation_ran_out_marks_for_refund(): void
    {
        $phase = $this->makeOpenPhase();
        $order = $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);
        $tx = SaleTransaction::where('uuid', $order['transaction_id'])->firstOrFail();

        // มีคนอื่นซื้อจนโควตาหมดระหว่างที่รายการนี้ยังรอโอนเงิน
        $phase->update(['sold' => $phase->allocation]);

        try {
            $this->bank()->confirm($tx);
            $this->fail('ต้องโยน PurchaseException เมื่อโควตาไม่พอ');
        } catch (PurchaseException) {
            // คาดไว้แล้ว
        }

        $fresh = $tx->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertTrue($fresh->metadata['needs_refund']);
    }

    /** ปฏิเสธรายการที่ไม่มีเงินเข้า */
    public function test_rejecting_marks_the_order_failed_with_a_reason(): void
    {
        $phase = $this->makeOpenPhase();
        $order = $this->bank()->createOrder(self::BUYER, $phase->id, 100.0);
        $tx = SaleTransaction::where('uuid', $order['transaction_id'])->firstOrFail();

        $rejected = $this->bank()->reject($tx, 'ไม่พบเงินเข้าภายใน 3 วัน', 'admin@tpix.test');

        $this->assertSame('failed', $rejected->status);
        $this->assertSame('ไม่พบเงินเข้าภายใน 3 วัน', $rejected->metadata['reject_reason']);
        $this->assertSame(0.0, (float) $phase->fresh()->sold);
    }

    // =========================================================================
    // ปิดทางคริปโตจริงที่หลังบ้าน
    // =========================================================================

    /**
     * รอบขายที่รับแค่บัตร/โอนเงิน ต้องปฏิเสธการจ่ายด้วยคริปโต.
     *
     * ซ่อนปุ่มในหน้าเว็บอย่างเดียวไม่พอ — ปลายทาง /token-sale/purchase ยังอยู่
     * ถ้าไม่มีด่านนี้ ใครก็ยิง API ตรงเพื่อโอนไปยังกระเป๋า BSC เดิมได้
     * ซึ่งเป็นกระเป๋าที่ยังพิสูจน์ไม่ได้ว่ามีใครถือกุญแจ (nonce 0 ยอด 0)
     */
    public function test_crypto_payment_is_refused_when_sale_accepts_fiat_only(): void
    {
        $phase = $this->makeOpenPhase();

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('This payment method is not accepted');

        app(TokenSaleService::class)->assertPurchasable(self::BUYER, $phase->id, 'USDT', 100.0);
    }

    /** USD ต้องผ่าน เพราะรอบขายรับ CARD/BANK ซึ่งบันทึกเป็น USD */
    public function test_usd_is_accepted_when_sale_accepts_card_or_bank(): void
    {
        $phase = $this->makeOpenPhase();

        $result = app(TokenSaleService::class)->assertPurchasable(self::BUYER, $phase->id, 'USD', 100.0);

        $this->assertSame(1000.0, $result['tpix_amount']);
    }

    /** รอบขายเก่าที่ไม่ได้ระบุช่องทางไว้ ต้องไม่ถูกกระทบ */
    public function test_sales_without_declared_currencies_stay_unrestricted(): void
    {
        // ทางคริปโตยังต้องมีกระเป๋ารับเงินตามเดิม — ตั้งให้ครบก่อน
        $phase = $this->makeOpenPhase([
            'accept_currencies' => [],
            'sale_wallet_address' => '0x'.str_repeat('ab', 20),
        ]);

        $result = app(TokenSaleService::class)->assertPurchasable(self::BUYER, $phase->id, 'USDT', 100.0);

        $this->assertSame(1000.0, $result['tpix_amount']);
    }

    // =========================================================================
    // กระเป๋าจ่ายเหรียญ = กระเป๋าค่าบริการ
    // =========================================================================

    /** เจ้าของสั่งให้ใช้กระเป๋าใบเดียวกับที่รับค่าบริการ */
    public function test_payout_wallet_follows_the_revenue_wallet_setting(): void
    {
        SiteSetting::set('revenue', 'tpix_wallet', '0x2112b98e3ec5A252b7b2A8f02d498B64a2186A7f');

        $this->assertSame(
            '0x2112b98e3ec5A252b7b2A8f02d498B64a2186A7f',
            TreasuryWallet::address()
        );
        $this->assertTrue(TreasuryWallet::isConfigured());
    }

    /**
     * Stripe ต้องอ่านคีย์จากกลุ่มที่หน้าหลังบ้านบันทึกจริง ('payment').
     *
     * เกิดขึ้นจริงบน production: เจ้าของกรอก pk_live/sk_live/whsec_ และเปิดสวิตช์
     * ไว้ครบแล้ว แต่ระบบไปอ่านกลุ่ม 'stripe' ที่ไม่มีอยู่จริง จึงรายงานว่า
     * "ยังไม่ได้ตั้งค่า" มาตลอด — ค่าไม่เคยหาย แค่หาผิดที่
     */
    public function test_stripe_reads_keys_from_the_payment_group(): void
    {
        config(['services.stripe.secret' => '']);

        SiteSetting::set('payment', 'stripe_secret_key', 'sk_live_dummy_for_test');
        SiteSetting::set('payment', 'stripe_enabled', '1');

        $this->assertTrue(app(StripePaymentService::class)->isEnabled());
    }

    /** สวิตช์ปิดในกลุ่ม payment ต้องปิดจริง */
    public function test_stripe_respects_the_payment_group_toggle(): void
    {
        config(['services.stripe.secret' => '']);

        SiteSetting::set('payment', 'stripe_secret_key', 'sk_live_dummy_for_test');
        SiteSetting::set('payment', 'stripe_enabled', '0');

        $this->assertFalse(app(StripePaymentService::class)->isEnabled());
    }

    /** ไม่ได้ตั้งไว้ → ตกไปใช้ค่าจาก config ตามเดิม */
    public function test_payout_wallet_falls_back_to_config(): void
    {
        SiteSetting::set('revenue', 'tpix_wallet', '');
        config(['treasury.hot_wallet.address' => '0x78B81dF5345e69ef7A1af231dE1C5b1b30869C8f']);

        $this->assertSame(
            '0x78B81dF5345e69ef7A1af231dE1C5b1b30869C8f',
            TreasuryWallet::address()
        );
    }
}
