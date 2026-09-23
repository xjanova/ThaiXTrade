<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiMarketView;
use App\Models\Chain;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\AiBot\Analyst\AnalystCalibration;
use App\Services\AiBot\Analyst\AutoPairResolver;
use App\Services\AiBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ให้ AI เลือกเหรียญที่บอทจะเทรด.
 *
 * สามกฎที่ห้ามข้าม (ดูเหตุผลเต็มใน AutoPairResolver):
 *   1. ห้ามย้ายตอนถือของ — ไม้เดิมจะค้างบนคู่ที่ไม่มีใครดูแลต่อ
 *   2. ห้ามย้ายถี่กว่าเวลาพัก — ต้นทุนเข้าออก 0.36% กินกำไรก่อนได้พิสูจน์อะไร
 *   3. คู่ปลายทางต้องเปิดเทรดอยู่จริง — ย้ายไปคู่ที่ปิดแล้ว = บอทตายเงียบ
 *
 * Developed by Xman Studio.
 */
class AutoPairResolverTest extends TestCase
{
    use RefreshDatabase;

    private AutoPairResolver $resolver;

    private Chain $chain;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('aibot_analyst.enabled', true);
        config()->set('aibot_analyst.auto_pair.enabled', true);
        config()->set('aibot_analyst.auto_pair.min_hold_minutes', 240);

        $this->resolver = app(AutoPairResolver::class);

        $this->chain = Chain::create([
            'chain_id' => 4289,
            'name' => 'TPIX Chain',
            'symbol' => 'TPIX',
            'rpc_url' => 'https://rpc.example',
            'native_currency_name' => 'TPIX',
            'native_currency_symbol' => 'TPIX',
            'is_active' => true,
        ]);

