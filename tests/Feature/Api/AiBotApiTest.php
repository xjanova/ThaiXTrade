<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Services\AiBotService;
use App\Services\MarketDataService;
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
        $this->marketAnswers(true);

        // ค่าปริยายของระบบคือ "ยังไม่เปิดขาย" — เทสต์ชุดนี้ทดสอบกลไกการเช่า
        // จึงเปิดให้ ส่วนตัวด่านเองมีเทสต์แยกด้านล่าง
        config(['aibot.sales_open' => true]);
    }

    /**
     * ตลาดตอบว่ามีแท่งเทียนให้เสมอ เว้นแต่เทสต์นั้นสั่งเป็นอย่างอื่น.
     *
     * ด่านตอนสร้างบอทถามตลาดว่าคู่นี้มีข้อมูลให้ใช้จริงไหม ถ้าไม่ดักไว้ เทสต์จะ
     * ยิงออกเน็ตจริงทุกครั้ง — ช้า และผลเปลี่ยนตามว่าเครื่องที่รันต่อเน็ตได้ไหม
     *
     * ใช้ partialMock ไม่ใช่ Http::fake เพราะ stub ของ Http ซ้อนกันแล้วตัวที่
     * ลงทะเบียนก่อนชนะ — เทสต์ที่อยากได้คำตอบอื่นจะถูก stub ของ setUp บังไว้เงียบๆ
     */
    private function marketAnswers(?bool $hasCandles): void
    {
        $this->partialMock(
            MarketDataService::class,
            fn ($mock) => $mock->shouldReceive('hasKlines')->andReturn($hasCandles)
        );
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
            ->assertJsonCount(AiBotPlan::active()->count(), 'data.plans')
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

    /**
     * กระเป๋าที่เพิ่งเข้ามาครั้งแรกต้องได้แพลนฟรีตั้งแต่ถามสถานะ ไม่ใช่รอตอนกดสร้างบอท.
     *
     * เดิม status() อ่าน activeSubscription() ตรงๆ → ผู้ใช้ใหม่ได้ subscription = null,
     * max_bots = 0 และ unlocked_strategies = [] หน้าเว็บจึงปิดปุ่ม "บอทใหม่" ให้เอง
     * (quotaFull คิดจาก used_bots >= max_bots → 0 >= 0) แล้วล็อกกลยุทธ์ทุกตัว
     * ผลคือผู้ใช้ใหม่เปิดหน้า AI TRADE มาแล้วกดอะไรไม่ได้เลย และไม่มีอะไรอธิบายว่าทำไม
     */
    public function test_a_brand_new_wallet_gets_the_free_plan_from_the_very_first_status_call(): void
    {
        $response = $this->getJson('/api/v1/ai-bot/status?wallet_address='.$this->wallet)
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.subscription.plan_code', 'free')
            ->assertJsonPath('data.subscription.is_free', true);

        // โควตาต้องมากกว่าศูนย์ ไม่งั้นปุ่มสร้างบอทถูกปิดทั้งที่แพลนฟรีเปิดให้ใช้
        $this->assertGreaterThan(0, $response->json('data.quota.max_bots'));
        $this->assertNotEmpty($response->json('data.unlocked_strategies'));
    }

    /** ถามสถานะซ้ำๆ (หน้าเว็บ poll เป็นระยะ) ต้องไม่งอกแถวแพลนฟรีเพิ่มทุกครั้ง */
    public function test_polling_status_does_not_pile_up_duplicate_free_subscriptions(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->getJson('/api/v1/ai-bot/status?wallet_address='.$this->wallet)->assertStatus(200);
        }

        $this->assertSame(
            1,
            AiBotSubscription::where('wallet_address', strtolower($this->wallet))->count()
        );
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

        /*
         * ยกเลิกแพลนเสียเงินแล้ว "ตกลงมาที่แพลนฟรี" ไม่ใช่ "ไม่มีแพลน"
         *
         * is_active จึงยังเป็น true — ตัวชี้ว่าเลิกจ่ายแล้วคือ subscription.is_free
         * (เดิมเทสต์นี้คาด is_active=false ซึ่งเป็นสถานะที่อยู่ได้ไม่ถึงนาที เพราะ
         *  การถามสถานะครั้งถัดไปก็ลงแพลนฟรีให้อยู่ดี — คาดค่าที่ไม่ยั่งยืนไว้ในเทสต์
         *  ทำให้ตอนย้ายการลงแพลนฟรีมาที่ status() ดูเหมือนของพัง ทั้งที่ตั้งใจ)
         */
        $this->postJson('/api/v1/ai-bot/cancel', ['wallet_address' => $this->wallet])
            ->assertStatus(200)
            ->assertJsonPath('data.subscription.plan_code', 'free')
            ->assertJsonPath('data.subscription.is_free', true);

        $this->assertSame('stopped', $bot->fresh()->status);

        /*
         * ยกเลิกทันทีที่เช่า → ได้คืนเกือบครบ
         *
         * "เกือบ" เพราะการคืนเงินคิดตามเวลาที่ใช้จริงแล้ว (ไม่ปัดขึ้นเป็นวันเต็ม
         * ซึ่งเคยเปิดช่องให้ใช้ VIP ฟรีตลอดกาล) เวลาที่ผ่านไประหว่างรันเทสต์
         * จึงถูกหักออกเป็นเศษเสี้ยว — เทียบแบบเป๊ะไม่ได้และไม่ควรเทียบ
         */
        $this->assertEqualsWithDelta(500.0, $this->service->balanceFor($this->wallet), 1.0);
    }

    // ── บอท ─────────────────────────────────────────────────────────────────

    /**
     * ไม่เคยเช่า = ตกลงมาที่แพลนฟรีอัตโนมัติ (บอทเดินเฉพาะตอนเปิดหน้าเว็บทิ้งไว้)
     * เปลี่ยนจากพฤติกรรมเดิมที่ปฏิเสธไปเลย — ดู AiBotTierTest สำหรับเส้นแบ่งฟรี/คลาวด์.
     */
    public function test_creating_a_bot_without_a_subscription_falls_back_to_the_free_plan(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', [
            'wallet_address' => $this->wallet,
            'name' => 'Bot A',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
        ])->assertStatus(201);

        $this->assertSame(
            'free',
            $this->service->activeSubscription($this->wallet)->plan->code,
            'ต้องถูกลงแพลนฟรีให้อัตโนมัติ'
        );
    }

    /**
     * แต่กลยุทธ์ที่แพลนฟรียังไม่ปลดล็อก ต้องถูกปฏิเสธเหมือนเดิม
     * (เจตนาเดิมของเทสต์ที่ถูกแทนที่ด้านบน — สิทธิ์ต้องไม่หลุด).
     */
    public function test_creating_a_bot_with_a_locked_strategy_is_still_refused(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', [
            'wallet_address' => $this->wallet,
            'name' => 'Bot A',
            'pair' => 'BTC/USDT',
            'strategy' => 'ai_signal',
            'timeframe' => '1h',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', AiBotService::ERR_STRATEGY_LOCKED);
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

        /*
         * ค่าที่ผ่าน schema ทีละตัว แต่รวมกันแล้วขาดทุนแน่นอน ต้องถูกบีบด้วย
         *
         * เดิมเทสต์นี้ยืนยันว่า 60 ชั้นในกรอบ 0.5% "ผ่าน" ซึ่งเป็นการล็อกบั๊กไว้ —
         * ระยะชั้นละ 0.8 bps ขณะที่ต้นทุนไปกลับ 36 bps เก็บครบทุกชั้นก็ยังติดลบ
         */
        $response->assertStatus(201)
            // กรอบถูกขยายให้กว้างพอสำหรับชั้นน้อยสุด (3 ชั้น × 36 bps = 1.08%)
            ->assertJsonPath('data.params.range_pct', 1.08)
            ->assertJsonPath('data.params.grid_levels', 3)
            // เพดานทุนของ starter = $500 → ถูกตัดจาก 999999
            ->assertJsonPath('data.risk.max_position_usd', 500)
            ->assertJsonPath('data.risk.stop_loss_pct', 0.5);

        $this->assertArrayNotHasKey('bogus_key', $response->json('data.params'));

        // ระยะหนึ่งชั้นต้องไม่ต่ำกว่าต้นทุนไปกลับ ไม่ว่าผู้ใช้จะป้อนอะไรมา
        $params = $response->json('data.params');
        $stepBps = $params['range_pct'] * 100 / $params['grid_levels'];

        $this->assertGreaterThanOrEqual(
            app(AiBotService::class)->roundTripCostBps(),
            round($stepBps, 6),
        );
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

    /**
     * ยังไม่มีรางรับเงินจริง → ต้องปฏิเสธพร้อมเหตุผล ไม่ใช่ตอบว่าสำเร็จ.
     *
     * เดิมตอบ 200 พร้อม `pending_payment` ซึ่งไม่มีใครมารับเงินต่อและไม่มีหน้า
     * แอดมินยืนยัน ผู้ใช้ที่เห็นข้อความ "ส่งคำขอแล้ว" จะรอเครดิตที่ไม่มีวันมา
     */
    public function test_topup_is_refused_with_a_reason_while_payments_are_closed(): void
    {
        $this->postJson('/api/v1/ai-bot/topup', [
            'wallet_address' => $this->wallet,
            'pack' => 'pack_1500',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', AiBotService::ERR_TOPUP_CLOSED);

        $this->assertSame(0.0, $this->service->balanceFor($this->wallet));
    }

    /** เปิดระบบชำระเงินแล้วต้องได้ใบแจ้งความจำนง และยังไม่แจกเครดิตจนกว่าเงินจะเข้า */
    public function test_topup_creates_a_pending_intent_once_payments_are_open(): void
    {
        config(['aibot.credits.topup_enabled' => true]);

        $this->postJson('/api/v1/ai-bot/topup', [
            'wallet_address' => $this->wallet,
            'pack' => 'pack_1500',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.pack.credits', 1500);

        $this->assertSame(0.0, $this->service->balanceFor($this->wallet));
    }

    /** แคตตาล็อกต้องบอกทั้งเว็บและแอพว่าอะไรเปิดอยู่ ไม่ให้แต่ละจอเดาเอง */
    public function test_the_catalog_reports_which_features_are_open(): void
    {
        $features = $this->getJson('/api/v1/ai-bot/catalog')
            ->assertStatus(200)
            ->json('data.features');

        $this->assertFalse($features['credit_topup']);
        $this->assertFalse($features['live_trading']);
    }

    // ── สิ่งที่ทำให้ "จ่ายแล้วไม่ได้ของ" ──────────────────────────────────────

    /**
     * เพดานทุนของแพลนฟรีต้องไม่บีบทุนต่อไม้เหลือศูนย์.
     *
     * เดิม seeder ตั้ง max_capital_usd = 0 (ตั้งใจให้แปลว่า "ไม่จำกัด" แต่ null
     * ต่างหากที่แปลแบบนั้น) แล้ว sanitizeRisk() เทียบแค่ `!== null` ซึ่งเป็นจริง
     * เพราะ cast decimal:2 คืนสตริง "0.00" → min(x, 0) = 0 → บอทเปิดไม้ไม่ได้เลย
     */
    public function test_a_free_plan_bot_gets_a_usable_position_size(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload('Free bot'))
            ->assertStatus(201);

        $bot = AiBotConfig::where('wallet_address', $this->wallet)->firstOrFail();

        $this->assertGreaterThan(0, $bot->risk['max_position_usd']);
    }

    /**
     * อัปเกรดแพลนแล้วเพดานของบอทเดิมต้องขยายตาม ไม่ใช่ค้างค่าเดิม.
     *
     * นี่คือจุดที่ทำให้จ่ายเงินแล้วไม่ได้ของจริงๆ — sanitizeRisk() ถูกเรียกเฉพาะ
     * ตอนสร้าง/แก้บอท คลาวด์จึงเดินบอทให้ตามที่จ่าย แต่บอทยังถูกบีบด้วยเพดานของ
     * แพลนเก่า จนกว่าผู้ใช้จะเดาเองว่าต้องไปกดบันทึกฟอร์มใหม่
     */
    public function test_upgrading_the_plan_widens_the_cap_on_bots_created_earlier(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', array_merge($this->botPayload('Bot A'), [
            'risk' => ['max_position_usd' => 5000],
        ]))->assertStatus(201);

        $before = AiBotConfig::where('wallet_address', $this->wallet)->firstOrFail();
        $this->assertSame(100.0, (float) $before->risk['max_position_usd']);   // เพดานแพลนฟรี

        $this->subscribeTo('vip');   // VIP = max_capital_usd null (ไม่จำกัด)

        $after = $before->fresh();
        $this->assertSame(5000.0, (float) $after->risk['max_position_usd']);
    }

    /**
     * เช่า-ยกเลิกวันละรอบต้องไม่ได้ของฟรี.
     *
     * daysRemaining() ปัดขึ้น (ถูกแล้วสำหรับการแสดงผล) แต่ถ้าเอาไปคิดเงินคืนด้วย
     * ผู้ใช้จะเช่า 90 วัน ใช้ 23 ชม. 59 นาที แล้วยกเลิกได้คืนครบ 100%
     */
    public function test_cancelling_after_a_day_of_use_does_not_refund_everything(): void
    {
        $this->service->record($this->wallet, 'topup', 5000, 'test:topup');
        $plan = AiBotPlan::where('code', 'starter')->firstOrFail();
        $this->service->subscribe($this->wallet, $plan, 30);

        $spent = 5000 - $this->service->balanceFor($this->wallet);
        $this->assertGreaterThan(0, $spent);

        $this->travel(25)->hours();

        // การยืนยันกระเป๋าอยู่ในแคชอายุ 4 ชม. — เดินเวลาข้ามไปแล้วต้องเซ็นใหม่
        $this->verifyWallet($this->wallet);

        $this->postJson('/api/v1/ai-bot/cancel', ['wallet_address' => $this->wallet])
            ->assertStatus(200);

        // ใช้ไปเกิน 1 วันจาก 30 → ต้องได้คืนไม่ครบ
        $this->assertLessThan(5000, $this->service->balanceFor($this->wallet));
    }

    /** คืนเงินต้องไม่เกินที่จ่ายมาจริง ไม่ว่าราคาแพลนจะถูกปรับทีหลังยังไง */
    public function test_a_refund_never_exceeds_what_was_actually_paid(): void
    {
        $this->service->record($this->wallet, 'topup', 5000, 'test:topup');
        $plan = AiBotPlan::where('code', 'starter')->firstOrFail();
        $this->service->subscribe($this->wallet, $plan, 7);

        $afterRent = $this->service->balanceFor($this->wallet);

        // ราคาขึ้นสองเท่าหลังเช่าไปแล้ว
        $plan->update(['credits_per_day' => $plan->credits_per_day * 2]);

        $this->postJson('/api/v1/ai-bot/cancel', ['wallet_address' => $this->wallet])
            ->assertStatus(200);

        $this->assertLessThanOrEqual(5000.0, $this->service->balanceFor($this->wallet));
        $this->assertGreaterThan($afterRent, $this->service->balanceFor($this->wallet));
    }

    /** ยิงคำขอเช่าเดิมซ้ำ (กดรัว/เน็ตหลุดแล้ว retry) ต้องตัดเครดิตครั้งเดียว */
    public function test_repeating_the_same_rent_request_charges_only_once(): void
    {
        $this->service->record($this->wallet, 'topup', 5000, 'test:topup');

        $payload = ['wallet_address' => $this->wallet, 'plan_code' => 'starter', 'days' => 7];

        $this->postJson('/api/v1/ai-bot/subscribe', $payload)->assertStatus(200);
        $afterFirst = $this->service->balanceFor($this->wallet);

        $this->postJson('/api/v1/ai-bot/subscribe', $payload)->assertStatus(200);

        $this->assertSame($afterFirst, $this->service->balanceFor($this->wallet));
    }

    /**
     * ลดแพลนลงต้องทำได้เมื่อมูลค่าที่คืนพอจ่าย ไม่ใช่ดูแต่ยอดคงเหลือ.
     *
     * เดิมเช็คยอดก่อนคืนเงินแพลนเดิม → VIP ที่ยอดเหลือ 0 แต่มีมูลค่าค้างอยู่เยอะ
     * ลดมา Starter ไม่ได้ ทั้งที่กำลังจะได้คืนในบรรทัดถัดไป
     */
    public function test_downgrading_works_when_the_refund_covers_the_new_plan(): void
    {
        $this->service->record($this->wallet, 'topup', 1680, 'test:topup');

        $vip = AiBotPlan::where('code', 'vip')->firstOrFail();
        $this->service->subscribe($this->wallet, $vip, 7);

        $this->assertSame(0.0, $this->service->balanceFor($this->wallet));

        $this->postJson('/api/v1/ai-bot/subscribe', [
            'wallet_address' => $this->wallet,
            'plan_code' => 'starter',
            'days' => 7,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.subscription.plan_code', 'starter');
    }

    // ── บอทที่สร้างแล้วไม่มีวันทำงาน ต้องสร้างไม่ได้ตั้งแต่แรก ────────────────

    /**
     * คู่ที่ตลาดไม่มีข้อมูลให้ ต้องถูกปฏิเสธตอนสร้าง ไม่ใช่ปล่อยไปเงียบตอนบอทเดิน.
     *
     * รวมถึง TPIX/USDT ซึ่งเป็นคู่เรือธงของเว็บเอง — ผู้ใช้เลือกจาก dropdown ได้
     * แล้วบอทขึ้นว่า "ยังดึงแท่งเทียนของคู่นี้ไม่ได้" ทุก 5 นาทีตลอดอายุการเช่า
     * ซึ่งอ่านเหมือนปัญหาชั่วคราวที่รอแล้วหาย ทั้งที่ถาวร
     */
    public function test_a_pair_the_market_has_no_candles_for_is_refused_at_creation(): void
    {
        $this->marketAnswers(false);

        $this->postJson('/api/v1/ai-bot/bots', array_merge($this->botPayload('Ghost'), [
            'pair' => 'TPIX/USDT',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAIR_NO_CANDLES');

        $this->assertDatabaseCount('ai_bot_configs', 0);
    }

    /** ถามตลาดไม่สำเร็จ = ตัดสินไม่ได้ ต้องปล่อยผ่าน ไม่ใช่โทษผู้ใช้ */
    public function test_a_market_outage_does_not_block_bot_creation(): void
    {
        $this->marketAnswers(null);   // ถามตลาดไม่สำเร็จ = ตัดสินไม่ได้

        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload('Still fine'))
            ->assertStatus(201);
    }

    /**
     * กรอบเวลาต้องตรวจกับกลยุทธ์ที่เลือก ไม่ใช่รายการรวมของทั้งระบบ.
     *
     * หน้าเว็บกรองให้เองอยู่แล้ว แต่แอพมือถือกับคนที่ยิง API ตรงไม่เห็นตัวกรองนั้น
     * dca บน 1m ต้องย้อนดู 1,440 แท่งขณะที่ engine ดึงมาแค่ 150 → เงียบตลอดกาล
     */
    public function test_a_timeframe_the_strategy_does_not_support_is_refused(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', array_merge($this->botPayload('Fast DCA'), [
            'strategy' => 'dca',
            'timeframe' => '1m',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('timeframe');
    }

    /** กรอบเวลาที่กลยุทธ์นั้นรองรับต้องผ่านตามปกติ */
    public function test_a_timeframe_the_strategy_supports_is_accepted(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', array_merge($this->botPayload('Daily DCA'), [
            'strategy' => 'dca',
            'timeframe' => '1d',
        ]))->assertStatus(201);
    }

    /**
     * เป้ากำไรของสแกลป์ต้องเกินต้นทุนไปกลับเสมอ.
     *
     * ค่าที่ต่ำกว่านั้นทำให้ไม้ที่ปิดด้วยเหตุผล "ถึงเป้ากำไร" มีกำไรติดลบจริงๆ —
     * ป้ายกับตัวเลขขัดกันเอง ผู้ใช้อ่านว่าชนะแต่ยอดลด
     */
    public function test_a_scalping_target_below_the_round_trip_cost_is_raised(): void
    {
        $this->subscribeTo('pro');

        $response = $this->postJson('/api/v1/ai-bot/bots', array_merge($this->botPayload('Scalper'), [
            'strategy' => 'scalping',
            'timeframe' => '5m',
            'params' => ['target_bps' => 3],
        ]))->assertStatus(201);

        $this->assertGreaterThan(
            app(AiBotService::class)->roundTripCostBps(),
            $response->json('data.params.target_bps'),
        );
    }

    // ── ด่านเปิด-ปิดการขาย + สิทธิ์ทีมงาน ────────────────────────────────────

    /**
     * ยังไม่เปิดขาย = คนทั่วไปเช่าไม่ได้ แม้จะมีเครดิตพอ.
     *
     * เจ้าของสั่งให้ปิดไว้จนกว่าจะทดสอบกลยุทธ์ครบ — ด่านนี้ต้องอยู่ที่ API ไม่ใช่
     * แค่ปุ่มบนหน้าเว็บ เพราะแอพมือถือกับคนที่ยิง endpoint ตรงไม่เห็นปุ่มเรา
     */
    public function test_a_normal_wallet_cannot_rent_while_sales_are_closed(): void
    {
        config(['aibot.sales_open' => false]);
        $this->service->record($this->wallet, 'topup', 5000, 'test:topup');

        $this->postJson('/api/v1/ai-bot/subscribe', [
            'wallet_address' => $this->wallet,
            'plan_code' => 'starter',
            'days' => 7,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', AiBotService::ERR_SALES_CLOSED);

        // เครดิตต้องไม่ถูกแตะเลย
        $this->assertSame(5000.0, $this->service->balanceFor($this->wallet));
    }

    /** ปิดขายอยู่ก็ยังใช้แพลนฟรี/โหมดทดลองได้ตามปกติ — ไม่มีใครเสียเงิน */
    public function test_the_free_plan_still_works_while_sales_are_closed(): void
    {
        config(['aibot.sales_open' => false]);

        $this->getJson('/api/v1/ai-bot/status?wallet_address='.$this->wallet)
            ->assertStatus(200)
            ->assertJsonPath('data.subscription.plan_code', 'free');

        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload('Free bot'))
            ->assertStatus(201);
    }

    /** แคตตาล็อกต้องบอกว่ายังไม่เปิดขาย ให้ทุกหน้าจอปิดปุ่มเองได้ */
    public function test_the_catalog_reports_that_sales_are_closed(): void
    {
        config(['aibot.sales_open' => false]);

        $this->getJson('/api/v1/ai-bot/catalog')
            ->assertStatus(200)
            ->assertJsonPath('data.features.sales_open', false);
    }

    /**
     * กระเป๋าของทีมงานได้สิทธิ์เต็มโดยไม่ต้องเช่าและไม่ต้องมีเครดิต.
     *
     * ต้องได้ครบทุกอย่างที่ลูกค้า VIP ได้ เพื่อทดสอบกลยุทธ์ได้จริงก่อนเปิดขาย
     */
    public function test_an_admin_wallet_gets_full_access_without_paying(): void
    {
        config(['aibot.sales_open' => false, 'aibot.admin_wallets' => [$this->wallet]]);

        $data = $this->getJson('/api/v1/ai-bot/status?wallet_address='.$this->wallet)
            ->assertStatus(200)
            ->assertJsonPath('data.is_admin', true)
            ->assertJsonPath('data.subscription.plan_code', AiBotPlanSeeder::ADMIN_PLAN_CODE)
            ->json('data');

        // ยอดเครดิตยังเป็นศูนย์ — แปลว่าไม่ได้จ่ายอะไรเลย
        $this->assertSame(0.0, $this->service->balanceFor($this->wallet));

        // ปลดล็อกครบทุกกลยุทธ์ + โควตาบอทเยอะพอสำหรับทดสอบ
        $this->assertCount(count(config('aibot.strategies')), $data['unlocked_strategies']);
        $this->assertGreaterThanOrEqual(10, $data['quota']['max_bots']);

        // และเป็นแพลนคลาวด์ = ตัวจับเวลาของเซิร์ฟเวอร์เดินบอทให้จริง
        $this->assertSame('cloud', $data['subscription']['execution']);
    }

    /** ทีมงานสร้างบอทด้วยกลยุทธ์ระดับ VIP ได้ทันที ไม่ต้องเช่าอะไรก่อน */
    public function test_an_admin_wallet_can_create_a_vip_strategy_bot_immediately(): void
    {
        config(['aibot.sales_open' => false, 'aibot.admin_wallets' => [$this->wallet]]);

        $this->postJson('/api/v1/ai-bot/bots', array_merge($this->botPayload('Team bot'), [
            'strategy' => 'ai_signal',
            'timeframe' => '1h',
        ]))->assertStatus(201);
    }

    /** แพลนทีมงานต้องไม่โผล่ในแคตตาล็อกให้ลูกค้าเห็นหรือเลือกได้ */
    public function test_the_internal_team_plan_is_never_listed_for_sale(): void
    {
        $codes = collect($this->getJson('/api/v1/ai-bot/catalog')->json('data.plans'))
            ->pluck('code')
            ->all();

        $this->assertNotContains(AiBotPlanSeeder::ADMIN_PLAN_CODE, $codes);
    }

    /** ที่อยู่กระเป๋าใน .env มักเป็น checksum case — ต้องเทียบแบบไม่สนตัวพิมพ์ */
    public function test_admin_wallets_are_matched_regardless_of_letter_case(): void
    {
        config(['aibot.admin_wallets' => [strtoupper($this->wallet)]]);

        $this->assertTrue($this->service->isAdminWallet($this->wallet));
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
