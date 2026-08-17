<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Services\AiBotService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — เส้นแบ่งระหว่างแพลนฟรีกับแพลนที่เสียเงิน.
 *
 * จุดขายเดียวของแพลนที่เสียเงินคือ "เซิร์ฟเวอร์เดินบอทให้แม้ปิดเบราว์เซอร์"
 * ถ้าเส้นนี้รั่วเมื่อไหร่ แพลนที่เสียเงินก็ไม่เหลือเหตุผลให้ใครจ่าย
 * ชุดทดสอบนี้จึงคุมทั้งสองทาง: ฟรีต้องไม่ได้คลาวด์ และคลาวด์ต้องไม่ถูกเดินซ้ำ
 *
 * Developed by Xman Studio.
 */
class AiBotTierTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x7777777777777777777777777777777777777777';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->seed(AiBotPlanSeeder::class);

        Cache::put('wallet_verified:'.self::WALLET, [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    private function subscribeTo(string $planCode): AiBotSubscription
    {
        return AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => AiBotPlan::where('code', $planCode)->firstOrFail()->id,
            'status' => 'active',
            'days' => 30,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);
    }

    private function makeBot(array $attributes = []): AiBotConfig
    {
        return AiBotConfig::create(array_merge([
            'wallet_address' => self::WALLET,
            'name' => 'บอททดสอบ',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'status' => 'running',
            'mode' => 'demo',
            'params' => [],
            'risk' => ['max_position_usd' => 100],
        ], $attributes));
    }

    // ───────────────────────── แพลนที่ seed มา ─────────────────────────

    #[Test]
    public function the_free_plan_runs_in_the_browser_and_costs_nothing(): void
    {
        $free = AiBotPlan::where('code', 'free')->firstOrFail();

        $this->assertSame('browser', $free->execution);
        $this->assertTrue($free->isFree());
        $this->assertFalse($free->runsInCloud());
        $this->assertSame(0, $free->credits_per_day);
    }

    #[Test]
    public function every_paid_plan_runs_in_the_cloud_and_is_priced_in_tpix(): void
    {
        $paid = AiBotPlan::where('code', '!=', 'free')->get();

        $this->assertNotEmpty($paid);

        foreach ($paid as $plan) {
            $this->assertSame('cloud', $plan->execution, "{$plan->code} ต้องรันบนคลาวด์");
            $this->assertTrue($plan->runsInCloud());
            $this->assertGreaterThan(0, (float) $plan->price_tpix_per_day, "{$plan->code} ต้องมีราคาเป็น TPIX");
        }
    }

    /**
     * แพลนฟรีปลดล็อกเฉพาะกลยุทธ์พื้นฐาน — ของขั้นสูงต้องจ่ายเงิน.
     */
    #[Test]
    public function the_free_plan_unlocks_only_the_basic_strategies(): void
    {
        $unlocked = AiBotPlan::where('code', 'free')->firstOrFail()->unlockedStrategies();

        $this->assertContains('grid', $unlocked);
        $this->assertContains('dca', $unlocked);

        $this->assertNotContains('momentum', $unlocked);
        $this->assertNotContains('ai_signal', $unlocked);
        $this->assertNotContains('arbitrage', $unlocked);
    }

    #[Test]
    public function a_paid_vip_plan_unlocks_everything_the_free_plan_does_and_more(): void
    {
        $free = AiBotPlan::where('code', 'free')->firstOrFail()->unlockedStrategies();
        $vip = AiBotPlan::where('code', 'vip')->firstOrFail()->unlockedStrategies();

        foreach ($free as $code) {
            $this->assertContains($code, $vip, "VIP ต้องได้ {$code} ที่ฟรีก็ได้");
        }

        $this->assertGreaterThan(count($free), count($vip));
    }

    // ───────────────── ลงแพลนฟรีอัตโนมัติเมื่อยังไม่เคยเช่า ─────────────────

    #[Test]
    public function a_wallet_with_no_rental_is_enrolled_into_the_free_plan_automatically(): void
    {
        $service = app(AiBotService::class);

        $this->assertNull($service->activeSubscription(self::WALLET));

        $subscription = $service->ensureFreeSubscription(self::WALLET);

        $this->assertNotNull($subscription);
        $this->assertSame('free', $subscription->plan->code);
        $this->assertSame('browser', $subscription->plan->execution);
    }

    #[Test]
    public function enrolling_twice_does_not_create_a_second_subscription(): void
    {
        $service = app(AiBotService::class);

        $service->ensureFreeSubscription(self::WALLET);
        $service->ensureFreeSubscription(self::WALLET);

        $this->assertSame(1, AiBotSubscription::where('wallet_address', self::WALLET)->count());
    }

    /**
     * ผู้ใช้ที่เช่าแพลนเสียเงินอยู่ ต้องไม่ถูกลดชั้นลงมาเป็นฟรี.
     */
    #[Test]
    public function a_paying_subscriber_is_never_downgraded_to_free(): void
    {
        $this->subscribeTo('vip');

        $subscription = app(AiBotService::class)->ensureFreeSubscription(self::WALLET);

        $this->assertSame('vip', $subscription->plan->code);
        $this->assertSame(1, AiBotSubscription::where('wallet_address', self::WALLET)->count());
    }

    // ───────────────── ⭐ เส้นแบ่งคลาวด์ กับ เบราว์เซอร์ ─────────────────

    /**
     * ⭐ ตัวจับเวลาของเซิร์ฟเวอร์ต้องมองไม่เห็นบอทของแพลนฟรี.
     *
     * ถ้าข้อนี้แดง = ผู้ใช้ฟรีได้คลาวด์ฟรี แพลนที่เสียเงินหมดจุดขายทันที
     */
    #[Test]
    public function the_scheduler_never_picks_up_a_free_plan_bot(): void
    {
        $this->subscribeTo('free');
        $bot = $this->makeBot();

        $picked = AiBotConfig::runnable()->cloudExecuted()->pluck('id');

        $this->assertNotContains($bot->id, $picked->all());
        $this->assertCount(0, $picked);
    }

    #[Test]
    public function the_scheduler_does_pick_up_a_paid_plan_bot(): void
    {
        $this->subscribeTo('pro');
        $bot = $this->makeBot();

        $picked = AiBotConfig::runnable()->cloudExecuted()->pluck('id');

        $this->assertContains($bot->id, $picked->all());
    }

    /**
     * การเช่าหมดอายุแล้ว เซิร์ฟเวอร์ต้องเลิกเดินบอทให้ทันที.
     */
    #[Test]
    public function an_expired_paid_rental_drops_out_of_the_scheduler(): void
    {
        $subscription = $this->subscribeTo('pro');
        $bot = $this->makeBot();

        $this->assertContains($bot->id, AiBotConfig::runnable()->cloudExecuted()->pluck('id')->all());

        $subscription->update(['expires_at' => now()->subMinute()]);

        $this->assertNotContains($bot->id, AiBotConfig::runnable()->cloudExecuted()->pluck('id')->all());
    }

    #[Test]
    public function a_paused_bot_is_not_picked_up_even_on_a_paid_plan(): void
    {
        $this->subscribeTo('vip');
        $bot = $this->makeBot(['status' => 'paused']);

        $this->assertNotContains($bot->id, AiBotConfig::runnable()->cloudExecuted()->pluck('id')->all());
    }

    // ───────────────── endpoint ที่เบราว์เซอร์ใช้เดินบอทฟรี ─────────────────

    /**
     * ⭐ บอทที่ซื้อคลาวด์ไว้ ห้ามให้หน้าเว็บสั่งเดินซ้ำ.
     *
     * ไม่งั้นบอทจะถูกเดินสองทาง (เซิร์ฟเวอร์ + เบราว์เซอร์) แล้วเปิดไม้ซ้ำ
     */
    #[Test]
    public function a_cloud_bot_cannot_be_ticked_from_the_browser(): void
    {
        $this->subscribeTo('vip');
        $bot = $this->makeBot();

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/tick", ['wallet_address' => self::WALLET])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CLOUD_BOT');
    }

    #[Test]
    public function a_stopped_bot_cannot_be_ticked_from_the_browser(): void
    {
        $this->subscribeTo('free');
        $bot = $this->makeBot(['status' => 'paused']);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/tick", ['wallet_address' => self::WALLET])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BOT_NOT_RUNNING');
    }

    /**
     * หน้าเว็บยิงถี่เกินไปต้องถูกบอกให้รอ ไม่ใช่เดินบอทรัวๆ.
     */
    #[Test]
    public function browser_ticks_are_throttled_to_one_per_interval(): void
    {
        config(['aibot.browser_tick_min_seconds' => 30]);

        $this->subscribeTo('free');
        $bot = $this->makeBot(['last_run_at' => now()->subSeconds(5)]);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/tick", ['wallet_address' => self::WALLET])
            ->assertOk()
            ->assertJsonPath('data.skipped', true);
    }

    #[Test]
    public function another_wallet_cannot_tick_your_bot(): void
    {
        $this->subscribeTo('free');
        $bot = $this->makeBot();

        $other = '0x8888888888888888888888888888888888888888';
        Cache::put('wallet_verified:'.$other, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHour());

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/tick", ['wallet_address' => $other])
            ->assertStatus(404);
    }

    // ───────────────────────── โหมดจริงยังไม่เปิด ─────────────────────────

    /**
     * ⭐ ตอนนี้เจ้าของสั่งให้ใช้ได้เฉพาะโหมดทดลอง.
     */
    #[Test]
    public function live_mode_is_refused_while_the_platform_is_demo_only(): void
    {
        config(['aibot.live_enabled' => false]);

        $this->subscribeTo('vip');
        $bot = $this->makeBot();

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", [
            'wallet_address' => self::WALLET,
            'mode' => 'live',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LIVE_DISABLED');

        $this->assertSame('demo', $bot->fresh()->mode);
    }

    #[Test]
    public function demo_mode_always_works_even_while_live_is_disabled(): void
    {
        config(['aibot.live_enabled' => false]);

        $this->subscribeTo('free');
        $bot = $this->makeBot(['mode' => 'demo']);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", [
            'wallet_address' => self::WALLET,
            'mode' => 'demo',
        ])->assertOk()->assertJsonPath('data.mode', 'demo');
    }

    /**
     * เปิดสวิตช์แล้วโหมดจริงต้องกลับมาใช้ได้ (สวิตช์ต้องมีผลจริง ไม่ใช่ตายตัว).
     */
    #[Test]
    public function live_mode_becomes_available_once_the_platform_enables_it(): void
    {
        config(['aibot.live_enabled' => true]);

        $this->subscribeTo('vip');
        $bot = $this->makeBot();

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", [
            'wallet_address' => self::WALLET,
            'mode' => 'live',
        ])->assertOk()->assertJsonPath('data.mode', 'live');
    }

    // ───────────────────────── ราคาเป็น TPIX ─────────────────────────

    #[Test]
    public function credit_packs_are_priced_in_tpix_only(): void
    {
        $packs = config('aibot.credits.packs');

        $this->assertSame('TPIX', config('aibot.credits.currency'));
        $this->assertNotEmpty($packs);

        foreach ($packs as $pack) {
            $this->assertArrayHasKey('price_tpix', $pack, "แพ็ก {$pack['code']} ต้องมีราคาเป็น TPIX");
            $this->assertArrayNotHasKey('price_usd', $pack, "แพ็ก {$pack['code']} ต้องไม่เหลือราคา USD");
            $this->assertGreaterThan(0, $pack['price_tpix']);
        }
    }

    #[Test]
    public function a_topup_intent_states_tpix_as_the_currency(): void
    {
        $intent = app(AiBotService::class)->createTopupIntent(self::WALLET, 'pack_500');

        $this->assertSame('TPIX', $intent['currency']);
        $this->assertSame('pending_payment', $intent['status']);
    }
}
