<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\SiteSetting;
use App\Models\TradingFeeTier;
use App\Services\Trading\TradingFeeQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — หน้าตั้งค่าบริการวางไม้ในหลังบ้าน.
 *
 * เจ้าของสั่งว่า "เราตั้งอัตราเองได้หมดหลายขนาด" — ต้องเพิ่ม/แก้/ลบขั้นได้จริง
 * ไม่ใช่แค่ดูได้
 *
 * ⚠️ ข้อที่สำคัญที่สุดคือการเตือนช่องโหว่ของขั้นบันได
 *    ตั้ง 0-100 กับ 500-1000 แล้วไม้ขนาด 300 ตกไปจ่ายแบบเดิมเงียบๆ
 *    แอดมินจะไม่รู้จนกว่าจะมีคนบ่นว่าโดนเก็บแพง
 *
 * Developed by Xman Studio.
 */
class TradingFeeAdminTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function asAdmin(): self
    {
        $this->actingAs($this->admin, 'admin');

        return $this;
    }

    private function tier(float $min, ?float $max, float $fee): TradingFeeTier
    {
        return TradingFeeTier::create([
            'min_order_usd' => $min,
            'max_order_usd' => $max,
            'fee_tpix' => $fee,
            'is_active' => true,
        ]);
    }

    // ── สิทธิ์ ───────────────────────────────────────────────────────────────

    #[Test]
    public function คนนอกเข้าหน้าตั้งค่าไม่ได้(): void
    {
        $this->get('/admin/trading-fees')->assertRedirect('/admin/login');

        $this->post('/admin/trading-fees', [
            'min_order_usd' => 0, 'max_order_usd' => 100, 'fee_tpix' => 1, 'is_active' => true,
        ])->assertRedirect('/admin/login');

        $this->assertSame(0, TradingFeeTier::count());
    }

    // ── ตารางขั้นบันได ───────────────────────────────────────────────────────

    #[Test]
    public function หน้าตั้งค่าแสดงขั้นบันไดและสถิติ(): void
    {
        $this->tier(0, 100, 0.5);
        $this->tier(100, null, 2);

        $this->asAdmin()
            ->get('/admin/trading-fees')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/TradingFees/Index')
                    ->has('tiers', 2)
                    ->has('stats')
                    ->has('settings.tpix_min_topup')
                    ->has('settings.refund_gas_fee')
            );
    }

    #[Test]
    public function เพิ่มขั้นใหม่ได้(): void
    {
        $this->asAdmin()->post('/admin/trading-fees', [
            'label' => 'ไม้ใหญ่พิเศษ',
            'min_order_usd' => 10000,
            'max_order_usd' => null,
            'fee_tpix' => 12,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('trading_fee_tiers', ['label' => 'ไม้ใหญ่พิเศษ', 'fee_tpix' => 12]);
    }

    #[Test]
    public function แก้และลบขั้นได้(): void
    {
        $tier = $this->tier(0, 100, 0.5);

        $this->asAdmin()->put("/admin/trading-fees/{$tier->id}", [
            'label' => 'ไม้เล็ก',
            'min_order_usd' => 0,
            'max_order_usd' => 250,
            'fee_tpix' => 0.75,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame(0.75, (float) $tier->fresh()->fee_tpix);

        $this->asAdmin()->delete("/admin/trading-fees/{$tier->id}")->assertRedirect();
        $this->assertSame(0, TradingFeeTier::count());
    }

    /**
     * ขั้นที่ปลายบนต่ำกว่าปลายล่างคือขั้นที่ไม่มีวันถูกเลือก.
     *
     * ปล่อยผ่านแล้วแอดมินจะงงว่าตั้งไปทำไมไม่มีผล และหาสาเหตุไม่เจอ
     */
    #[Test]
    public function ขั้นที่ปลายบนต่ำกว่าปลายล่างบันทึกไม่ได้(): void
    {
        $this->asAdmin()->post('/admin/trading-fees', [
            'min_order_usd' => 500,
            'max_order_usd' => 100,
            'fee_tpix' => 1,
            'is_active' => true,
        ])->assertSessionHasErrors('max_order_usd');

        $this->assertSame(0, TradingFeeTier::count());
    }

    // ── เตือนช่องโหว่ ────────────────────────────────────────────────────────

    /** ⚠️ ข้อที่กันความเสียหายเงียบที่สุดในหน้านี้ */
    #[Test]
    public function เตือนช่วงที่ไม่มีขั้นรองรับ(): void
    {
        $this->tier(0, 100, 0.5);
        $this->tier(500, 1000, 2);

        $gaps = $this->asAdmin()->get('/admin/trading-fees')->assertOk()->inertiaProps('coverageGaps');

        // ช่องโหว่ที่ต้องเจอ: 100-500 (ตรงกลาง) และ 1000 ขึ้นไป (ไม่มีขั้นบนสุด)
        $this->assertCount(2, $gaps);
        $this->assertEquals(100, $gaps[0]['from']);
        $this->assertEquals(500, $gaps[0]['to']);
        $this->assertEquals(1000, $gaps[1]['from']);
        $this->assertNull($gaps[1]['to']);
    }

    #[Test]
    public function ขั้นบันไดที่ครอบคลุมครบไม่มีคำเตือน(): void
    {
        $this->tier(0, 100, 0.5);
        $this->tier(100, 1000, 2);
        $this->tier(1000, null, 5);

        $gaps = $this->asAdmin()->get('/admin/trading-fees')->assertOk()->inertiaProps('coverageGaps');

        $this->assertSame([], $gaps);
    }

    #[Test]
    public function ยังไม่มีขั้นเลยต้องเตือนว่าไม่ครอบคลุมทั้งหมด(): void
    {
        $gaps = $this->asAdmin()->get('/admin/trading-fees')->assertOk()->inertiaProps('coverageGaps');

        $this->assertCount(1, $gaps);
        $this->assertEquals(0, $gaps[0]['from']);
        $this->assertNull($gaps[0]['to']);
    }

    // ── ค่าตั้งรวม ───────────────────────────────────────────────────────────

    #[Test]
    public function บันทึกค่าตั้งรวมแล้วมีผลกับระบบจริง(): void
    {
        $this->asAdmin()->put('/admin/trading-fees/settings', [
            'tpix_fee_enabled' => true,
            'tpix_topup_wallet' => '0xABCDEF0123456789ABCDEF0123456789ABCDEF01',
            'tpix_topup_chain_id' => 4289,
            'tpix_min_topup' => 25,
            'refund_gas_fee' => 0.5,
            'ticket_ttl_minutes' => 20,
        ])->assertRedirect();

        $this->assertTrue(app(TradingFeeQuoteService::class)->enabled());
        // เก็บเป็นตัวพิมพ์เล็กเสมอ — เทียบกับ tx.to ที่อ่านจากเชนต้องตรงกัน
        $this->assertSame(
            '0xabcdef0123456789abcdef0123456789abcdef01',
            SiteSetting::get('trading', 'tpix_topup_wallet'),
        );
        $this->assertEquals(25, SiteSetting::get('trading', 'tpix_min_topup'));
    }

    /** กระเป๋ารับเงินที่ไม่ใช่ที่อยู่จริง = เติมเครดิตไม่มีวันเข้า ต้องกันไว้ */
    #[Test]
    public function กระเป๋ารับเงินรูปแบบผิดบันทึกไม่ได้(): void
    {
        $this->asAdmin()->put('/admin/trading-fees/settings', [
            'tpix_fee_enabled' => true,
            'tpix_topup_wallet' => 'ไม่ใช่ที่อยู่กระเป๋า',
            'tpix_topup_chain_id' => 4289,
            'tpix_min_topup' => 10,
            'refund_gas_fee' => 0,
            'ticket_ttl_minutes' => 15,
        ])->assertSessionHasErrors('tpix_topup_wallet');
    }

    /** เว้นกระเป๋าว่างได้ = ยังไม่เปิดให้เติม (ตั้งใจ ไม่ใช่ error) */
    #[Test]
    public function เว้นกระเป๋ารับเงินว่างได้(): void
    {
        $this->asAdmin()->put('/admin/trading-fees/settings', [
            'tpix_fee_enabled' => false,
            'tpix_topup_wallet' => null,
            'tpix_topup_chain_id' => 4289,
            'tpix_min_topup' => 10,
            'refund_gas_fee' => 0,
            'ticket_ttl_minutes' => 15,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
}
