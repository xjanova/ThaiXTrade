<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Models\AiBotTrade;
use App\Services\AiBot\WorkerHealth;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ไม้ของบอทสำหรับกราฟ + สถานะออนไลน์จริงของบอท.
 *
 * เจ้าของสั่ง: "การเข้าไม้ ออกไม้ ต้องแสดงชัดเจนในเส้นกราฟ" และ "บอทต้องกลับมา
 * ออนไลน์ได้เองหากเซิร์ฟดับ" — API ต้องส่งของสองอย่างนี้ให้ทั้งเว็บและแอพ
 *
 * Developed by Xman Studio.
 */
class AiBotTradesApiTest extends TestCase
{
    use RefreshDatabase;

    private string $wallet = '0x3333333333333333333333333333333333333333';

    private string $other = '0x4444444444444444444444444444444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(AiBotPlanSeeder::class);
        config(['aibot.health.stale_minutes' => 5]);

        foreach ([$this->wallet, $this->other] as $wallet) {
            Cache::put('wallet_verified:'.$wallet, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHours(4));
        }
    }

    private function bot(string $wallet, array $overrides = []): AiBotConfig
    {
        return AiBotConfig::create(array_merge([
            'wallet_address' => $wallet, 'name' => 'b', 'pair' => 'BTC/USDT', 'strategy' => 'momentum',
            'timeframe' => '1h', 'status' => 'running', 'mode' => 'demo', 'last_run_at' => now(),
        ], $overrides));
    }

    private function trade(AiBotConfig $bot, string $side, float $price, array $overrides = []): AiBotTrade
    {
        return AiBotTrade::create(array_merge([
            'ai_bot_config_id' => $bot->id, 'wallet_address' => $bot->wallet_address, 'pair' => $bot->pair,
            'mode' => $bot->mode, 'side' => $side, 'price' => $price, 'quantity' => 0.001,
            'gross_value' => $price * 0.001, 'fee' => 0.01, 'slippage_cost' => 0.0,
            'realized_pnl' => $side === 'sell' ? 0.5 : null, 'strategy' => $bot->strategy, 'reason' => 'r',
        ], $overrides));
    }

    #[Test]
    public function ไม้ส่งกลับเรียงเก่าไปใหม่_กรองตามคู่_และเห็นเฉพาะของกระเป๋าตัวเอง(): void
    {
        $mine = $this->bot($this->wallet);
        $eth = $this->bot($this->wallet, ['pair' => 'ETH/USDT']);
        $theirs = $this->bot($this->other);

        $this->trade($mine, 'buy', 100);
        $this->trade($mine, 'sell', 110);
        $this->trade($eth, 'buy', 3000);
        $this->trade($theirs, 'buy', 999);

        $response = $this->getJson('/api/v1/ai-bot/trades?wallet_address='.$this->wallet.'&pair=btc-usdt')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $rows = $response->json('data');
        $this->assertSame(['buy', 'sell'], array_column($rows, 'side'), 'ต้องเรียงเก่า→ใหม่ให้ปลั๊กอิน marker');
        $this->assertSame('BTC/USDT', $rows[0]['pair']);
        $this->assertSame($mine->id, $rows[0]['bot_id']);
        $this->assertSame('b', $rows[0]['bot_name']);
        $this->assertSame(0.5, $rows[1]['realized_pnl']);
        $this->assertNull($rows[0]['realized_pnl']);

        // ไม่ระบุคู่ = ทุกคู่ของกระเป๋านี้ แต่ยังไม่เห็นของคนอื่น
        $this->getJson('/api/v1/ai-bot/trades?wallet_address='.$this->wallet)
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function กรองตามโหมดและจำกัดจำนวนได้(): void
    {
        $bot = $this->bot($this->wallet);
        $live = $this->bot($this->wallet, ['mode' => 'live']);

        $this->trade($bot, 'buy', 1);
        $this->trade($bot, 'buy', 2);
        $this->trade($live, 'buy', 3);

        $this->getJson('/api/v1/ai-bot/trades?wallet_address='.$this->wallet.'&mode=live')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mode', 'live');

        // limit ตัดจากฝั่งใหม่สุด แล้วค่อยพลิกกลับ — ไม้ล่าสุดต้องไม่หาย
        $rows = $this->getJson('/api/v1/ai-bot/trades?wallet_address='.$this->wallet.'&limit=2')->json('data');
        $this->assertSame([2.0, 3.0], array_map(fn ($r) => (float) $r['price'], $rows));

        $this->getJson('/api/v1/ai-bot/trades?wallet_address='.$this->wallet.'&limit=9999')
            ->assertStatus(422);
    }

    #[Test]
    public function ไม่ยืนยันกระเป๋าดูไม้ไม่ได้(): void
    {
        $this->getJson('/api/v1/ai-bot/trades?wallet_address=0x5555555555555555555555555555555555555555')
            ->assertStatus(403);
    }

    #[Test]
    public function รายการบอทบอกออนไลน์จริงไม่ใช่แค่สถานะ_running(): void
    {
        $plan = AiBotPlan::where('tier', 'vip')->firstOrFail();
        AiBotSubscription::create([
            'wallet_address' => $this->wallet, 'ai_bot_plan_id' => $plan->id,
            'status' => 'active', 'started_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        $bot = $this->bot($this->wallet);

        // วอร์กเกอร์ยังไม่เคยเต้น → running แต่ออฟไลน์
        $rows = $this->getJson('/api/v1/ai-bot/bots?wallet_address='.$this->wallet)->json('data');
        $this->assertSame('running', $rows[0]['status']);
        $this->assertFalse($rows[0]['online']);
        $this->assertSame('worker_silent', $rows[0]['offline_reason']);

        app(WorkerHealth::class)->beat('momentum');

        $rows = $this->getJson('/api/v1/ai-bot/bots?wallet_address='.$this->wallet)->json('data');
        $this->assertTrue($rows[0]['online']);
        $this->assertNull($rows[0]['offline_reason']);
        $this->assertNotNull($rows[0]['worker_last_beat_at']);

        // สรุปสุขภาพของวอร์กเกอร์แนบมากับ /status ด้วย (null จนกว่ายามจะตรวจรอบแรก)
        $this->getJson('/api/v1/ai-bot/status?wallet_address='.$this->wallet)
            ->assertStatus(200)
            ->assertJsonPath('data.worker', null);

        $this->artisan('aibot:health');

        $this->getJson('/api/v1/ai-bot/status?wallet_address='.$this->wallet)
            ->assertJsonPath('data.worker.alive', true);
    }
}
