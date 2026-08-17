<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Models\MarketNews;
use App\Services\AiBot\PaperBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — API ของโหมดทดลองและด่านความเสี่ยง.
 *
 * โหมดทดลองคือด่านแรกที่ผู้ใช้เจอก่อนตัดสินใจจ่ายเงินเช่าบอท
 * ถ้าตรงนี้พังหรือโชว์ตัวเลขผิด เราเสียลูกค้าตั้งแต่ยังไม่ได้เริ่ม
 *
 * Developed by Xman Studio.
 */
class AiBotDemoApiTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x4444444444444444444444444444444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // จำลอง session ที่ผ่าน VerifyWalletOwnership แล้ว (ปกติมาจากการเซ็นข้อความ)
        foreach ([self::WALLET, '0x5555555555555555555555555555555555555555', '0x6666666666666666666666666666666666666666'] as $wallet) {
            Cache::put('wallet_verified:'.strtolower($wallet), [
                'ip' => '127.0.0.1',
                'verified_at' => now()->toIso8601String(),
            ], now()->addHours(4));
        }
    }

    private function query(array $extra = []): string
    {
        return http_build_query(array_merge(['wallet_address' => self::WALLET], $extra));
    }

    private function makePlan(): AiBotPlan
    {
        return AiBotPlan::create([
            'code' => 'pro', 'name' => 'Pro', 'name_th' => 'โปร',
            'credits_per_day' => 50, 'max_bots' => 5, 'max_position_usd' => 5000,
            'features' => ['a'], 'features_th' => ['ก'], 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    private function makeBot(array $attributes = []): AiBotConfig
    {
        return AiBotConfig::create(array_merge([
            'wallet_address' => self::WALLET,
            'name' => 'บอททดสอบ',
            'pair' => 'BTC/USDT',
            'strategy' => 'momentum',
            'timeframe' => '1h',
            'status' => 'running',
            'mode' => 'demo',
            'params' => [],
            'risk' => ['max_position_usd' => 1000],
        ], $attributes));
    }

    // ─────────────────────────── พอร์ตทดลอง ───────────────────────────

    /**
     * เปิดครั้งแรกต้องได้เครดิตทดลองตั้งต้นทันที ไม่ต้องกดอะไรก่อน.
     */
    #[Test]
    public function a_new_wallet_gets_a_funded_demo_account_on_first_view(): void
    {
        $response = $this->getJson('/api/v1/ai-bot/demo?'.$this->query());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account.balance', 10000)
            ->assertJsonPath('data.account.starting_balance', 10000)
            ->assertJsonPath('data.summary.trade_count', 0)
            ->assertJsonPath('data.summary.win_rate', null);

        $this->assertSame([], $response->json('data.positions'));
        $this->assertSame([], $response->json('data.trades'));
    }

    /**
     * ค่าธรรมเนียมกับ slippage ต้องเปิดเผยให้ผู้ใช้เห็น ไม่ใช่ซ่อนไว้.
     */
    #[Test]
    public function the_demo_discloses_its_fee_and_slippage_assumptions(): void
    {
        $this->getJson('/api/v1/ai-bot/demo?'.$this->query())
            ->assertOk()
            ->assertJsonPath('data.account.fee_rate', 0.1)
            ->assertJsonPath('data.account.slippage_bps', 8)
            ->assertJsonPath('data.account.resets_per_day', 3);
    }

    /**
     * ไม้ที่เทรดไปแล้วต้องโผล่ในประวัติ พร้อมเหตุผลที่บอทตัดสินใจ.
     */
    #[Test]
    public function the_trade_log_shows_positions_and_the_reasoning_behind_them(): void
    {
        $bot = $this->makeBot();
        app(PaperBroker::class)->buy($bot, 100.0, 1000.0, [
            'reason' => 'EMA ตัดขึ้นเหนือ EMA ช้า',
            'risk_level' => 'calm',
        ]);

        $response = $this->getJson('/api/v1/ai-bot/demo?'.$this->query())->assertOk();

        $this->assertCount(1, $response->json('data.positions'));
        $this->assertCount(1, $response->json('data.trades'));
        $this->assertSame('EMA ตัดขึ้นเหนือ EMA ช้า', $response->json('data.trades.0.reason'));
        $this->assertSame('buy', $response->json('data.trades.0.side'));
        $this->assertSame(1, $response->json('data.summary.trade_count'));
        $this->assertGreaterThan(0, $response->json('data.trades.0.fee'));
    }

    /**
     * สรุปผลต้องนับเฉพาะไม้ที่ปิดแล้ว — กำไรลอยยังไม่ใช่เงิน.
     */
    #[Test]
    public function the_summary_counts_only_closed_trades(): void
    {
        $bot = $this->makeBot();
        $broker = app(PaperBroker::class);

        $broker->buy($bot, 100.0, 1000.0, ['reason' => 'เข้าไม้']);

        // ยังไม่ปิด → ยังไม่มีอะไรให้สรุป
        $open = $this->getJson('/api/v1/ai-bot/demo?'.$this->query())->assertOk();
        $this->assertSame(0, $open->json('data.summary.closed_count'));
        $this->assertNull($open->json('data.summary.win_rate'));

        $broker->sell($bot, 130.0, ['reason' => 'ทำกำไร']);

        $closed = $this->getJson('/api/v1/ai-bot/demo?'.$this->query())->assertOk();
        $this->assertSame(1, $closed->json('data.summary.closed_count'));
        $this->assertSame(1, $closed->json('data.summary.wins'));
        $this->assertSame(0, $closed->json('data.summary.losses'));
        // json_encode เรนเดอร์ float ที่ลงตัวเป็น int — เทียบเป็น 100 ไม่ใช่ 100.0
        $this->assertSame(100, $closed->json('data.summary.win_rate'));
        $this->assertGreaterThan(0, $closed->json('data.summary.realized_pnl'));
    }

    /**
     * พอร์ตทดลองของคนอื่นต้องไม่รั่วมาให้เห็น.
     */
    #[Test]
    public function one_wallet_cannot_see_another_wallets_demo_portfolio(): void
    {
        $other = '0x5555555555555555555555555555555555555555';
        $bot = $this->makeBot(['wallet_address' => $other]);
        app(PaperBroker::class)->buy($bot, 100.0, 1000.0, ['reason' => 'ไม้ของคนอื่น']);

        $response = $this->getJson('/api/v1/ai-bot/demo?'.$this->query())->assertOk();

        $this->assertSame([], $response->json('data.trades'));
        $this->assertSame([], $response->json('data.positions'));
    }

    /**
     * ไม่ส่ง wallet มาต้องถูกปฏิเสธ ไม่ใช่ตกไปใช้พอร์ตกลางร่วมกัน.
     */
    #[Test]
    public function the_demo_endpoint_requires_a_wallet(): void
    {
        $this->getJson('/api/v1/ai-bot/demo')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_WALLET');
    }

    // ─────────────────────────── ล้างพอร์ต ───────────────────────────

    #[Test]
    public function resetting_the_demo_restores_the_starting_balance(): void
    {
        $bot = $this->makeBot();
        app(PaperBroker::class)->buy($bot, 100.0, 4000.0, ['reason' => 'เข้าไม้']);

        $response = $this->postJson('/api/v1/ai-bot/demo/reset', ['wallet_address' => self::WALLET]);

        $response->assertOk()
            ->assertJsonPath('data.account.balance', 10000)
            ->assertJsonPath('data.summary.trade_count', 0);

        $this->assertSame([], $response->json('data.positions'));
    }

    #[Test]
    public function resetting_too_often_is_blocked_with_a_clear_message(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/ai-bot/demo/reset', ['wallet_address' => self::WALLET])->assertOk();
        }

        $this->postJson('/api/v1/ai-bot/demo/reset', ['wallet_address' => self::WALLET])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RESET_LIMIT');
    }

    // ─────────────────────────── สลับโหมด ───────────────────────────

    /**
     * ⭐ ยังไม่ได้เช่า = ห้ามเปิดโหมดจริง.
     */
    #[Test]
    public function switching_to_live_mode_requires_an_active_rental(): void
    {
        $bot = $this->makeBot();

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", [
            'wallet_address' => self::WALLET,
            'mode' => 'live',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NO_SUBSCRIPTION');

        $this->assertSame('demo', $bot->fresh()->mode);
    }

    /**
     * เช่าแล้วต้องสลับเป็นโหมดจริงได้.
     */
    #[Test]
    public function a_subscriber_can_switch_a_bot_to_live_mode(): void
    {
        $plan = $this->makePlan();
        AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => $plan->id,
            'status' => 'active',
            'days' => 30,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);

        $bot = $this->makeBot();

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", [
            'wallet_address' => self::WALLET,
            'mode' => 'live',
        ])
            ->assertOk()
            ->assertJsonPath('data.mode', 'live');

        $this->assertSame('live', $bot->fresh()->mode);
    }

    /**
     * กลับมาโหมดทดลองต้องทำได้เสมอ ไม่ต้องมีการเช่า.
     */
    #[Test]
    public function switching_back_to_demo_never_requires_a_rental(): void
    {
        $bot = $this->makeBot(['mode' => 'live']);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", [
            'wallet_address' => self::WALLET,
            'mode' => 'demo',
        ])->assertOk()->assertJsonPath('data.mode', 'demo');
    }

    #[Test]
    public function an_unknown_mode_is_rejected(): void
    {
        $bot = $this->makeBot();

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", [
            'wallet_address' => self::WALLET,
            'mode' => 'ทำอะไรก็ได้',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_wallet_cannot_change_the_mode_of_someone_elses_bot(): void
    {
        $bot = $this->makeBot(['wallet_address' => '0x6666666666666666666666666666666666666666']);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", [
            'wallet_address' => self::WALLET,
            'mode' => 'demo',
        ])->assertStatus(404);
    }

    // ─────────────────────────── ด่านความเสี่ยง ───────────────────────────

    /**
     * ผู้ใช้ต้องเรียกดูได้ว่าตอนนี้ตลาดเสี่ยงแค่ไหน และเพราะข่าวอะไร.
     */
    #[Test]
    public function the_risk_endpoint_reports_the_level_and_the_headlines_behind_it(): void
    {
        MarketNews::create([
            'source' => 'coindesk',
            'title' => 'Major exchange hack drains user funds',
            'url_hash' => hash('sha256', 'risk-endpoint-news'),
            'url' => 'https://example.test/risk',
            'published_at' => now()->subMinutes(2),
            'panic_score' => 1.0,
            'sentiment' => -1.0,
            'symbols' => [],
            'matched_terms' => ['hack'],
        ]);

        $response = $this->getJson('/api/v1/ai-bot/risk?pair=BTC/USDT')->assertOk();

        $this->assertSame('panic', $response->json('data.level'));
        $this->assertTrue($response->json('data.force_exit'));
        $this->assertSame(0, $response->json('data.size_multiplier'));
        $this->assertSame(
            'Major exchange hack drains user funds',
            $response->json('data.news.headlines.0.title')
        );
    }

    #[Test]
    public function the_risk_endpoint_rejects_a_malformed_pair(): void
    {
        $this->getJson('/api/v1/ai-bot/risk?pair='.urlencode("BTC'; DROP TABLE users--"))
            ->assertStatus(422);
    }
}
