<?php

namespace Tests\Feature\Kyc;

use App\Models\KycSubmission;
use App\Models\SiteSetting;
use App\Models\ThaipromptKycLink;
use App\Models\User;
use App\Services\Kyc\KycGate;
use App\Services\Kyc\KycPurgeService;
use App\Services\Kyc\ThaipromptKycService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ยืนยันตัวตนด้วยบัญชี Thaiprompt.
 *
 * เจ้าของสั่ง: "ให้ไปยืนยันใน thaiprompt app ถ้าผ่านก็บันทึกว่าผ่านแล้ว
 *              ถ้าเคยยืนยันแล้วก็ผ่านเลย ไม่ต้องยืนยันอีก"
 *
 * ห้ามหลุด:
 *   1. เคยผ่านที่ Thaiprompt → กลับมาจากหน้าอนุญาตแล้วด่านเปิดทันที
 *   2. ยังไม่ผ่าน → ไม่ได้สิทธิ์ แต่พอ Thaiprompt ผ่านทีหลัง ถามซ้ำแล้วได้สิทธิ์เอง
 *   3. state ปลอม/หมดอายุ/คนละบัญชี → ไม่ผูก (กันเอาผล KYC ของคนหนึ่งไปติดอีกบัญชี)
 *   4. บัญชี Thaiprompt เดียวปลดล็อกได้บัญชีเดียว
 *   5. Thaiprompt ยกเลิกผล → สิทธิ์ฝั่งเราหายตาม
 *   6. ขอลบข้อมูล (PDPA) แล้วต้องไม่ถูกออกใบให้ใหม่เงียบๆ
 *
 * Developed by Xman Studio.
 */
class ThaipromptKycTest extends TestCase
{
    private const BASE = 'https://tp.test';

    private User $user;

    /** สถานะที่ Thaiprompt จะตอบรอบถัดไป */
    private array $tpStatus = ['sub' => '501', 'kyc_status' => 'approved', 'verified' => true, 'verified_at' => '2026-10-05T10:00:00+07:00', 'method' => 'ekyc'];

