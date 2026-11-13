<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Services\AiBot\Backtest\KlineArchive;
use App\Services\AiBot\Custom\RuleSet;
use App\Services\MarketDataService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — โหมด "วางกลยุทธ์เอง" ผ่าน API: กฎถูกตรวจและบันทึกที่เซิร์ฟเวอร์ ไม่ใช่ที่ฟอร์มของเว็บ.
 *
 * แอพมือถือกับคนที่ยิง API ตรงไม่ได้ผ่านตัวสร้างกฎของเว็บ — ด่านทั้งหมดจึงต้องอยู่ที่นี่:
 * ระดับแพลน · รายการอนุญาตของกฎ · จำนวนบล็อก · เพดานบอทต่อแพลน · และการทดสอบย้อนหลัง
 *
 * Developed by Xman Studio.
 */
class AiBotCustomStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x7777777777777777777777777777777777777777';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'aibot_risk.demo.starting_balance' => 10000.0,
            'aibot_risk.demo.fee_rate' => 0.1,
            'aibot_risk.demo.slippage_bps' => 8,
        ]);

        $this->seed(AiBotPlanSeeder::class);

        Cache::put('wallet_verified:'.self::WALLET, [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));

        $this->partialMock(
            MarketDataService::class,
            fn ($mock) => $mock->shouldReceive('hasKlines')->andReturn(true)
        );

        // คลังแท่งเทียนปลอม: นิ่ง 500 → ขึ้น 60 → ลง 60 → นิ่ง 100 (รายชั่วโมง) — ไม่ยิงตลาดจริง
        $this->app->instance(KlineArchive::class, new class() extends KlineArchive
        {
            public int $calls = 0;

            public function __construct() {}

            public function range(string $symbol, string $interval, int $fromMs, int $toMs, bool $offline = false): array
            {
                $this->calls++;

                $closes = array_fill(0, 500, 100.0);
                for ($i = 1; $i <= 60; $i++) {
                    $closes[] = 100.0 + $i * 0.5;
                }
                for ($i = 1; $i <= 60; $i++) {
                    $closes[] = 130.0 - $i * 0.5;
                }
                $closes = array_merge($closes, array_fill(0, 100, 100.0));

                $out = [];
                $start = $toMs - count($closes) * 3_600_000;
                foreach ($closes as $i => $close) {
                    $out[] = ['time' => $start + $i * 3_600_000, 'open' => $close, 'high' => $close * 1.002, 'low' => $close * 0.998, 'close' => $close, 'volume' => 1000.0];
                }

                return $out;
            }
        });

        $this->subscribe('vip');
    }

    private function subscribe(string $planCode): void
    {
        AiBotSubscription::where('wallet_address', self::WALLET)->delete();

        AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => AiBotPlan::where('code', $planCode)->firstOrFail()->id,
            'status' => 'active',
            'days' => 30,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);
    }

    private function rules(): array
    {
        return [
            'entry' => ['match' => 'all', 'blocks' => [
                ['type' => 'ema_cross', 'when' => 'crosses_above', 'fast' => 12, 'slow' => 26],
            ]],
            'exit' => ['match' => 'any', 'blocks' => [
                ['type' => 'ema_cross', 'when' => 'crosses_below', 'fast' => 12, 'slow' => 26],
            ]],
        ];
    }

    private function botPayload(array $overrides = []): array
    {
        return array_merge([
            'wallet_address' => self::WALLET,
            'name' => 'กฎของฉัน',
            'pair' => 'BTC/USDT',
            'strategy' => 'custom',
            'timeframe' => '1h',
            'params' => ['rules' => $this->rules(), 'order_size_usd' => 40, 'trailing_stop_pct' => 3],
            'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 5, 'take_profit_pct' => 12, 'max_daily_loss_usd' => 50],
        ], $overrides);
    }

    private function backtestPayload(array $overrides = []): array
    {
        return array_merge([
            'wallet_address' => self::WALLET,
            'strategy' => 'custom',
            'pair' => 'BTC/USDT',
            'timeframe' => '1h',
            'days' => 10,
            'params' => ['rules' => $this->rules(), 'macro_filter' => false],
            'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 50, 'take_profit_pct' => 200, 'max_daily_loss_usd' => 1000000],
        ], $overrides);
    }

    // ── แคตตาล็อก ────────────────────────────────────────────────────────────

    #[Test]
    public function the_catalogue_offers_the_custom_strategy_and_its_block_list(): void
    {
        $data = $this->getJson('/api/v1/ai-bot/catalog')->assertOk()->json('data');

        $custom = collect($data['strategies'])->firstWhere('code', 'custom');

        $this->assertNotNull($custom);
        $this->assertTrue($custom['available']);
        $this->assertSame('rules', collect($custom['params'])->firstWhere('key', 'rules')['type']);

        $types = array_column($data['custom_rules']['blocks'], 'type');
        foreach (['ema_cross', 'rsi', 'channel_breakout', 'atr', 'volume_ratio', 'price_change', 'trend', 'interval'] as $type) {
            $this->assertContains($type, $types);
        }

        $this->assertSame(['entry' => 6, 'exit' => 6], $data['custom_rules']['max_blocks']);
        // เพดานต่อแพลนเป็นเรื่องของเซิร์ฟเวอร์ ไม่ส่งออกไปกับแคตตาล็อกสาธารณะ
        $this->assertArrayNotHasKey('max_bots_per_tier', $data['custom_rules']);
    }

    // ── สร้าง / แก้บอท ───────────────────────────────────────────────────────

    #[Test]
    public function creating_a_custom_bot_stores_the_rules_in_canonical_form(): void
    {
        $response = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload())->assertCreated();

        $bot = AiBotConfig::firstOrFail();

        $this->assertSame('custom', $bot->strategy);
        $this->assertSame('paused', $bot->status);
        $this->assertSame(RuleSet::normalize($this->rules()), $bot->params['rules']);
        $this->assertEqualsWithDelta(40.0, $bot->params['order_size_usd'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $bot->params['trailing_stop_pct'], 1e-9);
        // คำตอบของ API คืนกฎรูปเดียวกับที่บันทึก — ตัวสร้างกฎเปิดแก้ต่อได้ทันที
        $this->assertSame($bot->params['rules'], $response->json('data.params.rules'));
        $this->assertSame('วางกลยุทธ์เอง', $response->json('data.strategy_name_th'));
    }

    #[Test]
    public function a_custom_bot_without_rules_is_rejected_instead_of_getting_an_example(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['params' => ['order_size_usd' => 40]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_RULES');

        $this->postJson('/api/v1/ai-bot/bots', array_diff_key($this->botPayload(), ['params' => true]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_RULES');

        $this->assertDatabaseCount('ai_bot_configs', 0);
    }

    #[Test]
    public function naming_an_example_template_uses_its_rules(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['timeframe' => '4h', 'template' => 'steady_dca', 'params' => [], 'risk' => []]))
            ->assertCreated();

        $bot = AiBotConfig::firstOrFail();

        $this->assertSame('interval', $bot->params['rules']['entry']['blocks'][0]['type']);
        $this->assertTrue($bot->params['add_to_position']);
        $this->assertFalse($bot->params['ai_gate'], 'ตัวอย่างของโหมดนี้เริ่มจากกฎล้วน — ผู้ใช้เปิด AI เองได้');
    }

    #[Test]
    public function invalid_rules_are_rejected_with_the_reason(): void
    {
        $cases = [
            'ชนิดที่ไม่รู้จัก' => ['entry' => ['match' => 'all', 'blocks' => [['type' => 'macd']]]],
            'ตัวเลขนอกช่วง' => ['entry' => ['match' => 'all', 'blocks' => [['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 500]]]],
            'นิพจน์อิสระ' => 'close > ema(200) && rsi(14) < 30',
            'ช่องแปลกปลอม' => ['entry' => ['match' => 'all', 'blocks' => [['type' => 'rsi', 'eval' => 'phpinfo()']]]],
            'ไม่มีเงื่อนไขเข้า' => ['entry' => ['match' => 'all', 'blocks' => []], 'exit' => ['match' => 'any', 'blocks' => [['type' => 'rsi']]]],
        ];

        foreach ($cases as $label => $rules) {
            $response = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['params' => ['rules' => $rules]]));

            $response->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RULES');
            $this->assertNotEmpty($response->json('error.message'), $label);
            $this->assertNotEmpty($response->json('error.details'), $label);
        }

        $this->assertDatabaseCount('ai_bot_configs', 0);
    }

    #[Test]
    public function an_oversized_rule_set_is_rejected(): void
    {
        $rules = $this->rules();
        $rules['entry']['blocks'] = array_fill(0, 7, ['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30]);

        $response = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['params' => ['rules' => $rules]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_RULES');

        $this->assertStringContainsString('ไม่เกิน 6 ข้อ', $response->json('error.message'));

        $rules['entry']['blocks'] = array_fill(0, 800, ['type' => 'rsi', 'junk' => str_repeat('x', 200)]);

        $response = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['params' => ['rules' => $rules]]))->assertStatus(422);

        $this->assertCount(1, $response->json('error.details'), 'ชุดใหญ่เกินต้องจบที่ข้อความเดียว');
        $this->assertDatabaseCount('ai_bot_configs', 0);
    }

    #[Test]
    public function rule_errors_and_trade_reasons_are_translated_for_english_callers(): void
    {
        $message = $this->withHeaders(['X-Locale' => 'en'])
            ->postJson('/api/v1/ai-bot/bots', $this->botPayload(['params' => ['rules' => ['entry' => ['match' => 'all', 'blocks' => [['type' => 'macd']]]]]]))
            ->assertStatus(422)
            ->json('error.message');

        $this->assertSame('The entry side, condition 1: unknown condition type macd', $message);

        $trades = $this->withHeaders(['X-Locale' => 'en'])
            ->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())
            ->assertOk()
            ->json('data.trades');

        $this->assertSame('Entry conditions met: EMA 12 crosses above EMA 26', $trades[0]['reason']);
        $this->assertSame('Exit conditions met: EMA 12 crosses below EMA 26', $trades[1]['reason']);

        // ผลที่แคชไว้เป็นภาษาของเครื่องยนต์ — ผู้ใช้ภาษาไทยที่ขอชุดเดียวกันต้องได้ภาษาไทย
        $thai = $this->withHeaders(['X-Locale' => 'th'])
            ->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())
            ->assertOk()
            ->json('data.trades.0.reason');

        $this->assertSame('เงื่อนไขเข้าเป็นจริง: EMA 12 ตัดขึ้นเหนือ EMA 26', $thai);
    }

    #[Test]
    public function a_plan_below_the_required_tier_cannot_create_or_backtest(): void
    {
        config(['aibot.strategies' => collect(config('aibot.strategies'))->map(
            fn (array $s) => $s['code'] === 'custom' ? ['tier' => 'pro'] + $s : $s
        )->all()]);

        $this->subscribe('starter');

        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'STRATEGY_LOCKED');

        $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'STRATEGY_LOCKED');

        $this->assertDatabaseCount('ai_bot_configs', 0);

        $this->subscribe('pro');
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload())->assertCreated();
    }

    #[Test]
    public function the_per_plan_limit_on_custom_bots_is_enforced_when_configured(): void
    {
        config(['aibot.custom_rules.max_bots_per_tier.vip' => 1]);

        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload())->assertCreated();

        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['name' => 'ตัวที่สอง']))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTOM_BOT_LIMIT_REACHED');

        // กลยุทธ์อื่นไม่ถูกนับในเพดานนี้ และแก้บอทเดิมยังทำได้
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['name' => 'กริด', 'strategy' => 'grid', 'timeframe' => '4h', 'params' => []]))->assertCreated();
        $this->putJson('/api/v1/ai-bot/bots/'.AiBotConfig::where('strategy', 'custom')->value('id'), $this->botPayload(['name' => 'เปลี่ยนชื่อ']))->assertOk();

        $this->assertSame(1, AiBotConfig::where('strategy', 'custom')->count());
    }

    #[Test]
    public function without_a_configured_limit_custom_bots_only_count_toward_the_plan_quota(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload())->assertCreated();
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['name' => 'ตัวที่สอง']))->assertCreated();
    }

    #[Test]
    public function editing_without_sending_rules_keeps_the_saved_rules(): void
    {
        $id = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload())->json('data.id');

        // แอพที่ยังไม่มีตัวสร้างกฎ: แก้ชื่อกับขนาดไม้ ไม่ได้ส่ง rules มา
        $this->putJson("/api/v1/ai-bot/bots/{$id}", $this->botPayload(['name' => 'ชื่อใหม่', 'params' => ['order_size_usd' => 60]]))->assertOk();

        $bot = AiBotConfig::findOrFail($id);

        $this->assertSame('ชื่อใหม่', $bot->name);
        $this->assertEqualsWithDelta(60.0, $bot->params['order_size_usd'], 1e-9);
        $this->assertSame(RuleSet::normalize($this->rules()), $bot->params['rules']);
    }

    #[Test]
    public function editing_with_invalid_rules_leaves_the_bot_untouched(): void
    {
        $id = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload())->json('data.id');

        $this->putJson("/api/v1/ai-bot/bots/{$id}", $this->botPayload(['name' => 'ชื่อใหม่', 'params' => ['rules' => ['entry' => ['match' => 'all', 'blocks' => [['type' => 'nope']]]]]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_RULES');

        $bot = AiBotConfig::findOrFail($id);

        $this->assertSame('กฎของฉัน', $bot->name);
        $this->assertSame(RuleSet::normalize($this->rules()), $bot->params['rules']);
    }

    #[Test]
    public function switching_a_fixed_strategy_bot_to_custom_needs_rules(): void
    {
        $id = $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['strategy' => 'grid', 'timeframe' => '4h', 'params' => []]))->json('data.id');

        $this->putJson("/api/v1/ai-bot/bots/{$id}", $this->botPayload(['params' => []]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_RULES');

        $this->assertSame('grid', AiBotConfig::findOrFail($id)->strategy);
    }

    #[Test]
    public function the_custom_strategy_only_accepts_its_own_timeframes(): void
    {
        $this->postJson('/api/v1/ai-bot/bots', $this->botPayload(['timeframe' => '1m']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('timeframe');
    }

    // ── ทดสอบย้อนหลัง ────────────────────────────────────────────────────────

    #[Test]
    public function backtesting_runs_the_users_rules_through_the_real_engine(): void
    {
        $data = $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())->assertOk()->json('data');

        $this->assertSame('BTC/USDT', $data['pair']);
        $this->assertSame(10, $data['requested_days']);
        $this->assertSame(1, $data['summary']['closed'], 'ตัดขึ้นหนึ่งครั้ง ตัดลงหนึ่งครั้ง = หนึ่งไม้');
        $this->assertGreaterThan(0, $data['summary']['realized_pnl']);
        $this->assertSame('insufficient', $data['verdict']['level'], 'หนึ่งไม้ยังสรุปอะไรไม่ได้ — ต้องบอกอย่างนั้น ไม่ใช่บอกว่ากำไร');
        $this->assertSame(10, $data['verdict']['min_closed']);
        $this->assertArrayHasKey('beats_hold', $data['verdict']);
        $this->assertArrayHasKey('buy_hold_pct', $data['summary']);
        $this->assertArrayHasKey('cost_bps', $data['summary']);
        $this->assertStringStartsWith('เงื่อนไขเข้าเป็นจริง: EMA 12 ตัดขึ้นเหนือ EMA 26', $data['trades'][0]['reason']);
        $this->assertArrayNotHasKey('final_equity', $data['summary'], 'ส่งเฉพาะตัวเลขที่หน้าจอใช้');
    }

    #[Test]
    public function changed_rules_give_a_different_backtest(): void
    {
        $base = $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())->assertOk()->json('data.summary');

        $inverse = $this->rules();
        $inverse['entry']['blocks'][0]['when'] = 'crosses_below';
        $inverse['exit']['blocks'][0]['when'] = 'crosses_above';

        $other = $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload(['params' => ['rules' => $inverse, 'macro_filter' => false]]))
            ->assertOk()->json('data.summary');

        $this->assertNotEquals($base['realized_pnl'] + $base['unrealized_pnl'], $other['realized_pnl'] + $other['unrealized_pnl']);
        $this->assertLessThan(0, $other['realized_pnl'] + $other['unrealized_pnl']);
    }

    #[Test]
    public function an_identical_backtest_is_served_from_cache(): void
    {
        $first = $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())->assertOk()->json('data');
        $calls = app(KlineArchive::class)->calls;

        $second = $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())->assertOk()->json('data');

        $this->assertSame($calls, app(KlineArchive::class)->calls, 'คำขอซ้ำต้องไม่โหลดแท่งเทียนและเล่นซ้ำอีกรอบ');
        $this->assertSame($first['summary'], $second['summary']);
    }

    #[Test]
    public function backtesting_rejects_invalid_rules_and_other_strategies(): void
    {
        $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload(['params' => ['rules' => ['entry' => ['match' => 'all', 'blocks' => [['type' => 'macd']]]]]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_RULES');

        $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload(['strategy' => 'momentum']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('strategy');

        $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload(['timeframe' => '1m']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('timeframe');

        $this->assertSame(0, app(KlineArchive::class)->calls, 'คำขอที่ไม่ผ่านต้องไม่ไปถึงการโหลดข้อมูล');
    }

    #[Test]
    public function the_backtest_window_is_capped_by_the_bar_budget(): void
    {
        config(['aibot.custom_rules.backtest.max_bars' => 480]);   // 1h → 20 วัน

        $data = $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload(['days' => 365]))->assertOk()->json('data');

        $this->assertSame(20, $data['max_days']);
        $this->assertSame(20, $data['requested_days']);
        $this->assertLessThanOrEqual(480, $data['summary']['bars']);
    }

    #[Test]
    public function backtesting_needs_a_verified_wallet(): void
    {
        Cache::forget('wallet_verified:'.self::WALLET);

        $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())->assertStatus(403);
    }

    #[Test]
    public function the_backtest_has_its_own_small_rate_budget(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())->assertOk();
        }

        $this->postJson('/api/v1/ai-bot/backtest', $this->backtestPayload())->assertStatus(429);

        // งบของ endpoint อื่นในกลุ่มไม่ถูกกินไปด้วย
        $this->getJson('/api/v1/ai-bot/status?wallet_address='.self::WALLET)->assertOk();
    }
}
