<?php

namespace Tests\Feature;

use App\Models\AiBotConfig;
use App\Models\AiBotDecision;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Services\MarketDataService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ตัวจับเวลาของบอทคลาวด์ต้องไม่ "แข็ง" เมื่อเวลาในฐานข้อมูลเพี้ยน.
 *
 * ⚠️ 2026-09-23: อัป Laravel 12 แล้วแอปเปลี่ยนโซนเวลาเงียบๆ (ไทย → UTC)
 *    last_run_at ที่เขียนเป็นเวลาไทยถูกอ่านเป็น UTC จึงล้ำหน้า 7 ชม.
 *    isDue() ใช้ diffInMinutes() ซึ่งใน Carbon 3 ติดลบได้ → บอททั้ง 15 ตัว
 *    บน prod "ยังไม่ถึงรอบ" ไปอีก ~7 ชม. ไม่มี error ไม่มี log สถานะยัง running
 *
 * Developed by Xman Studio.
 */
class AiBotTickCommandTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x4444444444444444444444444444444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(AiBotPlanSeeder::class);

        // ตลาดปลอม: ราคานิ่ง 101 แท่ง (แท่งสุดท้ายคือแท่งที่กำลังวิ่ง ถูกตัดทิ้ง) — ไม่ยิงเน็ตจริง
        $this->app->bind(MarketDataService::class, fn () => new class() extends MarketDataService
        {
            public function __construct() {}

            public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
            {
                $candles = [];

                for ($i = 0; $i < 101; $i++) {
                    $close = 100 + (($i % 2 === 0) ? 0.15 : -0.15);
                    $candles[] = [
                        'time' => 1_700_000_000_000 + $i * 3_600_000,
                        'open' => 100.0, 'high' => $close + 0.25, 'low' => $close - 0.25,
                        'close' => $close, 'volume' => 1000.0,
                    ];
                }

                return $candles;
            }
        });
    }

    /** บอทคลาวด์แพลน basic (รอบละ 5 นาที) ที่กำลังทำงาน */
    private function cloudBot(array $overrides = []): AiBotConfig
    {
        $plan = AiBotPlan::where('execution', 'cloud')->where('tier', 'basic')->firstOrFail();

        AiBotSubscription::create([
            'wallet_address' => self::WALLET, 'ai_bot_plan_id' => $plan->id,
            'status' => 'active', 'started_at' => now()->subDay(), 'expires_at' => now()->addDays(30),
        ]);

        return AiBotConfig::create(array_merge([
            'wallet_address' => self::WALLET, 'name' => 'tick', 'pair' => 'BTC/USDT',
            'strategy' => 'momentum', 'timeframe' => '1h', 'status' => 'running', 'mode' => 'demo',
            'params' => ['news_filter' => false],
        ], $overrides));
    }

    #[Test]
    public function บอทที่เพิ่งเดินไปยังไม่ถึงรอบ(): void
    {
        // ตัวควบคุม — ยืนยันว่าเทสต์ข้างล่างไม่ได้ผ่านเพราะ isDue() ตอบ true เสมอ
        $this->cloudBot(['last_run_at' => now()->subMinute()]);

        $this->artisan('aibot:tick', ['--strategy' => 'momentum'])
            ->expectsOutputToContain('ไม่มีบอทคลาวด์ที่ถึงรอบ')
            ->assertSuccessful();

        $this->assertSame(0, AiBotDecision::count());
    }

    #[Test]
    public function เวลารอบก่อนที่อยู่ในอนาคตต้องไม่ทำให้บอทหยุดเดิน(): void
    {
        // จำลองเหตุการณ์จริง: เวลาไทยถูกอ่านเป็น UTC = ล้ำหน้าไป 7 ชั่วโมง
        $bot = $this->cloudBot(['last_run_at' => now()->addHours(7)]);

        $this->artisan('aibot:tick', ['--strategy' => 'momentum'])
            ->doesntExpectOutputToContain('ไม่มีบอทคลาวด์ที่ถึงรอบ')
            ->assertSuccessful();

        $this->assertSame(1, AiBotDecision::where('ai_bot_config_id', $bot->id)->count(), 'บอทต้องได้คิดหนึ่งรอบ');
        $this->assertTrue($bot->fresh()->last_run_at->lte(now()), 'รอบนี้ต้องเขียนเวลาที่ถูกทับของเก่า');
    }
}
