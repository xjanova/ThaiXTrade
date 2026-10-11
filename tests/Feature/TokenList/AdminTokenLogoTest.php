<?php

namespace Tests\Feature\TokenList;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Chain;
use App\Models\FactoryToken;
use App\Models\Token;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * โลโก้ของเหรียญที่ขึ้น /tokenlist.json — แอดมินตั้ง/เปลี่ยนได้จากหน้าหลังบ้านเดิม.
 *
 *   /admin/tokens                     (เหรียญหลัก — ขยายฟอร์มเดิม ไม่ได้ทำ endpoint ใหม่)
 *   /admin/token-factory/{id}/logo    (เหรียญจาก Token Factory)
 *
 * กัน:
 *   - ไฟล์ที่ไม่ใช่ PNG/WebP, ไม่จัตุรัส, เล็กกว่า 128px, ใหญ่กว่า 512KB
 *   - moderator/support แก้แถวที่ขึ้นรายการ "ของจริง" ได้
 *   - เปลี่ยนรูปแล้วไม่มีร่องรอย (AuditLog ต้องมีค่าเดิม/ค่าใหม่)
 *   - เปลี่ยนรูปแล้วลบไฟล์ที่แถวอื่นยังใช้อยู่
 *   - ผู้สร้างอัปรูปใหม่จนโลโก้ของเหรียญที่ลิสต์แล้วโดนหมุนทิ้ง
 */
