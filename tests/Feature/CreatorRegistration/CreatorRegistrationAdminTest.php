<?php

namespace Tests\Feature\CreatorRegistration;

use App\Models\AuditLog;
use App\Models\CreatorRegistration;
use App\Models\KycSubmission;
use App\Services\CreatorRegistration\CreatorRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * หลังบ้านตรวจใบลงทะเบียนผู้สร้างเหรียญ — อนุมัติ (กำหนดส่วนเริ่มโครงการ) / ปฏิเสธ (มีเหตุผล) / ตั้งค่า.
 *
 * เข้าหลังบ้านด้วย session ของ guard admin (ไม่ใช้ actingAs — มันสลับ guard ปริยายแล้วซ่อนบั๊กจริง)
 *
 * Developed by Xman Studio.
 */
class CreatorRegistrationAdminTest extends TestCase
{
    use CreatorRegistrationHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function submittedUuid(int $keyN = 1): string
    {
        [, $wallet] = $this->submittedRegistration($keyN);
        Auth::guard('web')->logout();

        return $this->registrationOf($wallet)->uuid;
    }

    #[Test]
    public function คิวแสดงใบที่รอตรวจ_และไม่ส่งข้อมูลส่วนตัวในตาราง(): void
    {
        $uuid = $this->submittedUuid();

        $this->asAdmin($this->makeAdmin())
            ->get('/admin/creator-registrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/CreatorRegistrations/Index')
                ->where('filters.status', 'submitted')
                ->where('counts.submitted', 1)
                ->where('registrations.data.0.uuid', $uuid)
                ->missing('registrations.data.0.form')
                ->where('settings.required', false)
                ->where('settings.default_starter_bps', 1000)
                ->where('canEditSettings', true));
    }

    #[Test]
    public function ผู้เยี่ยมชมเข้าหลังบ้านไม่ได้(): void
    {
        $uuid = $this->submittedUuid();

        $this->get('/admin/creator-registrations')->assertRedirect();
        $this->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 1000])->assertRedirect();
        $this->assertSame(CreatorRegistration::STATUS_SUBMITTED, CreatorRegistration::first()->status);
    }

    #[Test]
    public function หน้าตรวจแสดงข้อมูลที่กรอก_ky_c_และผลตรวจลายเซ็น(): void
    {
        $uuid = $this->submittedUuid();

        $this->asAdmin($this->makeAdmin())
            ->get("/admin/creator-registrations/{$uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/CreatorRegistrations/Show')
                ->where('registration.form.full_name', 'สมชาย ใจดี')
                ->where('kyc.approved', true)
                ->where('evidence.ownership_signature_valid', true)
                ->where('evidence.agreement_signature_valid', true)
                ->where('evidence.agreement_hash_matches', true)
                ->where('evidence.form_hash_matches', true));
    }

    #[Test]
    public function อนุมัติพร้อมส่วนเริ่มโครงการ_บันทึกผู้ตรวจและ_audit(): void
    {
        $uuid = $this->submittedUuid();
        $admin = $this->makeAdmin();

        $this->asAdmin($admin)
            ->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 1500])
            ->assertRedirect()
            ->assertSessionHas('success');

        $r = CreatorRegistration::where('uuid', $uuid)->first();
        $this->assertSame(CreatorRegistration::STATUS_APPROVED, $r->status);
        $this->assertSame(1500, $r->starter_allocation_bps);
        $this->assertSame($admin->id, $r->reviewed_by);
        $this->assertNotNull($r->reviewed_at);
        $this->assertTrue(AuditLog::where('action', 'creator_registration.approve')->exists());
    }

    #[Test]
    public function แอดมินระดับต่ำกว่า_super_admin_อนุมัติหรือปฏิเสธไม่ได้(): void
    {
        $uuid = $this->submittedUuid();
        $admin = $this->makeAdmin('admin', 'ops@tpix.test');

        $this->asAdmin($admin)
            ->get("/admin/creator-registrations/{$uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canDecide', false));
        $this->asAdmin($admin)->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 1000])->assertForbidden();
        $this->asAdmin($admin)->post("/admin/creator-registrations/{$uuid}/reject", ['reason' => 'เหตุผลทดสอบยาวพอ'])->assertForbidden();

        $this->assertSame(CreatorRegistration::STATUS_SUBMITTED, CreatorRegistration::first()->status);
    }

    #[Test]
    public function ส่วนเริ่มโครงการเกินยี่สิบเปอร์เซ็นต์ถูกปฏิเสธ(): void
    {
        $uuid = $this->submittedUuid();

        $this->asAdmin($this->makeAdmin())
            ->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 2001])
            ->assertSessionHasErrors('starter_allocation_bps');

        $this->assertSame(CreatorRegistration::STATUS_SUBMITTED, CreatorRegistration::first()->status);
    }

    #[Test]
    public function อนุมัติไม่ได้ถ้า_ky_c_ถูกถอนหลังส่งตรวจ(): void
    {
        $uuid = $this->submittedUuid();
        KycSubmission::query()->update(['status' => KycSubmission::STATUS_REJECTED]);

        $this->asAdmin($this->makeAdmin())
            ->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 1000])
            ->assertSessionHasErrors('registration');

        $this->assertSame(CreatorRegistration::STATUS_SUBMITTED, CreatorRegistration::first()->status);
    }

    #[Test]
    public function อนุมัติไม่ได้ถ้าข้อมูลถูกแก้ในฐานข้อมูลหลังเซ็น(): void
    {
        $uuid = $this->submittedUuid();
        $r = CreatorRegistration::first();
        $r->form_data = array_merge($r->form_data, ['project_name' => 'แอบแก้']);
        $r->save();

        $this->asAdmin($this->makeAdmin())
            ->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 1000])
            ->assertSessionHasErrors('registration');

        $this->assertFalse(app(CreatorRegistrationService::class)->verifyEvidence($r->fresh())['form_hash_matches']);
    }

    #[Test]
    public function อนุมัติซ้ำไม่ได้(): void
    {
        $uuid = $this->submittedUuid();
        $admin = $this->makeAdmin();

        $this->asAdmin($admin)->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 1000])->assertSessionHas('success');
        $this->asAdmin($admin)->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 2000])->assertSessionHasErrors('registration');

        $this->assertSame(1000, CreatorRegistration::first()->starter_allocation_bps);
    }

    #[Test]
    public function ปฏิเสธต้องมีเหตุผล_และผู้สร้างแก้แล้วส่งใหม่ได้(): void
    {
        [$priv, $wallet] = $this->submittedRegistration();
        $uuid = $this->registrationOf($wallet)->uuid;
        $admin = $this->makeAdmin();

        $this->asAdmin($admin)->post("/admin/creator-registrations/{$uuid}/reject", ['reason' => 'สั้น'])->assertSessionHasErrors('reason');
        $this->asAdmin($admin)
            ->post("/admin/creator-registrations/{$uuid}/reject", ['reason' => 'เอกสารเว็บไซต์โครงการยังไม่ครบ'])
            ->assertSessionHas('success');

        $r = $this->registrationOf($wallet);
        $this->assertSame(CreatorRegistration::STATUS_REJECTED, $r->status);
        $this->assertSame('เอกสารเว็บไซต์โครงการยังไม่ครบ', $r->rejection_reason);

        // audit เก็บแค่ความยาวเหตุผล ไม่เก็บข้อความ
        $log = AuditLog::where('action', 'creator_registration.reject')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('เอกสารเว็บไซต์', json_encode($log->toArray(), JSON_UNESCAPED_UNICODE));

        // ผู้สร้างแก้แล้วเซ็นส่งใหม่ (session ของผู้สร้างยังอยู่)
        $this->saveForm(['website' => 'https://siamfarm.example/about'])->assertOk();
        $this->assertSame(CreatorRegistration::STATUS_DRAFT, $this->registrationOf($wallet)->status);
        $this->signAgreement($priv)->assertOk()->assertJsonPath('data.registration.status', 'submitted');
        $this->assertNull($this->registrationOf($wallet)->rejection_reason);
    }

    #[Test]
    public function แอดมินทั่วไปตั้งค่าไม่ได้(): void
    {
        $this->asAdmin($this->makeAdmin('admin', 'ops@tpix.test'))
            ->put('/admin/creator-registrations/settings', ['required' => true, 'default_starter_bps' => 500])
            ->assertForbidden();

        $this->assertFalse(app(CreatorRegistrationService::class)->isRequired());
    }

    #[Test]
    public function super_admin_ตั้งค่าได้(): void
    {
        $this->asAdmin($this->makeAdmin())
            ->put('/admin/creator-registrations/settings', ['required' => true, 'default_starter_bps' => 500])
            ->assertSessionHas('success');

        Cache::flush();
        $service = app(CreatorRegistrationService::class);
        $this->assertTrue($service->isRequired());
        $this->assertSame(500, $service->defaultStarterBps());
    }

    #[Test]
    public function ส่วนเริ่มโครงการตั้งต้นเกินเพดานถูกปฏิเสธ(): void
    {
        $this->asAdmin($this->makeAdmin())
            ->put('/admin/creator-registrations/settings', ['required' => false, 'default_starter_bps' => 2500])
            ->assertSessionHasErrors('default_starter_bps');
    }

    #[Test]
    public function แอดมินดาวน์โหลด_pd_f_ของใบได้(): void
    {
        $uuid = $this->submittedUuid();

        $response = $this->asAdmin($this->makeAdmin())->get("/admin/creator-registrations/{$uuid}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
