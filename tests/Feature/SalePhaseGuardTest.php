<?php

namespace Tests\Feature;

use App\Exceptions\PurchaseException;
use App\Models\SalePhase;
use App\Models\TokenSale;
use App\Services\StripePaymentService;
use App\Services\TokenSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TPIX TRADE — ทุกทางที่รับเงินต้องใช้ด่าน "เฟสเปิดอยู่จริงไหม" ตัวเดียวกัน.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * เหตุการณ์จริงที่เทสต์ชุดนี้กันไม่ให้เกิดซ้ำ (21 ส.ค. 2026)
 * ═══════════════════════════════════════════════════════════════════════════
 * เฟส "Private Sale" ค้างสถานะ active ไว้ทั้งที่ ends_at ผ่านมา 3 เดือน
 * ด่านช่วงเวลามีอยู่ที่ processPurchase() ทางเดียว ส่วน preview กับ Stripe ไม่มีเลย
 *
 *   - /preview ตอบว่า "จ่าย 10 USDT ได้ 200 TPIX" ตามปกติ (ยิงจริงบน production แล้ว)
 *     ผู้ใช้เชื่อ → โอน BNB/USDT บน BSC จริง → หลังบ้านค่อยปฏิเสธ
 *     ลำดับคือ "จ่ายก่อน แล้วค่อยยื่น tx_hash" จึงย้อนไม่ได้ เงินหายโดยไม่มีแถวบันทึก
 *
 *   - Stripe รับ phase_id อะไรก็ได้ที่ยิงมา → เลือกเฟสถูกสุด ($0.05) ได้ตลอดกาล
 *     ทั้งที่รอบจริงเดินไปถึง Public Sale ($0.10) แล้ว = ส่วนลด 50% ให้ใครก็ได้
 *
 * @see TokenSaleService::assertPhaseOpen()
 *
 * Developed by Xman Studio.
 */
class SalePhaseGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makePhase(?string $startsAt, ?string $endsAt, string $status = 'active'): SalePhase
    {
        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'sale_wallet_address' => '0x'.str_repeat('ab', 20),
        ]);

        return SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Private Sale',
            'slug' => 'private-sale',
            'phase_order' => 1,
            'price_usd' => 0.05,
            'allocation' => 200000000,
            'min_purchase' => 100,
            'max_purchase' => 10000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    // =========================================================================
    // preview — ด่านสุดท้ายก่อนผู้ใช้เปิดกระเป๋าจ่ายเงิน
    // =========================================================================

    /**
     * เฟสหมดอายุ → preview ต้องปฏิเสธ ไม่ใช่ตอบราคาให้.
     *
     * นี่คือบั๊กตัวจริงที่ทำให้เงินหาย — preview ที่ตอบสำเร็จคือคำสัญญากับลูกค้า
     * ว่าถ้าโอนเงินมาจะได้เหรียญ
     */
    public function test_preview_is_rejected_after_phase_ended(): void
    {
        $phase = $this->makePhase(
            now()->subMonths(5)->toDateTimeString(),
            now()->subMonths(3)->toDateTimeString(),
        );

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('This phase has already ended.');

        app(TokenSaleService::class)->calculatePurchasePreview($phase->id, 'USDT', 10.0);
    }

    /** เฟสที่ยังไม่ถึงเวลาเปิด → preview ต้องปฏิเสธเช่นกัน */
    public function test_preview_is_rejected_before_phase_starts(): void
    {
        $phase = $this->makePhase(
            now()->addDays(7)->toDateTimeString(),
            now()->addDays(37)->toDateTimeString(),
        );

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('This phase has not started yet.');

        app(TokenSaleService::class)->calculatePurchasePreview($phase->id, 'USDT', 10.0);
    }

    /**
     * API ต้องบอกเหตุผลจริงด้วยโค้ด PHASE_CLOSED ไม่ใช่กลบเป็น "Operation failed".
     *
     * หน้าเว็บใช้โค้ดนี้ปิดปุ่มซื้อทันที ถ้ากลบเป็นข้อความรวม หน้าเว็บจะแยกไม่ออก
     * ระหว่าง "ระบบล่ม" กับ "รอบขายปิด" แล้วปล่อยให้กดจ่ายเงินต่อได้
     */
    public function test_preview_endpoint_reports_phase_closed_honestly(): void
    {
        $phase = $this->makePhase(
            now()->subMonths(5)->toDateTimeString(),
            now()->subMonths(3)->toDateTimeString(),
        );

        $this->postJson('/api/v1/token-sale/preview', [
            'phase_id' => $phase->id,
            'currency' => 'USDT',
            'amount' => 10,
        ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'PHASE_CLOSED')
            ->assertJsonPath('error.message', 'This phase has already ended.');
    }

    /** เฟสที่เปิดอยู่จริง → preview ต้องคำนวณให้ตามปกติ */
    public function test_preview_works_while_phase_is_open(): void
    {
        $phase = $this->makePhase(
            now()->subDay()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
        );

        $preview = app(TokenSaleService::class)->calculatePurchasePreview($phase->id, 'USDT', 10.0);

        $this->assertSame($phase->id, $preview['phase_id']);
        $this->assertSame(200.0, $preview['tpix_amount']);
    }

    // =========================================================================
    // Stripe — ประตูที่สองที่เคยข้ามด่านทั้งหมด
    // =========================================================================

    /**
     * ทางบัตรต้องใช้ด่านเดียวกัน — ต้องกันตอน "สร้าง session" ไม่ใช่ตอน webhook
     * เพราะ webhook มาหลังลูกค้ารูดบัตรแล้ว ปฏิเสธตอนนั้นเท่ากับรับเงินไปเฉยๆ.
     */
    public function test_stripe_checkout_is_rejected_after_phase_ended(): void
    {
        $phase = $this->makePhase(
            now()->subMonths(5)->toDateTimeString(),
            now()->subMonths(3)->toDateTimeString(),
        );

        // ต้องมีกุญแจก่อน ไม่งั้นจะติดด่าน isEnabled() ก่อนถึงด่านเฟส
        // (ด่านนั้นถูกทดสอบแยกใน test_stripe_is_disabled_when_secret_key_is_missing)
        config(['services.stripe.secret' => 'sk_test_dummy_for_guard_test']);

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('This phase has already ended.');

        app(StripePaymentService::class)->createCheckoutSession(
            10.0,
            '0x'.str_repeat('cd', 20),
            $phase->id,
        );
    }

    /**
     * ไม่มีกุญแจ Stripe = ปิด ไม่ว่าสวิตช์ในหลังบ้านจะเปิดไว้แค่ไหน.
     *
     * เดิมอ่านแค่สวิตช์ที่ default เป็น true → ระบบบอกว่าจ่ายบัตรได้ทั้งที่ยังไม่เคย
     * ตั้งกุญแจ พอผู้ใช้กดจริง Stripe โยน exception ดิบเป็น 500 แทน 503
     */
    public function test_stripe_is_disabled_when_secret_key_is_missing(): void
    {
        config(['services.stripe.secret' => '']);

        $this->assertFalse(app(StripePaymentService::class)->isEnabled());
    }

    // =========================================================================
    // คำสั่งเลื่อนสถานะเฟสอัตโนมัติ
    // =========================================================================

    /**
     * เฟสที่เลยวันปิด ต้องถูกปิดเอง และเฟสถัดไปที่ถึงกำหนดต้องถูกเปิดให้.
     *
     * ถ้าไม่มีตัวนี้ สถานะในฐานข้อมูลจะค้างไม่ตรงกับความจริงเงียบๆ แบบที่เกิดจริง
     * มาแล้ว 3 เดือน โดยไม่มีสัญญาณเตือนใดๆ
     */
    public function test_advance_command_closes_expired_phase_and_opens_next(): void
    {
        $phase = $this->makePhase(
            now()->subMonths(3)->toDateTimeString(),
            now()->subMonth()->toDateTimeString(),
        );

        $next = SalePhase::create([
            'token_sale_id' => $phase->token_sale_id,
            'name' => 'Pre-Sale',
            'slug' => 'pre-sale',
            'phase_order' => 2,
            'price_usd' => 0.08,
            'allocation' => 300000000,
            'min_purchase' => 50,
            'max_purchase' => 5000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'upcoming',
            'starts_at' => now()->subMonth()->toDateTimeString(),
            'ends_at' => now()->addMonth()->toDateTimeString(),
        ]);

        $this->artisan('sale:advance-phases')->assertSuccessful();

        $this->assertSame('completed', $phase->fresh()->status);
        $this->assertSame('active', $next->fresh()->status);

        // และเฟสที่เพิ่งเปิดต้องซื้อได้จริง ไม่ใช่แค่ป้ายเปลี่ยน
        $preview = app(TokenSaleService::class)->calculatePurchasePreview($next->id, 'USDT', 8.0);
        $this->assertSame(100.0, $preview['tpix_amount']);
    }

    /**
     * ห้ามเปิดเฟสถัดไปถ้ายังมีเฟสที่เปิดขายอยู่จริง.
     *
     * ถ้าเปิดพร้อมกันหลายเฟส จะมีหลายราคาเปิดขายพร้อมกัน แล้ว getActivePhase()
     * ที่ใช้ ->first() จะหยิบมาแบบที่ทายไม่ได้ — ลูกค้าอาจได้ราคาที่เราไม่ได้ตั้งใจขาย
     */
    public function test_advance_command_leaves_a_healthy_phase_alone(): void
    {
        $phase = $this->makePhase(
            now()->subDay()->toDateTimeString(),
            now()->addMonth()->toDateTimeString(),
        );

        SalePhase::create([
            'token_sale_id' => $phase->token_sale_id,
            'name' => 'Pre-Sale',
            'slug' => 'pre-sale',
            'phase_order' => 2,
            'price_usd' => 0.08,
            'allocation' => 300000000,
            'min_purchase' => 50,
            'max_purchase' => 5000000,
            'status' => 'upcoming',
            'starts_at' => now()->subDay()->toDateTimeString(),
            'ends_at' => now()->addMonth()->toDateTimeString(),
        ]);

        $this->artisan('sale:advance-phases')->assertSuccessful();

        $this->assertSame('active', $phase->fresh()->status);
        $this->assertSame(
            1,
            SalePhase::where('status', 'active')->count(),
            'ต้องมีเฟสที่เปิดขายได้เพียงเฟสเดียวเท่านั้น'
        );
    }

    // =========================================================================
    // ด่านตรวจล่วงหน้า — ต้องจับได้ "ก่อน" เงินออกจากกระเป๋า
    // =========================================================================

    /**
     * เฟสปิดแล้ว → precheck ต้องปฏิเสธ ผู้ใช้จะได้ไม่เดินไปถึงจุดจ่ายเงิน.
     */
    public function test_precheck_rejects_closed_phase(): void
    {
        $phase = $this->makePhase(
            now()->subMonths(5)->toDateTimeString(),
            now()->subMonths(3)->toDateTimeString(),
        );

        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('This phase has already ended.');

        app(TokenSaleService::class)->assertPurchasable(
            '0x'.str_repeat('cd', 20),
            $phase->id,
            'USDT',
            100.0,
        );
    }

    /**
     * ยังไม่ได้ตั้งกระเป๋ารับเงิน → ต้องหยุดตั้งแต่ต้น.
     *
     * ถ้าปล่อยผ่าน ผู้ใช้จะโอนเงินไปยังที่อยู่ว่างหรือที่อยู่ที่ไม่มีใครถือกุญแจ
     */
    public function test_precheck_rejects_when_sale_wallet_is_not_configured(): void
    {
        $phase = $this->makePhase(
            now()->subDay()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
        );

        $phase->tokenSale->update(['sale_wallet_address' => null]);

        $this->expectException(PurchaseException::class);

        app(TokenSaleService::class)->assertPurchasable(
            '0x'.str_repeat('cd', 20),
            $phase->id,
            'USDT',
            100.0,
        );
    }

    /** ต่ำกว่าขั้นต่ำของเฟส → ต้องบอกก่อน ไม่ใช่ให้จ่ายมาแล้วค่อยปฏิเสธ */
    public function test_precheck_rejects_below_minimum_purchase(): void
    {
        $phase = $this->makePhase(
            now()->subDay()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
        );

        // ขั้นต่ำ 100 TPIX ที่ราคา $0.05 = ต้องจ่ายอย่างน้อย $5
        $this->expectException(PurchaseException::class);
        $this->expectExceptionMessage('Minimum purchase');

        app(TokenSaleService::class)->assertPurchasable(
            '0x'.str_repeat('cd', 20),
            $phase->id,
            'USDT',
            1.0,
        );
    }

    /** เฟสเปิดและข้อมูลครบ → ต้องผ่านพร้อมคืนข้อมูลที่หน้าเว็บใช้ต่อได้ */
    public function test_precheck_passes_and_returns_payment_target(): void
    {
        $phase = $this->makePhase(
            now()->subDay()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
        );

        $result = app(TokenSaleService::class)->assertPurchasable(
            '0x'.str_repeat('cd', 20),
            $phase->id,
            'USDT',
            100.0,
        );

        $this->assertSame($phase->id, $result['phase_id']);
        $this->assertSame(2000.0, $result['tpix_amount']);
        $this->assertSame('0x'.str_repeat('ab', 20), $result['sale_wallet_address']);
    }

    /**
     * ปลายทาง precheck ต้องอยู่หลังการยืนยันเจ้าของกระเป๋า.
     *
     * ถ้าหลุดไปเป็นปลายทางสาธารณะ มันจะกลับไปมีจุดบอดเดิมของ /preview ทันที
     * คือตรวจไม่ได้ว่าเซสชันกระเป๋าหมดอายุหรือยัง
     */
    public function test_precheck_endpoint_requires_wallet_verification(): void
    {
        $phase = $this->makePhase(
            now()->subDay()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
        );

        $response = $this->postJson('/api/v1/token-sale/precheck', [
            'wallet_address' => '0x'.str_repeat('cd', 20),
            'phase_id' => $phase->id,
            'currency' => 'USDT',
            'amount' => 100,
        ]);

        $this->assertContains(
            $response->status(),
            [401, 403],
            'ปลายทางนี้ต้องไม่เปิดสาธารณะ'
        );
    }

    /** โหมดทดลองต้องไม่แตะข้อมูลจริง */
    public function test_advance_command_dry_run_changes_nothing(): void
    {
        $phase = $this->makePhase(
            now()->subMonths(3)->toDateTimeString(),
            now()->subMonth()->toDateTimeString(),
        );

        $this->artisan('sale:advance-phases --dry-run')->assertSuccessful();

        $this->assertSame('active', $phase->fresh()->status);
    }
}