    private int $tokenCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'services.thaiprompt.base_url' => self::BASE,
            'services.thaiprompt.oauth_client_id' => '9',
            'services.thaiprompt.oauth_client_secret' => 'secret-xyz',
        ]);

        SiteSetting::set(KycGate::SETTING_GROUP, KycGate::KEY_ENABLED, '1', 'boolean');
        SiteSetting::set(KycGate::SETTING_GROUP, KycGate::KEY_GATE_PREFIX.'ai_bot', '1', 'boolean');
        Cache::flush();

        $this->user = User::create([
            'email' => 'trader@tpix.test',
            'password' => bcrypt('secret-password'),
            'wallet_address' => '0x1111111111111111111111111111111111111111',
        ]);

        // Thaiprompt จำลอง: ออก token ใหม่ทุกครั้ง (หมุน refresh token แบบ Passport) + ตอบสถานะตามที่ตั้งไว้
        Http::fake(function (HttpRequest $request) {
            if (str_ends_with($request->url(), '/oauth/token')) {
                $this->tokenCalls++;

                return Http::response([
                    'token_type' => 'Bearer',
                    'expires_in' => 1296000,
                    'access_token' => 'access-'.$this->tokenCalls,
                    'refresh_token' => 'refresh-'.$this->tokenCalls,
                ]);
            }

            if (str_ends_with($request->url(), '/api/oauth/kyc')) {
                return Http::response($this->tpStatus);
            }

            return Http::response([], 404);
        });
    }

    /** กดปุ่มเชื่อม → คืน state ที่ส่งไป Thaiprompt */
    private function startConnect(?User $as = null): string
    {
        $response = $this->actingAs($as ?? $this->user)
            ->post('/kyc/thaiprompt/connect', ['consent' => true], ['X-Inertia' => 'true']);

        $response->assertStatus(409);
        $location = $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith(self::BASE.'/oauth/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function gatePasses(): bool
    {
        return app(KycGate::class)->passes($this->user->fresh(), 'ai_bot');
    }

    // =========================================================================

    #[Test]
    public function ไปหน้าอนุญาตของ_thaiprompt_ด้วย_pkce_และขอแค่สิทธิ์_kyc(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/kyc/thaiprompt/connect', ['consent' => true], ['X-Inertia' => 'true']);

        parse_str((string) parse_url($response->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);

        $this->assertSame('9', $q['client_id']);
        $this->assertSame('kyc', $q['scope']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertNotEmpty($q['code_challenge']);
        $this->assertSame(route('kyc.thaiprompt.callback'), $q['redirect_uri']);
    }

    #[Test]
    public function ต้องกดยินยอมก่อนถึงจะไป_thaiprompt(): void
    {
        $this->actingAs($this->user)->post('/kyc/thaiprompt/connect', [])
            ->assertSessionHasErrors('consent');
    }

    #[Test]
    public function เคยผ่านที่_thaiprompt_แล้ว_กลับมาแล้วผ่านทันที(): void
    {
        $this->assertFalse($this->gatePasses());

        $state = $this->startConnect();

        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state)
            ->assertRedirect(route('kyc.index'))
            ->assertSessionHas('success');

        $this->assertTrue($this->gatePasses());

        $submission = KycSubmission::where('user_id', $this->user->id)->sole();
        $this->assertSame(KycSubmission::SOURCE_THAIPROMPT, $submission->source);
        $this->assertSame('501', $submission->external_ref);
        $this->assertSame(KycSubmission::LEVEL_BASIC, $submission->level);
        // ไม่มีข้อมูลบัตรจาก Thaiprompt เลย
        $this->assertNull($submission->full_name);
        $this->assertNull($submission->national_id);
        $this->assertSame('approved', $this->user->fresh()->kyc_status);

        // แลก code ด้วย verifier ของ PKCE จริง
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/oauth/token')
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === 'abc'
            && strlen((string) $r['code_verifier']) >= 43);
    }

    #[Test]
    public function ยังไม่ผ่าน_ได้แค่ผูกบัญชี_แล้วผ่านเองเมื่อ_thaiprompt_อนุมัติ(): void
    {
        $this->tpStatus = ['sub' => '501', 'kyc_status' => 'none', 'verified' => false, 'verified_at' => null, 'method' => null];

        $state = $this->startConnect();
        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state)->assertRedirect(route('kyc.index'));

        $this->assertFalse($this->gatePasses());
        $link = ThaipromptKycLink::where('user_id', $this->user->id)->sole();
        $this->assertSame('none', $link->kyc_status);
        $this->assertSame(0, KycSubmission::count());

        // ลูกค้าไปทำ eKYC ในแอปจนผ่าน แล้วหน้าเว็บถามซ้ำ
        $this->tpStatus = ['sub' => '501', 'kyc_status' => 'approved', 'verified' => true, 'verified_at' => '2026-10-09T12:00:00+07:00', 'method' => 'ekyc'];
        $link->forceFill(['last_checked_at' => now()->subMinute()])->save();

        $this->actingAs($this->user)->postJson('/kyc/thaiprompt/refresh')
            ->assertOk()
            ->assertJsonPath('thaiprompt.status', 'approved')
            ->assertJsonPath('gate.features.ai_bot.passed', true);

        $this->assertTrue($this->gatePasses());
    }

    #[Test]
    public function คำสั่งตั้งเวลาปลดล็อกให้แม้ลูกค้าไม่กลับมาที่หน้าเว็บ(): void
    {
        $this->tpStatus = ['sub' => '501', 'kyc_status' => 'pending', 'verified' => false, 'verified_at' => null, 'method' => null];
        $state = $this->startConnect();
        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state);

        ThaipromptKycLink::query()->update(['last_checked_at' => now()->subMinutes(10)]);
        $this->tpStatus = ['sub' => '501', 'kyc_status' => 'approved', 'verified' => true, 'verified_at' => null, 'method' => 'manual'];

        $this->artisan('kyc:thaiprompt-sync')->assertSuccessful();

        $this->assertTrue($this->gatePasses());
    }

    #[Test]
    public function state_ปลอมหรือของบัญชีอื่นต้องไม่ผูก(): void
    {
        $this->startConnect();

        $this->get('/kyc/thaiprompt/callback?code=abc&state=forged-state')
            ->assertRedirect(route('kyc.index'))
            ->assertSessionHasErrors('kyc');

        $this->assertSame(0, ThaipromptKycLink::count());
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/oauth/token'));

        // เริ่มในบัญชีหนึ่ง แต่ callback กลับมาตอนล็อกอินอีกบัญชี
        $other = User::create(['email' => 'other@tpix.test', 'password' => bcrypt('x-secret-123')]);
        $state = $this->startConnect();
        $this->actingAs($other)->get('/kyc/thaiprompt/callback?code=abc&state='.$state)
            ->assertSessionHasErrors('kyc');

        $this->assertSame(0, ThaipromptKycLink::count());
    }

    #[Test]
    public function state_ใช้ซ้ำไม่ได้(): void
    {
        $state = $this->startConnect();
        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state)->assertSessionHas('success');

        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state)->assertSessionHasErrors('kyc');
    }

    #[Test]
    public function ไม่อนุญาตที่หน้า_thaiprompt_ได้ข้อความที่อ่านรู้เรื่อง(): void
    {
        $state = $this->startConnect();

        $this->get('/kyc/thaiprompt/callback?error=access_denied&state='.$state)
            ->assertRedirect(route('kyc.index'))
            ->assertSessionHasErrors(['kyc' => ThaipromptKycService::ERR_DENIED]);
    }

    #[Test]
    public function บัญชี_thaiprompt_เดียวปลดล็อกได้บัญชีเดียว(): void
    {
        $state = $this->startConnect();
        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state);

        $other = User::create(['email' => 'second@tpix.test', 'password' => bcrypt('x-secret-123')]);
        $state = $this->startConnect($other);

        $this->actingAs($other)->get('/kyc/thaiprompt/callback?code=xyz&state='.$state)
            ->assertSessionHasErrors(['kyc' => ThaipromptKycService::ERR_TAKEN]);

        $this->assertFalse(app(KycGate::class)->passes($other->fresh(), 'ai_bot'));
    }

    #[Test]
    public function thaiprompt_ยกเลิกผลแล้วสิทธิ์ฝั่งเราหายตาม(): void
    {
        $state = $this->startConnect();
        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state);
        $this->assertTrue($this->gatePasses());

        $this->tpStatus = ['sub' => '501', 'kyc_status' => 'rejected', 'verified' => false, 'verified_at' => null, 'method' => null];
        ThaipromptKycLink::query()->update(['last_checked_at' => now()->subDays(8)]);

        $this->artisan('kyc:thaiprompt-sync')->assertSuccessful();

        $this->assertFalse($this->gatePasses());
        $this->assertSame(KycSubmission::STATUS_EXPIRED, KycSubmission::sole()->status);
    }

    #[Test]
    public function คำตอบผิดรูปจาก_thaiprompt_ต้องไม่ถูกตีความว่าผ่าน(): void
    {
        $this->tpStatus = ['sub' => '501', 'kyc_status' => 'definitely-approved', 'verified' => true];

        $state = $this->startConnect();
        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state)->assertSessionHasErrors('kyc');

        $this->assertFalse($this->gatePasses());
    }

    #[Test]
    public function token_หมดอายุต่ออายุให้เองแล้วถามต่อได้(): void
    {
        $this->tpStatus = ['sub' => '501', 'kyc_status' => 'pending', 'verified' => false, 'verified_at' => null, 'method' => null];
        $state = $this->startConnect();
        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state);

        ThaipromptKycLink::query()->update(['token_expires_at' => now()->subMinute(), 'last_checked_at' => now()->subMinute()]);

        $this->actingAs($this->user)->postJson('/kyc/thaiprompt/refresh')->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/oauth/token')
            && $r['grant_type'] === 'refresh_token'
            && $r['refresh_token'] === 'refresh-1');
        // Passport หมุน refresh token — ต้องเก็บตัวใหม่
        $this->assertSame('refresh-2', ThaipromptKycLink::sole()->refresh_token);
    }

    #[Test]
    public function ขอลบข้อมูลแล้วต้องไม่ถูกออกใบให้ใหม่เงียบๆ(): void
    {
        $state = $this->startConnect();
        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state);
        $this->assertTrue($this->gatePasses());

        app(KycPurgeService::class)->purgeUser($this->user, 'user_request');

        $this->assertSame(0, ThaipromptKycLink::count());
        $this->artisan('kyc:thaiprompt-sync')->assertSuccessful();

        $this->assertFalse($this->gatePasses());
    }

    #[Test]
    public function ยังไม่ได้ตั้งค่า_client_การ์ดไม่โผล่และเชื่อมไม่ได้(): void
    {
        config(['services.thaiprompt.oauth_client_id' => null]);

        $this->actingAs($this->user)->get('/kyc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('thaiprompt.available', false));

        $this->actingAs($this->user)->post('/kyc/thaiprompt/connect', ['consent' => true])
            ->assertSessionHasErrors(['kyc' => ThaipromptKycService::ERR_NOT_CONFIGURED]);
    }

    #[Test]
    public function token_ไม่หลุดไปหน้าเว็บ(): void
    {
        $state = $this->startConnect();
        $this->get('/kyc/thaiprompt/callback?code=abc&state='.$state);

        $html = $this->actingAs($this->user)->get('/kyc')->getContent();

        $this->assertStringNotContainsString('access-1', $html);
        $this->assertStringNotContainsString('refresh-1', $html);
        $this->assertStringNotContainsString('"501"', $html);
    }
}