        $this->pair('BTC');
        $this->pair('ETH');
    }

    #[Test]
    public function it_moves_the_bot_to_the_top_pick(): void
    {
        $this->makeView(['ETH/USDT', 'BTC/USDT']);
        $bot = $this->bot('BTC/USDT', auto: true);

        $result = $this->resolver->resolve($bot, $this->plan(), false);

        $this->assertTrue($result['switched']);
        $this->assertSame('ETH/USDT', $result['pair']);
        $this->assertSame('ETH/USDT', $bot->fresh()->pair);
    }

    /**
     * ⭐ AI ที่ทายแย่กว่าโยนเหรียญ ไม่มีสิทธิ์ย้ายเหรียญให้บอท.
     *
     * ออดิท R3: เหรียญที่ AI ให้ buy แพ้ BTC เฉลี่ย −98 bps ใน 24 ชม. — ถ้าลดขั้นเฉพาะด่าน
     * เข้า/ออกไม้ AI ที่ไม่มีฝีมือยังพาเงินไปเหรียญที่แย่กว่าได้ผ่านทางนี้
     */
    #[Test]
    public function a_demoted_ai_cannot_move_the_bot(): void
    {
        Cache::put(AnalystCalibration::CACHE_KEY, [
            'built_at' => now()->toIso8601String(), 'days' => 14, 'horizon' => 24, 'samples' => 260,
            'brier' => 0.292, 'brier_samples' => 260, 'buckets' => [],
        ], now()->addHour());

        $this->makeView(['ETH/USDT', 'BTC/USDT']);
        $bot = $this->bot('BTC/USDT', auto: true);

        $result = $this->resolver->resolve($bot, $this->plan(), false);

        $this->assertFalse($result['switched']);
        $this->assertSame('BTC/USDT', $bot->fresh()->pair);
        $this->assertStringContainsString('โยนเหรียญ', $result['reason']);
    }

    #[Test]
    public function a_bot_without_the_option_never_moves(): void
    {
        $this->makeView(['ETH/USDT']);
        $bot = $this->bot('BTC/USDT', auto: false);

        $result = $this->resolver->resolve($bot, $this->plan(), false);

        $this->assertFalse($result['switched']);
        $this->assertSame('BTC/USDT', $bot->fresh()->pair);
    }

    #[Test]
    public function holding_a_position_freezes_the_pair(): void
    {
        /*
         * กฎข้อ 1 — ย้ายตอนถือของแล้วไม้เดิมจะค้างอยู่บนคู่ที่ไม่มีกลยุทธ์ไหน
         * คอยขายให้ และ stop loss ก็ไม่ถูกตรวจอีกเลย เงินลอยอยู่ตรงนั้น
         */
        $this->makeView(['ETH/USDT']);
        $bot = $this->bot('BTC/USDT', auto: true);

        $result = $this->resolver->resolve($bot, $this->plan(), true);

        $this->assertFalse($result['switched']);
        $this->assertSame('BTC/USDT', $bot->fresh()->pair);
    }

    #[Test]
    public function it_waits_out_the_cooldown_before_moving_again(): void
    {
        $this->makeView(['ETH/USDT']);

        $bot = $this->bot('BTC/USDT', auto: true);
        $bot->update(['stats' => ['auto_pair_switched_at' => now()->subMinutes(30)->toDateTimeString()]]);

        $result = $this->resolver->resolve($bot, $this->plan(), false);

        $this->assertFalse($result['switched']);
        $this->assertStringContainsString('รออีก', $result['reason']);
    }

    #[Test]
    public function it_moves_once_the_cooldown_has_passed(): void
    {
        $this->makeView(['ETH/USDT']);

        $bot = $this->bot('BTC/USDT', auto: true);
        $bot->update(['stats' => ['auto_pair_switched_at' => now()->subMinutes(300)->toDateTimeString()]]);

        $result = $this->resolver->resolve($bot, $this->plan(), false);

        $this->assertTrue($result['switched']);
        $this->assertSame('ETH/USDT', $result['pair']);
    }

    /** เวลาย้ายที่อยู่ในอนาคต (นาฬิกา/โซนเวลาเลื่อน) ต้องไม่ทำให้ค้างอยู่ในช่วงพักเป็นชั่วโมง */
    #[Test]
    public function a_future_switch_time_does_not_freeze_the_cooldown(): void
    {
        $this->makeView(['ETH/USDT']);

        $bot = $this->bot('BTC/USDT', auto: true);
        $bot->update(['stats' => ['auto_pair_switched_at' => now()->addHours(7)->toDateTimeString()]]);

        $this->assertTrue($this->resolver->resolve($bot, $this->plan(), false)['switched']);
    }

    #[Test]
    public function a_delisted_pair_is_skipped_for_the_next_one(): void
    {
        // กฎข้อ 3 — แอดมินปิดคู่ระหว่างรอบได้ตลอด
        TradingPair::where('symbol', 'ETH/USDT')->update(['is_active' => false]);

        $this->makeView(['ETH/USDT', 'BTC/USDT']);
        $bot = $this->bot('SOL/USDT', auto: true);

        $result = $this->resolver->resolve($bot, $this->plan(), false);

        $this->assertTrue($result['switched']);
        $this->assertSame('BTC/USDT', $result['pair']);
    }

    #[Test]
    public function no_view_means_the_current_pair_stays(): void
    {
        $bot = $this->bot('BTC/USDT', auto: true);

        $result = $this->resolver->resolve($bot, $this->plan(), false);

        $this->assertFalse($result['switched']);
        $this->assertSame('BTC/USDT', $bot->fresh()->pair);
    }

    #[Test]
    public function a_corrupt_timestamp_does_not_break_the_bot(): void
    {
        $this->makeView(['ETH/USDT']);

        $bot = $this->bot('BTC/USDT', auto: true);
        $bot->update(['stats' => ['auto_pair_switched_at' => 'เมื่อวานตอนบ่ายๆ']]);

        $result = $this->resolver->resolve($bot, $this->plan(), false);

        $this->assertTrue($result['switched'], 'ค่าเสียในฐานข้อมูลต้องไม่ทำให้บอทหยุด');
    }

    #[Test]
    public function the_auto_pair_switch_survives_the_params_sanitiser(): void
    {
        /*
         * `sanitizeParams()` สร้างค่าที่บันทึกจากรายการ params ของกลยุทธ์เท่านั้น
         * คีย์ที่ไม่ได้ประกาศจะถูกตัดทิ้ง **เงียบๆ**
         *
         * ก่อนแก้: ผู้ใช้เปิด "ให้ AI เลือกเหรียญ" แล้วกดบันทึก หน้าเว็บขึ้นสำเร็จ
         * แต่ค่าไม่เคยลงฐานข้อมูล ตัวเลือกเหรียญจึงไม่ทำงานเลยสักครั้งและไม่มี
         * อะไรบอกว่าทำไม — เป็นรูปแบบความล้มเหลวเดียวกับที่โปรเจกต์นี้เจอซ้ำๆ
         */
        $clean = app(AiBotService::class)
            ->sanitizeParams('grid', ['auto_pair' => true, 'news_mode' => 'off']);

        $this->assertTrue($clean['auto_pair'], 'สวิตช์ให้ AI เลือกเหรียญถูกตัดทิ้ง');
        $this->assertSame('off', $clean['news_mode'], 'ทุกกลยุทธ์ต้องปิดด่านข่าวได้ด้วย');
    }

    /**
     * สวิตช์ข่าวรุ่นเดิม (news_filter) → news_mode — บอทที่เจ้าของเคยปิดข่าวต้องยังปิดอยู่.
     */
    #[Test]
    public function the_legacy_news_switch_maps_onto_the_news_mode(): void
    {
        $bots = app(AiBotService::class);

        foreach (['grid', 'ai_signal', 'momentum'] as $strategy) {
            // ค่าที่ระบบเดิมเก็บ/แอปเก่าส่งมาได้ทุกรูปแบบ
            foreach ([false, 'false', '0', 0, 'off', 'no'] as $off) {
                $this->assertSame('off', $bots->sanitizeParams($strategy, ['news_filter' => $off])['news_mode'], "{$strategy}: ".var_export($off, true));
            }

            $this->assertSame('confirm_exit', $bots->sanitizeParams($strategy, ['news_filter' => true])['news_mode']);
            $this->assertSame('confirm_exit', $bots->sanitizeParams($strategy, [])['news_mode'], 'ค่าปริยาย = แนะนำ');
            // ระบบเดิม null = "ใช้ค่าปริยาย" (ข่าวเปิด) ไม่ใช่ปิดข่าว
            $this->assertSame('confirm_exit', $bots->sanitizeParams($strategy, ['news_filter' => null])['news_mode'], 'null ต้องไม่กลายเป็นปิดข่าว');
            $this->assertSame('immediate_exit', $bots->sanitizeParams($strategy, ['news_filter' => false, 'news_mode' => 'immediate_exit'])['news_mode'], 'ค่าใหม่ที่ส่งมาเองชนะเสมอ');
            $this->assertSame('off', $bots->sanitizeParams($strategy, ['news_filter' => false, 'news_mode' => null])['news_mode'], 'news_mode ว่าง = ยังไม่เลือก ใช้ค่าเดิม');
            $this->assertSame('off', $bots->sanitizeParams($strategy, ['news_filter' => false, 'news_mode' => ''])['news_mode'], 'ข้อความว่างก็คือยังไม่เลือก');
            $this->assertSame('confirm_exit', $bots->sanitizeParams($strategy, ['news_mode' => 'มั่ว'])['news_mode'], 'ค่าที่ไม่รู้จัก = ปริยาย');
        }
    }

    /**
     * กลยุทธ์ประกาศช่องชื่อเดียวกับช่องร่วม = ค่าของกลยุทธ์ชนะ (มันรู้ช่วงค่าที่ถูกของตัวเองดีกว่า).
     *
     * ตอนนี้ไม่มีกลยุทธ์ไหนใช้ชื่อซ้ำ (เดิมคือ news_filter ของ ai_signal) — จำลองขึ้นมาเพื่อยึดกติกาไว้
     * ไม่งั้นวันที่มีคนเพิ่มช่องชื่อซ้ำ ช่องร่วมจะทับช่วงค่าของกลยุทธ์เงียบๆ
     */
    #[Test]
    public function a_strategy_owned_param_still_wins_over_the_common_one(): void
    {
        $strategies = config('aibot.strategies');

        foreach ($strategies as &$strategy) {
            if ($strategy['code'] === 'grid') {
                $strategy['params'][] = ['key' => 'macro_ema', 'label' => 'x', 'type' => 'select', 'default' => '200', 'options' => ['200']];
            }
        }
        unset($strategy);
        config(['aibot.strategies' => $strategies]);

        $bots = app(AiBotService::class);

        $this->assertSame('200', $bots->sanitizeParams('grid', [])['macro_ema'], 'ค่าปริยายของกลยุทธ์ต้องชนะ');
        $this->assertSame('200', $bots->sanitizeParams('grid', ['macro_ema' => '50'])['macro_ema'], 'ช่วงค่าของกลยุทธ์ต้องชนะ');
        $this->assertSame('50', $bots->sanitizeParams('momentum', [])['macro_ema'], 'กลยุทธ์อื่นยังใช้ช่องร่วม');
    }

    /**
     * ตัวเลือกที่ส่งมาเป็นตัวเลขต้องไม่ถูกรีเซ็ตเงียบๆ (JSON / --sweep ส่ง 200 ไม่ใช่ '200').
     *
     * รีวิว 2026-09-23: เดิมเทียบแบบเข้ม macro_ema = 200 กลายเป็น '50' — sweep EMA 50/100/200
     * ของ aibot:backtest ได้ผลเท่ากันสามแถวเพราะรัน 50 ทั้งหมด
     */
    #[Test]
    public function a_numeric_choice_is_matched_to_its_declared_option(): void
    {
        $bots = app(AiBotService::class);

        $this->assertSame('200', $bots->sanitizeParams('grid', ['macro_ema' => 200])['macro_ema']);
        $this->assertSame('100', $bots->sanitizeParams('grid', ['macro_ema' => 100.0])['macro_ema']);
        $this->assertSame('200', $bots->sanitizeParams('grid', ['macro_ema' => '200'])['macro_ema']);
        // ตัวเลขที่เขียนต่างรูปก็คือตัวเลือกเดียวกัน
        $this->assertSame('100', $bots->sanitizeParams('grid', ['macro_ema' => '100.0'])['macro_ema']);
        $this->assertSame('200', $bots->sanitizeParams('grid', ['macro_ema' => '0200'])['macro_ema']);
        $this->assertSame('50', $bots->sanitizeParams('grid', ['macro_ema' => 75])['macro_ema'], 'ค่าที่ไม่มีในตัวเลือก = ปริยาย');
        $this->assertSame('50', $bots->sanitizeParams('grid', ['macro_ema' => 200.5])['macro_ema'], 'ใกล้เคียงไม่นับ');
        $this->assertSame('50', $bots->sanitizeParams('grid', ['macro_ema' => true])['macro_ema'], 'bool ไม่ใช่ตัวเลือก');
        $this->assertSame('50', $bots->sanitizeParams('grid', ['macro_ema' => ['200']])['macro_ema'], 'ค่าไม่ใช่ scalar = ปริยาย');
    }

    // ── ตัวช่วย ───────────────────────────────────────────────────────────────

    private function bot(string $pair, bool $auto): AiBotConfig
    {
        return AiBotConfig::create([
            'wallet_address' => '0x'.str_repeat('a', 40),
            'name' => 'ทดสอบ',
            'pair' => $pair,
            'strategy' => 'ai_signal',
            'timeframe' => '1h',
            'mode' => 'demo',
            'status' => 'running',
            'params' => $auto ? ['auto_pair' => true] : [],
        ]);
    }

    private function plan(string $tier = 'vip'): AiBotPlan
    {
        return new AiBotPlan(['code' => $tier, 'name' => $tier, 'tier' => $tier]);
    }

    private function makeView(array $shortlist): AiMarketView
    {
        return AiMarketView::create([
            'scope' => AiMarketView::SCOPE_STRATEGIC,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'regime' => 'neutral',
            'confidence' => 0.9,
            'size_multiplier' => 1.0,
            'coins' => [],
            'shortlist' => $shortlist,
            'expires_at' => now()->addHour(),
        ]);
    }

    private function pair(string $base): void
    {
        $baseToken = Token::firstOrCreate(
            ['symbol' => $base, 'chain_id' => $this->chain->id],
            [
                'name' => $base,
                'decimals' => 18,
                'is_active' => true,
                'contract_address' => '0x'.substr(hash('sha256', $base), 0, 40),
            ],
        );

        $quoteToken = Token::firstOrCreate(
            ['symbol' => 'USDT', 'chain_id' => $this->chain->id],
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
                'chain_id' => $this->chain->id,
                'is_active' => true,
            ],
        );
    }
}
