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

    /**
     * ⭐ ตัดขึ้นใต้เส้นเทรนด์ใหญ่ = เด้งสั้นในขาลง ค่าปริยายต้องไม่ซื้อ (ปิดตัวกรองแล้วซื้อ).
     */
    public function test_momentum_refuses_a_cross_up_below_the_long_trend_unless_told_to(): void
    {
        // ลงยาวจาก 200 → 100 แล้วนิ่ง 40 แท่ง (EMA เร็ว/ช้าเข้าใกล้กัน) แล้วดีดขึ้น 4% แท่งเดียว
        $closes = [];
        for ($i = 0; $i < 260; $i++) {
            $closes[] = 200.0 - $i * (100.0 / 259);
        }
        $closes = array_merge($closes, array_fill(0, 40, 100.0), [104.0]);
        $market = $this->candles($closes);

        $guarded = $this->registry->find('momentum')
            ->decide($market, $this->defaultParams('momentum'), null);

        $this->assertSame(Signal::HOLD, $guarded->action);
        $this->assertStringContainsString('ขาลง', $guarded->reason);

        $unguarded = $this->registry->find('momentum')
            ->decide($market, ['fast_ema' => 12, 'slow_ema' => 26, 'volume_filter' => false, 'htf_confirm' => false, 'min_atr_pct' => 0], null);

        $this->assertSame(Signal::BUY, $unguarded->action, 'ปิดตัวกรองแล้วการตัดขึ้นต้องยังเป็นสัญญาณซื้อ');
    }

    /** time stop: ถือครบแล้วยังไม่กำไร = ปิดคืนทุน · ยังไม่ครบ = ถือต่อ */
    public function test_momentum_time_stop_closes_a_trade_going_nowhere(): void
    {
        $flat = $this->candles(array_fill(0, 80, 100.0));
        $params = ['fast_ema' => 12, 'slow_ema' => 26, 'max_hold_bars' => 48];

        $stale = $this->registry->find('momentum')
            ->decide($flat, $params, ['qty' => 1.0, 'entry' => 100.18, 'entry_market' => 100.0, 'bars_held' => 100]);
        $fresh = $this->registry->find('momentum')
            ->decide($flat, $params, ['qty' => 1.0, 'entry' => 100.18, 'entry_market' => 100.0, 'bars_held' => 10]);

        $this->assertSame(Signal::SELL, $stale->action);
        $this->assertStringContainsString('time stop', $stale->reason);
        $this->assertSame(Signal::HOLD, $fresh->action);
    }

    public function test_momentum_refuses_an_inverted_configuration(): void
    {
        $signal = $this->registry->find('momentum')
            ->decide($this->risingMarket(), ['fast_ema' => 30, 'slow_ema' => 10], null);

        $this->assertSame(Signal::HOLD, $signal->action);
        $this->assertStringContainsString('ตั้งค่าผิด', $signal->reason);
    }

    // ── mean reversion ──────────────────────────────────────────────────────

    /**
     * ⭐ RSI ต่ำในขาลงใหญ่ = มีดตก ไม่ใช่ของถูก — ค่าปริยายต้องไม่ซื้อ.
     *
     * backtest 180 วัน: สวนค่าเฉลี่ยบน SOL (ใต้ EMA 200 เกือบตลอด) edge −66 bps
     * ผู้ใช้ปิดตัวกรองได้เอง ถ้าปิดต้องซื้อเหมือนเดิม
     */
    public function test_mean_reversion_refuses_to_buy_oversold_in_a_major_downtrend_unless_told_to(): void
    {
        $market = $this->fallingMarket();

        $guarded = $this->registry->find('mean_reversion')
            ->decide($market, $this->defaultParams('mean_reversion'), null);

        $this->assertSame(Signal::HOLD, $guarded->action);
        $this->assertLessThan(30, $guarded->meta['rsi'], 'RSI ต้องต่ำจริง — ที่ไม่ซื้อเป็นเพราะเทรนด์ ไม่ใช่ RSI');
        $this->assertStringContainsString('มีดตก', $guarded->reason);
        $this->assertSame('down', $guarded->meta['trend']);

        $unguarded = $this->registry->find('mean_reversion')
            ->decide($market, ['rsi_period' => 14, 'oversold' => 30, 'overbought' => 70, 'regime_filter' => false], null);

        $this->assertSame(Signal::BUY, $unguarded->action);
    }

    /** ย่อ "ในขาขึ้น" ยังต้องซื้อ — นั่นคือฉากที่กลยุทธ์นี้ทำเงินจริง */
    public function test_mean_reversion_still_buys_a_dip_inside_an_uptrend(): void
    {
        $closes = [];
        for ($i = 0; $i < 280; $i++) {
            $closes[] = 100.0 + $i * 0.2;
        }
        for ($i = 1; $i <= 12; $i++) {
            $closes[] = 156.0 - $i;
        }

        $signal = $this->registry->find('mean_reversion')
            ->decide($this->candles($closes), $this->defaultParams('mean_reversion'), null);

        $this->assertSame(Signal::BUY, $signal->action);
        $this->assertSame('up', $signal->meta['trend']);
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

    /** ทะลุนิดเดียว (เทียบ ATR) หลอกบ่อย — ค่าขั้นต่ำที่ตั้งต้องกันได้ */
    public function test_breakout_ignores_a_tiny_breakout_when_a_minimum_size_is_set(): void
    {
        $closes = array_fill(0, 60, 100.0);
        $closes[] = 100.25;   // ขอบบน = 100.2 → ทะลุ 0.05 ≈ 0.12 ATR
        $market = $this->candles($closes);

        $strict = $this->registry->find('breakout')
            ->decide($market, ['channel_period' => 20, 'atr_multiple' => 2, 'min_breakout_atr' => 0.3], null);
        $loose = $this->registry->find('breakout')
            ->decide($market, ['channel_period' => 20, 'atr_multiple' => 2, 'min_breakout_atr' => 0.05], null);

        $this->assertSame(Signal::HOLD, $strict->action);
        $this->assertStringContainsString('นิดเดียว', $strict->reason);
        $this->assertSame(Signal::BUY, $loose->action);
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

    /**
     * ⭐ กริดในตลาดที่มีทิศทาง = รับมีดตกทีละชั้น — ค่าปริยายต้องไม่เข้า (ปิดตัวกรองแล้วเข้า).
     */
    public function test_grid_refuses_to_enter_a_trending_market_unless_told_to(): void
    {
        // ลงช้าๆ ไม่ย้อนเลย 100 แท่ง: ยังอยู่ในกรอบและต่ำกว่าชั้นซื้อ แต่ ER = 1
        $closes = [];
        for ($i = 0; $i < 100; $i++) {
            $closes[] = 110.0 - $i * 0.1;
        }
        $market = $this->candles($closes);

        $guarded = $this->registry->find('grid')
            ->decide($market, $this->defaultParams('grid'), null);
        $unguarded = $this->registry->find('grid')
            ->decide($market, ['grid_levels' => 10, 'range_pct' => 6, 'regime_filter' => false], null);

        $this->assertSame(Signal::HOLD, $guarded->action);
        $this->assertStringContainsString('ทิศทาง', $guarded->reason);
        $this->assertSame(Signal::BUY, $unguarded->action, 'ปิดตัวกรองแล้วต้องซื้อที่ชั้นซื้อเหมือนเดิม');
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

    /**
     * ต้องซื้อได้ด้วย "ค่าปริยายจริงจาก config" ไม่ใช่ค่าที่เทสต์ป้อนให้เอง.
     *
     * เทสต์ชุดเดิมส่ง confidence_min = 55 เข้าไปเองทุกครั้ง จึงไม่มีใครเห็นว่า
     * ค่าปริยายจริง (65) สูงกว่าคะแนนสูงสุดที่สูตรเดิมทำได้ (~56.6) — กลยุทธ์ที่
     * เป็นจุดขายของแพลน VIP จึงไม่เคยออกสัญญาณซื้อเลย และไม่มีเทสต์ไหนจับได้
     */
    public function test_ai_signal_buys_an_uptrend_using_the_shipped_defaults(): void
    {
        $defaults = collect(config('aibot.strategies'))
            ->firstWhere('code', 'ai_signal')['params'];

        $params = collect($defaults)->pluck('default', 'key')->all();

        $signal = $this->registry->find('ai_signal')
            ->decide($this->risingMarket(70, 20), $params, null);

        $this->assertSame(Signal::BUY, $signal->action, sprintf(
            'ความมั่นใจ %.1f%% ยังไม่ถึงเกณฑ์ปริยาย %s%%',
            $signal->meta['confidence'] ?? 0,
            $params['confidence_min'] ?? '?'
        ));
    }

    /**
     * ตลาดออกข้างแล้วราคาย่อลงชนขอบล่าง = ของถูกในกรอบ ต้องไม่ตีความเป็นสัญญาณขาย.
     *
     * เป็นอีกด้านของการแก้สูตร: พอให้น้ำหนักฝั่งตามเทรนด์มากขึ้นตอนตลาดมีทิศทาง
     * ต้องไม่เผลอทิ้งฝั่งสวนค่าเฉลี่ยไปด้วยตอนตลาดไม่มีทิศทาง ซึ่งเป็นสถานการณ์
     * ที่ฝั่งนั้นถูกของมันเอง (ai_signal ต้องใช้ได้ทั้งสองสภาพตลาด ไม่ใช่แค่ขาขึ้น)
     */
    public function test_ai_signal_still_buys_a_dip_in_a_ranging_market(): void
    {
        // ต้องยาวกว่า minCandles (60) ไม่งั้นได้ hold เพราะข้อมูลไม่พอ ไม่ใช่เพราะสูตร
        $closes = [];
        for ($i = 0; $i < 64; $i++) {
            $closes[] = 100.0 + ($i % 2 === 0 ? 1.5 : -1.5);
        }
        // ย่อเบาๆ ให้ยังอยู่ในกรอบ — ถ้าย่อแรงก็กลายเป็นขาลงจริง ซึ่งควรถูกอ่านว่าขาลง
        for ($i = 1; $i <= 4; $i++) {
            $closes[] = 100.0 - $i * 0.4;
        }

        $signal = $this->registry->find('ai_signal')
            ->decide($this->candles($closes), ['confidence_min' => 65, 'mode' => 'balanced'], null);

        $this->assertNotSame(Signal::SELL, $signal->action);
        $this->assertGreaterThan(50, $signal->meta['confidence']);
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
