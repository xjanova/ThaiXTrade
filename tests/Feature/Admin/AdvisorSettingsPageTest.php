<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\SiteSetting;
use App\Services\AiBot\Advisor\AdvisorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — หน้ากรอกคีย์ที่ปรึกษา AI ในหลังบ้าน.
 *
 * เจ้าของสั่งว่า "ฟังก์ชันกรอก api ai ต้องกรอกได้ที่หลังบ้านได้ด้วย"
 * เทสต์ชุดนี้คุมทางเข้าจริงตั้งแต่หน้าเว็บถึงฐานข้อมูล ไม่ใช่แค่ตัวอ่านค่า
 *
 * Developed by Xman Studio.
 */
class AdvisorSettingsPageTest extends TestCase
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

    private function asAdmin(): self
    {
        $this->actingAs($this->admin, 'admin');

        return $this;
    }

    #[Test]
    public function บันทึกคีย์จากหลังบ้านแล้วที่ปรึกษาหยิบไปใช้จริง(): void
    {
        config(['aibot_advisor.providers.openai.api_key' => '']);

        $this->asAdmin()
            ->put('/admin/settings/advisor', [
                'advisor_enabled' => true,
                'advisor_openai_api_key' => 'sk-typed-in-admin',
                'advisor_openai_model' => 'gpt-4o',
                'advisor_openai_max_calls' => 150,
            ])
            ->assertRedirect();

        $config = app(AdvisorSettings::class)->providerConfig('openai');

        $this->assertSame('sk-typed-in-admin', $config['api_key']);
        $this->assertSame('gpt-4o', $config['model']);
        $this->assertSame(150, $config['max_calls_per_day']);
    }

    /** คีย์ที่บันทึกแล้วต้องไม่ถูกส่งกลับไปหน้าเว็บเป็นตัวเต็ม */
    #[Test]
    public function คีย์ถูกปิดบังตอนส่งกลับไปหน้าเว็บ(): void
    {
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_openai_api_key', 'sk-super-secret-1234');

        $this->asAdmin()
            ->get('/admin/settings')
            ->assertInertia(
                fn ($page) => $page
                    ->where('settings.advisor_openai_api_key', str_repeat('*', 20).'1234')
            );
    }

    /**
     * บันทึกทับด้วยค่าที่ถูกปิดบังต้องไม่ทำลายคีย์เดิม.
     *
     * แอดมินที่เข้ามาแก้แค่ชื่อโมเดลจะส่งค่าที่ถูก mask กลับมาโดยไม่รู้ตัว
     * เขียนทับตรงๆ = คีย์กลายเป็น "********************1234" แล้วที่ปรึกษาเงียบไปเลย
     */
    #[Test]
    public function แก้แค่โมเดลแล้วคีย์เดิมไม่หาย(): void
    {
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_openai_api_key', 'sk-super-secret-1234');

        $this->asAdmin()->put('/admin/settings/advisor', [
            'advisor_openai_api_key' => str_repeat('*', 20).'1234',
            'advisor_openai_model' => 'gpt-4o-mini',
        ]);

        $this->assertSame(
            'sk-super-secret-1234',
            SiteSetting::get(AdvisorSettings::GROUP, 'advisor_openai_api_key'),
        );
    }

    #[Test]
    public function หน้าตั้งค่าบอกว่าคีย์มาจากไหน(): void
    {
        config(['aibot_advisor.providers.gemini.api_key' => 'from-env']);

        $this->asAdmin()
            ->get('/admin/settings')
            ->assertInertia(
                fn ($page) => $page
                    ->where('advisorStatus.providers.gemini.configured', true)
                    ->where('advisorStatus.providers.gemini.source', 'env')
                    ->where('advisorStatus.providers.openai.configured', false)
                    ->where('advisorStatus.providers.openai.source', 'none')
            );
    }

    #[Test]
    public function ปุ่มทดสอบบอกเหตุผลเมื่อยังไม่มีคีย์(): void
    {
        config(['aibot_advisor.providers.openai.api_key' => '']);

        $this->asAdmin()
            ->post('/admin/settings/advisor/test', ['provider' => 'openai'])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'ยังไม่ได้ตั้งคีย์'));
    }

    #[Test]
    public function ปุ่มทดสอบรับเฉพาะผู้ให้บริการที่รู้จัก(): void
    {
        $this->asAdmin()
            ->post('/admin/settings/advisor/test', ['provider' => 'ผู้ให้บริการปลอม'])
            ->assertSessionHasErrors('provider');
    }

    #[Test]
    public function คนนอกเข้าหน้าตั้งค่าไม่ได้(): void
    {
        $this->put('/admin/settings/advisor', ['advisor_openai_api_key' => 'sk-attacker'])
            ->assertRedirect('/admin/login');

        $this->assertNull(SiteSetting::get(AdvisorSettings::GROUP, 'advisor_openai_api_key'));
    }

    /**
     * คีย์ของกลุ่ม advisor กับกลุ่ม ai ต้องไม่ทับกันตอนแบนลงเป็น map ชั้นเดียว.
     *
     * หน้า /admin/settings ใช้ `key` เป็นดัชนีโดยไม่รวม group — ตั้งชื่อซ้ำเมื่อไหร่
     * คีย์สร้างรูปกับคีย์ที่ปรึกษาจะกลายเป็นช่องเดียวกันโดยไม่มีอะไรฟ้อง
     */
    #[Test]
    public function คีย์สร้างรูปกับคีย์ที่ปรึกษาไม่ทับกันในหน้าเว็บ(): void
    {
        SiteSetting::set('ai', 'gemini_api_key', 'key-for-images-9999');
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_gemini_api_key', 'key-for-advice-1111');

        $this->asAdmin()
            ->get('/admin/settings')
            ->assertInertia(
                fn ($page) => $page
                    ->where('settings.gemini_api_key', str_repeat('*', 20).'9999')
                    ->where('settings.advisor_gemini_api_key', str_repeat('*', 20).'1111')
            );
    }
}