class AdminTokenLogoTest extends TestCase
{
    private const ADDRESS = '0xabcabcabcabcabcabcabcabcabcabcabcabcabca';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        $this->withoutVite();
        config(['app.url' => 'https://tpix.online']);
    }

    private function admin(string $role = 'admin'): AdminUser
    {
        return AdminUser::create([
            'name' => "ผู้ใช้ {$role}",
            'email' => "{$role}@tpix.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function tpixChain(): Chain
    {
        return Chain::where('chain_id', 4289)->firstOrFail();
    }

    /** @param  array<string, mixed>  $overrides */
    private function coreToken(array $overrides = []): Token
    {
        return Token::create(array_merge([
            'chain_id' => $this->tpixChain()->id,
            'name' => 'Core Coin',
            'symbol' => 'CORE',
            'contract_address' => self::ADDRESS,
            'decimals' => 18,
            'is_active' => true,
            'is_verified' => true,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function factoryToken(array $overrides = []): FactoryToken
    {
        return FactoryToken::create(array_merge([
            'name' => 'Factory Coin',
            'symbol' => 'FAC',
            'decimals' => 18,
            'total_supply' => '1000000',
            'creator_address' => '0x'.str_repeat('9', 40),
            'contract_address' => '0x'.str_repeat('f', 40),
            'chain_id' => 4289,
            'status' => 'deployed',
            'is_listed' => true,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function updatePayload(Token $token, array $extra = []): array
    {
        return array_merge([
            '_method' => 'put',
            'chain_id' => $token->chain_id,
            'name' => $token->name,
            'symbol' => $token->symbol,
            'contract_address' => $token->contract_address,
            'decimals' => $token->decimals,
            'is_active' => 1,
            'is_verified' => 1,
            'sort_order' => 0,
        ], $extra);
    }

    // =========================================================================
    //  เหรียญหลัก (/admin/tokens)
    // =========================================================================

    #[Test]
    public function แอดมินเปลี่ยนโลโก้เหรียญหลัก_เก็บแบบชื่อผูกเนื้อไฟล์และลง_audit_log(): void
    {
        $token = $this->coreToken(['logo' => 'https://cdn.example.com/old.png']);

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/tokens/{$token->id}", $this->updatePayload($token, [
                'logo_file' => UploadedFile::fake()->image('logo.png', 256, 256),
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $logo = $token->fresh()->logo;
        $this->assertMatchesRegularExpression('#^token-logos/4289/'.self::ADDRESS.'-[a-f0-9]{16}\.png$#', $logo);
        Storage::disk('public')->assertExists($logo);

        $audit = AuditLog::where('action', 'token.logo_update')->firstOrFail();
        $this->assertSame(Token::class, $audit->model_type);
        $this->assertSame($token->id, $audit->model_id);
        $this->assertSame('https://cdn.example.com/old.png', $audit->old_values['logo']);
        $this->assertSame($logo, $audit->new_values['logo']);

        // ขึ้นรายการทันที (แคชถูกล้าง)
        $this->assertSame(
            'https://tpix.online/storage/'.$logo,
            $this->get('/tokenlist.json')->json('tokens.0.logoURI'),
        );
    }

    #[Test]
    public function รูปเดิมได้_url_เดิม_รูปใหม่ได้_url_ใหม่และลบไฟล์เก่าที่ไม่มีใครใช้(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = $this->admin();
        $token = $this->coreToken();

        $upload = fn (UploadedFile $file) => $this->actingAs($admin, 'admin')
            ->post("/admin/tokens/{$token->id}", $this->updatePayload($token, ['logo_file' => $file]))
            ->assertSessionHasNoErrors();

        $upload(UploadedFile::fake()->image('a.png', 256, 256));
        $first = $token->fresh()->logo;

        $upload(UploadedFile::fake()->image('b.png', 300, 300));
        $second = $token->fresh()->logo;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function ไม่ลบไฟล์เก่าถ้าแถวอื่นยังใช้อยู่(): void
    {
        Storage::disk('public')->put('token-logos/4289/shared.png', 'png');
        $token = $this->coreToken(['logo' => 'token-logos/4289/shared.png']);
        $this->factoryToken(['logo_url' => '/storage/token-logos/4289/shared.png']);

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/tokens/{$token->id}", $this->updatePayload($token, [
                'logo_file' => UploadedFile::fake()->image('new.png', 256, 256),
            ]))
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists('token-logos/4289/shared.png');
    }

    #[Test]
    public function เปลี่ยนเป็น_url_ภายนอกไม่ลบไฟล์นอกโฟลเดอร์โลโก้(): void
    {
        // เดิมลบทุกไฟล์บนดิสก์ public ที่ชื่อตรง — ใส่ /storage/settings/logo.png เป็นโลโก้เหรียญ
        // แล้วเปลี่ยนทีหลัง = โลโก้เว็บหาย
        Storage::disk('public')->put('settings/logo.png', 'site');
        $token = $this->coreToken(['logo' => '/storage/settings/logo.png']);

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/tokens/{$token->id}", $this->updatePayload($token, ['logo' => 'https://cdn.example.com/x.png']))
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists('settings/logo.png');
        $this->assertSame('https://cdn.example.com/x.png', $token->fresh()->logo);
    }

    /** @return array<string, array{0: \Closure(): UploadedFile}> */
    public static function badLogos(): array
    {
        return [
            'jpg' => [fn () => UploadedFile::fake()->image('logo.jpg', 256, 256)],
            'svg' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'ไม่จัตุรัส' => [fn () => UploadedFile::fake()->image('logo.png', 256, 200)],
            'เล็กกว่า 128px' => [fn () => UploadedFile::fake()->image('logo.png', 64, 64)],
            'ใหญ่กว่า 512KB' => [fn () => UploadedFile::fake()->image('logo.png', 256, 256)->size(600)],
            'png ปลอม (ข้อความเปลี่ยนนามสกุล)' => [fn () => UploadedFile::fake()->createWithContent('logo.png', 'not really an image')],
        ];
    }

    #[Test]
    #[DataProvider('badLogos')]
    public function ไฟล์โลโก้ผิดกติกาถูกปฏิเสธ_เหรียญหลัก(\Closure $make): void
    {
        $token = $this->coreToken(['logo' => 'https://cdn.example.com/keep.png']);

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/tokens/{$token->id}", $this->updatePayload($token, ['logo_file' => $make()]))
            ->assertSessionHasErrors('logo_file');

        $this->assertSame('https://cdn.example.com/keep.png', $token->fresh()->logo);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    #[DataProvider('badLogos')]
    public function ไฟล์โลโก้ผิดกติกาถูกปฏิเสธ_เหรียญจากโรงงาน(\Closure $make): void
    {
        $token = $this->factoryToken();

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/token-factory/{$token->id}/logo", ['logo' => $make()])
            ->assertSessionHasErrors('logo');

        $this->assertNull($token->fresh()->logo_url);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function webp_จัตุรัสรับได้(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD ไม่มี WebP');
        }

        $token = $this->factoryToken();

        $this->actingAs($this->admin('super_admin'), 'admin')
            ->post("/admin/token-factory/{$token->id}/logo", ['logo' => UploadedFile::fake()->image('logo.webp', 200, 200)])
            ->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('#^/storage/token-logos/4289/0xf{40}-[a-f0-9]{16}\.webp$#', $token->fresh()->logo_url);
    }

    #[Test]
    public function แอดมินรับรองหรือถอนการรับรองได้_และลง_audit_log(): void
    {
        $token = $this->coreToken(['is_verified' => false]);

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/tokens/{$token->id}", $this->updatePayload($token, ['is_verified' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($token->fresh()->is_verified);
        $audit = AuditLog::where('action', 'token.update')->firstOrFail();
        $this->assertFalse($audit->old_values['is_verified']);
        $this->assertTrue($audit->new_values['is_verified']);
    }

    // =========================================================================
    //  เหรียญจาก Token Factory
    // =========================================================================

    #[Test]
    public function แอดมินตั้งโลโก้เหรียญจากโรงงาน_และลง_audit_log(): void
    {
        $token = $this->factoryToken(['logo_url' => '/storage/token-logos/0x'.str_repeat('9', 40).'/creator.png']);

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/token-factory/{$token->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 512, 512)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $url = $token->fresh()->logo_url;
        $this->assertMatchesRegularExpression('#^/storage/token-logos/4289/0xf{40}-[a-f0-9]{16}\.png$#', $url);
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));

        $audit = AuditLog::where('action', 'token_factory.logo_update')->firstOrFail();
        $this->assertSame($token->id, $audit->model_id);
        $this->assertSame('/storage/token-logos/0x'.str_repeat('9', 40).'/creator.png', $audit->old_values['logo_url']);
        $this->assertSame($url, $audit->new_values['logo_url']);
    }

    #[Test]
    public function โลโก้ที่แอดมินอัปผ่านหน้าหลังบ้านขึ้นรายการทันที(): void
    {
        $token = $this->factoryToken();
        $this->get('/tokenlist.json')->assertOk();

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/token-factory/{$token->id}/logo", [
                'logo' => UploadedFile::fake()->image('logo.png', 256, 256),
            ])
            ->assertSessionHasNoErrors();

        $logo = $this->get('/tokenlist.json')->json('tokens.0.logoURI');
        $this->assertMatchesRegularExpression('#^https://tpix\.online/storage/token-logos/4289/0xf{40}-[a-f0-9]{16}\.png$#', $logo);
    }

    #[Test]
    public function เหรียญที่ยังไม่_deploy_ตั้งโลโก้ไม่ได้(): void
    {
        $token = $this->factoryToken(['status' => 'pending', 'contract_address' => null]);

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/token-factory/{$token->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 256, 256)])
            ->assertSessionHas('error');

        $this->assertNull($token->fresh()->logo_url);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // =========================================================================
    //  สิทธิ์ — admin/super_admin เท่านั้น
    // =========================================================================

    /** @return array<string, array{0: string}> */
    public static function lowRoles(): array
    {
        return ['moderator' => ['moderator'], 'support' => ['support']];
    }

    #[Test]
    #[DataProvider('lowRoles')]
    public function moderator_และ_support_แก้เหรียญและโลโก้ไม่ได้(string $role): void
    {
        $actor = $this->admin($role);
        $token = $this->coreToken(['logo' => 'https://cdn.example.com/keep.png']);
        $factory = $this->factoryToken();
        $tokenCount = Token::count();

        $this->actingAs($actor, 'admin')
            ->post("/admin/tokens/{$token->id}", $this->updatePayload($token, [
                'logo_file' => UploadedFile::fake()->image('logo.png', 256, 256),
            ]))
            ->assertForbidden();

        $this->actingAs($actor, 'admin')
            ->post('/admin/tokens', $this->updatePayload($token, ['_method' => 'post', 'contract_address' => '0x'.str_repeat('e', 40)]))
            ->assertForbidden();

        $this->actingAs($actor, 'admin')->delete("/admin/tokens/{$token->id}")->assertForbidden();

        $this->actingAs($actor, 'admin')
            ->post("/admin/token-factory/{$factory->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 256, 256)])
            ->assertForbidden();

        $this->assertSame('https://cdn.example.com/keep.png', $token->fresh()->logo);
        $this->assertNull($factory->fresh()->logo_url);
        $this->assertSame($tokenCount, Token::count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // ยังเปิดดูหน้ารายการได้ตามเดิม
        $this->actingAs($actor, 'admin')->get('/admin/tokens')->assertOk();
    }

    #[Test]
    public function หน้ารายการบอกหน้าเว็บว่าใครแก้ได้(): void
    {
        $this->actingAs($this->admin('moderator'), 'admin')
            ->get('/admin/tokens')
            ->assertInertia(fn ($page) => $page->component('Admin/Tokens/Index')->where('canManage', false));

        $this->actingAs($this->admin('super_admin'), 'admin')
            ->get('/admin/tokens')
            ->assertInertia(fn ($page) => $page->where('canManage', true)->where('logoRules.max_kb', 512)->where('logoRules.min_px', 128));
    }

    #[Test]
    public function สร้างเหรียญใหม่_ลง_audit_log_และรับรองตามที่ส่งมา(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/tokens', [
                'chain_id' => $this->tpixChain()->id,
                'name' => 'New Coin',
                'symbol' => 'NEW',
                'contract_address' => self::ADDRESS,
                'decimals' => 18,
                'is_active' => 1,
                'is_verified' => 1,
                'sort_order' => 0,
                'logo_file' => UploadedFile::fake()->image('logo.png', 128, 128),
            ])
            ->assertSessionHasNoErrors();

        $token = Token::where('symbol', 'NEW')->firstOrFail();
        $this->assertTrue($token->is_verified);
        $this->assertStringStartsWith('token-logos/4289/'.self::ADDRESS.'-', $token->logo);
        $this->assertSame(1, AuditLog::where('action', 'token.create')->where('model_id', $token->id)->count());
        $this->assertSame(['NEW'], array_column($this->get('/tokenlist.json')->json('tokens'), 'symbol'));
    }

    // =========================================================================
    //  โลโก้ของผู้สร้างต้องไม่ถูกหมุนทิ้งเมื่อเหรียญ deploy แล้ว
    // =========================================================================

    #[Test]
    public function ผู้สร้างอัปรูปใหม่กี่ครั้ง_โลโก้ของเหรียญที่_deploy_แล้วก็ไม่หาย(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $wallet = '0x1111111111111111111111111111111111111111';
        Cache::put('wallet_verified:'.$wallet, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHours(4));

        $first = $this->postJson('/api/v1/token-factory/upload-logo', [
            'logo' => UploadedFile::fake()->image('first.png'),
            'wallet_address' => $wallet,
        ])->assertOk()->json('data');

        $this->factoryToken(['creator_address' => $wallet, 'logo_url' => $first['logo_url']]);

        for ($i = 1; $i <= 5; $i++) {
            $this->travel(1)->minutes();
            $this->postJson('/api/v1/token-factory/upload-logo', [
                'logo' => UploadedFile::fake()->image("next{$i}.png"),
                'wallet_address' => $wallet,
            ])->assertOk();
        }

        Storage::disk('public')->assertExists($first['path']);
        // ไฟล์ที่ไม่มีเหรียญใช้ยังถูกจำกัดไว้ 3 ไฟล์ล่าสุด (+ ไฟล์ของเหรียญที่ deploy แล้ว)
        $this->assertCount(4, Storage::disk('public')->files('token-logos/'.$wallet));
    }
}
