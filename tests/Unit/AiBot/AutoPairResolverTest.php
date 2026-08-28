<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiMarketView;
use App\Models\Chain;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\AiBot\Analyst\AutoPairResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $clean = app(\App\Services\AiBotService::class)
            ->sanitizeParams('grid', ['auto_pair' => true, 'news_filter' => false]);

        $this->assertTrue($clean['auto_pair'], 'สวิตช์ให้ AI เลือกเหรียญถูกตัดทิ้ง');
        $this->assertFalse($clean['news_filter'], 'กลยุทธ์ที่ไม่ได้ประกาศ news_filter ต้องปิดด่านข่าวได้ด้วย');
    }

    #[Test]
    public function a_strategy_owned_param_still_wins_over_the_common_one(): void
    {
        // กลยุทธ์รู้ช่วงค่าที่ถูกต้องของตัวเองดีกว่า รายการร่วมต้องไม่ไปทับ
        $clean = app(\App\Services\AiBotService::class)->sanitizeParams('ai_signal', ['news_filter' => false]);

        $this->assertFalse($clean['news_filter']);
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
