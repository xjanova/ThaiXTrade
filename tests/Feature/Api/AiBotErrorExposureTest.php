<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Services\AiBotService;
use App\Services\MarketDataService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ข้อความ exception ดิบต้องไม่ถึงผู้ใช้ — QueryException และ RPC ก็เป็น RuntimeException เหมือนรหัสที่ตั้งใจส่ง.
 */
class AiBotErrorExposureTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x1111111111111111111111111111111111111111';

    private const RAW = 'SQLSTATE[23000]: insert into `ai_bot_credits` (`wallet`) values (0xdeadbeef)';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(AiBotPlanSeeder::class);

        Cache::put('wallet_verified:'.self::WALLET, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHours(4));
        $this->partialMock(MarketDataService::class, fn ($mock) => $mock->shouldReceive('hasKlines')->andReturn(true));

        config(['aibot.sales_open' => true]);
    }

    private function serviceThrows(string $method, string $message): void
    {
        $this->partialMock(AiBotService::class, fn ($mock) => $mock->shouldReceive($method)->andThrow(new RuntimeException($message)));
    }

    private function bot(string $status = 'running'): AiBotConfig
    {
        return AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'Bot A',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'status' => $status,
        ]);
    }

    private function hit(string $endpoint)
    {
        $wallet = ['wallet_address' => self::WALLET];
        $newBot = $wallet + ['name' => 'Bot A', 'pair' => 'BTC/USDT', 'strategy' => 'grid', 'timeframe' => '1h'];

        return match ($endpoint) {
            'topup' => $this->postJson('/api/v1/ai-bot/topup', $wallet + ['pack' => 'starter']),
            'subscribe' => $this->postJson('/api/v1/ai-bot/subscribe', $wallet + ['plan_code' => 'starter', 'days' => 30]),
            'store' => $this->postJson('/api/v1/ai-bot/bots', $newBot),
            'update' => $this->putJson('/api/v1/ai-bot/bots/'.$this->bot()->id, $newBot),
            'state' => $this->postJson('/api/v1/ai-bot/bots/'.$this->bot('paused')->id.'/state', $wallet + ['action' => 'start']),
            'tick' => $this->postJson('/api/v1/ai-bot/bots/'.$this->bot()->id.'/tick', $wallet),
        };
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'เติมเครดิต' => ['topup', 'createTopupIntent'],
            'เช่าแพลน' => ['subscribe', 'subscribe'],
            'สร้างบอท' => ['store', 'assertCanRunBot'],
            'แก้บอท' => ['update', 'assertCanRunBot'],
            'เริ่มบอท' => ['state', 'assertCanRunBot'],
            'เดินบอทจากเบราว์เซอร์' => ['tick', 'assertCanRunBot'],
        ];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function raw_exception_text_stays_on_the_server(string $endpoint, string $method): void
    {
        $this->serviceThrows($method, self::RAW);
        Log::spy();

        $response = $this->hit($endpoint);

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'REQUEST_FAILED')
            ->assertJsonPath('error.message', 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('deadbeef', $response->getContent());

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context) => str_contains($context['error'] ?? '', 'SQLSTATE')
        );
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function deliberate_codes_keep_their_code_and_message(string $endpoint, string $method): void
    {
        $this->serviceThrows($method, AiBotService::ERR_SUBSCRIBE_BUSY);

        $response = $this->hit($endpoint);

        $this->assertLessThan(500, $response->getStatusCode());
        $response->assertJsonPath('error.code', AiBotService::ERR_SUBSCRIBE_BUSY)
            ->assertJsonPath('error.message', 'กำลังทำรายการเช่าของกระเป๋านี้อยู่ กรุณารอสักครู่แล้วลองใหม่');
    }

    /** @return array<string, array{string, int}> */
    public static function subscribeCodes(): array
    {
        return [
            'เครดิตไม่พอ' => [AiBotService::ERR_INSUFFICIENT_CREDITS, 402],
            'คีย์ชน' => [AiBotService::ERR_IDEMPOTENCY_CONFLICT, 409],
            'ยังไม่เปิดขาย' => [AiBotService::ERR_SALES_CLOSED, 422],
        ];
    }

    #[Test]
    #[DataProvider('subscribeCodes')]
    public function subscribe_keeps_its_status_codes_for_deliberate_failures(string $code, int $status): void
    {
        $this->serviceThrows('subscribe', $code);

        $this->hit('subscribe')->assertStatus($status);
    }

    #[Test]
    public function a_raw_failure_does_not_pause_the_bot_during_a_browser_tick(): void
    {
        $this->serviceThrows('assertCanRunBot', self::RAW);
        $bot = $this->bot();

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/tick", ['wallet_address' => self::WALLET])->assertStatus(500);

        $this->assertSame('running', $bot->fresh()->status);
    }
}
