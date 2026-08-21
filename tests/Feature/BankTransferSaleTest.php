<?php

namespace Tests\Feature;

use App\Exceptions\PurchaseException;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Services\BankTransferSaleService;
use App\Services\StripePaymentService;
use App\Services\TokenSaleService;
use App\Support\TreasuryWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
