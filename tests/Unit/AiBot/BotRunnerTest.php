<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotDecision;
use App\Models\AiBotPlan;
use App\Models\AiBotPosition;
use App\Models\AiBotSubscription;
use App\Models\AiBotTrade;
use App\Models\MarketNews;
use App\Services\AiBot\BotRunner;
use App\Services\AiBot\NewsFeedService;
use App\Services\AiBot\PaperBroker;
use App\Services\MarketDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ลำดับการตัดสินใจของบอทหนึ่งรอบ.
 *
 * ลำดับนี้คือทั้งหมดที่กันไม่ให้ผู้ใช้เสียเงินโดยไม่จำเป็น สลับเมื่อไหร่พังทันที:
 *   เช็คการเช่า → ด่านความเสี่ยง → กรอบของผู้ใช้ → กลยุทธ์ → ลงมือ
 *
 * ที่ต้องมีเทสต์ชุดนี้เพราะบั๊กลำดับไม่ทำให้อะไรพัง มันแค่ทำให้บอท
 * "ซื้อในวันที่ควรขาย" อย่างเงียบๆ แล้วผู้ใช้มารู้ตอนเงินหายไปแล้ว
 *
 * Developed by Xman Studio.
 */
class BotRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x3333333333333333333333333333333333333333';

    private BotRunner $runner;

    /** @var list<array<string, float|int>> แท่งเทียนที่ MarketDataService ปลอมจะคืนกลับมา */
    private array $candles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'aibot_risk.demo.starting_balance' => 10000.0,
            'aibot_risk.demo.fee_rate' => 0.1,
            'aibot_risk.demo.slippage_bps' => 8,
            'aibot_risk.lookback_minutes' => 180,
        ]);

        // ตลาดปลอมที่คืนแท่งเทียนตามที่แต่ละเทสต์กำหนด — ไม่ยิงเน็ตจริง
        $this->app->bind(MarketDataService::class, function () {
            return new class($this) extends MarketDataService
            {
                public function __construct(private $test) {}

                public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
                {
                    return $this->test->candlesForFake();
                }
            };
        });

        $this->runner = app(BotRunner::class);
    }

    /** ให้ MarketDataService ปลอมเรียกใช้ */
    public function candlesForFake(): array
    {
        return $this->candles;
    }

    // ─────────────────────────── ตัวช่วยสร้างฉาก ───────────────────────────

    /** ราคานิ่ง ไม่มีสัญญาณอะไร */
    private function flatCandles(int $count = 80, float $price = 100.0): array
    {
        $candles = [];

        // +1 สำหรับแท่งที่ "กำลังวิ่ง" ซึ่ง BotRunner จะตัดทิ้งก่อนส่งให้กลยุทธ์
        $count++;

        for ($i = 0; $i < $count; $i++) {
            $close = $price + (($i % 2 === 0) ? 0.15 : -0.15);
            $candles[] = [
                'time' => 1_700_000_000_000 + $i * 3_600_000,
                'open' => $price, 'high' => $close + 0.25, 'low' => $close - 0.25,
                'close' => $close, 'volume' => 1000.0,
            ];
        }

        return $candles;
    }

    /**
     * ราคานิ่งแล้วดีดขึ้นที่แท่งสุดท้าย — จุดที่ EMA ตัดขึ้นพอดี.
     *
     * ⚠️ ต้องขึ้น "แท่งเดียว" เท่านั้น ไม่ใช่ขึ้นยาวๆ — วัดแล้วว่า momentum
     *    ให้สัญญาณซื้อเฉพาะแท่งแรกของการขึ้น (ขึ้น 2 แท่งขึ้นไป = hold)
     *    เพราะกลยุทธ์เช็ค "แท่งก่อนหน้าอยู่คนละฝั่ง" กันไม่ให้ไล่ราคาทุกแท่ง
     */
    private function risingCandles(int $flat = 60): array
    {
        $closes = array_fill(0, $flat, 100.0);
        $closes[] = 102.0;

        /*
         * แท่งท้ายสุดคือแท่งที่ "ยังปิดไม่จบ" ซึ่ง BotRunner ตัดทิ้งเสมอ
         *
         * ของจริงตลาดคืนแท่งที่กำลังวิ่งมาด้วยทุกครั้ง ฟิกซ์เจอร์จึงต้องมีให้เหมือนกัน
         * ไม่งั้นเทสต์จะทดสอบสภาพที่ไม่มีทางเกิดขึ้นจริง — และตอนที่แก้ให้ตัดแท่งสด
         * เทสต์จะแดงทั้งที่โค้ดถูกขึ้น (เกิดขึ้นแล้วรอบนี้)
         */
        $closes[] = 102.0;

        $candles = [];

        foreach ($closes as $i => $close) {
            $candles[] = [
                'time' => 1_700_000_000_000 + $i * 3_600_000,
                'open' => $close * 0.999, 'high' => $close * 1.002, 'low' => $close * 0.998,
                'close' => $close, 'volume' => 1000.0,
            ];
        }

        return $candles;
    }

    private function makeBot(array $attributes = []): AiBotConfig
    {
        $plan = AiBotPlan::create([
            'code' => 'pro', 'name' => 'Pro', 'name_th' => 'โปร',
            'credits_per_day' => 50, 'max_bots' => 5, 'max_position_usd' => 5000,
            'features' => ['a'], 'features_th' => ['ก'], 'sort_order' => 1, 'is_active' => true,
        ]);

        AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => $plan->id,
            'status' => 'active',
            'days' => 30,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);

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

    private function giveBotAPosition(AiBotConfig $bot, float $entry = 100.0, float $qty = 10.0): AiBotPosition
    {
        return AiBotPosition::create([
            'ai_bot_config_id' => $bot->id,
            'pair' => $bot->pair,
            'mode' => 'demo',
            'quantity' => $qty,
            'entry_price' => $entry,
            'cost_basis' => $entry * $qty,
            'entry_count' => 1,
            'opened_at' => now()->subHours(5),
        ]);
    }

    /**
     * ข่าวร้ายแรงของจริง — ให้คะแนนด้วยตัวให้คะแนนตัวจริง ไม่ใช่ยัดเลขเอง.
     *
     * ถ้ายัดเลขเองแล้ววันหนึ่งเกณฑ์ในโปรดักชันเปลี่ยน เทสต์จะยังเขียวทั้งที่
     * ของจริงพังไปแล้ว — ผูกกับตัวให้คะแนนจริงทำให้เทสต์แดงตอนที่ควรแดง
     */
    private function newsFrom(string $headline, int $minutesAgo = 3): void
    {
        $scored = app(NewsFeedService::class)->score($headline);

        MarketNews::create([
            'source' => 'coindesk',
            'title' => $headline,
            'url_hash' => hash('sha256', $headline),
            'url' => 'https://example.test/'.md5($headline),
            'published_at' => now()->subMinutes($minutesAgo),
            'panic_score' => $scored['panic'],
            'sentiment' => $scored['sentiment'],
            'symbols' => $scored['symbols'],
            'matched_terms' => $scored['terms'],
        ]);
    }

    /** ข่าวระดับ "เงินหายแล้ว" — แรงพอจะสั่งเทออกได้เอง */
    private function panicNews(): void
    {
        $this->newsFrom('Major exchange hack drains user funds');
    }

    // ─────────────────────────── 1) การเช่า ───────────────────────────

    /**
     * การเช่าหมดอายุ = หยุดทันที ไม่ใช่เทรดต่อฟรีๆ.
     */
    #[Test]
    public function an_expired_rental_stops_the_bot(): void
    {
        $bot = $this->makeBot();
        AiBotSubscription::query()->update(['expires_at' => now()->subDay()]);
        $this->candles = $this->risingCandles();

        $result = $this->runner->tick($bot->fresh());

        $this->assertSame('stopped', $result['action']);
        $this->assertSame('paused', $bot->fresh()->status);
        $this->assertSame(0, AiBotTrade::count(), 'หมดอายุแล้วต้องไม่มีไม้ใหม่');
    }

    /**
     * ดึงแท่งเทียนไม่ได้ = ถือไว้เฉยๆ ไม่ใช่เดาแล้วยิง.
     */
    #[Test]
    public function it_holds_when_market_data_is_unavailable(): void
    {
        $bot = $this->makeBot();
        $this->candles = [];

        $result = $this->runner->tick($bot);

        $this->assertSame('hold', $result['action']);
        $this->assertSame(0, AiBotTrade::count());
    }

    // ─────────────────────── 2) ด่านความเสี่ยงมาก่อนกลยุทธ์ ───────────────────────

    /**
     * ⭐ ข่าวร้ายแรงต้องสั่งเทออก แม้กลยุทธ์จะยังไม่บอกให้ขาย.
     *
     * นี่คือสิ่งที่ผู้ใช้ขอโดยตรง — "มีข่าวที่จะทำให้แพนิค แล้วให้บอทประเมินว่า save
     * หรือเทได้" ราคายังไต่ขึ้นอยู่ แต่ข่าวบอกว่าอันตราย บอทต้องออกก่อน
     */
    #[Test]
    public function severe_news_forces_an_exit_even_while_the_price_is_still_rising(): void
    {
        $bot = $this->makeBot();
        $this->giveBotAPosition($bot);
        $this->candles = $this->risingCandles();
        $this->panicNews();

        $result = $this->runner->tick($bot);

        $this->assertSame('sell', $result['action']);
        $this->assertSame('panic', $result['risk']);
        $this->assertStringContainsString('ตื่นตระหนก', $result['reason']);

        $trade = AiBotTrade::first();
        $this->assertNotNull($trade, 'ต้องมีไม้ขายจริงเกิดขึ้น');
        $this->assertSame('sell', $trade->side);
        $this->assertSame(0, AiBotPosition::count(), 'ต้องไม่เหลือของค้างหลังเทออก');
    }

    /**
     * ตอนความเสี่ยงสูงสุด ห้ามเข้าไม้ใหม่ แม้กลยุทธ์จะเห็นของถูก.
     */
    #[Test]
    public function no_new_entries_are_opened_while_risk_is_at_panic(): void
    {
        $bot = $this->makeBot();
        $this->candles = $this->risingCandles();
        $this->panicNews();

        $result = $this->runner->tick($bot);

        $this->assertSame('hold', $result['action']);
        $this->assertSame(0, AiBotTrade::count());
        $this->assertSame(0, AiBotPosition::count());
    }

    /**
     * ความเสี่ยงปานกลาง = เข้าได้แต่ไม้ต้องเล็กลงกว่าตอนตลาดปกติ.
     */
    #[Test]
    public function elevated_risk_shrinks_the_position_size(): void
    {
        $this->candles = $this->risingCandles();

        $calmBot = $this->makeBot();
        $this->runner->tick($calmBot);
        $calmSize = (float) AiBotTrade::where('ai_bot_config_id', $calmBot->id)->first()?->gross_value;

        $this->assertGreaterThan(0.0, $calmSize, 'ตลาดปกติต้องเข้าไม้ได้ก่อน');

        // รอบสอง: ข่าวเสี่ยงปานกลาง (ไม่ถึงขั้น force exit)
        AiBotTrade::query()->delete();
        AiBotPosition::query()->delete();
        Cache::flush();

        $this->newsFrom('Regulator opens investigation into a major exchange', 2);

        $riskyBot = AiBotConfig::create([
            'wallet_address' => self::WALLET, 'name' => 'บอทที่สอง', 'pair' => 'BTC/USDT',
            'strategy' => 'momentum', 'timeframe' => '1h', 'status' => 'running', 'mode' => 'demo',
            'params' => [], 'risk' => ['max_position_usd' => 1000],
        ]);

        $this->runner->tick($riskyBot);
        $riskySize = (float) AiBotTrade::where('ai_bot_config_id', $riskyBot->id)->first()?->gross_value;

        $this->assertGreaterThan(0.0, $riskySize, 'ความเสี่ยงปานกลางยังเข้าไม้ได้');
        $this->assertLessThan($calmSize, $riskySize, 'แต่ไม้ต้องเล็กลง');
    }

    // ─────────────────────── 3) กรอบความเสี่ยงของผู้ใช้ ───────────────────────

    /**
     * ⭐ จุดตัดขาดทุนต้องชนะสัญญาณของกลยุทธ์เสมอ.
     */
    #[Test]
    public function the_stop_loss_overrides_any_strategy_signal(): void
    {
        $bot = $this->makeBot(['risk' => ['max_position_usd' => 1000, 'stop_loss_pct' => 5]]);

        // ต้นทุนสูงกว่าราคาปัจจุบันมาก → ขาดทุนเกิน 5%
        $this->candles = $this->risingCandles();
        $currentPrice = $this->candles[count($this->candles) - 1]['close'];
        $this->giveBotAPosition($bot, $currentPrice * 1.2);

        $result = $this->runner->tick($bot);

        $this->assertSame('sell', $result['action']);
        $this->assertStringContainsString('ตัดขาดทุน', $result['reason']);
        $this->assertSame(0, AiBotPosition::count());
    }

    /**
     * ถึงเป้าทำกำไรแล้วต้องปิดไม้ ไม่ใช่ปล่อยให้กำไรหายไป.
     */
    #[Test]
    public function it_takes_profit_at_the_configured_target(): void
    {
        $bot = $this->makeBot(['risk' => ['max_position_usd' => 1000, 'take_profit_pct' => 10]]);

        $this->candles = $this->risingCandles();
        $currentPrice = $this->candles[count($this->candles) - 1]['close'];
        $this->giveBotAPosition($bot, $currentPrice * 0.8);   // กำไร 25%

        $result = $this->runner->tick($bot);

        $this->assertSame('sell', $result['action']);
        $this->assertStringContainsString('เป้าทำกำไร', $result['reason']);
        $this->assertGreaterThan(0.0, (float) AiBotTrade::first()->realized_pnl);
    }

    /**
     * ขาดทุนสะสมถึงเพดานต่อวัน = ปิดไม้แล้วพักบอท ไม่ให้ขุดหลุมลึกกว่าเดิม.
     */
    #[Test]
    public function hitting_the_daily_loss_cap_pauses_the_bot(): void
    {
        $bot = $this->makeBot(['risk' => ['max_position_usd' => 1000, 'max_daily_loss_usd' => 50]]);

        $this->candles = $this->risingCandles();
        $currentPrice = $this->candles[count($this->candles) - 1]['close'];

        // ไม้ที่ถืออยู่ขาดทุนเกินเพดานแล้ว
        $this->giveBotAPosition($bot, $currentPrice * 2, 1.0);

        $result = $this->runner->tick($bot);

        $this->assertSame('sell', $result['action']);
        $this->assertStringContainsString('เพดาน', $result['reason']);
        $this->assertSame('paused', $bot->fresh()->status, 'ชนเพดานแล้วต้องพัก');
    }

    // ─────────────────────── 4) กลยุทธ์และการเติมไม้ ───────────────────────

    /**
     * กลยุทธ์ที่ไม่เติมไม้ ต้องไม่ซื้อซ้ำตอนถือของอยู่.
     */
    #[Test]
    public function a_non_pyramiding_strategy_does_not_buy_while_already_holding(): void
    {
        $bot = $this->makeBot(['strategy' => 'momentum']);
        $this->candles = $this->risingCandles();
        $this->giveBotAPosition($bot, $this->candles[count($this->candles) - 1]['close']);

        $result = $this->runner->tick($bot);

        $this->assertSame('hold', $result['action']);
        $this->assertSame(1, AiBotPosition::count());
        $this->assertSame(0, AiBotTrade::where('side', 'buy')->count());
    }

    /**
     * ตลาดนิ่งไม่มีสัญญาณ = ไม่ต้องเทรด. บอทที่เทรดตลอดเวลาคือบอทที่เผาค่าธรรมเนียม.
     */
    #[Test]
    public function a_flat_market_produces_no_trades(): void
    {
        $bot = $this->makeBot();
        $this->candles = $this->flatCandles();

        $result = $this->runner->tick($bot);

        $this->assertSame('hold', $result['action']);
        $this->assertSame(0, AiBotTrade::count());
    }

    /**
     * กลยุทธ์ที่ไม่รู้จักต้องรายงานว่าเป็น error ไม่ใช่เงียบแล้วไม่ทำอะไร.
     */
    #[Test]
    public function an_unknown_strategy_is_reported_as_an_error(): void
    {
        $bot = $this->makeBot(['strategy' => 'ไม่มีกลยุทธ์นี้']);
        $this->candles = $this->risingCandles();

        $result = $this->runner->tick($bot);

        $this->assertSame('error', $result['action']);
    }

    // ─────────────────────── 5) โหมดจริงต้องไม่แอบเทรด ───────────────────────

    /**
     * ⭐ โหมด live ต้องบันทึกเป็นสัญญาณรอผู้ใช้ยืนยัน ห้ามเทรดแทน.
     *
     * ระบบไม่ถือกุญแจของผู้ใช้ (non-custodial) — ถ้าโหมดจริงสร้างไม้ขึ้นมาเอง
     * แปลว่ามีที่ไหนสักแห่งกำลังโกหกว่าเทรดสำเร็จทั้งที่ไม่มีธุรกรรมจริง
     */
    #[Test]
    public function live_mode_records_a_signal_instead_of_trading_on_its_own(): void
    {
        $bot = $this->makeBot(['mode' => 'live']);
        $this->candles = $this->risingCandles();

        $result = $this->runner->tick($bot);

        $this->assertSame(0, AiBotTrade::count(), 'โหมดจริงต้องไม่สร้างไม้เอง');
        $this->assertSame(0, AiBotPosition::count());

        // ต้องแยกจาก hold ให้ได้ — หน้าเว็บใช้ค่านี้ตัดสินว่าจะเด้งปุ่มยืนยันไหม
        $this->assertSame('signal', $result['action']);

        $fresh = $bot->fresh();
        $this->assertNotNull($fresh->last_signal_at, 'ต้องบันทึกเวลาที่เกิดสัญญาณ');
        $this->assertStringContainsString('[รอยืนยัน]', $fresh->last_reason);

        // ⭐ ต้องเหลือเหตุผลของกลยุทธ์ไว้ด้วย ไม่ใช่ถูกข้อความระบบทับ
        // ถ้าเหลือแค่ "ต้องให้ผู้ใช้กดยืนยัน" ผู้ใช้จะไม่รู้ว่าต้องยืนยันอะไร
        $this->assertStringContainsString('EMA', $fresh->last_reason);
        $this->assertStringContainsString('ซื้อ', $fresh->last_reason);
    }

    /**
     * เครดิตทดลองต้องไม่ถูกแตะเลยในโหมดจริง.
     */
    #[Test]
    public function live_mode_never_touches_the_demo_balance(): void
    {
        $bot = $this->makeBot(['mode' => 'live']);
        $this->candles = $this->risingCandles();

        $this->runner->tick($bot);

        $this->assertEqualsWithDelta(10000.0, (float) app(PaperBroker::class)->account(self::WALLET)->balance, 0.00001);
    }

    // ─────────────────────── 6) ผู้ใช้ต้องเห็นว่าบอทคิดอะไร ───────────────────────

    /**
     * ทุกรอบต้องทิ้งร่องรอยว่าบอทตัดสินใจอะไรและเพราะอะไร.
     */
    #[Test]
    public function every_tick_records_what_the_bot_decided_and_why(): void
    {
        $bot = $this->makeBot();
        $this->candles = $this->flatCandles();

        $this->runner->tick($bot);
        $fresh = $bot->fresh();

        $this->assertNotNull($fresh->last_run_at);
        $this->assertNotEmpty($fresh->last_reason);
        $this->assertArrayHasKey('last_action', $fresh->stats);
        $this->assertArrayHasKey('last_risk', $fresh->stats);
    }

    /**
     * ไม้ที่บอทเปิดต้องแนบเหตุผลของกลยุทธ์ไว้ให้ย้อนดูได้.
     */
    #[Test]
    public function opened_trades_carry_the_strategy_reasoning(): void
    {
        $bot = $this->makeBot();
        $this->candles = $this->risingCandles();

        $this->runner->tick($bot);

        $trade = AiBotTrade::first();

        $this->assertNotNull($trade, 'ตลาดขาขึ้นชัดเจน momentum ควรเข้าไม้');
        $this->assertNotEmpty($trade->reason);
        $this->assertSame('momentum', $trade->strategy);
        $this->assertSame('calm', $trade->risk_level);
    }

    // ── ช่องในฟอร์มที่เคยไม่มีโค้ดอ่าน ────────────────────────────────────────

    /**
     * "ขนาดต่อไม้ (USD)" ของกริดต้องคุมขนาดไม้จริง.
     *
     * เดิมไม่มีโค้ดอ่านคีย์นี้เลยสักบรรทัด — ผู้ใช้ตั้ง $20 แล้วบอทเข้าไม้ตามเพดาน
     * ทุน ($1,000 ในเทสต์นี้) ห้าสิบเท่าของที่สั่ง และไม่มีอะไรบอกว่าค่าที่ตั้งถูกทิ้ง
     */
    #[Test]
    public function the_grid_order_size_actually_caps_the_trade(): void
    {
        $bot = $this->makeBot([
            'strategy' => 'grid',
            'timeframe' => '1h',
            'params' => ['grid_levels' => 3, 'range_pct' => 6, 'order_size_usd' => 20],
            'risk' => ['max_position_usd' => 1000],
        ]);

        $this->candles = $this->fallingIntoGrid();
        $this->runner->tick($bot);

        $trade = AiBotTrade::first();

        $this->assertNotNull($trade, 'ราคาหลุดขอบล่างของกริด ควรเข้าไม้');
        $this->assertLessThanOrEqual(20.0, (float) $trade->quote_amount);
    }

    /**
     * "หยุดเทรดช่วงข่าวแรง" ต้องปิดได้จริง.
     *
     * เดิมเก็บค่าไว้เฉยๆ — ผู้ใช้ปิดสวิตช์แล้วด่านข่าวยังทำงานอยู่เหมือนเดิม
     */
    #[Test]
    public function turning_off_the_news_filter_actually_skips_the_news_gate(): void
    {
        MarketNews::create([
            'source' => 'test',
            'title' => 'Major exchange halts withdrawals after hack',
            'url_hash' => hash('sha256', 'news-filter-test'),
            'url' => 'https://example.test/hack',
            'published_at' => now()->subMinutes(5),
            'panic_score' => 0.98,
            'symbols' => ['BTC'],
        ]);

        $withFilter = $this->makeBot([
            'strategy' => 'grid',
            'params' => ['grid_levels' => 3, 'range_pct' => 6, 'news_filter' => true],
        ]);

        $this->candles = $this->fallingIntoGrid();
        $blocked = $this->runner->tick($withFilter);

        Cache::flush();   // คะแนนข่าวถูกแคชไว้ 60 วิ — ต้องล้างก่อนวัดอีกฝั่ง

        $withoutFilter = AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'ปิดด่านข่าว',
            'pair' => 'BTC/USDT',
            'strategy' => 'grid',
            'timeframe' => '1h',
            'status' => 'running',
            'mode' => 'demo',
            'params' => ['grid_levels' => 3, 'range_pct' => 6, 'news_filter' => false],
            'risk' => ['max_position_usd' => 1000],
        ]);

        $allowed = $this->runner->tick($withoutFilter);

        $this->assertSame('panic', $blocked['risk'], 'เปิดด่านข่าวไว้ ต้องเห็นข่าวแพนิค');
        // ฉากราคาแกว่งแรงทำให้ด่านราคาขึ้น caution ได้เอง — สิ่งที่ต้องไม่เกิดคือ "panic จากข่าว"
        $this->assertNotSame('panic', $allowed['risk'], 'ปิดด่านข่าวแล้ว ข่าวต้องไม่มีผลกับคะแนน');
    }

    /**
     * บอทที่ params ว่าง ต้องได้ค่าปริยายของกลยุทธ์ ไม่ใช่ตกไปใช้เพดานทุน.
     *
     * เจอตอนรันกับตลาดจริง: ตั้งงบ $25 แต่ลงจริง $30 เพราะ orderSizeFor() อ่าน
     * `$bot->params` ดิบซึ่งว่างอยู่ → เงื่อนไข "ผู้ใช้ระบุจำนวนเงินไหม" เป็นเท็จ
     * → ไปคิดจากเพดานทุน $100 × ความแรงสัญญาณแทน
     *
     * บอทที่สร้างก่อนกติกาเปลี่ยนก็อยู่ในสภาพนี้ทั้งหมด
     */
    #[Test]
    public function a_bot_with_empty_params_still_uses_the_strategy_default_budget(): void
    {
        $bot = $this->makeBot([
            'strategy' => 'grid',
            'timeframe' => '1h',
            'params' => [],                              // ว่างเปล่า เหมือนบอทที่สร้างไว้ก่อน
            'risk' => ['max_position_usd' => 1000],      // เพดานสูงกว่างบมาก
        ]);

        $this->candles = $this->fallingIntoGrid();
        $this->runner->tick($bot);

        $trade = AiBotTrade::first();
        $this->assertNotNull($trade, 'ราคาลงถึงชั้นซื้อ ควรเข้าไม้');

        // ค่าปริยายของกริดคือ order_size_usd = 20 — ต้องไม่ใช่ 1000 หรือครึ่งหนึ่งของมัน
        $spent = (float) $trade->gross_value + (float) $trade->fee;

        $this->assertLessThanOrEqual(20.0, $spent);
        $this->assertGreaterThan(0.0, $spent);
    }

    /** ราคาแกว่งลงมาถึงชั้นซื้อของกริด — จุดที่กลยุทธ์กริดเข้าไม้ */
    private function fallingIntoGrid(): array
    {
        /*
         * ต้องลงมาอยู่ "ในกรอบแต่ต่ำกว่าชั้นซื้อ" พอดี
         * ลงลึกกว่านี้กริดจะอ่านว่าหลุดกรอบล่างแล้วหยุดเข้าใหม่ (ไม่ใช่สัญญาณซื้อ)
         *
         * ⚠️ ต้อง "แกว่ง" ลง ไม่ใช่ดิ่งตรงๆ — กริดมีตัวกรองสภาพตลาดแล้ว (2 ก.ย. 2026)
         *    ดิ่ง 4 แท่งติดได้ efficiency ratio = 1 ซึ่งกริดอ่านว่าตลาดมีทิศทางแล้ว
         *    ปฏิเสธเข้าไม้อย่างถูกต้อง ฉากนี้จึงลง 1.5 เด้ง 1.0 สลับกัน (ER ≈ 0.29)
         */
        $closes = array_fill(0, 60, 100.0);
        foreach ([98.5, 99.5, 98.0, 99.0, 97.5, 98.5, 97.0, 98.0, 96.5, 97.5, 96.0] as $close) {
            $closes[] = $close;
        }

        // แท่งที่กำลังวิ่ง — ถูกตัดทิ้ง จึงต้องซ้ำราคาเดิมไว้ไม่ให้สัญญาณเปลี่ยน
        $closes[] = end($closes);

        $candles = [];
        foreach ($closes as $i => $close) {
            $candles[] = [
                'time' => 1_700_000_000_000 + $i * 3_600_000,
                'open' => $close * 1.001, 'high' => $close * 1.002, 'low' => $close * 0.998,
                'close' => $close, 'volume' => 1000.0,
            ];
        }

        return $candles;
    }

    // ─────────────────────── 7) ไม่คิดซ้ำบนแท่งเดิม · ไม่บันทึกซ้ำ ───────────────────────

    /**
     * ⭐ แท่งปิดเดิม + ไม่มีของ = ข้าม ไม่ประเมินซ้ำ ไม่บันทึกซ้ำ.
     *
     * บอท VIP ถูกปลุกทุกนาทีบนแท่ง 1 ชั่วโมง → เห็นภาพเดิม 60 รอบ วัดบน prod:
     * 81,105 แถวใน 13 วัน จน aibot:harvest ตายด้วย memory limit
     */
    #[Test]
    public function it_skips_re_evaluating_the_same_closed_candle_while_flat(): void
    {
        $bot = $this->makeBot();
        $this->candles = $this->flatCandles();

        $first = $this->runner->tick($bot);
        $second = $this->runner->tick($bot->fresh());

        $this->assertSame('hold', $first['action']);
        $this->assertArrayNotHasKey('skipped', $first);
        $this->assertTrue($second['skipped'] ?? false, 'รอบสองบนแท่งเดิมต้องถูกข้าม');
        $this->assertSame(1, AiBotDecision::count(), 'ต้องมีบันทึกแค่รอบเดียว');
        $this->assertNotNull($bot->fresh()->last_run_at, 'ยังต้องขยับ last_run_at ให้ตัวจับเวลานับรอบถูก');

        // แท่งปิดใหม่มาถึง → ต้องคิดใหม่
        $this->candles = $this->flatCandles(81);
        $third = $this->runner->tick($bot->fresh());

        $this->assertArrayNotHasKey('skipped', $third);
    }

    /**
     * ถือของอยู่ต้องประเมินทุกรอบ (ข่าว/AI เปลี่ยนได้ระหว่างแท่ง) แต่สภาพเดิมนับซ้ำในแถวเดิม.
     */
    #[Test]
    public function while_holding_it_keeps_evaluating_but_folds_identical_decisions_into_one_row(): void
    {
        $bot = $this->makeBot();
        // ขาขึ้นที่เพิ่งตัดขึ้น — momentum ถือต่อแน่นอน (ตลาดนิ่งๆ EMA ตัดกันไปมาแล้วขายทิ้ง)
        $this->candles = $this->risingCandles();
        $this->giveBotAPosition($bot, $this->candles[count($this->candles) - 2]['close']);

        $this->runner->tick($bot);
        $second = $this->runner->tick($bot->fresh());
        $this->runner->tick($bot->fresh());

        $this->assertSame(1, AiBotPosition::count(), 'ฉากนี้ต้องยังถือของอยู่ตลอด');
        $this->assertArrayNotHasKey('skipped', $second, 'ถือของอยู่ห้ามข้าม');
        $this->assertSame(1, AiBotDecision::count(), 'สภาพเดิมสามรอบ = แถวเดียว');

        $row = AiBotDecision::first();
        $this->assertSame(3, $row->repeat_count);
        $this->assertNotNull($row->last_seen_at);
        $this->assertNotNull($row->price, 'ราคาต้องติดไปกับบันทึกเสมอ');
    }

    /**
     * ไม้ที่ด่านความเสี่ยงสั่งปิด ต้องบันทึกราคาไว้ — ไม่งั้นรายงานให้คะแนนไม้นั้นไม่ได้.
     */
    #[Test]
    public function forced_exits_record_the_price_they_sold_at(): void
    {
        $bot = $this->makeBot();
        $this->giveBotAPosition($bot);
        $this->candles = $this->risingCandles();
        $this->panicNews();

        $this->runner->tick($bot);

        $decision = AiBotDecision::where('action', 'sell')->first();
        $this->assertNotNull($decision);
        $this->assertNotNull($decision->price);
        $this->assertTrue($decision->has_position);
    }

    // ─────────────────────── 8) สวิตช์ AI รายบอท (กลุ่มควบคุม) ───────────────────────

    /**
     * ⭐ ปิด ai_gate = กฎล้วน — มุมมอง AI ที่ห้ามเข้าไม้ต้องไม่มีผลกับบอทตัวนี้.
     *
     * นี่คือกลุ่มควบคุมของการทดลอง: ไม่มีมัน แยกไม่ได้เลยว่าผลมาจาก AI หรือจากตลาด
     */
    #[Test]
    public function a_bot_with_the_ai_gate_off_ignores_the_market_view(): void
    {
        config(['aibot_analyst.enabled' => true, 'aibot_analyst.shadow_mode' => false]);

        \App\Models\AiMarketView::create([
            'scope' => 'strategic', 'provider' => 'openai', 'model' => 'test',
            'regime' => 'risk_on', 'confidence' => 0.9, 'size_multiplier' => 1.0,
            'coins' => ['BTC' => ['score' => -0.8, 'stance' => 'avoid', 'why' => 'ทดสอบ']],
            'shortlist' => [], 'summary' => '', 'headlines' => [], 'prompt' => '', 'raw_response' => '',
            'tokens_used' => 0, 'latency_ms' => 0, 'expires_at' => now()->addHour(),
        ]);

        $this->candles = $this->risingCandles();

        $gated = $this->makeBot();
        $blocked = $this->runner->tick($gated);

        $control = AiBotConfig::create([
            'wallet_address' => self::WALLET, 'name' => 'กลุ่มควบคุม', 'pair' => 'BTC/USDT',
            'strategy' => 'momentum', 'timeframe' => '1h', 'status' => 'running', 'mode' => 'demo',
            'params' => ['ai_gate' => false], 'risk' => ['max_position_usd' => 1000],
        ]);
        $free = $this->runner->tick($control);

        $this->assertSame('hold', $blocked['action'], 'AI สั่ง avoid → บอทปกติต้องไม่เข้าไม้');
        $this->assertStringContainsString('AI', $blocked['reason']);
        $this->assertSame('buy', $free['action'], 'ปิด ai_gate → กฎล้วนต้องเข้าไม้ได้');
    }

    /**
     * ⭐ AI สั่งปิดไม้ แต่ DCA ถือผ่านขาลงโดยออกแบบ → ไม่ขาย และไม่เติมไม้ใหม่รอบนี้.
     */
    #[Test]
    public function an_ai_exit_is_ignored_by_strategies_that_hold_through_dips(): void
    {
        config(['aibot_analyst.enabled' => true, 'aibot_analyst.shadow_mode' => false]);

        \App\Models\AiMarketView::create([
            'scope' => 'strategic', 'provider' => 'openai', 'model' => 'test',
            'regime' => 'risk_off', 'confidence' => 0.95, 'size_multiplier' => 1.0,
            'coins' => ['BTC' => ['score' => -0.9, 'stance' => 'exit', 'why' => 'ทดสอบ']],
            'shortlist' => [], 'summary' => '', 'headlines' => [], 'prompt' => '', 'raw_response' => '',
            'tokens_used' => 0, 'latency_ms' => 0, 'expires_at' => now()->addHour(),
        ]);

        $dca = $this->makeBot([
            'strategy' => 'dca',
            'params' => ['interval_hours' => 1, 'budget_usd' => 25, 'dip_boost_pct' => 3],
            'risk' => ['max_position_usd' => 1000],
        ]);
        $this->candles = $this->flatCandles();
        $this->giveBotAPosition($dca, 100.0, 0.5);

        $result = $this->runner->tick($dca);

        $this->assertSame('hold', $result['action']);
        $this->assertStringContainsString('ถือผ่านขาลง', $result['reason']);
        $this->assertSame(1, AiBotPosition::count(), 'ของต้องยังอยู่');
        $this->assertSame(0, AiBotTrade::count(), 'ไม่ขาย และไม่เติมไม้ใหม่ระหว่างที่ AI ไม่ชอบเหรียญนี้');
    }

    // ─────────────────────── 8.5) เพดานทุนคุมยอดรวมของกลยุทธ์ที่เติมไม้ ───────────────────────

    /**
     * ⭐ DCA เติมไม้ได้ แต่ต้นทุนสะสมต้องไม่ทะลุ "ทุนสูงสุดต่อไม้" ที่ผู้ใช้ตั้ง.
     *
     * backtest 90 วันเปิดโปงว่าเดิมไม่มีด่านนี้ — เพดาน $100 แต่เติม $25 ทุกวันไม่มีหยุด
     */
    #[Test]
    public function a_pyramiding_strategy_stops_adding_once_the_position_cap_is_reached(): void
    {
        $bot = $this->makeBot([
            'strategy' => 'dca',
            'params' => ['interval_hours' => 1, 'budget_usd' => 25, 'dip_boost_pct' => 3],
            'risk' => ['max_position_usd' => 60],
        ]);
        $this->candles = $this->flatCandles();
        $this->giveBotAPosition($bot, 100.0, 0.5);   // ต้นทุนสะสม 50 → เหลือช่อง 10

        $first = $this->runner->tick($bot);
        $trade = AiBotTrade::where('side', 'buy')->first();

        $this->assertSame('buy', $first['action']);
        $this->assertNotNull($trade);
        $this->assertLessThanOrEqual(10.0 + 1e-6, (float) $trade->gross_value + (float) $trade->fee, 'ไม้ต้องถูกตัดให้พอดีช่องที่เหลือ');

        // ช่องเต็มแล้ว → รอบถัดไป (ครบรอบ DCA แล้ว) ต้องถือ พร้อมบอกว่าชนเพดาน
        $this->travel(2)->hours();
        $this->candles = $this->flatCandles(81);
        $second = $this->runner->tick($bot->fresh());

        $this->assertSame('hold', $second['action']);
        $this->assertStringContainsString('เพดานทุน', $second['reason']);
        $this->assertSame(1, AiBotTrade::where('side', 'buy')->count());
    }

    // ─────────────────────── 9) กลยุทธ์ที่ถูกถอดออกจากการขาย ───────────────────────

    /**
     * บอทที่ยังเดินอยู่ด้วยกลยุทธ์ที่ถูกถอด ต้องถูกพักพร้อมเหตุผล ไม่ใช่เทรดต่อเงียบๆ.
     */
    #[Test]
    public function a_running_bot_on_a_retired_strategy_is_paused_with_the_reason(): void
    {
        $bot = $this->makeBot(['strategy' => 'scalping', 'timeframe' => '5m']);
        $this->candles = $this->risingCandles();

        $result = $this->runner->tick($bot);

        $this->assertSame('stopped', $result['action']);
        $this->assertSame('paused', $bot->fresh()->status);
        $this->assertStringContainsString('ถอด', $bot->fresh()->last_reason);
        $this->assertSame(0, AiBotTrade::count(), 'ห้ามเทรดด้วยกลยุทธ์ที่ถอดแล้ว');
    }
}
