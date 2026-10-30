<?php

namespace Tests\Feature\Api;

use App\Models\ThaipromptKycLink;
use App\Models\User;
use App\Services\Kyc\ThaipromptKycService;
use App\Services\WalletSessionService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ข้อความ exception ดิบของหน้ายืนยันตัวตนในแอปต้องไม่ถึงผู้ใช้ — QueryException ก็เป็น RuntimeException.
 *
 * ตอนผูกบัญชีและต่ออายุ token ค่า bind ของ SQL คือ token ของ Thaiprompt และรหัสผู้ใช้ฝั่งโน้น
 * ค่าพวกนี้จึงห้ามออกทาง response และห้ามลง log
 */
class KycApiErrorExposureTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x1111111111111111111111111111111111111111';

    private const BASE = 'https://tp.test';

    private const SUB = 'thaiprompt-sub-7c9e6679';

    private const ACCESS_TOKEN = 'ACCESS-e3b0c44298fc1c149afbf4c8996fb924';

    private const REFRESH_TOKEN = 'REFRESH-27ae41e4649b934ca495991b7852b855';

    private const COMPLETION = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4';

    /** Laravel เข้ารหัสเป็น base64 ของ {"iv":"... — ทุก token ที่ลงฐานขึ้นต้นแบบนี้ */
    private const CIPHERTEXT = 'eyJpdiI6';

    private const UPDATE = 'update `thaiprompt_kyc_links` set `access_token` = ?, `refresh_token` = ? where `thaiprompt_user_id` = ?';

    private const DRIVER_ERROR = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found';

    private const GENERIC_MESSAGE = 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';

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

        // throttle ของกลุ่มกับของ route ใช้คีย์เดียวกัน — เทสต์ที่ยิงหลายคำขอติดกันแล้วชน 429
        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'services.thaiprompt.base_url' => self::BASE,
            'services.thaiprompt.oauth_client_id' => '9',
            'services.thaiprompt.oauth_client_secret' => 'secret-xyz',
        ]);

        $this->user = User::create([
            'email' => 'trader@tpix.test',
            'password' => bcrypt('secret-password'),
            'wallet_address' => self::WALLET,
        ]);

        $issued = app(WalletSessionService::class)->issue(self::WALLET, 56);
        $this->withHeaders([WalletSessionService::HEADER => $issued['token']]);

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

    /** เริ่มคำขอ + Thaiprompt ส่งลูกค้ากลับมา → ได้รหัสรับผลจริงที่แลกได้ */
    private function completion(): string
    {
        $service = app(ThaipromptKycService::class);

        parse_str((string) parse_url($service->beginAppAuthorization($this->user), PHP_URL_QUERY), $authorize);
        parse_str((string) parse_url($service->captureAppCallback(['state' => $authorize['state'], 'code' => 'abc']), PHP_URL_QUERY), $deepLink);

        return $deepLink['completion'];
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

    private function hit(string $endpoint, string $completion = self::COMPLETION): TestResponse
    {
        $wallet = ['wallet_address' => self::WALLET];

        return match ($endpoint) {
            'status' => $this->getJson('/api/v1/kyc/status?wallet_address='.self::WALLET),
            'start' => $this->postJson('/api/v1/kyc/thaiprompt/start', $wallet + ['consent' => true]),
            'complete' => $this->postJson('/api/v1/kyc/thaiprompt/complete', $wallet + ['completion' => $completion]),
            'refresh' => $this->postJson('/api/v1/kyc/thaiprompt/refresh', $wallet),
        };
    }

    private function assertNoBoundValuesIn(string $text): void
    {
        foreach ([self::SUB, self::ACCESS_TOKEN, self::REFRESH_TOKEN, self::CIPHERTEXT, self::COMPLETION] as $needle) {
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

    private function assertGenericFailure(TestResponse $response, string $code): void
    {
        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', $code)
            ->assertJsonPath('error.message', self::GENERIC_MESSAGE);
    }

    private function assertDeliberateFailure(TestResponse $response, string $code, string $message): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', $code)
            ->assertJsonPath('error.message', $message);

        $this->assertStringNotContainsString('สาเหตุที่ไม่ได้ตั้งใจ', $this->logged, 'ความล้มเหลวที่ตั้งใจไว้ไม่ใช่เหตุผิดปกติ');
    }

    /** @return array<string, array{string, string, string}> */
    public static function guardedEndpoints(): array
    {
        return [
            'เริ่มเชื่อม' => ['start', 'beginAppAuthorization', 'REQUEST_FAILED'],
            // รหัสรับผลถูกดึงออกจากแคชไปแล้ว — รหัสนี้สั่งแอปเลิกเก็บไว้แลกซ้ำ
            'แลกรหัสรับผล' => ['complete', 'completeAppAuthorization', 'THAIPROMPT_LINK_FAILED'],
            'ถามผลซ้ำ' => ['refresh', 'refresh', 'REQUEST_FAILED'],
        ];
    }

    #[Test]
    #[DataProvider('guardedEndpoints')]
    public function a_query_failure_never_reaches_the_client(string $endpoint, string $method, string $code): void
    {
        $this->storedLink();
        $this->serviceThrows($method, $this->queryFailure());

        $response = $this->hit($endpoint);

        $this->assertGenericFailure($response, $code);
        $this->assertNothingSensitiveIn($response->getContent());
    }

    #[Test]
    #[DataProvider('guardedEndpoints')]
    public function a_query_failure_is_logged_without_its_bound_values(string $endpoint, string $method): void
    {
        $this->storedLink();
        $this->serviceThrows($method, $this->queryFailure());

        $this->hit($endpoint);

        // ต้องเหลือพอให้ไล่สาเหตุได้ — ข้อความจาก driver และ SQL ที่ยังไม่แทนค่า
        $this->assertStringContainsString('Deadlock', $this->logged);
        $this->assertStringContainsString('thaiprompt_kyc_links', $this->logged);
        $this->assertStringContainsString("user_id\":{$this->user->id}", $this->logged);
        $this->assertNoBoundValuesIn($this->logged);
    }

    #[Test]
    #[DataProvider('guardedEndpoints')]
    public function other_runtime_exceptions_stay_on_the_server(string $endpoint, string $method, string $code): void
    {
        $this->storedLink();
        $this->serviceThrows($method, new RuntimeException('cURL error 60: SSL certificate problem for '.self::BASE.'/oauth/token'));

        $response = $this->hit($endpoint);

        $this->assertGenericFailure($response, $code);
        $this->assertStringNotContainsString('cURL', $response->getContent());
        $this->assertStringNotContainsString(self::BASE, $response->getContent());
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
    public function a_real_failed_insert_while_completing_stays_out_of_the_response_and_the_log(string $driverError, string $thrown): void
    {
        $this->thaipromptAnswers();
        $completion = $this->completion();
        DB::statement("CREATE TRIGGER block_kyc_submission BEFORE INSERT ON kyc_submissions BEGIN SELECT RAISE(ABORT, '{$driverError}'); END");

        $response = $this->hit('complete', $completion);

        $this->assertGenericFailure($response, 'THAIPROMPT_LINK_FAILED');
        $this->assertNothingSensitiveIn($response->getContent());
        $this->assertStringNotContainsString($driverError, $response->getContent());
        $this->assertStringNotContainsString($completion, $response->getContent());

        $this->assertNoBoundValuesIn($this->logged);
        $this->assertStringNotContainsString($completion, $this->logged);
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

        $this->assertGenericFailure($response, 'REQUEST_FAILED');
        $response->assertJsonPath('data.thaiprompt.link.status', ThaipromptKycLink::STATUS_PENDING);
        $this->assertNothingSensitiveIn($response->getContent());

        $this->assertNoBoundValuesIn($this->logged);
        $this->assertStringContainsString('disk quota exceeded', $this->logged);
        $this->assertStringContainsString('thaiprompt_kyc_links', $this->logged);
    }

    /** เปลี่ยน APP_KEY แล้ว token เก่าถอดไม่ออก — DecryptException ก็เป็น RuntimeException */
    #[Test]
    public function a_token_that_cannot_be_decrypted_is_not_described_to_the_client(): void
    {
        $this->thaipromptAnswers();
        $this->expiredLink();
        DB::table('thaiprompt_kyc_links')->update(['refresh_token' => 'not-a-ciphertext']);

        $response = $this->hit('refresh');

        $this->assertGenericFailure($response, 'REQUEST_FAILED');
        $this->assertStringNotContainsString('payload', strtolower($response->json('error.message')));
        $this->assertStringContainsString('DecryptException', $this->logged);
    }

    /** ฐานข้อมูลล่มจริง: สถานะที่จะแนบไปกับคำตอบก็อ่านไม่ได้เหมือนกัน */
    #[Test]
    public function a_failure_reading_the_attached_status_falls_to_the_global_handler(): void
    {
        config(['app.debug' => false]);
        $this->storedLink();
        $this->partialMock(ThaipromptKycService::class, function ($mock) {
            $mock->shouldReceive('refresh')->andThrow($this->queryFailure());
            $mock->shouldReceive('ownerPayload')->andThrow($this->queryFailure());
        });

        $response = $this->hit('refresh');

        $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
    }

    #[Test]
    public function the_status_endpoint_stays_behind_the_global_handler(): void
    {
        config(['app.debug' => false]);
        $this->serviceThrows('ownerPayload', $this->queryFailure());

        $response = $this->hit('status');

        $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertNothingSensitiveIn($response->getContent());
    }

    #[Test]
    public function an_unconfigured_start_keeps_its_message(): void
    {
        config(['services.thaiprompt.oauth_client_id' => null]);

        $this->assertDeliberateFailure($this->hit('start'), 'THAIPROMPT_UNAVAILABLE', ThaipromptKycService::ERR_NOT_CONFIGURED);
    }

    #[Test]
    public function an_unknown_completion_keeps_its_message(): void
    {
        $this->assertDeliberateFailure($this->hit('complete'), 'THAIPROMPT_LINK_FAILED', ThaipromptKycService::ERR_STATE);
    }

    #[Test]
    public function a_thaiprompt_account_used_elsewhere_keeps_its_own_code(): void
    {
        $other = User::create([
            'email' => 'other@tpix.test',
            'password' => bcrypt('secret-password'),
            'wallet_address' => '0x2222222222222222222222222222222222222222',
        ]);
        $this->storedLink(['user_id' => $other->id]);
        $this->thaipromptAnswers();

        $this->assertDeliberateFailure($this->hit('complete', $this->completion()), 'THAIPROMPT_TAKEN', ThaipromptKycService::ERR_TAKEN);
    }

    /** @return array<string, array{int, array<string, mixed>, int, array<string, mixed>, string}> */
    public static function completeRefusals(): array
    {
        return [
            'code ใช้ไม่ได้' => [400, ['error' => 'invalid_grant'], 200, self::VERIFIED, ThaipromptKycService::ERR_STATE],
            'แลก token ตอนเขาล่ม' => [503, [], 200, self::VERIFIED, ThaipromptKycService::ERR_UNAVAILABLE],
            'ไม่มี access token' => [200, ['token_type' => 'Bearer'], 200, self::VERIFIED, ThaipromptKycService::ERR_UNAVAILABLE],
            'ไม่ได้รับสิทธิ์ kyc' => [200, self::TOKENS, 401, [], ThaipromptKycService::ERR_STATE],
            'ถามสถานะตอนเขาล่ม' => [200, self::TOKENS, 500, [], ThaipromptKycService::ERR_UNAVAILABLE],
            'สถานะผิดรูป' => [200, self::TOKENS, 200, ['sub' => ''], ThaipromptKycService::ERR_UNAVAILABLE],
        ];
    }

    #[Test]
    #[DataProvider('completeRefusals')]
    public function thaiprompt_refusals_while_completing_keep_their_message(int $tokenStatus, array $tokens, int $kycStatus, array $kyc, string $message): void
    {
        $this->thaipromptAnswers($tokenStatus, $tokens, $kycStatus, $kyc);

        $this->assertDeliberateFailure($this->hit('complete', $this->completion()), 'THAIPROMPT_LINK_FAILED', $message);
    }

    #[Test]
    public function a_lost_connection_while_completing_keeps_its_message(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->assertDeliberateFailure($this->hit('complete', $this->completion()), 'THAIPROMPT_LINK_FAILED', ThaipromptKycService::ERR_UNAVAILABLE);
    }

    /** @return array<string, array{int, array<string, mixed>, int, array<string, mixed>, string, string}> */
    public static function refreshRefusals(): array
    {
        $reconnect = [ThaipromptKycService::ERR_RECONNECT, 'THAIPROMPT_RECONNECT'];
        $unavailable = [ThaipromptKycService::ERR_UNAVAILABLE, 'THAIPROMPT_UNAVAILABLE'];

        return [
            'refresh token ใช้ไม่ได้' => [401, [], 200, self::VERIFIED, ...$reconnect],
            'ต่ออายุตอนเขาล่ม' => [503, [], 200, self::VERIFIED, ...$unavailable],
            'ถามสถานะตอนเขาล่ม' => [200, self::TOKENS, 500, [], ...$unavailable],
            'ตอบเป็นบัญชีอื่น' => [200, self::TOKENS, 200, ['sub' => 'someone-else'] + self::VERIFIED, ...$reconnect],
        ];
    }

    #[Test]
    #[DataProvider('refreshRefusals')]
    public function thaiprompt_refusals_during_refresh_keep_their_message_and_status(int $tokenStatus, array $tokens, int $kycStatus, array $kyc, string $message, string $code): void
    {
        $this->thaipromptAnswers($tokenStatus, $tokens, $kycStatus, $kyc);
        $this->expiredLink();

        $response = $this->hit('refresh');

        $this->assertDeliberateFailure($response, $code, $message);
        $response->assertJsonStructure(['data' => ['gate', 'thaiprompt' => ['link']]]);
    }

    #[Test]
    public function a_link_waiting_for_reconnect_keeps_its_code(): void
    {
        $this->storedLink(['needs_reconnect' => true]);

        $this->assertDeliberateFailure($this->hit('refresh'), 'THAIPROMPT_RECONNECT', ThaipromptKycService::ERR_RECONNECT);
    }

    #[Test]
    public function an_unconfigured_refresh_keeps_its_message(): void
    {
        config(['services.thaiprompt.oauth_client_id' => null]);
        $this->storedLink();

        $this->assertDeliberateFailure($this->hit('refresh'), 'THAIPROMPT_UNAVAILABLE', ThaipromptKycService::ERR_NOT_CONFIGURED);
    }

    #[Test]
    public function successful_responses_never_carry_tokens_or_the_thaiprompt_user_id(): void
    {
        $this->thaipromptAnswers();

        $bodies = [
            $this->hit('complete', $this->completion())->assertOk()->assertJsonPath('data.thaiprompt.link.status', 'approved')->getContent(),
            $this->hit('refresh')->assertOk()->getContent(),
            $this->hit('status')->assertOk()->getContent(),
        ];

        $this->assertSame(self::ACCESS_TOKEN, ThaipromptKycLink::first()->access_token);

        foreach ($bodies as $body) {
            $this->assertNothingSensitiveIn($body);
        }
    }
}
