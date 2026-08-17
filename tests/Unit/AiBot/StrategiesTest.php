<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\Signal;
use App\Services\AiBot\StrategyRegistry;
use Tests\TestCase;

/**
 * TPIX TRADE — กลยุทธ์ต้องตัดสินใจถูกในสถานการณ์ที่ออกแบบไว้.
 *
 * นี่คือชั้นที่เอาเงินผู้ใช้ไปเสี่ยง จึงทดสอบด้วย "ตลาดจำลองที่รู้คำตอบล่วงหน้า"
 * ทุกกลยุทธ์ต้องผ่านกฎร่วมด้วย: ไม่สั่งขายตอนไม่มีของ ไม่สั่งซื้อซ้ำตอนถือของอยู่
 *
 * Developed by Xman Studio.
 */
class StrategiesTest extends TestCase
{
    private StrategyRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new StrategyRegistry();
    }

    // ── ตัวช่วยสร้างตลาดจำลอง ───────────────────────────────────────────────

    /** @param list<float> $closes */
    private function candles(array $closes, float $volume = 100.0): array
    {
        $candles = [];
        foreach ($closes as $i => $close) {
            $candles[] = [
                'time' => 1_700_000_000 + $i * 3600,
                'open' => $close,
                'high' => $close * 1.002,
                'low' => $close * 0.998,
                'close' => $close,
                'volume' => $volume,
            ];
        }

        return $candles;
    }

    /** ราคานิ่งแล้วพุ่งขึ้นตอนท้าย — สร้างสัญญาณ EMA ตัดขึ้น */
    private function risingMarket(int $flat = 60, int $rise = 12): array
    {
        $closes = array_fill(0, $flat, 100.0);
        for ($i = 1; $i <= $rise; $i++) {
            $closes[] = 100.0 + $i * 2;
        }

        return $this->candles($closes);
    }

    /**
     * ตลาดแกว่งขึ้นลงสลับกัน แล้วปิดท้ายด้วยการย่อ — ใช้ทดสอบ RSI ช่วงกลางๆ
     * (ตลาดที่ดิ่งทางเดียวทำให้ RSI = 0 ตลอด จนแยกระดับความแรงไม่ออก).
     */
    private function choppyMarket(int $dipBars, float $dipStep): array
    {
        $closes = [];
        for ($i = 0; $i < 40; $i++) {
            $closes[] = 100.0 + ($i % 2 === 0 ? 1.0 : -1.0);
        }

        $price = 100.0;
        for ($i = 0; $i < $dipBars; $i++) {
            $price -= $dipStep;
            $closes[] = $price;
        }

        return $this->candles($closes);
    }

    /** ราคานิ่งแล้วดิ่งลงตอนท้าย */
    private function fallingMarket(int $flat = 60, int $fall = 12): array
    {
        $closes = array_fill(0, $flat, 100.0);
        for ($i = 1; $i <= $fall; $i++) {
            $closes[] = 100.0 - $i * 2;
        }

        return $this->candles($closes);
    }

    // ── กฎร่วมของทุกกลยุทธ์ ─────────────────────────────────────────────────

    public function test_registry_covers_every_strategy_in_the_catalogue(): void
    {
        $configured = collect(config('aibot.strategies'))->pluck('code')->sort()->values()->all();
        $implemented = collect($this->registry->codes())->sort()->values()->all();

        $this->assertSame($configured, $implemented, 'กลยุทธ์ใน config กับที่เขียนคลาสไว้ไม่ตรงกัน');
    }

    public function test_no_strategy_sells_when_holding_nothing(): void
    {
        foreach ([$this->risingMarket(), $this->fallingMarket()] as $market) {
            foreach ($this->registry->all() as $code => $strategy) {
                // arbitrage ปิดไม้ค้างได้เมื่อมี position เท่านั้น — ไม่มีของก็ต้องไม่ขาย
                $signal = $strategy->decide($market, $this->defaultParams($code), null);

                $this->assertNotSame(
                    Signal::SELL,
                    $signal->action,
                    "{$code} สั่งขายทั้งที่ยังไม่มีของ"
                );
            }
        }
    }

    public function test_no_strategy_buys_again_while_already_holding(): void
    {
        $position = ['qty' => 1.0, 'entry' => 100.0];

        foreach ([$this->risingMarket(), $this->fallingMarket()] as $market) {
            foreach ($this->registry->all() as $code => $strategy) {
                if ($strategy->allowsPyramiding()) {
                    continue; // DCA เติมไม้ทับได้ตามออกแบบ — ทดสอบแยกด้านล่าง
                }

                $signal = $strategy->decide($market, $this->defaultParams($code), $position);

                $this->assertNotSame(
                    Signal::BUY,
                    $signal->action,
                    "{$code} สั่งซื้อซ้ำทั้งที่ถือของอยู่แล้ว"
                );
            }
        }
    }

    public function test_every_strategy_holds_and_explains_itself_without_enough_data(): void
    {
        $tiny = $this->candles([100.0, 101.0]);

        foreach ($this->registry->all() as $code => $strategy) {
            if ($code === 'arbitrage') {
                continue; // ตัวนี้ไม่ต้องใช้แท่งเทียน
            }

            $signal = $strategy->decide($tiny, $this->defaultParams($code), null);

            $this->assertSame(Signal::HOLD, $signal->action, "{$code} ตัดสินใจทั้งที่ข้อมูลไม่พอ");
            $this->assertNotSame('', $signal->reason, "{$code} ไม่ได้บอกเหตุผลที่เงียบ");
        }
    }

    public function test_signal_strength_always_stays_in_range(): void
    {
        foreach ([$this->risingMarket(), $this->fallingMarket()] as $market) {
            foreach ($this->registry->all() as $code => $strategy) {
                foreach ([null, ['qty' => 1.0, 'entry' => 100.0]] as $position) {
                    $signal = $strategy->decide($market, $this->defaultParams($code), $position);

                    $this->assertGreaterThanOrEqual(0.0, $signal->strength, "{$code} strength ติดลบ");
                    $this->assertLessThanOrEqual(1.0, $signal->strength, "{$code} strength เกิน 1");
                }
            }
        }
    }

    // ── momentum ────────────────────────────────────────────────────────────

    public function test_momentum_buys_on_a_fresh_cross_up(): void
    {
        // วัดจริงแล้ว: EMA12 ตัดขึ้นเหนือ EMA26 ที่ "แท่งแรก" ของการพุ่งขึ้น
        $signal = $this->registry->find('momentum')
            ->decide($this->risingMarket(60, 1), $this->defaultParams('momentum'), null);

        $this->assertSame(Signal::BUY, $signal->action);
        $this->assertStringContainsString('EMA', $signal->reason);
    }

    public function test_momentum_does_not_buy_again_mid_trend(): void
    {
        // เทรนด์ขึ้นมานานแล้ว — EMA ไม่ได้เพิ่งตัดกัน จึงต้องไม่เข้าไม้ใหม่
        $market = $this->risingMarket(60, 40);

        $signal = $this->registry->find('momentum')
            ->decide($market, $this->defaultParams('momentum'), null);

        $this->assertSame(Signal::HOLD, $signal->action);
    }

    public function test_momentum_rejects_a_cross_without_volume(): void
    {
        // วอลุ่มแท่งสุดท้ายต่ำกว่าค่าเฉลี่ยมาก → ไม่ยืนยันการตัด
        $market = $this->risingMarket(60, 1);
        $market[count($market) - 1]['volume'] = 1.0;

        $signal = $this->registry->find('momentum')
            ->decide($market, ['fast_ema' => 12, 'slow_ema' => 26, 'volume_filter' => true], null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringContainsString('วอลุ่ม', $signal->reason);
    }

    public function test_momentum_sells_when_the_trend_flips(): void
    {
        $signal = $this->registry->find('momentum')
            ->decide($this->fallingMarket(60, 1), $this->defaultParams('momentum'), ['qty' => 1.0, 'entry' => 100.0]);

        $this->assertSame(Signal::SELL, $signal->action);
    }

    public function test_momentum_refuses_an_inverted_configuration(): void
    {
        $signal = $this->registry->find('momentum')
            ->decide($this->risingMarket(), ['fast_ema' => 30, 'slow_ema' => 10], null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringContainsString('ตั้งค่าผิด', $signal->reason);
    }

    // ── mean reversion ──────────────────────────────────────────────────────

    public function test_mean_reversion_buys_when_oversold(): void
    {
        $signal = $this->registry->find('mean_reversion')
            ->decide($this->fallingMarket(), $this->defaultParams('mean_reversion'), null);

        $this->assertSame(Signal::BUY, $signal->action);
        $this->assertLessThan(30, $signal->meta['rsi']);
    }

    public function test_mean_reversion_sells_when_overbought(): void
    {
        $signal = $this->registry->find('mean_reversion')
            ->decide($this->risingMarket(), $this->defaultParams('mean_reversion'), ['qty' => 1.0, 'entry' => 100.0]);

        $this->assertSame(Signal::SELL, $signal->action);
        $this->assertGreaterThan(70, $signal->meta['rsi']);
    }

    public function test_mean_reversion_buys_harder_the_deeper_the_oversold(): void
    {
        // ตลาดดิ่งล้วนทำให้ RSI = 0 ทั้งคู่ น้ำหนักตัน 1.0 เท่ากัน จนเทียบไม่ได้
        // จึงใช้ตลาดผสมที่วัดแล้วได้ RSI 48.8 (ย่อเบา) กับ 34.6 (ย่อลึก)
        $params = ['rsi_period' => 14, 'oversold' => 50, 'overbought' => 70];

        $mild = $this->registry->find('mean_reversion')
            ->decide($this->choppyMarket(1, 0.5), $params, null);
        $deep = $this->registry->find('mean_reversion')
            ->decide($this->choppyMarket(6, 1.5), $params, null);

        $this->assertLessThan(1.0, $deep->strength, 'น้ำหนักตันเพดาน เทียบไม่ได้');
        $this->assertGreaterThan($mild->strength, $deep->strength);
    }

    public function test_dca_averages_in_while_already_holding(): void
    {
        // DCA ประกาศว่าเติมไม้ได้ — ต้องยังซื้อต่อแม้ถือของอยู่ (นี่คือหัวใจของกลยุทธ์)
        $strategy = $this->registry->find('dca');
        $this->assertTrue($strategy->allowsPyramiding());

        $signal = $strategy->decide(
            $this->candles(array_fill(0, 40, 100.0)),
            ['dip_boost_pct' => 3, '_bars_since_entry' => 30, '_interval_bars' => 24],
            ['qty' => 1.0, 'entry' => 100.0],
        );

        $this->assertSame(Signal::BUY, $signal->action);
    }

    public function test_only_dca_allows_pyramiding(): void
    {
        foreach ($this->registry->all() as $code => $strategy) {
            $this->assertSame(
                $code === 'dca',
                $strategy->allowsPyramiding(),
                "{$code} ประกาศความสามารถเติมไม้ไม่ตรงกับที่ออกแบบ"
            );
        }
    }

    // ── breakout ────────────────────────────────────────────────────────────

    public function test_breakout_buys_when_price_clears_the_channel(): void
    {
        $signal = $this->registry->find('breakout')
            ->decide($this->risingMarket(), $this->defaultParams('breakout'), null);

        $this->assertSame(Signal::BUY, $signal->action);
        $this->assertGreaterThan($signal->meta['upper'], $signal->meta['close']);
    }

    public function test_breakout_holds_inside_the_channel(): void
    {
        $flat = $this->candles(array_fill(0, 60, 100.0));

        $signal = $this->registry->find('breakout')
            ->decide($flat, $this->defaultParams('breakout'), null);

        $this->assertSame(Signal::HOLD, $signal->action);
    }

    public function test_breakout_exits_on_the_atr_stop(): void
    {
        $signal = $this->registry->find('breakout')
            ->decide($this->fallingMarket(), $this->defaultParams('breakout'), ['qty' => 1.0, 'entry' => 100.0]);

        $this->assertSame(Signal::SELL, $signal->action);
    }

    public function test_breakout_refuses_short_only_configuration(): void
    {
        $signal = $this->registry->find('breakout')->decide(
            $this->risingMarket(),
            ['channel_period' => 20, 'atr_multiple' => 2, 'direction' => 'short'],
            null,
        );

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringContainsString('short', $signal->reason);
    }

    // ── grid ────────────────────────────────────────────────────────────────

    public function test_grid_buys_a_dip_inside_the_range(): void
    {
        // ราคานิ่งที่ 100 แล้วย่อลงเล็กน้อย — ยังอยู่ในกรอบ
        $closes = array_fill(0, 40, 100.0);
        $closes[] = 98.5;

        $signal = $this->registry->find('grid')
            ->decide($this->candles($closes), ['grid_levels' => 10, 'range_pct' => 6], null);

        $this->assertSame(Signal::BUY, $signal->action);
    }

    public function test_grid_stops_entering_when_price_breaks_below_the_range(): void
    {
        $signal = $this->registry->find('grid')
            ->decide($this->fallingMarket(), ['grid_levels' => 10, 'range_pct' => 6], null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringContainsString('หลุดกรอบล่าง', $signal->reason);
    }

    public function test_grid_takes_profit_one_level_up(): void
    {
        $closes = array_fill(0, 40, 100.0);
        $closes[] = 102.0;

        $signal = $this->registry->find('grid')->decide(
            $this->candles($closes),
            ['grid_levels' => 10, 'range_pct' => 6],
            ['qty' => 1.0, 'entry' => 99.0],
        );

        $this->assertSame(Signal::SELL, $signal->action);
    }

    // ── dca ─────────────────────────────────────────────────────────────────

    public function test_dca_waits_until_the_interval_elapses(): void
    {
        $signal = $this->registry->find('dca')->decide(
            $this->candles(array_fill(0, 40, 100.0)),
            ['dip_boost_pct' => 3, '_bars_since_entry' => 2, '_interval_bars' => 24],
            null,
        );

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringContainsString('ยังไม่ครบรอบ', $signal->reason);
    }

    public function test_dca_buys_once_the_interval_elapses(): void
    {
        $signal = $this->registry->find('dca')->decide(
            $this->candles(array_fill(0, 40, 100.0)),
            ['dip_boost_pct' => 3, '_bars_since_entry' => 30, '_interval_bars' => 24],
            null,
        );

        $this->assertSame(Signal::BUY, $signal->action);
    }

    public function test_dca_buys_harder_on_a_dip(): void
    {
        $flat = $this->candles(array_fill(0, 40, 100.0));

        $dipped = array_fill(0, 40, 100.0);
        $dipped[] = 90.0;

        $params = ['dip_boost_pct' => 3, '_bars_since_entry' => 30, '_interval_bars' => 24];

        $normal = $this->registry->find('dca')->decide($flat, $params, null);
        $onDip = $this->registry->find('dca')->decide($this->candles($dipped), $params, null);

        $this->assertGreaterThan($normal->strength, $onDip->strength);
    }

    // ── scalping ────────────────────────────────────────────────────────────

    public function test_scalping_takes_profit_at_its_target(): void
    {
        $closes = array_fill(0, 30, 100.0);
        $closes[] = 101.0; // +100 bps

        $signal = $this->registry->find('scalping')->decide(
            $this->candles($closes),
            ['target_bps' => 15, 'max_spread_bps' => 8, 'cooldown_sec' => 20],
            ['qty' => 1.0, 'entry' => 100.0],
        );

        $this->assertSame(Signal::SELL, $signal->action);
        $this->assertGreaterThanOrEqual(15, $signal->meta['gain_bps']);
    }

    public function test_scalping_stays_out_when_volatility_is_too_high(): void
    {
        $signal = $this->registry->find('scalping')->decide(
            $this->fallingMarket(),
            ['target_bps' => 15, 'max_spread_bps' => 1, 'cooldown_sec' => 20],
            null,
        );

        $this->assertSame(Signal::HOLD, $signal->action);
    }

    // ── arbitrage ───────────────────────────────────────────────────────────

    public function test_arbitrage_is_honest_that_it_cannot_run_yet(): void
    {
        $signal = $this->registry->find('arbitrage')
            ->decide($this->risingMarket(), ['min_edge_bps' => 25], null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertFalse($signal->meta['available']);
        $this->assertStringContainsString('DEX', $signal->reason);
    }

    // ── ai signal ───────────────────────────────────────────────────────────

    public function test_ai_signal_needs_confidence_above_the_threshold(): void
    {
        $flat = $this->candles(array_fill(0, 80, 100.0));

        $signal = $this->registry->find('ai_signal')
            ->decide($flat, ['confidence_min' => 65, 'mode' => 'balanced'], null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertEqualsWithDelta(50.0, $signal->meta['confidence'], 1.0);
    }

    public function test_ai_signal_buys_a_strong_uptrend(): void
    {
        $signal = $this->registry->find('ai_signal')
            ->decide($this->risingMarket(70, 20), ['confidence_min' => 55, 'mode' => 'balanced'], null);

        $this->assertSame(Signal::BUY, $signal->action);
        $this->assertGreaterThan(55, $signal->meta['confidence']);
    }

    public function test_ai_signal_scores_every_dimension(): void
    {
        $signal = $this->registry->find('ai_signal')
            ->decide($this->risingMarket(70, 20), ['confidence_min' => 55, 'mode' => 'balanced'], null);

        foreach (['trend', 'momentum', 'reversion', 'position'] as $dimension) {
            $this->assertArrayHasKey($dimension, $signal->meta);
            $this->assertGreaterThanOrEqual(-1.0, $signal->meta[$dimension]);
            $this->assertLessThanOrEqual(1.0, $signal->meta[$dimension]);
        }
    }

    public function test_ai_signal_aggressive_mode_sizes_up(): void
    {
        $market = $this->risingMarket(70, 20);

        $conservative = $this->registry->find('ai_signal')
            ->decide($market, ['confidence_min' => 55, 'mode' => 'conservative'], null);
        $aggressive = $this->registry->find('ai_signal')
            ->decide($market, ['confidence_min' => 55, 'mode' => 'aggressive'], null);

        $this->assertGreaterThan($conservative->strength, $aggressive->strength);
    }

    // ── helper ──────────────────────────────────────────────────────────────

    /** ค่าเริ่มต้นจาก config จริง — เทสต์จึงใช้ค่าชุดเดียวกับที่ผู้ใช้ได้ */
    private function defaultParams(string $code): array
    {
        $strategy = collect(config('aibot.strategies'))->firstWhere('code', $code);
        $params = collect($strategy['params'] ?? [])->pluck('default', 'key')->all();

        // DCA ต้องรู้ว่าผ่านมากี่แท่งแล้ว — engine เป็นคนใส่ให้ตอนรันจริง
        if ($code === 'dca') {
            $params['_bars_since_entry'] = 999;
            $params['_interval_bars'] = 24;
        }

        return $params;
    }
}
