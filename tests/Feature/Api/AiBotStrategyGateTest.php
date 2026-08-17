<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — กลยุทธ์ที่ยังเปิดใช้ไม่ได้ต้องสร้างบอทไม่ได้.
 *
 * เจ้าของสั่งว่า "ฟังก์ชันไหนยังใช้ไม่ได้ ก็ต้องทำให้ปุ่มไม่พร้อมใช้ไปก่อน"
 *
 * ⚠️ ปุ่มเทาอย่างเดียวไม่พอ ต้องกันที่ API ด้วย
 *    แอพมือถือกับใครก็ตามที่ยิง endpoint ตรงไม่เคยเห็นปุ่มของเรา ถ้ากันแค่หน้าเว็บ
 *    ผู้ใช้ VIP จะสร้างบอทอาร์บิทราจได้ผ่านแอพ แล้วบอทนั้นจะกินเครดิตทุกครั้งที่ตื่น
 *    โดยไม่เคยเข้าไม้เลย เพราะยังไม่มีราคาฝั่งที่สองให้เทียบส่วนต่าง
 *
 * Developed by Xman Studio.
 */
class AiBotStrategyGateTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x8888888888888888888888888888888888888888';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(AiBotPlanSeeder::class);

        Cache::put('wallet_verified:'.self::WALLET, [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));

        // VIP ปลดล็อกทุกกลยุทธ์ — เพื่อพิสูจน์ว่าด่านนี้ไม่ใช่เรื่องระดับแพลน
        AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => AiBotPlan::where('code', 'vip')->firstOrFail()->id,
            'status' => 'active',
            'days' => 30,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);
    }

    private function botPayload(array $overrides = []): array
    {
        return array_merge([
            'wallet_address' => self::WALLET,
            'name' => 'บอททดสอบ',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
        ], $overrides);
    }

    #[Test]
    public function แคตตาล็อกบอกว่ากลยุทธ์ไหนยังลงมือไม่ได้(): void
    {
        $strategies = $this->getJson('/api/v1/ai-bot/catalog')
            ->assertOk()
            ->json('data.strategies');

        $arbitrage = collect($strategies)->firstWhere('code', 'arbitrage');
        $grid = collect($strategies)->firstWhere('code', 'grid');

        $this->assertFalse($arbitrage['available']);
        $this->assertNotEmpty($arbitrage['unavailable_reason']);
        $this->assertTrue($grid['available']);
    }

    #[Test]
    public function ผู้ใช้_vip_ก็ยังสร้างบอทอาร์บิทราจไม่ได้(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['strategy' => 'arbitrage', 'timeframe' => '5m']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('strategy');

        $this->assertDatabaseCount('ai_bot_configs', 0);
    }

    /** ข้อความต้องบอกเหตุผลจริง ไม่ใช่ "ไม่พบกลยุทธ์" ซึ่งทำให้คิดว่ากรอกผิด */
    #[Test]
    public function ข้อความปฏิเสธบอกเหตุผลที่แท้จริง(): void
    {
        $message = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['strategy' => 'arbitrage', 'timeframe' => '5m']))
            ->assertStatus(422)
            ->json('errors.strategy.0');

        $this->assertStringContainsString('ยังเปิดใช้งานไม่ได้', $message);
        $this->assertStringContainsString('DEX', $message);
    }

    #[Test]
    public function กลยุทธ์ที่พร้อมใช้ยังสร้างได้ตามปกติ(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['strategy' => 'grid']))
            ->assertStatus(201);

        $this->assertDatabaseHas('ai_bot_configs', ['strategy' => 'grid']);
    }

    /**
     * บอทที่สร้างไว้ก่อนกลยุทธ์ถูกปิด ต้องยังแก้ค่าอื่นได้.
     *
     * ปิดตายทั้งหมดจะบังคับให้ผู้ใช้ลบบอททิ้งอย่างเดียว ทั้งที่การลดความเสี่ยง
     * หรือแก้ชื่อไม่ได้ทำให้กลยุทธ์นั้นเดินได้เพิ่มขึ้นเลย
     */
    #[Test]
    public function บอทอาร์บิทราจที่มีอยู่แล้วยังแก้ค่าอื่นได้(): void
    {
        $bot = AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'บอทเก่า',
            'pair' => 'BTC/USDT',
            'strategy' => 'arbitrage',
            'timeframe' => '5m',
            'status' => 'paused',
            'mode' => 'demo',
            'params' => [],
            'risk' => ['max_position_usd' => 100],
        ]);

        $this->putJson("/api/v1/ai-bot/bots/{$bot->id}", $this->botPayload([
            'name' => 'บอทเก่า (แก้ชื่อ)',
            'strategy' => 'arbitrage',
            'timeframe' => '5m',
        ]))->assertOk();

        $this->assertSame('บอทเก่า (แก้ชื่อ)', $bot->fresh()->name);
    }

    /** แต่จะสลับบอทตัวอื่นมาใช้กลยุทธ์ที่ปิดอยู่ไม่ได้ */
    #[Test]
    public function สลับบอทที่มีอยู่ไปใช้กลยุทธ์ที่ปิดอยู่ไม่ได้(): void
    {
        $bot = AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'บอทกริด',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'status' => 'paused',
            'mode' => 'demo',
            'params' => [],
            'risk' => ['max_position_usd' => 100],
        ]);

        $this->putJson("/api/v1/ai-bot/bots/{$bot->id}", $this->botPayload([
            'strategy' => 'arbitrage',
            'timeframe' => '5m',
        ]))->assertStatus(422);

        $this->assertSame('grid', $bot->fresh()->strategy);
    }
}
