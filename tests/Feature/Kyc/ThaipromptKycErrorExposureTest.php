<?php

namespace Tests\Feature\Kyc;

use App\Models\ThaipromptKycLink;
use App\Models\User;
use App\Services\Kyc\ThaipromptKycService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ข้อความ exception ดิบของการเชื่อม Thaiprompt ทางเว็บต้องไม่ถึงเบราว์เซอร์ — QueryException ก็เป็น RuntimeException.
 *
 * ค่า bind ของ SQL ตอนผูกบัญชีและต่ออายุ token คือ token ของ Thaiprompt และรหัสผู้ใช้ฝั่งโน้น
 * ค่าพวกนี้จึงห้ามออกทาง session errors หรือ JSON และห้ามลง log
 */
class ThaipromptKycErrorExposureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tp.test';

    private const SUB = 'thaiprompt-sub-7c9e6679';

    private const ACCESS_TOKEN = 'ACCESS-e3b0c44298fc1c149afbf4c8996fb924';

    private const REFRESH_TOKEN = 'REFRESH-27ae41e4649b934ca495991b7852b855';

    private const OAUTH_CODE = 'oauth-code-4f2a9d1c';

    /** Laravel เข้ารหัสเป็น base64 ของ {"iv":"... — ทุก token ที่ลงฐานขึ้นต้นแบบนี้ */
    private const CIPHERTEXT = 'eyJpdiI6';

    private const UPDATE = 'update `thaiprompt_kyc_links` set `access_token` = ?, `refresh_token` = ? where `thaiprompt_user_id` = ?';

    private const DRIVER_ERROR = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found';

    private const TOKENS = [
        'token_type' => 'Bearer',
        'expires_in' => 1296000,
        'access_token' => self::ACCESS_TOKEN,
        'refresh_token' => self::REFRESH_TOKEN,
    ];

    private const VERIFIED = [
        'sub' => self::SUB,
        'kyc_status' => 'approved',
        'verified' => true,
        'verified_at' => '2026-10-05T10:00:00+07:00',
        'method' => 'ekyc',
    ];

    private User $user;

    private string $logged = '';

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

        $this->user = User::create([
            'email' => 'trader@tpix.test',
            'password' => bcrypt('secret-password'),
            'wallet_address' => '0x1111111111111111111111111111111111111111',
        ]);

        // ล็อกอินแบบที่เบราว์เซอร์ทำจริง — actingAs() สลับ default guard ก่อนคำขอเริ่ม
        $this->withSession([Auth::guard('web')->getName() => $this->user->id]);

        // จับทุกระดับ ไม่ใช่แค่ error — token ที่ห่อแล้วห้ามลง log ไม่ว่าระดับไหน
        Log::listen(function (MessageLogged $entry) {
            $this->logged .= $entry->message.' '.json_encode($entry->context)."\n";
        });
    }

    private function queryFailure(): QueryException
    {
        return new QueryException('mysql', self::UPDATE, [self::ACCESS_TOKEN, self::REFRESH_TOKEN, self::SUB], new PDOException(self::DRIVER_ERROR));
    }

    private function serviceThrows(string $method, RuntimeException $e): void
    {
        $this->partialMock(ThaipromptKycService::class, fn ($mock) => $mock->shouldReceive($method)->andThrow($e));
    }

    /** Thaiprompt จำลอง — ตอบ token และสถานะตามที่เทสต์กำหนด */
    private function thaipromptAnswers(int $tokenStatus = 200, array $tokens = self::TOKENS, int $kycStatus = 200, array $kyc = self::VERIFIED): void
    {
        Http::fake([
            '*/oauth/token' => Http::response($tokens, $tokenStatus),
            '*/api/oauth/kyc' => Http::response($kyc, $kycStatus),
        ]);
    }

    /** กดเชื่อมจริง → คืน state ที่ค้างอยู่ใน session */
    private function startConnect(): string
    {
        $response = $this->post('/kyc/thaiprompt/connect', ['consent' => true], ['X-Inertia' => 'true'])->assertStatus(409);

        parse_str((string) parse_url($response->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function storedLink(array $overrides = []): ThaipromptKycLink
    {
        return ThaipromptKycLink::create($overrides + [
            'user_id' => $this->user->id,
            'thaiprompt_user_id' => self::SUB,
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'token_expires_at' => now()->addDay(),
            'kyc_status' => ThaipromptKycLink::STATUS_PENDING,
            'linked_at' => now(),
        ]);
    }

    private function expiredLink(): ThaipromptKycLink
    {
        return $this->storedLink(['token_expires_at' => now()->subHour()]);
    }

    private function hit(string $route, string $query = 'code='.self::OAUTH_CODE.'&state=forged-state'): TestResponse
    {
        return match ($route) {
            'connect' => $this->from('/kyc')->post('/kyc/thaiprompt/connect', ['consent' => true]),
            'callback' => $this->get('/kyc/thaiprompt/callback?'.$query),
            'refresh' => $this->postJson('/kyc/thaiprompt/refresh'),
        };
    }

    private function assertNoBoundValuesIn(string $text): void
    {
        foreach ([self::SUB, self::ACCESS_TOKEN, self::REFRESH_TOKEN, self::CIPHERTEXT, self::OAUTH_CODE] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
    }

    private function assertNothingSensitiveIn(string $text): void
    {
        foreach (['SQLSTATE', 'thaiprompt_kyc_links', 'kyc_submissions', 'Deadlock', 'Connection:'] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }

        $this->assertNoBoundValuesIn($text);
    }

    /** ข้อความที่หน้า /kyc เอาไปแสดง — อ่านจาก prop จริง ไม่ใช่แค่ session ก่อนถึงหน้า */
    private function assertKycPageShows(string $message): void
    {
        $this->get('/kyc')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Kyc/Index')
            ->where('errors.kyc', $message));
    }

    private function assertRedirectedWith(TestResponse $response, string $message): void
    {
        $response->assertRedirect(route('kyc.index'))->assertSessionHasErrors(['kyc' => $message]);

        $this->assertKycPageShows($message);
    }

    private function assertRefreshFailed(TestResponse $response, int $status, string $message): void
    {
        $response->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', $message)
            ->assertJsonPath('thaiprompt.status', ThaipromptKycLink::STATUS_PENDING);
    }

    private function assertUnexpectedFailure(TestResponse $response, string $route): void
    {
        if ($route === 'refresh') {
            $this->assertRefreshFailed($response, 500, ThaipromptKycService::ERR_UNEXPECTED);
            $this->assertNothingSensitiveIn($response->getContent());

            return;
        }

        $this->assertRedirectedWith($response, ThaipromptKycService::ERR_UNEXPECTED);
    }

    private function assertNotReportedAsUnexpected(): void
    {
        $this->assertStringNotContainsString('สาเหตุที่ไม่ได้ตั้งใจ', $this->logged, 'ความล้มเหลวที่ตั้งใจไว้ไม่ใช่เหตุผิดปกติ');
    }

    /** @return array<string, array{string, string}> */
    public static function guardedRoutes(): array
    {
        return [
            'เริ่มเชื่อม' => ['connect', 'beginAuthorization'],
            'กลับจาก Thaiprompt' => ['callback', 'completeAuthorization'],
            'ถามผลซ้ำ' => ['refresh', 'refresh'],
        ];
    }

    #[Test]
    #[DataProvider('guardedRoutes')]
    public function a_query_failure_never_reaches_the_browser(string $route, string $method): void
    {
        $this->storedLink();
        $this->serviceThrows($method, $this->queryFailure());

        $this->assertUnexpectedFailure($this->hit($route), $route);
    }

    #[Test]
    #[DataProvider('guardedRoutes')]
    public function a_query_failure_is_logged_without_its_bound_values(string $route, string $method): void
    {
        $this->storedLink();
        $this->serviceThrows($method, $this->queryFailure());

        $this->hit($route);

        // ต้องเหลือพอให้ไล่สาเหตุได้ — ข้อความจาก driver และ SQL ที่ยังไม่แทนค่า
        $this->assertStringContainsString('Deadlock', $this->logged);
        $this->assertStringContainsString('thaiprompt_kyc_links', $this->logged);
        $this->assertStringContainsString('kyc\/thaiprompt\/'.$route, $this->logged);
        $this->assertStringContainsString("user_id\":{$this->user->id}", $this->logged);
        $this->assertNoBoundValuesIn($this->logged);
    }

    #[Test]
    #[DataProvider('guardedRoutes')]
    public function other_runtime_exceptions_stay_on_the_server(string $route, string $method): void
    {
        $this->storedLink();
        $this->serviceThrows($method, new RuntimeException('cURL error 60: SSL certificate problem for '.self::BASE.'/oauth/token'));

        $this->assertUnexpectedFailure($this->hit($route), $route);

        $this->assertStringContainsString('cURL error 60', $this->logged);
    }

    /**
     * ข้อความ "locked" ทำให้ Laravel ห่อเป็น DeadlockException — ทรานแซกชันของ RefreshDatabase ทำให้ซ้อนกัน.
     *
     * @return array<string, array{string, class-string}>
     */
    public static function driverFailures(): array
    {
        return [
            'deadlock ที่ลอกข้อความมาทั้งก้อน' => ['database is locked', DeadlockException::class],
            'query ล้มเหลวทั่วไป' => ['disk quota exceeded', QueryException::class],
        ];
    }

    /**
     * วาง trigger ที่ใบ KYC ไม่ใช่ที่ลิงก์ — SQLite ตอบ RAISE เป็น 23000 ซึ่ง bind() ตีความว่าบัญชีถูกใช้แล้ว
     * ตรงนี้เป็นขั้นหลังผูกบัญชี ไม่มี catch ของ service คั่นอยู่.
     */
    #[Test]
    #[DataProvider('driverFailures')]
    public function a_real_failed_insert_on_return_stays_out_of_the_page_and_the_log(string $driverError, string $thrown): void
    {
        $this->thaipromptAnswers();
        $state = $this->startConnect();
        DB::statement("CREATE TRIGGER block_kyc_submission BEFORE INSERT ON kyc_submissions BEGIN SELECT RAISE(ABORT, '{$driverError}'); END");

        $response = $this->hit('callback', 'code='.self::OAUTH_CODE.'&state='.$state);

        $this->assertRedirectedWith($response, ThaipromptKycService::ERR_UNEXPECTED);

        $this->assertNoBoundValuesIn($this->logged);
        $this->assertStringNotContainsString($state, $this->logged);
        $this->assertStringContainsString($driverError, $this->logged);
        $this->assertStringContainsString('kyc_submissions', $this->logged);
        $this->assertStringContainsString(class_basename($thrown), $this->logged);
        $this->assertDatabaseCount('kyc_submissions', 0);
    }

    /** ต่ออายุ token = UPDATE ที่มี ciphertext ของ token ใหม่เป็นค่า bind ตรงๆ */
    #[Test]
    public function a_real_failed_token_update_during_refresh_keeps_the_ciphertext_private(): void
    {
        $this->thaipromptAnswers();
        $this->expiredLink();
        DB::statement("CREATE TRIGGER block_link_update BEFORE UPDATE ON thaiprompt_kyc_links BEGIN SELECT RAISE(ABORT, 'disk quota exceeded'); END");

        $response = $this->hit('refresh');

        $this->assertUnexpectedFailure($response, 'refresh');

        $this->assertNoBoundValuesIn($this->logged);
        $this->assertStringContainsString('disk quota exceeded', $this->logged);
        $this->assertStringContainsString('thaiprompt_kyc_links', $this->logged);
    }

    /** เปลี่ยน APP_KEY แล้ว token เก่าถอดไม่ออก — DecryptException ก็เป็น RuntimeException */
    #[Test]
    public function a_token_that_cannot_be_decrypted_is_not_described_to_the_browser(): void
    {
        $this->thaipromptAnswers();
        $this->expiredLink();
        DB::table('thaiprompt_kyc_links')->update(['refresh_token' => 'not-a-ciphertext']);

        $this->assertUnexpectedFailure($this->hit('refresh'), 'refresh');

        $this->assertStringContainsString('DecryptException', $this->logged);
    }

    /** ฐานข้อมูลล่มจริง: สถานะที่จะแนบไปกับคำตอบก็อ่านไม่ได้เหมือนกัน */
    #[Test]
    public function a_failure_reading_the_attached_status_falls_to_the_global_handler(): void
    {
        config(['app.debug' => false]);
        $this->storedLink();
        $this->partialMock(ThaipromptKycService::class, fn ($mock) => $mock->shouldReceive('refresh')->andReturnUsing(function () {
            Schema::drop('thaiprompt_kyc_links');

            throw $this->queryFailure();
        }));

        $this->hit('refresh')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);

        $this->assertNoBoundValuesIn($this->logged);
    }

    #[Test]
    public function an_unconfigured_connect_keeps_its_message(): void
    {
        config(['services.thaiprompt.oauth_client_id' => null]);

        $this->assertRedirectedWith($this->hit('connect'), ThaipromptKycService::ERR_NOT_CONFIGURED);
        $this->assertNotReportedAsUnexpected();
    }

    #[Test]
    public function a_forged_state_keeps_its_message(): void
    {
        $this->thaipromptAnswers();
        $this->startConnect();

        $this->assertRedirectedWith($this->hit('callback'), ThaipromptKycService::ERR_STATE);
        $this->assertNotReportedAsUnexpected();
    }

    #[Test]
    public function a_denied_consent_keeps_its_message(): void
    {
        $state = $this->startConnect();

        $this->assertRedirectedWith($this->hit('callback', 'error=access_denied&state='.$state), ThaipromptKycService::ERR_DENIED);
        $this->assertNotReportedAsUnexpected();
    }

    #[Test]
    public function a_thaiprompt_account_used_elsewhere_keeps_its_message(): void
    {
        $other = User::create([
            'email' => 'other@tpix.test',
            'password' => bcrypt('secret-password'),
            'wallet_address' => '0x2222222222222222222222222222222222222222',
        ]);
        $this->storedLink(['user_id' => $other->id]);
        $this->thaipromptAnswers();
        $state = $this->startConnect();

        $response = $this->hit('callback', 'code='.self::OAUTH_CODE.'&state='.$state);

        $this->assertRedirectedWith($response, ThaipromptKycService::ERR_TAKEN);
        $this->assertNotReportedAsUnexpected();
    }

    /** @return array<string, array{int, array<string, mixed>, int, array<string, mixed>, string}> */
    public static function callbackRefusals(): array
    {
        return [
            'code ใช้ไม่ได้' => [400, ['error' => 'invalid_grant'], 200, self::VERIFIED, ThaipromptKycService::ERR_STATE],
            'แลก token ตอนเขาล่ม' => [503, [], 200, self::VERIFIED, ThaipromptKycService::ERR_UNAVAILABLE],
            'ไม่ได้รับสิทธิ์ kyc' => [200, self::TOKENS, 401, [], ThaipromptKycService::ERR_STATE],
            'ถามสถานะตอนเขาล่ม' => [200, self::TOKENS, 500, [], ThaipromptKycService::ERR_UNAVAILABLE],
        ];
    }

    #[Test]
    #[DataProvider('callbackRefusals')]
    public function thaiprompt_refusals_on_return_keep_their_message(int $tokenStatus, array $tokens, int $kycStatus, array $kyc, string $message): void
    {
        $this->thaipromptAnswers($tokenStatus, $tokens, $kycStatus, $kyc);
        $state = $this->startConnect();

        $response = $this->hit('callback', 'code='.self::OAUTH_CODE.'&state='.$state);

        $this->assertRedirectedWith($response, $message);
        $this->assertNotReportedAsUnexpected();
    }

    #[Test]
    public function a_lost_connection_on_return_keeps_its_message(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $state = $this->startConnect();

        $response = $this->hit('callback', 'code='.self::OAUTH_CODE.'&state='.$state);

        $this->assertRedirectedWith($response, ThaipromptKycService::ERR_UNAVAILABLE);
        $this->assertNotReportedAsUnexpected();
    }

    /** @return array<string, array{int, array<string, mixed>, int, array<string, mixed>, string}> */
    public static function refreshRefusals(): array
    {
        return [
            'refresh token ใช้ไม่ได้' => [401, [], 200, self::VERIFIED, ThaipromptKycService::ERR_RECONNECT],
            'ต่ออายุตอนเขาล่ม' => [503, [], 200, self::VERIFIED, ThaipromptKycService::ERR_UNAVAILABLE],
            'ถามสถานะตอนเขาล่ม' => [200, self::TOKENS, 500, [], ThaipromptKycService::ERR_UNAVAILABLE],
            'ตอบเป็นบัญชีอื่น' => [200, self::TOKENS, 200, ['sub' => 'someone-else'] + self::VERIFIED, ThaipromptKycService::ERR_RECONNECT],
        ];
    }

    #[Test]
    #[DataProvider('refreshRefusals')]
    public function thaiprompt_refusals_during_refresh_keep_their_message_and_status(int $tokenStatus, array $tokens, int $kycStatus, array $kyc, string $message): void
    {
        $this->thaipromptAnswers($tokenStatus, $tokens, $kycStatus, $kyc);
        $this->expiredLink();

        $this->assertRefreshFailed($this->hit('refresh'), 422, $message);
        $this->assertNotReportedAsUnexpected();
    }

    #[Test]
    public function a_link_waiting_for_reconnect_keeps_its_message(): void
    {
        $this->storedLink(['needs_reconnect' => true]);

        $this->assertRefreshFailed($this->hit('refresh'), 422, ThaipromptKycService::ERR_RECONNECT);
        $this->assertNotReportedAsUnexpected();
    }

    #[Test]
    public function an_unconfigured_refresh_keeps_its_message(): void
    {
        config(['services.thaiprompt.oauth_client_id' => null]);
        $this->storedLink();

        $this->assertRefreshFailed($this->hit('refresh'), 422, ThaipromptKycService::ERR_NOT_CONFIGURED);
        $this->assertNotReportedAsUnexpected();
    }

    #[Test]
    public function a_user_with_no_link_still_gets_404(): void
    {
        $this->hit('refresh')->assertNotFound()->assertJsonPath('success', false);
    }
}
