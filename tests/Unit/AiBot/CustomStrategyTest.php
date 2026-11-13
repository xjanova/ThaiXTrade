<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\Backtest\BacktestEngine;
use App\Services\AiBot\Signal;
use App\Services\AiBot\Strategies\CustomStrategy;
use App\Services\AiBotService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — โหมด "วางกลยุทธ์เอง": บอทต้องทำตามกฎของผู้ใช้ ไม่มากไม่น้อยกว่านั้น.
 *
 * ครึ่งแรกทดสอบคลาสกลยุทธ์ตรงๆ (เข้า · ออกทุกแบบ · ซื้อเพิ่ม · พักหลังปิดไม้)
 * ครึ่งหลังเดินผ่าน BacktestEngine ตัวจริง — ตัวเดียวกับที่หน้าตัวสร้างกฎใช้ทดสอบย้อนหลัง —
 * เพื่อพิสูจน์ว่าเปลี่ยนกฎแล้วผลเปลี่ยน และกรอบความเสี่ยงเดิม (take profit / stop loss / ทุนสูงสุด) ยังคุมอยู่
 *
 * Developed by Xman Studio.
 */
class CustomStrategyTest extends TestCase
{
    private CustomStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aibot_risk.demo.starting_balance' => 10000.0,
            'aibot_risk.demo.fee_rate' => 0.1,
            'aibot_risk.demo.slippage_bps' => 8,
        ]);

        $this->strategy = new CustomStrategy();
    }

    // ── ตัวช่วย ──────────────────────────────────────────────────────────────

    /** @param list<float> $closes */
    private function candles(array $closes): array
    {
        $out = [];

        foreach ($closes as $i => $close) {
            $out[] = [
                'time' => 1_700_000_000_000 + $i * 3_600_000,
                'open' => $close * 0.999,
                'high' => $close * 1.002,
                'low' => $close * 0.998,
                'close' => $close,
                'volume' => 1000.0,
            ];
        }

        return $out;
    }

    /** นิ่งที่ 100 จำนวน $flat แท่ง แล้วต่อด้วยราคาที่กำหนด */
    private function flatThen(int $flat, array $then = []): array
    {
        return $this->candles(array_merge(array_fill(0, $flat, 100.0), $then));
    }

    /** พารามิเตอร์ในรูปที่เครื่องยนต์ส่งให้กลยุทธ์จริง (ผ่านตัวล้างค่า) */
    private function params(array $entry, array $exit = [], array $overrides = [], string $match = 'all'): array
    {
        return app(AiBotService::class)->sanitizeParams('custom', $overrides + [
            'rules' => [
                'entry' => ['match' => $match, 'blocks' => $entry],
                'exit' => ['match' => 'any', 'blocks' => $exit],
            ],
        ]) + array_filter($overrides, fn ($key) => str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);
    }

    /** เงื่อนไขเข้าที่เป็นจริงเสมอเมื่อยังไม่เคยซื้อ */
    private const ALWAYS = [['type' => 'interval', 'bars' => 1]];

    private const POSITION = ['qty' => 1.0, 'entry' => 100.18, 'entry_market' => 100.0, 'bars_held' => 5];

    // ── เข้าไม้ ──────────────────────────────────────────────────────────────

    #[Test]
    public function it_refuses_to_trade_without_an_entry_condition(): void
    {
        $signal = $this->strategy->decide($this->flatThen(60), app(AiBotService::class)->sanitizeParams('custom', []), null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringContainsString('ยังไม่ได้ตั้งเงื่อนไขเข้าไม้', $signal->reason);
    }

    #[Test]
    public function it_refuses_to_trade_on_rules_it_cannot_fully_read(): void
    {
        // เงื่อนไขข้อแรกเป็นจริงเสมอ — ถ้าเครื่องยนต์ทิ้งข้อที่อ่านไม่ออกแล้วเดินต่อ มันจะซื้อ
        $params = $this->params(array_merge(self::ALWAYS, [['type' => 'block_removed_in_a_later_release']]));

        foreach ([null, self::POSITION] as $position) {
            $signal = $this->strategy->decide($this->flatThen(60), $params, $position);

            $this->assertSame(Signal::HOLD, $signal->action);
            $this->assertStringContainsString('ระบบไม่รองรับแล้ว', $signal->reason);
        }
    }

    #[Test]
    public function it_buys_when_every_entry_condition_is_met_and_says_which(): void
    {
        $params = $this->params([
            ['type' => 'price_change', 'op' => 'above', 'bars' => 5, 'pct' => 3],
            ['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 60],
        ]);

        $signal = $this->strategy->decide($this->flatThen(60, [101, 102, 103, 104, 105]), $params, null);

        $this->assertSame(Signal::BUY, $signal->action);
        $this->assertStringStartsWith('เงื่อนไขเข้าเป็นจริง: ', $signal->reason);
        $this->assertStringContainsString('ราคาเปลี่ยนใน 5 แท่งมากกว่า 3%', $signal->reason);
        $this->assertCount(2, $signal->meta['entry']);
    }

    #[Test]
    public function it_waits_and_names_the_unmet_condition(): void
    {
        $params = $this->params([
            ['type' => 'price_change', 'op' => 'above', 'bars' => 5, 'pct' => 3],
            ['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30],
        ]);

        $signal = $this->strategy->decide($this->flatThen(60, [101, 102, 103, 104, 105]), $params, null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringStartsWith('ยังไม่เข้าไม้ — รอ: RSI(14) ต่ำกว่า 30', $signal->reason);
    }

    #[Test]
    public function any_of_enters_on_a_single_met_condition(): void
    {
        $blocks = [
            ['type' => 'price_change', 'op' => 'above', 'bars' => 5, 'pct' => 3],
            ['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 30],
        ];
        $market = $this->flatThen(60, [101, 102, 103, 104, 105]);

        $this->assertSame(Signal::HOLD, $this->strategy->decide($market, $this->params($blocks, match: 'all'), null)->action);
        $this->assertSame(Signal::BUY, $this->strategy->decide($market, $this->params($blocks, match: 'any'), null)->action);
    }

    #[Test]
    public function it_holds_and_explains_when_candles_are_too_few(): void
    {
        $params = $this->params([['type' => 'trend', 'side' => 'above', 'period' => 200]]);

        $signal = $this->strategy->decide($this->flatThen(60), $params, null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringContainsString('ข้อมูลแท่งเทียนยังไม่พอ', $signal->reason);
        $this->assertSame(350, $this->strategy->minCandles(['rules' => $params['rules']]));
    }

    #[Test]
    public function it_does_not_add_to_a_position_unless_the_owner_turned_that_on(): void
    {
        $market = $this->flatThen(60);

        $off = $this->strategy->decide($market, $this->params(self::ALWAYS), self::POSITION);
        $on = $this->strategy->decide($market, $this->params(self::ALWAYS, overrides: ['add_to_position' => true]), self::POSITION);

        $this->assertSame(Signal::HOLD, $off->action, 'ค่าปริยาย: ถือของอยู่แล้วไม่ซื้อซ้ำ');
        $this->assertSame(Signal::BUY, $on->action);
    }

    #[Test]
    public function scheduled_accumulation_waits_for_its_interval(): void
    {
        $entry = [['type' => 'interval', 'bars' => 6]];
        $market = $this->flatThen(60);

        $early = $this->strategy->decide($market, $this->params($entry, overrides: ['add_to_position' => true, '_bars_since_entry' => 5]), self::POSITION);
        $due = $this->strategy->decide($market, $this->params($entry, overrides: ['add_to_position' => true, '_bars_since_entry' => 6]), self::POSITION);

        $this->assertSame(Signal::HOLD, $early->action);
        $this->assertSame(Signal::BUY, $due->action);
    }

    #[Test]
    public function cooldown_blocks_a_re_entry_right_after_closing(): void
    {
        $market = $this->flatThen(60);

        $cooling = $this->strategy->decide($market, $this->params(self::ALWAYS, overrides: ['cooldown_bars' => 6, '_bars_since_exit' => 2]), null);
        $ready = $this->strategy->decide($market, $this->params(self::ALWAYS, overrides: ['cooldown_bars' => 6, '_bars_since_exit' => 6]), null);
        $never = $this->strategy->decide($market, $this->params(self::ALWAYS, overrides: ['cooldown_bars' => 6]), null);

        $this->assertSame(Signal::HOLD, $cooling->action);
        $this->assertSame('พักหลังปิดไม้ อีก 4 แท่ง', $cooling->reason);
        $this->assertSame(Signal::BUY, $ready->action);
        $this->assertSame(Signal::BUY, $never->action, 'ยังไม่เคยปิดไม้ = ไม่มีอะไรให้พัก');
    }

    // ── ออกจากไม้ ────────────────────────────────────────────────────────────

    #[Test]
    public function it_never_sells_without_a_position(): void
    {
        $params = $this->params(self::ALWAYS, [['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 60]], ['trailing_stop_pct' => 1, 'max_hold_bars' => 1, 'time_stop_mode' => 'always']);

        $this->assertNotSame(Signal::SELL, $this->strategy->decide($this->flatThen(60, [101, 102, 103, 104, 105]), $params, null)->action);
    }

    #[Test]
    public function exit_conditions_close_the_position(): void
    {
        $params = $this->params(self::ALWAYS, [['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 60]]);

        $rising = $this->strategy->decide($this->flatThen(60, [101, 102, 103, 104, 105]), $params, self::POSITION);
        $flat = $this->strategy->decide($this->flatThen(65), $params, self::POSITION);

        $this->assertSame(Signal::SELL, $rising->action);
        $this->assertStringStartsWith('เงื่อนไขออกเป็นจริง: RSI(14) สูงกว่า 60', $rising->reason);
        $this->assertSame(Signal::HOLD, $flat->action);
        $this->assertSame('ถือต่อ — ยังไม่เข้าเงื่อนไขออกที่ตั้งไว้', $flat->reason);
    }

    #[Test]
    public function trailing_stop_follows_the_peak_since_entry(): void
    {
        $params = $this->params(self::ALWAYS, overrides: ['trailing_stop_pct' => 5]);
        $position = ['qty' => 1.0, 'entry' => 100.18, 'entry_market' => 100.0, 'bars_held' => 4];

        // ขึ้นไป 120 แล้วย่อเหลือ 113 = ลงจากจุดสูงสุด 5.83%
        $pulledBack = $this->strategy->decide($this->flatThen(60, [110, 120, 118, 113]), $params, $position);
        // ย่อแค่ 117 = 2.5%
        $shallow = $this->strategy->decide($this->flatThen(60, [110, 120, 118, 117]), $params, $position);

        $this->assertSame(Signal::SELL, $pulledBack->action);
        $this->assertStringContainsString('trailing stop', $pulledBack->reason);
        $this->assertEqualsWithDelta(120.0, $pulledBack->meta['peak'], 1e-9);
        $this->assertSame(Signal::HOLD, $shallow->action);
    }

    #[Test]
    public function trailing_stop_ignores_highs_from_before_the_entry(): void
    {
        $params = $this->params(self::ALWAYS, overrides: ['trailing_stop_pct' => 5]);
        // จุดสูง 130 เกิดก่อนเข้าไม้ (ถือมาแค่ 2 แท่ง) — เข้าที่ 100 ตอนนี้ 99 = ยังไม่ถึง 5%
        $market = $this->flatThen(60, [130, 100, 100, 99]);

        $signal = $this->strategy->decide($market, $params, ['qty' => 1.0, 'entry' => 100.18, 'entry_market' => 100.0, 'bars_held' => 2]);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertEqualsWithDelta(100.0, $signal->meta['peak'], 1e-9);
    }

    #[Test]
    public function trailing_stop_also_protects_a_trade_that_never_rose(): void
    {
        $params = $this->params(self::ALWAYS, overrides: ['trailing_stop_pct' => 5]);

        $signal = $this->strategy->decide($this->flatThen(60, [98, 96, 94]), $params, ['qty' => 1.0, 'entry' => 100.18, 'entry_market' => 100.0, 'bars_held' => 3]);

        $this->assertSame(Signal::SELL, $signal->action);
    }

    #[Test]
    public function time_stop_closes_a_trade_that_is_not_in_profit(): void
    {
        $params = $this->params(self::ALWAYS, overrides: ['max_hold_bars' => 10, 'time_stop_mode' => 'no_profit']);
        $held = fn (int $bars) => ['qty' => 1.0, 'entry' => 100.18, 'entry_market' => 100.0, 'bars_held' => $bars];

        $this->assertSame(Signal::HOLD, $this->strategy->decide($this->flatThen(60), $params, $held(9))->action, 'ยังไม่ครบเวลา');

        $stale = $this->strategy->decide($this->flatThen(60), $params, $held(10));
        $this->assertSame(Signal::SELL, $stale->action);
        $this->assertSame('ถือครบ 10 แท่งแล้วยังไม่กำไร — ปิดไม้คืนทุน (time stop)', $stale->reason);

        $this->assertSame(Signal::HOLD, $this->strategy->decide($this->flatThen(60, [104]), $params, $held(10))->action, 'กำไรอยู่ = ถือต่อ');
    }

    #[Test]
    public function time_stop_can_be_set_to_always_close(): void
    {
        $params = $this->params(self::ALWAYS, overrides: ['max_hold_bars' => 10, 'time_stop_mode' => 'always']);

        $signal = $this->strategy->decide($this->flatThen(60, [104]), $params, ['qty' => 1.0, 'entry' => 100.18, 'entry_market' => 100.0, 'bars_held' => 10]);

        $this->assertSame(Signal::SELL, $signal->action);
        $this->assertSame('ถือครบ 10 แท่งตามที่ตั้งไว้ — ปิดไม้ (time stop)', $signal->reason);
    }

    #[Test]
    public function the_ai_cannot_loosen_the_owners_rules(): void
    {
        $params = $this->params(self::ALWAYS);

        $this->assertSame($params, $this->strategy->withAiRelief($params, 8.0));
    }

    // ── ผ่าน BacktestEngine ตัวจริง ──────────────────────────────────────────

    /** @return array{summary: array, trades: list<array>} */
    private function backtest(array $closes, array $rawParams, array $risk = []): array
    {
        return app(BacktestEngine::class)->run('custom', $this->candles($closes), '1h', $rawParams + ['macro_filter' => false], $risk + [
            'max_position_usd' => 100, 'stop_loss_pct' => 50, 'take_profit_pct' => 200, 'max_daily_loss_usd' => 1000000,
        ], ['risk_gate' => false]);
    }

    private function rules(array $entry, array $exit = []): array
    {
        return ['entry' => ['match' => 'all', 'blocks' => $entry], 'exit' => ['match' => 'any', 'blocks' => $exit]];
    }

    /** นิ่ง 200 → ขึ้น 60 → ลง 60 → นิ่ง 60 */
    private function roundTrip(): array
    {
        $closes = array_fill(0, 200, 100.0);
        for ($i = 1; $i <= 60; $i++) {
            $closes[] = 100.0 + $i * 0.5;
        }
        for ($i = 1; $i <= 60; $i++) {
            $closes[] = 130.0 - $i * 0.5;
        }

        return array_merge($closes, array_fill(0, 60, 100.0));
    }

    #[Test]
    public function changing_the_rules_changes_the_backtest(): void
    {
        $momentum = $this->backtest($this->roundTrip(), ['rules' => $this->rules(
            [['type' => 'ema_cross', 'when' => 'crosses_above', 'fast' => 12, 'slow' => 26]],
            [['type' => 'ema_cross', 'when' => 'crosses_below', 'fast' => 12, 'slow' => 26]],
        )]);

        // กฎกลับด้าน: ซื้อตอนเริ่มลง ขายตอนตัดขึ้น
        $inverse = $this->backtest($this->roundTrip(), ['rules' => $this->rules(
            [['type' => 'ema_cross', 'when' => 'crosses_below', 'fast' => 12, 'slow' => 26]],
            [['type' => 'ema_cross', 'when' => 'crosses_above', 'fast' => 12, 'slow' => 26]],
        )]);

        $this->assertSame(1, $momentum['summary']['closed']);
        $this->assertGreaterThan(0, $momentum['summary']['realized_pnl'], 'ซื้อตอนเริ่มขึ้น ขายตอนเริ่มลง = กำไร');
        $this->assertLessThan(0, $inverse['summary']['realized_pnl'] + $inverse['summary']['unrealized_pnl'], 'ซื้อตอนเริ่มลง = ขาดทุน');
        $this->assertNotEquals($momentum['summary']['realized_pnl'], $inverse['summary']['realized_pnl']);
        $this->assertStringStartsWith('เงื่อนไขเข้าเป็นจริง: EMA 12 ตัดขึ้นเหนือ EMA 26', $momentum['trades'][0]['reason']);
        $this->assertStringStartsWith('เงื่อนไขออกเป็นจริง: EMA 12 ตัดลงใต้ EMA 26', $momentum['trades'][1]['reason']);
    }

    #[Test]
    public function the_same_rules_give_the_same_backtest_every_time(): void
    {
        $params = ['rules' => $this->rules([['type' => 'rsi', 'op' => 'above', 'period' => 14, 'level' => 60]], [['type' => 'rsi', 'op' => 'below', 'period' => 14, 'level' => 40]])];

        $this->assertSame($this->backtest($this->roundTrip(), $params)['summary'], $this->backtest($this->roundTrip(), $params)['summary']);
    }

    #[Test]
    public function a_single_changed_number_changes_the_backtest(): void
    {
        $with = fn (float $level) => $this->backtest($this->roundTrip(), ['rules' => $this->rules(
            [['type' => 'price_change', 'op' => 'above', 'bars' => 10, 'pct' => $level]],
            [['type' => 'price_change', 'op' => 'below', 'bars' => 10, 'pct' => -2]],
        )]);

        $early = $with(1)['trades'][0];
        $late = $with(4)['trades'][0];

        $this->assertLessThan($late['time'], $early['time'], 'เกณฑ์ต่ำกว่า = เข้าเร็วกว่า');
        $this->assertLessThan($late['price'], $early['price']);
    }

    #[Test]
    public function the_bots_take_profit_and_stop_loss_still_close_the_trade(): void
    {
        $up = array_merge(array_fill(0, 200, 100.0), array_map(fn ($i) => 100.0 + $i, range(1, 30)));
        $down = array_merge(array_fill(0, 200, 100.0), array_map(fn ($i) => 100.0 - $i, range(1, 30)));
        $params = ['rules' => $this->rules(self::ALWAYS)];

        $profit = $this->backtest($up, $params, ['take_profit_pct' => 5]);
        $loss = $this->backtest($down, $params, ['stop_loss_pct' => 5]);

        $this->assertStringContainsString('ถึงเป้าทำกำไรที่ตั้งไว้', collect($profit['trades'])->firstWhere('side', 'sell')['reason']);
        $this->assertStringContainsString('ถึงจุดตัดขาดทุนที่ตั้งไว้', collect($loss['trades'])->firstWhere('side', 'sell')['reason']);
    }

    #[Test]
    public function trailing_and_time_stops_fire_inside_the_engine(): void
    {
        // ขึ้นไป 120 แล้วไหลลง — trailing 5% ต้องปิดก่อน stop loss 50%
        $closes = array_merge(array_fill(0, 200, 100.0), [105, 110, 115, 120, 118, 116, 113, 110, 108]);
        $trailing = $this->backtest($closes, ['rules' => $this->rules(self::ALWAYS), 'trailing_stop_pct' => 5, 'cooldown_bars' => 500]);

        $sell = collect($trailing['trades'])->firstWhere('side', 'sell');
        $this->assertStringContainsString('trailing stop', $sell['reason']);
        $this->assertEqualsWithDelta(113.0, $sell['market_price'], 1e-9);

        // ราคานิ่งตลอด — time stop 12 แท่งต้องปิดไม้
        $timed = $this->backtest(array_fill(0, 260, 100.0), ['rules' => $this->rules(self::ALWAYS), 'max_hold_bars' => 12, 'cooldown_bars' => 500]);
        $sell = collect($timed['trades'])->firstWhere('side', 'sell');

        $this->assertStringContainsString('time stop', $sell['reason']);
        $this->assertSame(12, $sell['held_bars']);
    }

    #[Test]
    public function scheduled_accumulation_respects_the_interval_the_order_size_and_the_position_cap(): void
    {
        $result = $this->backtest(array_fill(0, 320, 100.0), [
            'rules' => $this->rules([['type' => 'interval', 'bars' => 10]]),
            'add_to_position' => true,
            'order_size_usd' => 30,
        ], ['max_position_usd' => 100]);

        $buys = array_values(array_filter($result['trades'], fn ($t) => $t['side'] === 'buy'));

        $this->assertGreaterThanOrEqual(4, count($buys));
        $this->assertSame(10, $buys[1]['index'] - $buys[0]['index'], 'ซื้อห่างกันตามรอบที่ตั้ง');
        $this->assertEqualsWithDelta(30.0, $buys[0]['gross_value'], 0.5, 'ขนาดไม้ตามที่ผู้ใช้ตั้ง');
        $this->assertLessThanOrEqual(100.0, $result['summary']['max_deployed'], 'ยอดสะสมไม่เกินทุนสูงสุดต่อไม้');
    }

    #[Test]
    public function without_adding_enabled_the_engine_holds_one_entry_only(): void
    {
        $result = $this->backtest(array_fill(0, 320, 100.0), ['rules' => $this->rules([['type' => 'interval', 'bars' => 10]]), 'order_size_usd' => 30]);

        $this->assertCount(1, array_filter($result['trades'], fn ($t) => $t['side'] === 'buy'));
    }

    #[Test]
    public function cooldown_delays_the_next_entry_inside_the_engine(): void
    {
        // ลงต่อเนื่อง — เข้าทันที โดนตัดขาดทุน 3% แล้วเข้าใหม่
        $closes = array_merge(array_fill(0, 200, 100.0), array_map(fn ($i) => 100.0 - $i * 0.8, range(1, 40)));
        $run = fn (int $cooldown) => $this->backtest($closes, ['rules' => $this->rules(self::ALWAYS), 'cooldown_bars' => $cooldown], ['stop_loss_pct' => 3]);

        $eager = $run(0)['trades'];
        $patient = $run(15)['trades'];

        $gap = fn (array $trades) => $trades[2]['index'] - $trades[1]['index'];

        $this->assertSame('sell', $eager[1]['side']);
        $this->assertSame(1, $gap($eager), 'ไม่พัก = เข้าใหม่แท่งถัดไป');
        $this->assertSame(15, $gap($patient), 'พัก 15 แท่งตามที่ตั้ง');
        $this->assertLessThan(count($eager), count($patient));
    }
}
