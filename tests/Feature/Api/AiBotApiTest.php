<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Services\AiBotService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TPIX TRADE — AI Trade (Cloud Bot) API tests.
 *
 * ครอบคลุมกลไกที่เสียหายแล้วเห็นยาก: เครดิตติดลบไม่ได้, โควตาบอท,
 * กลยุทธ์ที่ล็อกตาม tier, และการอ่านบอทของ wallet อื่นไม่ได้
 *
 * Developed by Xman Studio.
 */
class AiBotApiTest extends TestCase
{
    use RefreshDatabase;

    private string $wallet = '0x1111111111111111111111111111111111111111';

    private AiBotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(AiBotPlanSeeder::class);
        $this->service = app(AiBotService::class);

        $this->verifyWallet($this->wallet);
    }

    /** จำลอง session ที่ผ่าน VerifyWalletOwnership แล้ว (ปกติมาจากการเซ็นข้อความ) */
    private function verifyWallet(string $wallet): void
    {
        Cache::put('wallet_verified:'.strtolower($wallet), [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    // ── แคตตาล็อก ───────────────────────────────────────────────────────────

    public function test_catalog_is_public_and_lists_plans_and_strategies(): void
    {
        $this->getJson('/api/v1/ai-bot/catalog')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.plans')
            ->assertJsonCount(count(config('aibot.strategies')), 'data.strategies');
    }

    public function test_vip_plan_unlocks_every_strategy_and_starter_does_not(): void
    {
        $starter = AiBotPlan::where('code', 'starter')->firstOrFail();
        $vip = AiBotPlan::where('code', 'vip')->firstOrFail();

        $this->assertNotContains('ai_signal', $starter->unlockedStrategies());
        $this->assertContains('grid', $starter->unlockedStrategies());
        $this->assertCount(count(config('aibot.strategies')), $vip->unlockedStrategies());
    }

    // ── เครดิต + การเช่า ────────────────────────────────────────────────────

    public function test_status_requires_a_verified_wallet(): void
    {
        $this->getJson('/api/v1/ai-bot/status?wallet_address=0x2222222222222222222222222222222222222222')
            ->assertStatus(403);
    }

    public function test_subscribe_without_credits_is_rejected_with_a_top_up_hint(): void
    {
        $this->postJson('/api/v1/ai-bot/subscribe', [
            'wallet_address' => $this->wallet,
            'plan_code' => 'starter',
            'days' => 7,
        ])
            ->assertStatus(402)
            ->assertJsonPath('error.code', AiBotService::ERR_INSUFFICIENT_CREDITS);

        $this->assertSame(0.0, $this->service->balanceFor($this->wallet));
    }

    public function test_welcome_bonus_is_granted_once_only(): void
    {
        $first = $this->postJson('/api/v1/ai-bot/welcome', ['wallet_address' => $this->wallet]);
        $second = $this->postJson('/api/v1/ai-bot/welcome', ['wallet_address' => $this->wallet]);

        $bonus = config('aibot.credits.welcome_bonus');

        $first->assertStatus(200)->assertJsonPath('data.credits', $bonus);
        $second->assertStatus(200)->assertJsonPath('data.credits', $bonus);
        $this->assertDatabaseCount('ai_bot_credits', 1);
    }

    public function test_subscribe_charges_credits_and_activates_the_plan(): void
    {
        $this->service->record($this->wallet, 'topup', 500, 'test:topup');

        $this->postJson('/api/v1/ai-bot/subscribe', [
            'wallet_address' => $this->wallet,
            'plan_code' => 'starter',
            'days' => 7,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.subscription.plan_code', 'starter')
            // 30 เครดิต/วัน × 7 วัน = 210 → เหลือ 290
            ->assertJsonPath('data.credits', 290);
    }

    public function test_cancelling_refunds_the_unused_days_and_stops_bots(): void
    {
        $this->service->record($this->wallet, 'topup', 500, 'test:topup');
        $plan = AiBotPlan::where('code', 'starter')->firstOrFail();
        $this->service->subscribe($this->wallet, $plan, 7);

        $bot = AiBotConfig::create([
            'wallet_address' => $this->wallet,
            'name' => 'Bot A',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'status' => 'running',
        ]);

        $this->postJson('/api/v1/ai-bot/cancel', ['wallet_address' => $this->wallet])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertSame('stopped', $bot->fresh()->status);
        // คืน 7 วันที่ยังไม่ใช้ → กลับไปเท่ายอดเดิม
        $this->assertSame(500.0, $this->service->balanceFor($this->wallet));
    }

    // ── บอท ─────────────────────────────────────────────────────────────────

    public function test_creating_a_bot_without_a_subscription_is_refused(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', [
            'wallet_address' => $this->wallet,
            'name' => 'Bot A',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', AiBotService::ERR_NO_SUBSCRIPTION);
    }

    public function test_starter_plan_cannot_use_a_vip_strategy(): void
    {
        $this->subscribeTo('starter');

        $this->postJson('/api/v1/ai-bot/bots', [
            'wallet_address' => $this->wallet,
            'name' => 'AI Bot',
            'pair' => 'BTC/USDT',
            'strategy' => 'ai_signal',
            'timeframe' => '1h',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', AiBotService::ERR_STRATEGY_LOCKED);
    }

    public function test_bot_quota_is_enforced_by_the_plan(): void
    {
        $this->subscribeTo('starter'); // max_bots = 1

        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload('Bot A'))->assertStatus(201);

        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload('Bot B'))
            ->assertStatus(403)
            ->assertJsonPath('error.code', AiBotService::ERR_BOT_LIMIT);
    }

    public function test_params_are_clamped_to_the_strategy_schema(): void
    {
        $this->subscribeTo('starter');

        $response = $this->postJson('/api/v1/ai-bot/bots', array_merge($this->botPayload('Bot A'), [
            'params' => ['grid_levels' => 9999, 'range_pct' => -5, 'bogus_key' => 'x'],
            'risk' => ['max_position_usd' => 999999, 'stop_loss_pct' => 0.001],
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.params.grid_levels', 60) // max ของ schema
            ->assertJsonPath('data.params.range_pct', 0.5)    // min ของ schema
            // เพดานทุนของ starter = $500 → ถูกตัดจาก 999999
            ->assertJsonPath('data.risk.max_position_usd', 500)
            ->assertJsonPath('data.risk.stop_loss_pct', 0.5);

        $this->assertArrayNotHasKey('bogus_key', $response->json('data.params'));
    }

    public function test_a_wallet_cannot_touch_another_wallets_bot(): void
    {
        $this->subscribeTo('starter');
        $botId = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload('Bot A'))->json('data.id');

        $other = '0x3333333333333333333333333333333333333333';
        $this->verifyWallet($other);

        $this->postJson("/api/v1/ai-bot/bots/{$botId}/state", [
            'wallet_address' => $other,
            'action' => 'stop',
        ])->assertStatus(404);

        $this->deleteJson("/api/v1/ai-bot/bots/{$botId}", ['wallet_address' => $other])
            ->assertStatus(404);

        $this->assertDatabaseHas('ai_bot_configs', ['id' => $botId, 'status' => 'paused']);
    }

    public function test_bot_can_be_started_and_stopped(): void
    {
        $this->subscribeTo('starter');
        $botId = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload('Bot A'))->json('data.id');

        $this->postJson("/api/v1/ai-bot/bots/{$botId}/state", [
            'wallet_address' => $this->wallet,
            'action' => 'start',
        ])->assertStatus(200)->assertJsonPath('data.status', 'running');

        $this->postJson("/api/v1/ai-bot/bots/{$botId}/state", [
            'wallet_address' => $this->wallet,
            'action' => 'stop',
        ])->assertStatus(200)->assertJsonPath('data.status', 'stopped');
    }

    public function test_invalid_pair_format_is_rejected(): void
    {
        $this->subscribeTo('starter');

        $this->postJson('/api/v1/ai-bot/bots', array_merge($this->botPayload('Bot A'), [
            'pair' => '../../etc/passwd',
        ]))->assertStatus(422);
    }

    public function test_topup_creates_a_pending_intent_without_granting_credits(): void
    {
        $this->postJson('/api/v1/ai-bot/topup', [
            'wallet_address' => $this->wallet,
            'pack' => 'pack_1500',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.pack.credits', 1500);

        $this->assertSame(0.0, $this->service->balanceFor($this->wallet));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function subscribeTo(string $planCode): void
    {
        $this->service->record($this->wallet, 'topup', 5000, 'test:topup:'.$planCode);
        $this->service->subscribe($this->wallet, AiBotPlan::where('code', $planCode)->firstOrFail(), 7);
    }

    private function botPayload(string $name): array
    {
        return [
            'wallet_address' => $this->wallet,
            'name' => $name,
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
        ];
    }
}
