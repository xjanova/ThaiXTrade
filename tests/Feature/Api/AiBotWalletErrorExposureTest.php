<?php

namespace Tests\Feature\Api;

use App\Models\AiBotWallet;
use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletKeyring;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\Web3BalanceService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * ข้อความ exception ดิบของกระเป๋าบอทต้องไม่ถึงผู้ใช้ — QueryException ก็เป็น RuntimeException.
 *
 * ตอนสร้างกระเป๋า ค่า bind ของ SQL คือ key_ciphertext คอลัมน์นี้จึงห้ามออกทาง response และห้ามลง log
 */
class AiBotWalletErrorExposureTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x1111111111111111111111111111111111111111';

    private const SEALED = 'SEALED-KEY-e3b0c44298fc1c149afbf4c8996fb924';

    private const INSERT = 'insert into `ai_bot_wallets` (`owner_address`, `key_ciphertext`) values (?, ?)';

    private const DRIVER_ERROR = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found';

    private string $logged = '';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // throttle ของกลุ่มกับของ route ใช้คีย์ (IP) เดียวกัน — เทสต์ที่ยิงหลายคำขอติดกันแล้วชน 429
        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'aibot.bot_wallet.enabled' => true,
            'aibot.bot_wallet.encryption_key' => str_repeat('k', 40),
        ]);

        Cache::put('wallet_verified:'.self::WALLET, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHours(4));

        // จับทุกระดับ ไม่ใช่แค่ error — กุญแจที่ห่อแล้วห้ามลง log ไม่ว่าระดับไหน
        Log::listen(function (MessageLogged $entry) {
            $this->logged .= $entry->message.' '.json_encode($entry->context)."\n";
        });
    }

    private function queryFailure(): QueryException
    {
        return new QueryException('mysql', self::INSERT, [self::WALLET, self::SEALED], new PDOException(self::DRIVER_ERROR));
    }

    private function serviceThrows(string $method, RuntimeException $e): void
    {
        $this->partialMock(BotWalletService::class, fn ($mock) => $mock->shouldReceive($method)->andThrow($e));
    }

    private function sealsTo(string $sealed): void
    {
        $this->partialMock(BotWalletKeyring::class, fn ($mock) => $mock->shouldReceive('seal')->andReturn($sealed));
    }

    private function storedWallet(): AiBotWallet
    {
        return AiBotWallet::create([
            'owner_address' => self::WALLET,
            'chain_id' => 56,
            'address' => '0x2222222222222222222222222222222222222222',
            'key_ciphertext' => self::SEALED,
            'key_version' => BotWalletKeyring::KEY_VERSION,
            'status' => AiBotWallet::STATUS_ACTIVE,
        ]);
    }

    private function hit(string $endpoint): TestResponse
    {
        $wallet = ['wallet_address' => self::WALLET];

        return match ($endpoint) {
            'show' => $this->getJson('/api/v1/ai-bot/wallet?wallet_address='.self::WALLET),
            'store' => $this->postJson('/api/v1/ai-bot/wallet', $wallet),
            'refresh' => $this->postJson('/api/v1/ai-bot/wallet/refresh', $wallet),
            'withdraw' => $this->postJson('/api/v1/ai-bot/wallet/withdraw', $wallet + ['asset' => 'USDT', 'amount' => 50]),
            'cancel' => $this->postJson('/api/v1/ai-bot/wallet/withdraw/7/cancel', $wallet),
        };
    }

    private function assertNothingSensitiveIn(string $text): void
    {
        foreach (['SQLSTATE', 'insert into', 'key_ciphertext', 'Deadlock', self::SEALED] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
    }

    private function assertGenericFailure(TestResponse $response): void
    {
        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'REQUEST_FAILED')
            ->assertJsonPath('error.message', 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    }

    /** @return array<string, array{string, string}> */
    public static function guardedEndpoints(): array
    {
        return [
            'สร้างกระเป๋า' => ['store', 'ensure'],
            'ขอถอน' => ['withdraw', 'requestWithdraw'],
            'ยกเลิกถอน' => ['cancel', 'cancelWithdraw'],
        ];
    }

    #[Test]
    #[DataProvider('guardedEndpoints')]
    public function a_query_failure_never_reaches_the_client(string $endpoint, string $method): void
    {
        $this->serviceThrows($method, $this->queryFailure());

        $response = $this->hit($endpoint);

        $this->assertGenericFailure($response);
        $this->assertNothingSensitiveIn($response->getContent());
    }

    #[Test]
    #[DataProvider('guardedEndpoints')]
    public function a_query_failure_is_logged_without_its_bound_values(string $endpoint, string $method): void
    {
        $this->serviceThrows($method, $this->queryFailure());

        $this->hit($endpoint);

        // ต้องเหลือพอให้ไล่สาเหตุได้ — ข้อความจาก driver และ SQL ที่ยังไม่แทนค่า
        $this->assertStringContainsString('Deadlock', $this->logged);
        $this->assertStringContainsString('ai_bot_wallets', $this->logged);
        $this->assertStringNotContainsString(self::SEALED, $this->logged);
        $this->assertStringNotContainsString(self::WALLET, $this->logged);
    }

    /**
     * ข้อความ "locked" ทำให้ Laravel ห่อเป็น DeadlockException — ทรานแซกชันของ RefreshDatabase ทำให้ซ้อนกัน.
     *
     * @return array<string, array{string, class-string}>
     */
    public static function insertFailures(): array
    {
        return [
            'deadlock ที่ลอกข้อความมาทั้งก้อน' => ['database is locked', DeadlockException::class],
            'query ล้มเหลวทั่วไป' => ['disk quota exceeded', QueryException::class],
        ];
    }

    #[Test]
    #[DataProvider('insertFailures')]
    public function a_real_failed_insert_keeps_the_sealed_key_out_of_the_response_and_the_log(string $driverError, string $thrown): void
    {
        $this->sealsTo(self::SEALED);
        DB::statement("CREATE TRIGGER block_wallet_insert BEFORE INSERT ON ai_bot_wallets BEGIN SELECT RAISE(ABORT, '{$driverError}'); END");

        $response = $this->hit('store');

        $this->assertGenericFailure($response);
        $this->assertNothingSensitiveIn($response->getContent());
        $this->assertStringNotContainsString(self::SEALED, $this->logged);
        $this->assertStringContainsString($driverError, $this->logged);
        $this->assertStringContainsString(class_basename($thrown), $this->logged);
        $this->assertDatabaseCount('ai_bot_wallets', 0);
    }

    #[Test]
    public function internal_keyring_codes_are_not_part_of_the_api(): void
    {
        $this->serviceThrows('ensure', new RuntimeException('BOT_WALLET_SEAL_FAILED'));

        $response = $this->hit('store');

        $this->assertGenericFailure($response);
        $this->assertStringNotContainsString('BOT_WALLET_SEAL_FAILED', $response->getContent());
        $this->assertStringContainsString('BOT_WALLET_SEAL_FAILED', $this->logged);
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function deliberateFailures(): array
    {
        return [
            'สร้าง: ยังไม่เปิด' => ['store', 'ensure', BotWalletService::ERR_DISABLED, 503],
            'ถอน: ยังไม่เปิด' => ['withdraw', 'requestWithdraw', BotWalletService::ERR_DISABLED, 503],
            'ถอน: ไม่มีกระเป๋า' => ['withdraw', 'requestWithdraw', BotWalletService::ERR_NOT_FOUND, 404],
            'ถอน: ยอดไม่พอ' => ['withdraw', 'requestWithdraw', BotWalletService::ERR_BALANCE, 422],
            'ยกเลิก: ไม่พบรายการ' => ['cancel', 'cancelWithdraw', BotWalletService::ERR_NOT_FOUND, 404],
            'ยกเลิก: ส่งไปแล้ว' => ['cancel', 'cancelWithdraw', BotWalletService::ERR_NOT_CANCELLABLE, 422],
        ];
    }

    #[Test]
    #[DataProvider('deliberateFailures')]
    public function deliberate_codes_keep_their_status(string $endpoint, string $method, string $code, int $status): void
    {
        $this->serviceThrows($method, new RuntimeException($code));

        $this->hit($endpoint)
            ->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', $code);

        $this->assertSame('', $this->logged, 'ความล้มเหลวที่ตั้งใจไว้ไม่ใช่เหตุผิดปกติ');
    }

    /** @return array<string, array{string}> */
    public static function serviceCodes(): array
    {
        $constants = (new ReflectionClass(BotWalletService::class))->getConstants();
        $codes = array_filter($constants, fn (string $name) => str_starts_with($name, 'ERR_'), ARRAY_FILTER_USE_KEY);

        return array_map(fn (string $code) => [$code], $codes);
    }

    /** รหัสใหม่ที่ลืมใส่ข้อความจะกลายเป็น 500 เงียบ ๆ — ผู้ใช้ไม่รู้ว่าต้องแก้อะไร */
    #[Test]
    #[DataProvider('serviceCodes')]
    public function every_service_code_has_a_message_of_its_own(string $code): void
    {
        $this->serviceThrows('requestWithdraw', new RuntimeException($code));

        $response = $this->hit('withdraw');

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertJsonPath('error.code', $code);
        $this->assertNotSame('ทำรายการไม่สำเร็จ', $response->json('error.message'));
    }

    /**
     * สองทางนี้ไม่มี catch ของตัวเอง — ตกถึงตัวจัดการกลางที่ซ่อนรายละเอียดเมื่อ APP_DEBUG=false.
     *
     * @return array<string, array{string, string}>
     */
    public static function unguardedEndpoints(): array
    {
        return [
            'ดูกระเป๋า' => ['show', 'find'],
            'อ่านยอดใหม่' => ['refresh', 'refreshBalances'],
        ];
    }

    #[Test]
    #[DataProvider('unguardedEndpoints')]
    public function endpoints_without_a_catch_stay_behind_the_global_handler(string $endpoint, string $method): void
    {
        config(['app.debug' => false]);
        $this->storedWallet();
        $this->serviceThrows($method, $this->queryFailure());

        $response = $this->hit($endpoint);

        $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertNothingSensitiveIn($response->getContent());
    }

    #[Test]
    public function successful_responses_never_carry_the_sealed_key(): void
    {
        $this->sealsTo(self::SEALED);
        $this->partialMock(Web3BalanceService::class, function ($mock) {
            $mock->shouldReceive('getNativeBalance')->andReturn('0.05');
            $mock->shouldReceive('getTokenBalance')->andReturn('120');
        });

        $bodies = [
            $this->hit('store')->assertStatus(201)->getContent(),
            $this->hit('refresh')->assertStatus(200)->getContent(),
            $this->hit('withdraw')->assertStatus(201)->getContent(),
            $this->hit('show')->assertStatus(200)->getContent(),
        ];

        $this->assertSame(self::SEALED, AiBotWallet::first()->key_ciphertext);
        $this->assertSame(1, AiBotWalletTransfer::count());

        foreach ($bodies as $body) {
            $this->assertNothingSensitiveIn($body);
        }
    }
}
