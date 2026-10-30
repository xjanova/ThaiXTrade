<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — แท็บ Security แก้ได้เฉพาะ super_admin และคีย์ลับต้องไม่หลุดลงหน้าตั้งค่า.
 *
 * ก่อนแก้: แอดมินทุกระดับปิด Turnstile ได้ แล้วยิงล็อกอินแบบไม่มีด่านกันบอทต่อ
 * ส่วน cloudflare_image_key ตกจากรายการ mask จึงถูกส่งตัวเต็มไปกับ /admin/settings
 *
 * Developed by Xman Studio.
 */
class SecuritySettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SECURITY_KEYS = [
        'turnstile_enabled',
        'turnstile_site_key',
        'turnstile_secret_key',
        'max_login_attempts',
        'lockout_duration',
    ];

    private const SITE_KEY = '0x4AAAAAAASiteKeyOldValue';

    private const SECRET_KEY = '0x4AAAAAAASecretOldValueQ7Zp';

    private const NEW_SECRET_KEY = '0x4AAAAAAASecretNewValueM3Xk';

    private const IMAGE_KEY = 'cf-worker-key-Old7Value9Tail';

    private const NEW_IMAGE_KEY = 'cf-worker-key-New4Value2Rest';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        SiteSetting::set('security', 'turnstile_enabled', '1', 'boolean');
        SiteSetting::set('security', 'turnstile_site_key', self::SITE_KEY);
        SiteSetting::set('security', 'turnstile_secret_key', self::SECRET_KEY);
    }

    private function asRole(string $role): self
    {
        $this->actingAs(AdminUser::create([
            'name' => $role,
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]), 'admin');

        return $this;
    }

    private function masked(string $secret): string
    {
        return str_repeat('*', 20).substr($secret, -4);
    }

    private function assertTurnstileUntouched(): void
    {
        $this->assertTrue(SiteSetting::get('security', 'turnstile_enabled'));
        $this->assertSame(self::SITE_KEY, SiteSetting::get('security', 'turnstile_site_key'));
        $this->assertSame(self::SECRET_KEY, SiteSetting::get('security', 'turnstile_secret_key'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lowerRoles(): array
    {
        return [
            'admin' => ['admin'],
            'moderator' => ['moderator'],
            'support' => ['support'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allRoles(): array
    {
        return ['super_admin' => ['super_admin']] + self::lowerRoles();
    }

    // ── ด่านสิทธิ์ ───────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('lowerRoles')]
    public function แอดมินระดับล่างปิด_turnstileไม่ได้(string $role): void
    {
        $this->asRole($role)
            ->put('/admin/settings/security', [
                'turnstile_enabled' => false,
                'turnstile_secret_key' => self::NEW_SECRET_KEY,
            ])
            ->assertForbidden();

        $this->assertTurnstileUntouched();
    }

    #[Test]
    public function super_adminยังบันทึกแท็บ_securityได้(): void
    {
        $this->asRole('super_admin')
            ->put('/admin/settings/security', [
                'turnstile_enabled' => false,
                'turnstile_site_key' => self::SITE_KEY,
                'turnstile_secret_key' => self::NEW_SECRET_KEY,
                'max_login_attempts' => 3,
                'lockout_duration' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertFalse(SiteSetting::get('security', 'turnstile_enabled'));
        $this->assertSame(self::NEW_SECRET_KEY, SiteSetting::get('security', 'turnstile_secret_key'));
        $this->assertSame('3', SiteSetting::get('security', 'max_login_attempts'));
        $this->assertSame('30', SiteSetting::get('security', 'lockout_duration'));
    }

    /** ด่าน route กันแค่ปลายทางเดียว — ต้องไม่มีทางอ้อมผ่านปลายทางรวมที่เปิดให้ทุกระดับ */
    #[Test]
    #[DataProvider('lowerRoles')]
    public function ปลายทางรวมเขียนกลุ่ม_securityไม่ได้(string $role): void
    {
        $this->asRole($role)
            ->put('/admin/settings', ['settings' => [
                'security.turnstile_enabled' => '0',
                'SECURITY .turnstile_secret_key' => self::NEW_SECRET_KEY,
            ]])
            ->assertRedirect();

        $this->assertTurnstileUntouched();
    }

    // ── ค่าที่ส่งลงหน้าเว็บ ──────────────────────────────────────────────────

    #[Test]
    #[DataProvider('lowerRoles')]
    public function แอดมินระดับล่างไม่ได้รับค่ากลุ่ม_securityเลย(string $role): void
    {
        $response = $this->asRole($role)->get('/admin/settings')->assertOk();

        $this->assertFalse($response->inertiaProps('canManageSecurity'));

        $settings = $response->inertiaProps('settings');
        foreach (self::SECURITY_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $settings);
        }

        $this->assertStringNotContainsString(self::SECRET_KEY, $response->getContent());
        $this->assertStringNotContainsString(self::SITE_KEY, $response->getContent());
    }

    /** แท็บอื่นของแอดมินทั่วไปต้องยังใช้ได้ — ซ่อนกลุ่ม security ต้องไม่กินกลุ่มข้างเคียง */
    #[Test]
    public function แอดมินระดับล่างยังเห็นค่าแท็บงานประจำ(): void
    {
        SiteSetting::set('social', 'twitter_url', 'https://x.com/tpix');

        $settings = $this->asRole('admin')->get('/admin/settings')->inertiaProps('settings');

        $this->assertSame('https://x.com/tpix', $settings['twitter_url']);
    }

    #[Test]
    public function super_adminเห็นแท็บ_securityแต่คีย์ลับยังถูกปิด(): void
    {
        $response = $this->asRole('super_admin')->get('/admin/settings')->assertOk();

        $this->assertTrue($response->inertiaProps('canManageSecurity'));

        $settings = $response->inertiaProps('settings');
        $this->assertTrue($settings['turnstile_enabled']);
        $this->assertSame(self::SITE_KEY, $settings['turnstile_site_key']);
        $this->assertSame($this->masked(self::SECRET_KEY), $settings['turnstile_secret_key']);
        $this->assertStringNotContainsString(self::SECRET_KEY, $response->getContent());
    }

    /** กดบันทึกโดยไม่แตะช่องคีย์ = ส่งค่าที่ถูก mask กลับมา — เขียนทับแล้ว Turnstile จะเช็กด้วยคีย์ปลอมทุกครั้ง */
    #[Test]
    public function บันทึกแท็บ_securityโดยไม่แตะคีย์แล้วคีย์เดิมไม่หาย(): void
    {
        $this->assertSavesWithoutTouchingSecret('security', 'turnstile_secret_key', self::SECRET_KEY, [
            'max_login_attempts' => 7,
        ]);

        $this->assertSame('7', SiteSetting::get('security', 'max_login_attempts'));
    }

    // ── คีย์ Worker สร้างรูป ─────────────────────────────────────────────────

    #[Test]
    #[DataProvider('allRoles')]
    public function คีย์_workerสร้างรูปไม่หลุดลงหน้าตั้งค่า(string $role): void
    {
        SiteSetting::set('ai', 'cloudflare_image_key', self::IMAGE_KEY);

        $response = $this->asRole($role)->get('/admin/settings')->assertOk();

        $this->assertSame($this->masked(self::IMAGE_KEY), $response->inertiaProps('settings')['cloudflare_image_key']);
        $this->assertStringNotContainsString(self::IMAGE_KEY, $response->getContent());
    }

    /** ใช้ค่าที่หน้าเว็บได้รับจริงส่งกลับ ไม่ใช่สตริงที่เทสต์ปั้นเอง — ฟอร์ม AI ส่งทุกช่องเสมอแม้แก้แค่ช่องเดียว */
    #[Test]
    public function บันทึกแท็บเอไอโดยไม่แตะคีย์_workerแล้วคีย์เดิมไม่หาย(): void
    {
        SiteSetting::set('ai', 'cloudflare_image_key', self::IMAGE_KEY);

        $this->assertSavesWithoutTouchingSecret('ai', 'cloudflare_image_key', self::IMAGE_KEY, [
            'groq_default_model' => 'llama-3.3-70b-versatile',
        ]);

        $this->assertSame('llama-3.3-70b-versatile', SiteSetting::get('ai', 'groq_default_model'));
    }

    #[Test]
    public function เปลี่ยนคีย์_workerเป็นค่าใหม่ได้(): void
    {
        SiteSetting::set('ai', 'cloudflare_image_key', self::IMAGE_KEY);

        $this->asRole('super_admin')
            ->put('/admin/settings/ai', ['cloudflare_image_key' => self::NEW_IMAGE_KEY])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(self::NEW_IMAGE_KEY, SiteSetting::get('ai', 'cloudflare_image_key'));
    }

    /**
     * @param  array<string, mixed>  $otherFields
     */
    private function assertSavesWithoutTouchingSecret(string $tab, string $key, string $secret, array $otherFields): void
    {
        $this->asRole('super_admin');

        $fromPage = $this->get('/admin/settings')->inertiaProps('settings')[$key];
        $this->assertSame($this->masked($secret), $fromPage);

        $this->put("/admin/settings/{$tab}", [$key => $fromPage] + $otherFields)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($secret, SiteSetting::get($tab, $key));
    }
}
