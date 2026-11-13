<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Chain;
use App\Models\SiteSetting;
use App\Models\Transaction;
use App\Services\FeeCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * หลังบ้านของค่าธรรมเนียมเทรด — ค่าที่บันทึกต้องปลอดภัย และรายได้ที่รายงานต้องตรงกับไม้จริง.
 *
 * อาการเดิม (ไล่เส้นทาง 2026-11-13):
 *   - ลบช่องอัตรา/เพดานทิ้งแล้วกดบันทึก ผ่านโดยไม่ตรวจ → ค่าธรรมเนียมทุกไม้เป็น 0
 *   - หน้า Dashboard นับรายได้สวอปจาก status = 'completed' ทั้งที่ไม้สวอปถูกบันทึกเป็น 'confirmed'
 *     → รายได้ค่าธรรมเนียมสวอปขึ้น 0 ตลอดไป และบวก fee_amount ต่างหน่วยเข้าด้วยกัน
 *
 * เข้าระบบผ่าน session แบบที่เบราว์เซอร์ทำ (ไม่ใช้ actingAs ซึ่งสลับ guard ปริยายก่อนคำขอเริ่ม)
 *
 * Developed by Xman Studio
 */
class TradingFeeBackOfficeTest extends TestCase
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

        $this->withSession([Auth::guard('admin')->getName() => $this->admin->id]);

        SiteSetting::set('trading', 'default_fee_rate', '0.3', 'number');
        SiteSetting::set('trading', 'max_fee_rate', '5', 'number');
    }

    private function saveTrading(array $fields)
    {
        return $this->from('/admin/settings')->put('/admin/settings/trading', $fields);
    }

    private function swapRow(string $status, ?string $feeUsd, int $chainRowId, array $overrides = []): Transaction
    {
        $tx = Transaction::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'type' => 'swap',
            'wallet_address' => '0x'.str_repeat('1', 40),
            'chain_id' => $chainRowId,
            'from_token' => '0x'.str_repeat('a', 40),
            'to_token' => '0x'.str_repeat('b', 40),
            'from_amount' => '1000000',
            'to_amount' => '10',
            'fee_amount' => '3000',
            'fee_currency' => 'PEPE',
            'tx_hash' => '0x'.Str::random(64),
            'status' => $status,
        ], $overrides));

        // fee_usd ไม่อยู่ใน fillable — เขียนตรงแบบเดียวกับตัวบันทึกไม้
        $tx->forceFill(['fee_usd' => $feeUsd])->save();

        return $tx;
    }

    // ── บันทึกอัตราค่าธรรมเนียม ─────────────────────────────────────────────

    public function test_blank_or_invalid_fee_rates_are_refused_and_nothing_is_written(): void
    {
        $bad = [
            ['max_fee_rate' => ''],
            ['max_fee_rate' => '0'],
            ['max_fee_rate' => '-2'],
            ['max_fee_rate' => 'abc'],
            ['max_fee_rate' => '80'],
            ['default_fee_rate' => ''],
            ['default_fee_rate' => '-0.3'],
            ['default_fee_rate' => 'free'],
            ['default_fee_rate' => '51'],
        ];

        foreach ($bad as $fields) {
            $this->saveTrading($fields)->assertSessionHasErrors(array_key_first($fields));
        }

        $this->assertSame('0.3', (string) SiteSetting::where('group', 'trading')->where('key', 'default_fee_rate')->value('value'));
        $this->assertSame('5', (string) SiteSetting::where('group', 'trading')->where('key', 'max_fee_rate')->value('value'));
        // ไม้ถัดไปยังคิดค่าธรรมเนียมตามเดิม
        $this->assertSame(0.3, app(FeeCalculationService::class)->calculateSwapFee(100.0, 1)['fee_rate']);
    }

    public function test_valid_fee_rates_are_saved_and_charged_on_the_next_trade(): void
    {
        $fees = app(FeeCalculationService::class);
        $this->assertSame(0.3, $fees->calculateSwapFee(100.0, 1)['fee_rate']);   // อุ่นแคช

        $this->saveTrading(['default_fee_rate' => '0.25', 'max_fee_rate' => '3'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/settings');

        $this->assertSame(0.25, $fees->calculateSwapFee(100.0, 1)['fee_rate']);
    }

    public function test_zero_default_rate_is_allowed_as_a_deliberate_free_trading_setting(): void
    {
        $this->saveTrading(['default_fee_rate' => '0'])->assertSessionHasNoErrors();

        $this->assertSame(0.0, app(FeeCalculationService::class)->calculateSwapFee(100.0, 1)['fee_rate']);
    }

    public function test_saving_other_trading_fields_does_not_require_the_rate_fields(): void
    {
        $this->saveTrading(['fee_collector_wallet' => '0x'.str_repeat('f', 40)])->assertSessionHasNoErrors();

        $this->assertSame('0x'.str_repeat('f', 40), SiteSetting::get('trading', 'fee_collector_wallet'));
    }

    // ── รายได้บนหน้า Dashboard ─────────────────────────────────────────────

    public function test_dashboard_counts_confirmed_swap_fees_in_dollars_split_by_chain(): void
    {
        $bsc = Chain::where('chain_id', 56)->firstOrFail();
        $tpix = Chain::where('chain_id', 4289)->firstOrFail();

        $this->swapRow('confirmed', '30.5', $bsc->id);                       // ไม้ BSC ที่ยืนยันแล้ว
        $this->swapRow('completed', '1.25', $bsc->id);                       // สถานะเก่า — ยังนับ
        $this->swapRow('confirmed', '0.75', $tpix->id);                      // ไม้บน TPIX Chain
        $this->swapRow('confirmed', null, $bsc->id);                         // ตีมูลค่าไม่ได้ — ไม่นับ
        $this->swapRow('pending', '99', $bsc->id);                           // ยังไม่ยืนยัน — ไม่นับ
        $this->swapRow('failed', '99', $bsc->id);                            // ไม้ล้ม — ไม่นับ
        $this->swapRow('confirmed', '99', $bsc->id, ['type' => 'deposit']);   // ไม่ใช่สวอป — ไม่นับ

        $this->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Dashboard')
                // 3,000 PEPE ต่อไม้ ต้องไม่ถูกบวกเป็น "รายได้ 9,000"
                ->where('revenue.swap_fees', fn ($v) => abs((float) $v - 32.5) < 0.0001)
                ->where('revenue.revenue_by_token.wtpix.swap', fn ($v) => abs((float) $v - 31.75) < 0.0001)
                ->where('revenue.revenue_by_token.tpix.swap', fn ($v) => abs((float) $v - 0.75) < 0.0001)
                ->where('stats.swaps24h', 3)
                ->etc());
    }

    public function test_dashboard_shows_zero_swap_revenue_when_there_are_no_settled_swaps(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('revenue.swap_fees', fn ($v) => (float) $v === 0.0)
                ->where('stats.swaps24h', 0)
                ->etc());
    }
}
