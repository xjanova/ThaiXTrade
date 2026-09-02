<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiMarketView;
use App\Services\AiBot\Analyst\AiViewGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ขอบเขตอำนาจของ AI ต่อการตัดสินใจเรื่องเงิน.
 *
 * เจ้าของสั่ง 28 ส.ค. 2026 ให้ OpenAI เข้าร่วมตัดสินใจเข้าไม้/ออกไม้ "เป็นรอบ"
 * ชั้นนี้คือจุดเดียวที่มุมมองของ AI ถูกแปลงเป็นการกระทำจริง จึงต้องคุมให้แน่น
 * ที่สุดในระบบ — คำตอบ LLM ไม่คงที่ ถ้าขอบเขตหลวม ผลลัพธ์จะไม่มีทางย้อนตรวจได้
 *
 * สิ่งที่ต้องยืน:
 *   1. ไม่มีมุมมอง / มั่นใจไม่พอ → ต้องไม่แตะอะไรเลย (ถอยไปใช้กฎล้วน)
 *   2. ทุกตัวเลขถูกบีบอยู่ในเพดานของ config เสมอ
 *   3. การสั่งขายต้องยากกว่าการสั่งห้ามซื้อ (การขายมีต้นทุนจริง 0.36%)
 *   4. แพลนต่ำต้องไม่ได้รอบสั้น
 *
 * Developed by Xman Studio.
 */
class AiViewGateTest extends TestCase
{
    use RefreshDatabase;

    private AiViewGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('aibot_analyst.enabled', true);
        config()->set('aibot_analyst.shadow_mode', false);

