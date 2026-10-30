<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Services\Discord\DiscordSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — แอดมินระดับล่างต้องเอาคีย์ลับออกไปไม่ได้ และการเปลี่ยนค่าต้องตามร่องรอยได้.
 *
 * ก่อนแก้: แอดมินทุกระดับเขียน ai.cloudflare_image_url ได้ แล้ว ContentService ส่ง X-API-Key ไปที่ URL นั้น
 * หน้าเว็บ mask คีย์ไว้ก็ไม่ช่วย — ชี้ URL ไปเซิร์ฟเวอร์ตัวเองแล้วรอการสร้างรูปครั้งถัดไปก็ได้คีย์ตัวจริง
 *
 * ⚠️ ล็อกอินผ่าน session แบบเบราว์เซอร์จริง ห้ามใช้ actingAs($admin, 'admin') เพราะมันสลับ guard ปริยาย
 *    ซึ่ง production ไม่ได้ทำ — เทสต์ audit จะผ่านทั้งที่ admin_id เป็น null
 *
 * Developed by Xman Studio.
 */
class SecretSettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER_URL = 'https://tpix-image-gen.xjanovax.workers.dev/';

    private const ATTACKER_URL = 'https://collector.attacker.test/steal';

    private const IMAGE_KEY = 'cf-worker-key-Old7Value9Tail';

    private const NEW_IMAGE_KEY = 'cf-worker-key-New4Value2Rest';

    private const TILE_URL = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';

    private const TILE_KEY = 'carto-tile-key-Old7Value9Tail';

    private const ADVISOR_KEY = 'sk-advisor-key-Old7Value9Tail';

    private const BOT_TOKEN = 'discord-bot-token-Plain1Value';

    private const TURNSTILE_SITE_KEY = '0x4AAAAAAASiteKeyOldValue';

    private const TURNSTILE_SECRET = '0x4AAAAAAASecretOldValueQ7Zp';

    private const NEW_TURNSTILE_SECRET = '0x4AAAAAAASecretNewValueM3Xk';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');

        SiteSetting::set('ai', 'cloudflare_image_url', self::WORKER_URL);
        SiteSetting::set('ai', 'cloudflare_image_key', self::IMAGE_KEY);
        SiteSetting::set('map', 'map_tile_url', self::TILE_URL);
        SiteSetting::set('map', 'map_tile_key', self::TILE_KEY);
        SiteSetting::set('advisor', 'advisor_openai_api_key', self::ADVISOR_KEY);
    }

    private function signIn(string $role): AdminUser
    {
        $admin = AdminUser::create([
            'name' => $role,
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);

        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);

        return $admin;
    }

    private function masked(string $secret): string
    {
        return str_repeat('*', 20).substr($secret, -4);
    }

    /** ค่าในฐานข้อมูลจริง ไม่ผ่านแคช — ค่าที่รั่วเข้าแคชอย่างเดียวไม่นับว่าเขียนสำเร็จ */
    private function stored(string $group, string $key): ?string
    {
        return SiteSetting::where('group', $group)->where('key', $key)->value('value');
    }

    private function fakeImageWorker(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 2000))]);
    }

    private function auditRow(string $action): AuditLog
    {
        return AuditLog::where('action', $action)->sole();
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

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function gatedWrites(): array
    {
        $tabs = [
            'ปลายทางคีย์ worker' => ['/admin/settings/ai', 'ai', 'cloudflare_image_url', self::ATTACKER_URL, self::WORKER_URL],
            'คีย์ worker' => ['/admin/settings/ai', 'ai', 'cloudflare_image_key', 'attacker-key', self::IMAGE_KEY],
            'ปลายทางไทล์แผนที่' => ['/admin/settings/map', 'map', 'map_tile_url', self::ATTACKER_URL, self::TILE_URL],
            'คีย์ไทล์แผนที่' => ['/admin/settings/map', 'map', 'map_tile_key', 'attacker-key', self::TILE_KEY],
            'คีย์ที่ปรึกษา' => ['/admin/settings/advisor', 'advisor', 'advisor_openai_api_key', 'sk-attacker', self::ADVISOR_KEY],
        ];

        $cases = [];
        foreach (self::lowerRoles() as $role => [$value]) {
            foreach ($tabs as $name => $tab) {
                $cases["{$role} แก้{$name}"] = [$value, ...$tab];
            }
        }

        return $cases;
    }

    // ── คีย์ต้องไม่ถูกส่งไปยัง URL ที่ระดับล่างเลือกเอง ───────────────────────

    #[Test]
    #[DataProvider('lowerRoles')]
    public function แอดมินระดับล่างชี้_workerไปเซิร์ฟเวอร์ตัวเองแล้วรับคีย์ไม่ได้(string $role): void
    {
        $this->fakeImageWorker();
        $this->signIn($role);

        $this->put('/admin/settings/ai', ['cloudflare_image_url' => self::ATTACKER_URL])->assertForbidden();

        // ปุ่มสร้างรูปยังเปิดให้ทุกระดับ — นี่คือจังหวะที่คีย์ถูกส่งออกจริง
        $this->postJson('/admin/content/generate-image', ['prompt' => 'cover', 'provider' => 'cloudflare'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (Request $request) => $request->url() === self::WORKER_URL
            && $request->hasHeader('X-API-Key', self::IMAGE_KEY));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'attacker.test'));
    }

    /** ด่าน route กันแค่ปลายทางเดียว — ปลายทางรวมที่เปิดให้ทุกระดับต้องไม่เป็นทางอ้อม */
    #[Test]
    #[DataProvider('lowerRoles')]
    public function ปลายทางรวมเขียน_url_workerไม่ได้(string $role): void
    {
        $this->signIn($role);

        $this->put('/admin/settings', ['settings' => [
            'ai.cloudflare_image_url' => self::ATTACKER_URL,
            'AI .cloudflare_image_url' => self::ATTACKER_URL,
            'map.map_tile_url' => self::ATTACKER_URL,
        ]])->assertRedirect();

        $this->assertSame(self::WORKER_URL, $this->stored('ai', 'cloudflare_image_url'));
        $this->assertSame(self::TILE_URL, $this->stored('map', 'map_tile_url'));
    }

    #[Test]
    #[DataProvider('gatedWrites')]
    public function แอดมินระดับล่างแก้แท็บที่มีคีย์ไม่ได้(string $role, string $path, string $group, string $key, string $attempt, string $original): void
    {
        $this->signIn($role);

        $this->put($path, [$key => $attempt])->assertForbidden();

        $this->assertSame($original, $this->stored($group, $key));
    }

    /** แต่ละครั้งกินโควตาจริงของผู้ให้บริการ และปุ่มนี้อยู่ในฟอร์มที่ระดับล่างไม่เห็นแล้ว */
    #[Test]
    #[DataProvider('lowerRoles')]
    public function แอดมินระดับล่างยิงทดสอบที่ปรึกษาไม่ได้(string $role): void
    {
        Http::fake();
        $this->signIn($role);

        $this->post('/admin/settings/advisor/test', ['provider' => 'openai'])->assertForbidden();

        Http::assertNothingSent();
    }

    #[Test]
    public function super_adminยังเปลี่ยน_url_workerได้และคีย์ไปที่ใหม่(): void
    {
        $this->fakeImageWorker();
        $this->signIn('super_admin');

        $this->put('/admin/settings/ai', ['cloudflare_image_url' => 'https://worker.tpix.test/'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->postJson('/admin/content/generate-image', ['prompt' => 'cover', 'provider' => 'cloudflare'])->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://worker.tpix.test/');
    }

    // ── งานประจำของระดับล่างต้องยังทำได้ ─────────────────────────────────────

    /** ตารางเวลา Auto Content อยู่ในแท็บ AI — ปิดฟอร์มคีย์ต้องไม่กินงานนี้ไปด้วย */
    #[Test]
    #[DataProvider('lowerRoles')]
    public function แอดมินระดับล่างยังตั้งเวลา_auto_contentได้(string $role): void
    {
        $this->signIn($role);

        $this->put('/admin/settings/content', ['content_max_per_day' => 4])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('4', $this->stored('content', 'content_max_per_day'));

        $settings = $this->get('/admin/settings')->assertOk()->inertiaProps('settings');
        $this->assertSame('4', $settings['content_max_per_day']);
    }

    // ── ค่าที่ส่งลงหน้าเว็บ ──────────────────────────────────────────────────

    #[Test]
    #[DataProvider('lowerRoles')]
    public function แอดมินระดับล่างไม่ได้รับค่ากลุ่มคีย์เลย(string $role): void
    {
        $this->signIn($role);

        $response = $this->get('/admin/settings')->assertOk();
        $settings = $response->inertiaProps('settings');

        foreach (['cloudflare_image_url', 'cloudflare_image_key', 'map_tile_url', 'map_tile_key', 'advisor_openai_api_key'] as $key) {
            $this->assertArrayNotHasKey($key, $settings);
        }

        foreach ([self::WORKER_URL, self::IMAGE_KEY, self::TILE_KEY, self::ADVISOR_KEY] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    #[Test]
    public function super_adminเห็นค่าครบแต่คีย์ยังถูกปิด(): void
    {
        $this->signIn('super_admin');

        $settings = $this->get('/admin/settings')->assertOk()->inertiaProps('settings');

        $this->assertSame(self::WORKER_URL, $settings['cloudflare_image_url']);
        $this->assertSame($this->masked(self::IMAGE_KEY), $settings['cloudflare_image_key']);
        $this->assertSame($this->masked(self::TILE_KEY), $settings['map_tile_key']);
        $this->assertSame($this->masked(self::ADVISOR_KEY), $settings['advisor_openai_api_key']);
    }

    #[Test]
    #[DataProvider('allRoles')]
    public function โทเคนบอท_discordไม่ถูกส่งลงหน้าตั้งค่าเลย(string $role): void
    {
        app(DiscordSettings::class)->saveBotToken(self::BOT_TOKEN);
        SiteSetting::set('social', 'discord_url', 'https://discord.gg/tpix');
        $cipher = $this->stored('discord', 'discord_bot_token');

        $this->signIn($role);

        $response = $this->get('/admin/settings')->assertOk();
        $settings = $response->inertiaProps('settings');

        $this->assertArrayNotHasKey('discord_bot_token', $settings);
        $this->assertStringNotContainsString('discord_bot_token', $response->getContent());
        $this->assertStringNotContainsString($cipher, $response->getContent());
        // ลิงก์ชุมชนอยู่คนละกลุ่ม — ซ่อนกลุ่ม discord ต้องไม่กินแท็บ Social
        $this->assertSame('https://discord.gg/tpix', $settings['discord_url']);
    }

    // ── ร่องรอยการเปลี่ยนค่า ─────────────────────────────────────────────────

    private function seedTurnstile(): void
    {
        SiteSetting::set('security', 'turnstile_enabled', '1', 'boolean');
        SiteSetting::set('security', 'turnstile_site_key', self::TURNSTILE_SITE_KEY);
        SiteSetting::set('security', 'turnstile_secret_key', self::TURNSTILE_SECRET);
    }

    #[Test]
    public function ปิด_turnstileแล้วเหลือแถวบอกว่าใครเปลี่ยนจากอะไรเป็นอะไร(): void
    {
        $this->seedTurnstile();
        $admin = $this->signIn('super_admin');

        $this->put('/admin/settings/security', ['turnstile_enabled' => false])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $log = $this->auditRow('settings.security.update');

        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame(['security.turnstile_enabled' => '1'], $log->old_values);
        $this->assertSame(['security.turnstile_enabled' => '0'], $log->new_values);
        $this->assertNotNull($log->ip_address);
    }

    #[Test]
    public function เปลี่ยนคีย์_turnstileแล้วบันทึกว่าเปลี่ยนแต่ไม่เก็บค่าจริง(): void
    {
        $this->seedTurnstile();
        $this->signIn('super_admin');

        $this->put('/admin/settings/security', [
            'turnstile_site_key' => '0x4AAAAAAASiteKeyNewValue',
            'turnstile_secret_key' => self::NEW_TURNSTILE_SECRET,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $log = $this->auditRow('settings.security.update');

        $this->assertSame('[redacted]', $log->old_values['security.turnstile_secret_key']);
        $this->assertSame('[redacted]', $log->new_values['security.turnstile_secret_key']);
        // site key แสดงบนหน้าล็อกอินอยู่แล้ว เก็บค่าจริงได้และช่วยตามร่องรอย
        $this->assertSame(self::TURNSTILE_SITE_KEY, $log->old_values['security.turnstile_site_key']);
        $this->assertSame('0x4AAAAAAASiteKeyNewValue', $log->new_values['security.turnstile_site_key']);

        $raw = json_encode([$log->old_values, $log->new_values]);
        $this->assertStringNotContainsString(self::TURNSTILE_SECRET, $raw);
        $this->assertStringNotContainsString(self::NEW_TURNSTILE_SECRET, $raw);
    }

    /** กดบันทึกโดยไม่แตะช่องคีย์ = ส่งค่าที่ถูก mask กลับมา — ไม่ได้เปลี่ยนจึงต้องไม่มีในร่องรอย */
    #[Test]
    public function บันทึก_securityโดยไม่แตะคีย์แล้วร่องรอยไม่อ้างว่าคีย์เปลี่ยน(): void
    {
        $this->seedTurnstile();
        $this->signIn('super_admin');

        $this->put('/admin/settings/security', ['turnstile_secret_key' => $this->masked(self::TURNSTILE_SECRET)])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->auditRow('settings.security.update')->new_values);
        $this->assertSame(self::TURNSTILE_SECRET, $this->stored('security', 'turnstile_secret_key'));
    }

    /** ชื่อคีย์พวกนี้ไม่มีคำว่า api_key/secret — ถ้า pattern ไม่ครอบ ค่าจริงจะลง audit_logs ตรงๆ */
    #[Test]
    public function คีย์ที่ชื่อไม่เข้าแบบเดิมต้องไม่ลงร่องรอยเป็นค่าจริง(): void
    {
        $this->signIn('super_admin');

        $this->put('/admin/settings/ai', [
            'cloudflare_image_url' => 'https://worker.tpix.test/',
            'cloudflare_image_key' => self::NEW_IMAGE_KEY,
        ])->assertRedirect();
        $this->put('/admin/settings/map', ['map_tile_key' => 'carto-tile-key-New4Value2Rest'])->assertRedirect();
        $this->put('/admin/settings/advisor', ['advisor_openai_api_key' => 'sk-advisor-key-New4Value2Rest'])->assertRedirect();

        $ai = $this->auditRow('settings.ai.update');
        // URL ต้องเก็บค่าจริง — คือหลักฐานว่าคีย์เคยถูกชี้ไปที่ไหน
        $this->assertSame(self::WORKER_URL, $ai->old_values['ai.cloudflare_image_url']);
        $this->assertSame('https://worker.tpix.test/', $ai->new_values['ai.cloudflare_image_url']);
        $this->assertSame('[redacted]', $ai->new_values['ai.cloudflare_image_key']);

        $this->assertSame('[redacted]', $this->auditRow('settings.map.update')->new_values['map.map_tile_key']);
        $this->assertSame('[redacted]', $this->auditRow('settings.advisor.update')->new_values['advisor.advisor_openai_api_key']);
    }

    #[Test]
    public function เปลี่ยนคีย์อีเมลแล้วเหลือร่องรอยโดยไม่เก็บค่าจริง(): void
    {
        SiteSetting::set('email', 'resend_api_key', 're_old_value');
        $admin = $this->signIn('admin');

        $this->put('/admin/settings/email', ['resend_api_key' => 're_new_value'])->assertRedirect();

        $log = $this->auditRow('settings.email.update');

        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame(['email.resend_api_key' => '[redacted]'], $log->new_values);
        $this->assertSame('re_new_value', $this->stored('email', 'resend_api_key'));
    }

    #[Test]
    public function บันทึกร่องรอยไม่ได้ต้องไม่เปลี่ยน_turnstile(): void
    {
        $this->seedTurnstile();
        $this->signIn('super_admin');

        AuditLog::creating(function (): void {
            throw new RuntimeException('audit store down');
        });

        $this->put('/admin/settings/security', ['turnstile_enabled' => false])->assertServerError();

        $this->assertSame('1', $this->stored('security', 'turnstile_enabled'));
        $this->assertTrue(SiteSetting::get('security', 'turnstile_enabled'));
    }
}
