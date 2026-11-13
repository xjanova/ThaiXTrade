<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\Custom\RuleEvaluator;
use App\Services\AiBot\Custom\RuleSet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — บล็อกเงื่อนไขของโหมด "วางกลยุทธ์เอง" ต้องตอบถูกบนตลาดจำลองที่รู้คำตอบ.
 *
 * ตัวประเมินนี้คือสิ่งที่แปลกฎของผู้ใช้เป็นการซื้อขาย — บล็อกที่ตอบ "จริง" ผิดจังหวะคือบอทที่เข้าไม้
 * ตอนที่เจ้าของเงินสั่งไว้ว่าไม่ให้เข้า ทุกบล็อกจึงถูกทดสอบทั้งฝั่งที่ต้องจริง ฝั่งที่ต้องเท็จ
 * และฝั่งที่ "ยังตอบไม่ได้" (ข้อมูลไม่พอ ต้องเป็น null ไม่ใช่ false หรือ true)
 *
 * Developed by Xman Studio.
 */
class CustomRuleEvaluatorTest extends TestCase
{
    /** @param list<float> $closes */
    private function candles(array $closes, float $volume = 100.0): array
    {
        $candles = [];

        foreach ($closes as $i => $close) {
            $candles[] = [
                'time' => 1_700_000_000_000 + $i * 3_600_000,
                'open' => $close,
                'high' => $close * 1.002,
                'low' => $close * 0.998,
                'close' => $close,
                'volume' => $volume,
            ];
        }

        return $candles;
    }

    /** นิ่งที่ 100 แล้วขยับทีละ $step ต่อแท่ง อีก $bars แท่ง */
    private function flatThen(int $flat, int $bars, float $step): array
    {
        $closes = array_fill(0, $flat, 100.0);

        for ($i = 1; $i <= $bars; $i++) {
            $closes[] = 100.0 + $i * $step;
        }

        return $this->candles($closes);
    }

    /** บล็อกในรูปที่เครื่องยนต์เห็นจริง (ผ่านตัวล้างค่าแล้ว) */
    private function block(array $block, string $side = 'entry'): array
    {
        $rules = RuleSet::normalize([$side => ['blocks' => [$block]]]);

        $this->assertArrayNotHasKey('invalid', $rules, 'บล็อกในเทสต์ต้องเป็นบล็อกที่ถูกต้อง');

        return $rules[$side]['blocks'][0];
    }

    private function ok(array $block, array $candles, array $context = []): ?bool
    {
        return RuleEvaluator::block($this->block($block), $candles, $context)['ok'];
    }

    // ── EMA ─────────────────────────────────────────────────────────────────

    #[Test]
    public function ema_cross_is_true_only_on_the_bar_it_crosses(): void
    {
        $block = ['type' => 'ema_cross', 'when' => 'crosses_above', 'fast' => 12, 'slow' => 26];

        $this->assertFalse($this->ok($block, $this->flatThen(60, 0, 2)), 'ตลาดนิ่งไม่มีการตัด');
        $this->assertTrue($this->ok($block, $this->flatThen(60, 1, 2)), 'แท่งแรกที่ขึ้น = ตัดขึ้น');
        $this->assertFalse($this->ok($block, $this->flatThen(60, 6, 2)), 'ขึ้นมาหลายแท่งแล้ว = ไม่ใช่แท่งที่ตัด (ไม่ไล่ราคา)');

        $down = ['type' => 'ema_cross', 'when' => 'crosses_below', 'fast' => 12, 'slow' => 26];
        $this->assertTrue($this->ok($down, $this->flatThen(60, 1, -2)));
        $this->assertFalse($this->ok($down, $this->flatThen(60, 1, 2)));
    }

    #[Test]
    public function ema_state_stays_true_for_the_whole_trend(): void
    {
        $above = ['type' => 'ema_cross', 'when' => 'is_above', 'fast' => 12, 'slow' => 26];
        $below = ['type' => 'ema_cross', 'when' => 'is_below', 'fast' => 12, 'slow' => 26];

        $this->assertTrue($this->ok($above, $this->flatThen(60, 6, 2)));
        $this->assertFalse($this->ok($below, $this->flatThen(60, 6, 2)));
        $this->assertTrue($this->ok($below, $this->flatThen(60, 6, -2)));
        $this->assertFalse($this->ok($above, $this->flatThen(60, 6, -2)));
    }