        $this->gate = app(AiViewGate::class);
    }

    #[Test]
    public function no_view_leaves_the_rules_untouched(): void
    {
        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), false);

        $this->assertFalse($result['applied']);
        $this->assertSame(1.0, $result['size_multiplier']);
        $this->assertFalse($result['block_entry']);
        $this->assertFalse($result['force_exit']);
        $this->assertSame(0.0, $result['confidence_relief']);
    }

    #[Test]
    public function disabled_switch_ignores_even_a_strong_view(): void
    {
        config()->set('aibot_analyst.enabled', false);

        $this->makeView(['BTC' => ['score' => -1, 'stance' => 'avoid']], confidence: 0.99);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), false);

        $this->assertFalse($result['applied']);
        $this->assertFalse($result['block_entry']);
    }

    #[Test]
    public function shadow_mode_records_but_never_touches_a_trade(): void
    {
        /*
         * โหมดเงาแยกสองเรื่องที่คนมักเหมารวม: "AI คิดหรือเปล่า" กับ "AI ใช้เงินได้ไหม"
         * เปิดโหมดนี้แล้วรอบวิเคราะห์ยังเดินและบันทึกครบ แต่กฎล้วนเป็นคนตัดสินทั้งหมด
         */
        config()->set('aibot_analyst.shadow_mode', true);

        $this->makeView(['BTC' => ['score' => -1, 'stance' => 'exit', 'why' => 'ข่าวแฮก']], confidence: 0.99);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), true);

        $this->assertFalse($result['applied']);
        $this->assertFalse($result['force_exit'], 'โหมดเงาต้องสั่งขายไม่ได้แม้มั่นใจเต็ม');
        $this->assertFalse($result['block_entry']);
        $this->assertSame(1.0, $result['size_multiplier']);
    }

    #[Test]
    public function low_confidence_gets_no_vote(): void
    {
        // 0.40 < min_confidence 0.55 — AI เองยังไม่มั่นใจ
        $this->makeView(['BTC' => ['score' => -1, 'stance' => 'avoid']], confidence: 0.40);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), false);

        $this->assertFalse($result['applied']);
        $this->assertFalse($result['block_entry'], 'AI ที่ไม่มั่นใจต้องห้ามเข้าไม้ไม่ได้');
    }

    #[Test]
    public function avoid_stance_blocks_new_entries(): void
    {
        $this->makeView(['BTC' => ['score' => -0.8, 'stance' => 'avoid', 'why' => 'ข่าวกำกับดูแลกดดัน']]);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), false);

        $this->assertTrue($result['applied']);
        $this->assertTrue($result['block_entry']);
        $this->assertStringContainsString('ข่าวกำกับดูแลกดดัน', implode(' ', $result['reasons']));
    }

    #[Test]
    public function exit_needs_the_higher_confidence_bar(): void
    {
        // 0.80 ≥ exit_confidence 0.75 → ปิดได้
        $this->makeView(['BTC' => ['score' => -0.9, 'stance' => 'exit', 'why' => 'เจอข่าวแฮก']], confidence: 0.80);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), true);

        $this->assertTrue($result['force_exit']);
    }

    #[Test]
    public function mid_confidence_exit_only_blocks_entry(): void
    {
        /*
         * 0.60 อยู่ระหว่าง min_confidence (0.55) กับ exit_confidence (0.75)
         *
         * กรณีนี้สำคัญที่สุดในไฟล์: การขายมีต้นทุนจริง 0.36% ต่อรอบ ถ้าปล่อยให้
         * AI สั่งขายด้วยความมั่นใจระดับกลาง ค่าธรรมเนียมจะกินพอร์ตมากกว่าที่
         * มันช่วยหนีทัน — ซึ่งพิสูจน์แล้วกับ scalping ที่ขาดทุนทั้งหมดจากต้นทุน
         */
        $this->makeView(['BTC' => ['score' => -0.5, 'stance' => 'exit']], confidence: 0.60);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), true);

        $this->assertFalse($result['force_exit'], 'มั่นใจไม่ถึงเกณฑ์แล้วต้องไม่สั่งขาย');
        $this->assertTrue($result['block_entry']);
    }

    #[Test]
    public function confidence_relief_is_capped(): void
    {
        // คะแนนเต็ม + มั่นใจเต็ม = เคสที่ผ่อนได้มากที่สุดเท่าที่เป็นไปได้
        $this->makeView(['BTC' => ['score' => 1.0, 'stance' => 'buy']], confidence: 1.0);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), false);

        $max = (float) config('aibot_analyst.limits.confidence_relief_max');

        $this->assertGreaterThan(0, $result['confidence_relief']);
        $this->assertLessThanOrEqual($max, $result['confidence_relief']);
    }

    #[Test]
    public function holding_a_position_gets_no_entry_relief(): void
    {
        $this->makeView(['BTC' => ['score' => 1.0, 'stance' => 'buy']], confidence: 1.0);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), true);

        $this->assertSame(0.0, $result['confidence_relief']);
    }

    #[Test]
    public function coin_without_an_opinion_is_left_alone(): void
    {
        $this->makeView(['ETH' => ['score' => -1.0, 'stance' => 'avoid']]);

        // บอทเทรด BTC แต่ AI พูดถึงแต่ ETH
        $result = $this->gate->evaluate($this->bot('BTC/USDT'), $this->plan('vip'), false);

        $this->assertTrue($result['applied']);
        $this->assertFalse($result['block_entry']);
        $this->assertSame(0.0, $result['confidence_relief']);
    }

    #[Test]
    public function low_tier_plan_cannot_reach_the_tactical_round(): void
    {
        $this->makeView(['BTC' => ['score' => 0.9, 'stance' => 'buy']], scope: AiMarketView::SCOPE_TACTICAL);

        $free = $this->gate->viewFor($this->plan('free'));
        $vip = $this->gate->viewFor($this->plan('vip'));

        $this->assertNull($free, 'แพลนฟรีต้องเข้าไม่ถึงรอบสั้น');
        $this->assertNotNull($vip);
    }

    #[Test]
    public function high_tier_falls_back_to_the_strategic_round(): void
    {
        $this->makeView(['BTC' => ['score' => 0.5, 'stance' => 'buy']], scope: AiMarketView::SCOPE_STRATEGIC);

        $view = $this->gate->viewFor($this->plan('vip'));

        $this->assertNotNull($view);
        $this->assertSame(AiMarketView::SCOPE_STRATEGIC, $view->scope);
    }

    #[Test]
    public function an_expired_view_is_never_used(): void
    {
        /*
         * เคสนี้คือกันความล้มเหลวเงียบแบบเดียวกับที่เคยเจอ: cron ตายแล้วไม่มีอะไรฟ้อง
         * ถ้ามุมมองเก่ายังถูกใช้ต่อไปเรื่อยๆ บอทจะเดินตามข่าวเมื่อวาน ซึ่งอันตราย
         * กว่าการไม่มีมุมมองเลย (ไม่มี = ถอยไปใช้กฎล้วน ซึ่งปลอดภัยกว่า)
         */
        $this->makeView(['BTC' => ['score' => 1.0, 'stance' => 'buy']], expired: true);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), false);

        $this->assertFalse($result['applied']);
    }

    // ── calibration: ประวัติจริงชนะความมั่นใจที่รายงานเอง ─────────────────────

    /**
     * ⭐ AI มั่นใจ 0.95 แต่ประวัติ exit ที่ระดับนี้ถูกแค่ 30% → ห้ามขาย (แค่ห้ามเติมไม้).
     *
     * ออดิท 2 ก.ย. 2026: ความมั่นใจ ≥ 0.8 ทายถูก 35% — ตัวเลขที่ AI รายงานเองกลับหัว
     */
    #[Test]
    public function a_bad_exit_track_record_overrides_high_reported_confidence(): void
    {
        $this->seedCalibration(['exit' => ['high' => ['n' => 20, 'hit_rate' => 0.30, 'avg_move_bps' => 40.0]]]);
        $this->makeView(['BTC' => ['score' => -0.9, 'stance' => 'exit', 'why' => 'ข่าวร้าย']], confidence: 0.95);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), true);

        $this->assertFalse($result['force_exit']);
        $this->assertTrue($result['block_entry']);
        $this->assertStringContainsString('ประวัติ', implode(' ', $result['reasons']));
    }

    /** ประวัติ exit ดี → ขายได้แม้ความมั่นใจต่ำกว่าเกณฑ์ดิบ 0.75 */
    #[Test]
    public function a_good_exit_track_record_allows_the_exit_below_the_raw_bar(): void
    {
        $this->seedCalibration(['exit' => ['mid' => ['n' => 20, 'hit_rate' => 0.70, 'avg_move_bps' => -60.0]]]);
        $this->makeView(['BTC' => ['score' => -0.6, 'stance' => 'exit']], confidence: 0.65);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), true);

        $this->assertTrue($result['force_exit']);
    }

    /** ประวัติ buy แพ้มากกว่าชนะ → ไม่ผ่อนเกณฑ์ให้กลยุทธ์ */
    #[Test]
    public function buy_relief_is_withheld_when_history_says_buys_lose(): void
    {
        $this->seedCalibration(['buy' => ['high' => ['n' => 21, 'hit_rate' => 0.38, 'avg_move_bps' => -44.0]]]);
        $this->makeView(['BTC' => ['score' => 1.0, 'stance' => 'buy']], confidence: 0.9);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), false);

        $this->assertSame(0.0, $result['confidence_relief']);
        $this->assertStringContainsString('ไม่ผ่อนเกณฑ์', implode(' ', $result['reasons']));
    }

    /** ตัวอย่างไม่พอ → ใช้เกณฑ์ดิบตามเดิม (พฤติกรรมก่อนมี calibration) */
    #[Test]
    public function thin_history_falls_back_to_the_raw_thresholds(): void
    {
        $this->seedCalibration(['exit' => ['high' => ['n' => 3, 'hit_rate' => 0.0, 'avg_move_bps' => 90.0]]]);
        $this->makeView(['BTC' => ['score' => -0.9, 'stance' => 'exit']], confidence: 0.9);

        $result = $this->gate->evaluate($this->bot(), $this->plan('vip'), true);

        $this->assertTrue($result['force_exit'], '3 ตัวอย่างคือความบังเอิญ — ยังต้องเชื่อเกณฑ์ดิบ');
    }

    /** ตารางที่ AnalystCalibration ให้ค่า — เขียนตรงๆ ลง cache ไม่ต้องดึงราคา */
    private function seedCalibration(array $buckets): void
    {
        $table = [];
        foreach (['buy', 'avoid', 'exit'] as $stance) {
            foreach (['low', 'mid', 'high'] as $bucket) {
                $table[$stance][$bucket] = $buckets[$stance][$bucket] ?? ['n' => 0, 'hit_rate' => null, 'avg_move_bps' => null];
            }
        }

        \Illuminate\Support\Facades\Cache::put(\App\Services\AiBot\Analyst\AnalystCalibration::CACHE_KEY, [
            'built_at' => now()->toIso8601String(), 'days' => 14, 'horizon' => 4, 'samples' => 40, 'brier' => null, 'buckets' => $table,
        ], now()->addHour());
    }

    // ── ตัวช่วย ───────────────────────────────────────────────────────────────

    private function bot(string $pair = 'BTC/USDT'): AiBotConfig
    {
        return new AiBotConfig([
            'wallet_address' => '0x'.str_repeat('a', 40),
            'pair' => $pair,
            'strategy' => 'ai_signal',
            'timeframe' => '1h',
            'mode' => 'demo',
        ]);
    }

    private function plan(string $tier): AiBotPlan
    {
        return new AiBotPlan(['code' => $tier, 'name' => $tier, 'tier' => $tier]);
    }

    private function makeView(
        array $coins,
        float $confidence = 0.9,
        string $scope = AiMarketView::SCOPE_STRATEGIC,
        bool $expired = false,
    ): AiMarketView {
        return AiMarketView::create([
            'scope' => $scope,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'regime' => 'neutral',
            'confidence' => $confidence,
            'size_multiplier' => 1.0,
            'coins' => $coins,
            'shortlist' => [],
            'summary' => '',
            'expires_at' => $expired ? now()->subMinute() : now()->addHour(),
        ]);
    }
}
