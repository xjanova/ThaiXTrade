<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Models\AiMarketView;
use App\Models\Chain;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\AiBot\Advisor\AdvisorSettings;
use App\Services\AiBot\Analyst\MarketAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — การแปลคำตอบของ AI ให้ปลอดภัยพอจะเอาไปใช้กับเงินจริง.
 *
 * ทุกเทสต์ในไฟล์นี้ตอบคำถามเดียว: "ถ้า AI ตอบมั่ว ระบบยังปลอดภัยไหม"
 * เพราะคำตอบของ LLM ไม่คงที่และเราควบคุมปลายทางไม่ได้ — สิ่งเดียวที่ควบคุมได้
 * คือการไม่เชื่อตัวเลขที่ได้มาแม้แต่ค่าเดียว
 *
 * Developed by Xman Studio.
 */
class MarketAnalystTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config()->set('aibot_analyst.enabled', true);
        config()->set('aibot_analyst.scopes.strategic.enabled', true);
        config()->set('aibot_analyst.scopes.tactical.enabled', true);
        config()->set('aibot_analyst.model', 'gpt-4o-mini');
        config()->set('aibot_advisor.providers.openai.api_key', 'sk-test');

        app(AdvisorSettings::class)->forget();

        $this->seedPair('BTC');
        $this->seedPair('ETH');
        $this->seedRunningBot();
    }

    #[Test]
    public function a_round_nobody_can_use_is_skipped_without_calling_the_provider(): void
    {
        /*
         * รอบสั้นยิงทุก 15 นาที = 96 ครั้ง/วัน ถ้าไม่มีบอทที่แพลนถึงเกณฑ์สักตัว
         * ก็เผาเงินฟรี — และคีย์ OpenAI มาจากพูลที่ **บิลรวมกัน** กับบอทดูดวง
         * รอบเปล่าจึงไปเบียดงบก้อนเดียวกับงานอื่นด้วย
         */
        AiBotSubscription::query()->delete();
        $this->fakeOpenAi(['regime' => 'neutral', 'confidence' => 0.9, 'size_multiplier' => 1, 'coins' => [], 'shortlist' => []]);

        $result = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC);

        $this->assertFalse($result['ok']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    #[Test]
    public function a_free_tier_bot_does_not_justify_the_tactical_round(): void
    {
        // รอบสั้นเป็นสิทธิ์ของแพลน pro ขึ้นไป — บอทแพลนฟรีไม่ควรทำให้มันเดิน
        AiBotPlan::query()->update(['tier' => 'free']);
        $this->fakeOpenAi(['regime' => 'neutral', 'confidence' => 0.9, 'size_multiplier' => 1, 'coins' => [], 'shortlist' => []]);

        $result = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_TACTICAL);

        $this->assertFalse($result['ok']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    #[Test]
    public function a_disabled_scope_never_calls_the_provider(): void
    {
        /*
         * เจ้าของสั่งปิดรอบ 15 นาทีไว้ก่อน — ตัว schedule ยังเดินอยู่ แต่ต้องจบ
         * ตั้งแต่ก่อนยิง API ไม่งั้นก็ยังเสียเงินเท่าเดิม 96 ครั้ง/วัน
         */
        config()->set('aibot_analyst.scopes.tactical.enabled', false);
        $this->fakeOpenAi(['regime' => 'neutral', 'confidence' => 0.9, 'size_multiplier' => 1, 'coins' => [], 'shortlist' => []]);

        $result = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_TACTICAL);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ถูกปิดไว้', $result['reason']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    #[Test]
    public function the_strategic_round_still_runs_while_tactical_is_held(): void
    {
        config()->set('aibot_analyst.scopes.tactical.enabled', false);
        $this->fakeOpenAi(['regime' => 'risk_on', 'confidence' => 0.8, 'size_multiplier' => 1, 'coins' => [], 'shortlist' => []]);

        $result = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC);

        $this->assertTrue($result['ok'], $result['reason'] ?? '');
    }

    #[Test]
    public function a_clean_answer_is_stored_as_a_view(): void
    {
        $this->fakeOpenAi([
            'regime' => 'risk_on',
            'confidence' => 0.82,
            'size_multiplier' => 1.1,
            'summary' => 'ตลาดฟื้นตัว',
            'coins' => ['BTC' => ['score' => 0.7, 'stance' => 'buy', 'why' => 'เงินไหลเข้า']],
            'shortlist' => ['BTC/USDT'],
        ]);

        $result = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC);

        $this->assertTrue($result['ok'], $result['reason'] ?? '');

        $view = $result['view'];
        $this->assertSame('risk_on', $view->regime);
        $this->assertSame(0.82, $view->confidence);
        $this->assertSame(['BTC/USDT'], $view->shortlistPairs());
        $this->assertSame('buy', $view->forPair('BTC/USDT')['stance']);
    }

    #[Test]
    public function an_absurd_size_multiplier_is_clamped_to_the_ceiling(): void
    {
        /*
         * เคสที่ต้องกันให้ได้ที่สุด — คำตอบเพี้ยนครั้งเดียวขอไม้ใหญ่ 50 เท่า
         * ถ้าไม่บีบ บอทจะเปิดไม้ใหญ่กว่าพอร์ตทั้งใบในรอบเดียว
         */
        $this->fakeOpenAi([
            'regime' => 'risk_on',
            'confidence' => 0.9,
            'size_multiplier' => 50,
            'coins' => [],
            'shortlist' => [],
        ]);

        $view = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC)['view'];

        $this->assertSame(
            (float) config('aibot_analyst.limits.size_multiplier_max'),
            $view->size_multiplier,
        );
    }

    #[Test]
    public function scores_and_confidence_are_clamped_to_their_range(): void
    {
        $this->fakeOpenAi([
            'regime' => 'neutral',
            'confidence' => 7.5,
            'size_multiplier' => -3,
            'coins' => ['BTC' => ['score' => 99, 'stance' => 'buy']],
            'shortlist' => [],
        ]);

        $view = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC)['view'];

        $this->assertSame(1.0, $view->confidence);
        $this->assertSame(0.0, $view->size_multiplier);
        $this->assertSame(1.0, $view->forPair('BTC/USDT')['score']);
    }

    #[Test]
    public function coins_outside_the_context_are_dropped(): void
    {
        /*
         * โมเดลชอบเติมเหรียญที่มันรู้จักจากการเทรนเข้ามาเอง — ถ้าปล่อยผ่าน
         * จะได้ shortlist ที่ชี้ไปยังคู่ที่เว็บเราไม่ได้เปิดเทรด แล้วบอทที่
         * เลือกเหรียญอัตโนมัติจะย้ายไปคู่ที่กดซื้อไม่ได้
         */
        $this->fakeOpenAi([
            'regime' => 'neutral',
            'confidence' => 0.8,
            'size_multiplier' => 1,
            'coins' => [
                'BTC' => ['score' => 0.5, 'stance' => 'buy'],
                'FARTCOIN' => ['score' => 0.9, 'stance' => 'buy'],
            ],
            'shortlist' => ['FARTCOIN/USDT', 'BTC/USDT'],
        ]);

        $view = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC)['view'];

        $this->assertArrayNotHasKey('FARTCOIN', (array) $view->coins);
        $this->assertSame(['BTC/USDT'], $view->shortlistPairs());
    }

    #[Test]
    public function an_unknown_stance_falls_back_to_hold(): void
    {
        $this->fakeOpenAi([
            'regime' => 'sideways_maybe',
            'confidence' => 0.8,
            'size_multiplier' => 1,
            'coins' => ['BTC' => ['score' => 0.2, 'stance' => 'YOLO']],
            'shortlist' => [],
        ]);

        $view = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC)['view'];

        $this->assertSame('neutral', $view->regime);
        $this->assertSame('hold', $view->forPair('BTC/USDT')['stance']);
    }

    #[Test]
    public function a_non_json_answer_produces_no_view(): void
    {
        Http::fake([
            '*/models' => Http::response(['data' => [['id' => 'gpt-4o-mini']]]),
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'ขอโทษครับ ผมช่วยเรื่องนี้ไม่ได้']]],
            ]),
        ]);

        $result = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, AiMarketView::count());
    }

    #[Test]
    public function a_model_the_provider_removed_stops_the_round_loudly(): void
    {
        /*
         * บทเรียนจริง 21 ส.ค. 2026: Groq ถอด Llama ออกหมด งานเจนคอนเทนต์ล้ม
         * วันละ 2 รอบเงียบๆ หลายวันกว่าจะมีคนไปอ่าน log เจอ
         */
        Http::fake([
            '*/models' => Http::response(['data' => [['id' => 'gpt-9-turbo']]]),
            '*/chat/completions' => Http::response(['choices' => []]),
        ]);

        $result = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gpt-4o-mini', $result['reason']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    #[Test]
    public function a_missing_api_key_is_reported_not_thrown(): void
    {
        config()->set('aibot_advisor.providers.openai.api_key', '');
        app(AdvisorSettings::class)->forget();

        $result = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('คีย์', $result['reason']);
    }

    #[Test]
    public function the_switch_being_off_skips_the_round_entirely(): void
    {
        config()->set('aibot_analyst.enabled', false);
        Http::fake();

        $result = app(MarketAnalyst::class)->run(AiMarketView::SCOPE_STRATEGIC);

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    // ── ตัวช่วย ───────────────────────────────────────────────────────────────

    private function fakeOpenAi(array $answer): void
    {
        Http::fake([
            '*/models' => Http::response(['data' => [['id' => 'gpt-4o-mini']]]),
            '*/ticker/24hr*' => Http::response([
                ['symbol' => 'BTCUSDT', 'lastPrice' => '79000', 'priceChangePercent' => '1.2', 'quoteVolume' => '100000', 'priceChange' => '900', 'highPrice' => '80000', 'lowPrice' => '78000', 'volume' => '10', 'openPrice' => '78100'],
                ['symbol' => 'ETHUSDT', 'lastPrice' => '3000', 'priceChangePercent' => '-0.5', 'quoteVolume' => '50000', 'priceChange' => '-15', 'highPrice' => '3100', 'lowPrice' => '2950', 'volume' => '20', 'openPrice' => '3015'],
            ]),
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($answer, JSON_UNESCAPED_UNICODE)]]],
                'usage' => ['total_tokens' => 420],
            ]),
            '*' => Http::response([]),
        ]);
    }

    /** บอทที่เดินอยู่จริงหนึ่งตัว — ไม่มีตัวนี้ ตัววิเคราะห์จะข้ามรอบทิ้งทั้งหมด */
    private function seedRunningBot(): void
    {
        $plan = AiBotPlan::create([
            'code' => 'vip', 'name' => 'VIP', 'tier' => 'vip', 'execution' => 'cloud',
            'credits_per_day' => 10, 'price_tpix_per_day' => 10,
            'max_bots' => 3, 'max_capital_usd' => 1000, 'is_active' => true,
        ]);

        AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => $plan->id,
            'status' => 'active',
            'days' => 30,
            'credits_spent' => 0,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);

        AiBotConfig::create([
            'wallet_address' => self::WALLET,
            'name' => 'ทดสอบ',
            'pair' => 'BTC/USDT',
            'strategy' => 'ai_signal',
            'timeframe' => '1h',
            'mode' => 'demo',
            'status' => 'running',
            'params' => [],
        ]);
    }

    private function seedPair(string $base): void
    {
        $chain = Chain::firstOrCreate(
            ['chain_id' => 4289],
            [
                'name' => 'TPIX Chain',
                'symbol' => 'TPIX',
                'rpc_url' => 'https://rpc.example',
                'native_currency_name' => 'TPIX',
                'native_currency_symbol' => 'TPIX',
                'is_active' => true,
            ],
        );

        $baseToken = Token::firstOrCreate(
            ['symbol' => $base, 'chain_id' => $chain->id],
            [
                'name' => $base,
                'decimals' => 18,
                'is_active' => true,
                'contract_address' => '0x'.substr(hash('sha256', $base), 0, 40),
            ],
        );

        $quoteToken = Token::firstOrCreate(
            ['symbol' => 'USDT', 'chain_id' => $chain->id],
            [
                'name' => 'Tether',
                'decimals' => 6,
                'is_active' => true,
                'contract_address' => '0x'.substr(hash('sha256', 'USDT'), 0, 40),
            ],
        );

        TradingPair::firstOrCreate(
            ['symbol' => "{$base}/USDT"],
            [
                'base_token_id' => $baseToken->id,
                'quote_token_id' => $quoteToken->id,
                'chain_id' => $chain->id,
                'is_active' => true,
            ],
        );
    }
}
