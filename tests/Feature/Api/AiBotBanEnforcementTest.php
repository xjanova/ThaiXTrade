<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Services\AiBotService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — บอทที่ทีมงานระงับต้องหยุดจริงทั้งสองเส้นทาง.
 *
 * ⚠️ เส้นทางของแพลนฟรีไม่ผ่าน `AiBotConfig::runnable()` เลย
 *    เบราว์เซอร์ของผู้ใช้ยิง POST /bots/{id}/tick เข้ามาตรงๆ
 *    ถ้ากันแค่ที่ scope การระงับจะได้ผลเฉพาะบอทคลาวด์ ส่วนบอทฟรีเดินต่อได้เรื่อยๆ
 *    ซึ่งเป็นกลุ่มที่ควบคุมยากที่สุดอยู่แล้ว — เทสต์ชุดนี้จึงยืนเส้นทางนั้นเป็นหลัก
 *
 * Developed by Xman Studio.
 */
class AiBotBanEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private string $wallet = '0x2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(AiBotPlanSeeder::class);

        Cache::put('wallet_verified:'.strtolower($this->wallet), [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    /** บอทของแพลนฟรี (เดินจากเบราว์เซอร์) ที่กำลังทำงานอยู่ */
    private function freeBot(array $overrides = []): AiBotConfig
    {
        $sub = app(AiBotService::class)->ensureFreeSubscription($this->wallet);

        return AiBotConfig::create(array_merge([
            'wallet_address' => strtolower($this->wallet),
            'ai_bot_subscription_id' => $sub->id,
            'name' => 'บอทแพลนฟรี',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'params' => [],
            'risk' => ['max_position_usd' => 100],
            'status' => 'running',
            'mode' => 'demo',
        ], $overrides));
    }

    #[Test]
    public function บอทฟรีที่ถูกระงับสั่งเดินจากเบราว์เซอร์ไม่ได้(): void
    {
        $bot = $this->freeBot([
            'banned_at' => now(),
            'banned_reason' => 'ต้องสงสัยว่าปั่นราคา',
        ]);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/tick", ['wallet_address' => $this->wallet])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'BOT_BANNED');
    }

    #[Test]
    public function เจ้าของกดเริ่มบอทที่ถูกระงับไม่ได้(): void
    {
        $bot = $this->freeBot([
            'status' => 'stopped',
            'banned_at' => now(),
            'banned_reason' => 'ตั้งค่าเสี่ยงเกินเพดาน',
        ]);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/state", [
            'wallet_address' => $this->wallet,
            'action' => 'start',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'BOT_BANNED');

        $this->assertSame('stopped', $bot->fresh()->status);
    }

    /**
     * เหตุผลต้องส่งกลับไปให้เจ้าของเห็น ไม่ใช่ปล่อยให้บอทเงียบเฉยๆ
     * ไม่งั้นเขาจะเดาว่าระบบพัง แล้วเปิดตั๋วแจ้งปัญหาที่ไม่มีอะไรให้แก้.
     */
    #[Test]
    public function เจ้าของต้องเห็นว่าบอทถูกระงับและเพราะอะไร(): void
    {
        $this->freeBot(['banned_at' => now(), 'banned_reason' => 'ตั้งค่าเสี่ยงเกินเพดาน']);

        $this->getJson('/api/v1/ai-bot/bots?wallet_address='.$this->wallet)
            ->assertStatus(200)
            ->assertJsonPath('data.0.banned', true)
            ->assertJsonPath('data.0.banned_reason', 'ตั้งค่าเสี่ยงเกินเพดาน');
    }

    #[Test]
    public function บอทที่ไม่ได้ถูกระงับยังสั่งเริ่มได้ตามปกติ(): void
    {
        $bot = $this->freeBot(['status' => 'paused']);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/state", [
            'wallet_address' => $this->wallet,
            'action' => 'start',
        ])->assertStatus(200);

        $this->assertSame('running', $bot->fresh()->status);
    }
}
