<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\SiteSetting;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ค่าบริการชำระด้วยบัตร — ตั้งจากหลังบ้าน แท็บ Payment (เจ้าของกำหนด 3% · 2026-10-21)
 *
 * เป็นเงินที่เรียกเก็บจากลูกค้าจริง จึงต้องรับแค่ตัวเลข 0–50 และหน้าซื้อต้องเห็นค่าใหม่ทันที
 *
 * Developed by Xman Studio.
 */
class CardServiceFeeSettingTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function ค่าปริยายคือสามเปอร์เซ็นต์(): void
    {
        $this->assertSame(3.0, app(StripePaymentService::class)->cardFeePercent());
    }

    #[Test]
    public function แอดมินตั้งค่าบริการบัตรได้แล้วระบบใช้ค่าใหม่(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->put('/admin/settings/payment', ['card_service_fee_percent' => '4.5'])
            ->assertRedirect();

        $this->assertSame('4.5', (string) SiteSetting::get('payment', 'card_service_fee_percent'));
        $this->assertSame(4.5, app(StripePaymentService::class)->cardFeePercent());
    }

    #[Test]
    public function ค่าที่ไม่ใช่ตัวเลขหรือเกินห้าสิบถูกปฏิเสธ(): void
    {
        foreach (['abc', '-1', '51'] as $bad) {
            $this->actingAs($this->admin, 'admin')
                ->put('/admin/settings/payment', ['card_service_fee_percent' => $bad])
                ->assertSessionHas('error');
        }

        $this->assertNull(SiteSetting::get('payment', 'card_service_fee_percent'));
    }

    #[Test]
    public function วันพัก_claim_ของบัตรปริยายคือหกสิบวันและแอดมินเปลี่ยนได้(): void
    {
        $this->assertSame(60, app(StripePaymentService::class)->cardClaimHoldDays());

        $this->withSession([Auth::guard('admin')->getName() => $this->admin->id])
            ->put('/admin/settings/payment', ['card_claim_hold_days' => '90'])
            ->assertRedirect();

        $this->assertSame(90, app(StripePaymentService::class)->cardClaimHoldDays());
    }

    #[Test]
    public function วันพักที่ไม่ใช่ตัวเลขหรือนอกช่วงถูกปฏิเสธ(): void
    {
        $this->withSession([Auth::guard('admin')->getName() => $this->admin->id]);

        foreach (['', 'abc', '-1', '181'] as $bad) {
            $this->put('/admin/settings/payment', ['card_claim_hold_days' => $bad])->assertSessionHas('error');
        }

        $this->assertNull(SiteSetting::get('payment', 'card_claim_hold_days'));
    }
}