    #[Test]
    public function ema_cannot_answer_without_enough_candles(): void
    {
        $block = ['type' => 'ema_cross', 'when' => 'is_above', 'fast' => 12, 'slow' => 26];

        $this->assertNull($this->ok($block, $this->flatThen(20, 0, 0)));
    }

    // ── RSI ─────────────────────────────────────────────────────────────────

    #[Test]
    public function rsi_compares_against_the_level_in_the_chosen_direction(): void
    {
        $falling = $this->flatThen(40, 10, -1.5);
        $rising = $this->flatThen(40, 10, 1.5);

        $this->assertTrue($this->ok(['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30], $falling));
        $this->assertFalse($this->ok(['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 70], $falling));
        $this->assertTrue($this->ok(['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 70], $rising));
        $this->assertFalse($this->ok(['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30], $rising));
        $this->assertNull($this->ok(['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30], $this->flatThen(10, 0, 0)));
    }

    #[Test]
    public function rsi_reports_the_value_it_measured(): void
    {
        $result = RuleEvaluator::block($this->block(['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 70]), $this->flatThen(40, 10, 1.5));

        $this->assertEqualsWithDelta(100.0, $result['value'], 1e-6, 'ขึ้นทางเดียว = RSI 100');
        $this->assertSame('RSI(14) สูงกว่า 70 (ตอนนี้ 100)', $result['text']);
    }

    // ── กรอบราคา ────────────────────────────────────────────────────────────

    #[Test]
    public function channel_breakout_needs_a_close_beyond_the_previous_bars(): void
    {
        $up = ['type' => 'channel_breakout', 'side' => 'above_high', 'period' => 20, 'min_atr' => 0];
        $down = ['type' => 'channel_breakout', 'side' => 'below_low', 'period' => 20, 'min_atr' => 0];

        $this->assertFalse($this->ok($up, $this->flatThen(40, 0, 0)), 'ราคานิ่งอยู่ในกรอบ');
        $this->assertTrue($this->ok($up, $this->flatThen(40, 1, 5)));
        $this->assertFalse($this->ok($down, $this->flatThen(40, 1, 5)));
        $this->assertTrue($this->ok($down, $this->flatThen(40, 1, -5)));
        $this->assertNull($this->ok($up, $this->flatThen(12, 0, 0)));
    }

    #[Test]
    public function channel_breakout_can_demand_a_minimum_size_in_atr(): void
    {
        // ทะลุขอบบน (100.2) ไปแค่ 0.3 ขณะที่ ATR ≈ 0.4 → ขนาด < 1 ATR
        $marginal = $this->flatThen(40, 1, 0.5);

        $this->assertTrue($this->ok(['type' => 'channel_breakout', 'side' => 'above_high', 'period' => 20, 'min_atr' => 0], $marginal));
        $this->assertFalse($this->ok(['type' => 'channel_breakout', 'side' => 'above_high', 'period' => 20, 'min_atr' => 2], $marginal));
        $this->assertTrue($this->ok(['type' => 'channel_breakout', 'side' => 'above_high', 'period' => 20, 'min_atr' => 2], $this->flatThen(40, 1, 5)));
    }

    // ── ตัวกรอง ─────────────────────────────────────────────────────────────

    #[Test]
    public function atr_filter_measures_volatility_as_a_percent_of_price(): void
    {
        // แท่งกว้าง 0.4% ของราคาทุกแท่ง → ATR ≈ 0.4%
        $quiet = $this->flatThen(40, 0, 0);

        $this->assertTrue($this->ok(['type' => 'atr', 'op' => 'above', 'period' => 14, 'pct' => 0.3], $quiet));
        $this->assertFalse($this->ok(['type' => 'atr', 'op' => 'above', 'period' => 14, 'pct' => 0.5], $quiet));
        $this->assertTrue($this->ok(['type' => 'atr', 'op' => 'below', 'period' => 14, 'pct' => 0.5], $quiet));
        $this->assertNull($this->ok(['type' => 'atr', 'op' => 'above', 'period' => 14, 'pct' => 0.3], $this->flatThen(10, 0, 0)));
    }

    #[Test]
    public function volume_filter_compares_the_last_bar_with_its_average(): void
    {
        $candles = $this->flatThen(40, 0, 0);
        $candles[count($candles) - 1]['volume'] = 300.0;   // ค่าเฉลี่ย 20 แท่ง = 110 → 2.73 เท่า

        $this->assertTrue($this->ok(['type' => 'volume_ratio', 'op' => 'above', 'ratio' => 1.5, 'period' => 20], $candles));
        $this->assertFalse($this->ok(['type' => 'volume_ratio', 'op' => 'above', 'ratio' => 3, 'period' => 20], $candles));
        $this->assertTrue($this->ok(['type' => 'volume_ratio', 'op' => 'below', 'ratio' => 3, 'period' => 20], $candles));
        $this->assertNull($this->ok(['type' => 'volume_ratio', 'op' => 'above', 'ratio' => 1.5, 'period' => 20], $this->flatThen(10, 0, 0)));
        // ไม่มีวอลุ่มเลย (ค่าเฉลี่ย 0) = เทียบไม่ได้ ไม่ใช่ "ผ่าน"
        $this->assertNull($this->ok(['type' => 'volume_ratio', 'op' => 'below', 'ratio' => 1.5, 'period' => 20], $this->candles(array_fill(0, 40, 100.0), 0.0)));
    }

    #[Test]
    public function price_change_looks_back_the_configured_number_of_bars(): void
    {
        $up = $this->flatThen(40, 10, 0.5);     // +5% ใน 10 แท่ง
        $down = $this->flatThen(40, 10, -0.5);  // −5% ใน 10 แท่ง

        $this->assertTrue($this->ok(['type' => 'price_change', 'op' => 'above', 'bars' => 10, 'pct' => 3], $up));
        $this->assertFalse($this->ok(['type' => 'price_change', 'op' => 'above', 'bars' => 10, 'pct' => 8], $up));
        $this->assertTrue($this->ok(['type' => 'price_change', 'op' => 'below', 'bars' => 10, 'pct' => -3], $down));
        $this->assertFalse($this->ok(['type' => 'price_change', 'op' => 'below', 'bars' => 10, 'pct' => -3], $up));
        // ข้อมูลไม่ถึง N แท่ง = ยังไม่รู้ (ตัวชี้วัดเดิมคืน 0 ซึ่งจะถูกอ่านว่า "ไม่ขยับ")
        $this->assertNull($this->ok(['type' => 'price_change', 'op' => 'below', 'bars' => 100, 'pct' => 3], $up));
    }

    #[Test]
    public function trend_block_reads_price_against_the_long_ema(): void
    {
        $rising = $this->flatThen(260, 20, 1.0);
        $falling = $this->flatThen(260, 20, -1.0);

        $this->assertTrue($this->ok(['type' => 'trend', 'side' => 'above', 'period' => 200], $rising));
        $this->assertFalse($this->ok(['type' => 'trend', 'side' => 'below', 'period' => 200], $rising));
        $this->assertTrue($this->ok(['type' => 'trend', 'side' => 'below', 'period' => 200], $falling));
        $this->assertFalse($this->ok(['type' => 'trend', 'side' => 'above', 'period' => 200], $falling));
        // ยังไม่มี EMA ให้เทียบ = ไม่รู้ทิศ ไม่ใช่ "ไม่ใช่ขาขึ้น"
        $this->assertNull($this->ok(['type' => 'trend', 'side' => 'above', 'period' => 200], $this->flatThen(30, 5, 1.0)));
    }

    #[Test]
    public function scheduled_buy_is_due_once_enough_bars_have_passed(): void
    {
        $block = ['type' => 'interval', 'bars' => 6];
        $candles = $this->flatThen(40, 0, 0);

        $this->assertTrue($this->ok($block, $candles), 'ยังไม่เคยซื้อ = ถึงรอบแล้ว');
        $this->assertFalse($this->ok($block, $candles, ['bars_since_entry' => 5]));
        $this->assertTrue($this->ok($block, $candles, ['bars_since_entry' => 6]));
    }

    #[Test]
    public function an_unknown_block_never_counts_as_met(): void
    {
        $this->assertNull(RuleEvaluator::block(['type' => 'whatever'], $this->flatThen(40, 0, 0))['ok']);
        $this->assertNull(RuleEvaluator::block([], $this->flatThen(40, 0, 0))['ok']);
    }

    #[Test]
    public function no_block_description_contains_the_list_separator(): void
    {
        $candles = $this->flatThen(260, 5, 1.0);

        foreach (RuleSet::blockSpecs() as $type => $spec) {
            foreach ($this->variants($spec) as $block) {
                $text = RuleEvaluator::block($this->block($block, $spec['use'][0]), $candles)['text'];

                $this->assertNotSame('', $text, $type);
                $this->assertStringNotContainsString(' · ', $text, "{$type}: ตัวคั่นรายการของ BotRunner::localizeReason");
            }
        }
    }

    // ── รวมเงื่อนไข ─────────────────────────────────────────────────────────

    #[Test]
    public function all_of_needs_every_block_and_lists_what_is_missing(): void
    {
        $candles = $this->flatThen(60, 1, 2);
        $group = RuleSet::normalize(['entry' => ['match' => 'all', 'blocks' => [
            ['type' => 'ema_cross', 'when' => 'crosses_above', 'fast' => 12, 'slow' => 26],
            ['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30],
        ]]])['entry'];

        $result = RuleEvaluator::evaluate($group, $candles);

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $result['met']);
        $this->assertCount(1, $result['unmet']);
        $this->assertStringContainsString('RSI(14) ต่ำกว่า 30', $result['unmet'][0]);

        $group['blocks'][1]['op'] = 'above';
        $group['blocks'][1]['level'] = 50.0;
        $this->assertTrue(RuleEvaluator::evaluate($group, $candles)['ok']);
    }

    #[Test]
    public function any_of_needs_just_one_block(): void
    {
        $candles = $this->flatThen(60, 1, 2);
        $group = RuleSet::normalize(['entry' => ['match' => 'any', 'blocks' => [
            ['type' => 'ema_cross', 'when' => 'crosses_above', 'fast' => 12, 'slow' => 26],
            ['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30],
        ]]])['entry'];

        $this->assertTrue(RuleEvaluator::evaluate($group, $candles)['ok']);

        $group['blocks'][0]['when'] = 'crosses_below';
        $this->assertFalse(RuleEvaluator::evaluate($group, $candles)['ok']);
    }

    #[Test]
    public function a_block_that_cannot_be_computed_blocks_all_of_but_not_a_true_any_of(): void
    {
        // 40 แท่ง: RSI คำนวณได้ แต่เส้นเทรนด์ EMA 200 ยังไม่มี
        $candles = $this->flatThen(30, 10, 1.5);
        $blocks = [
            ['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 70],
            ['type' => 'trend', 'side' => 'above', 'period' => 200],
        ];

        $all = RuleSet::normalize(['entry' => ['match' => 'all', 'blocks' => $blocks]])['entry'];
        $any = RuleSet::normalize(['entry' => ['match' => 'any', 'blocks' => $blocks]])['entry'];

        $this->assertNull(RuleEvaluator::evaluate($all, $candles)['ok'], 'ครบทุกข้อ: ข้อที่ยังไม่รู้ต้องไม่ถูกนับว่าผ่าน');
        $this->assertTrue(RuleEvaluator::evaluate($any, $candles)['ok']);

        $any['blocks'][0]['op'] = 'below';
        $any['blocks'][0]['level'] = 30.0;
        $this->assertNull(RuleEvaluator::evaluate($any, $candles)['ok'], 'ข้อใดข้อหนึ่ง: ไม่มีข้อไหนจริงและยังมีข้อที่ไม่รู้ = ยังตัดสินไม่ได้');
    }

    #[Test]
    public function an_empty_group_is_never_met(): void
    {
        foreach (['all', 'any'] as $match) {
            $this->assertFalse(RuleEvaluator::evaluate(['match' => $match, 'blocks' => []], $this->flatThen(40, 0, 0))['ok']);
        }
    }

    /** ทุกตัวเลือกของช่อง select ตัวแรกของบล็อก (ช่องอื่นใช้ค่าปริยาย) */
    private function variants(array $spec): array
    {
        $select = collect($spec['fields'])->firstWhere('type', 'select');

        if (! $select) {
            return [['type' => $spec['type']]];
        }

        return array_map(fn (string $option) => ['type' => $spec['type'], $select['key'] => $option], $select['options']);
    }
}
