<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\Indicators;
use PHPUnit\Framework\TestCase;

/**
 * TPIX TRADE — ตัวชี้วัดต้องคำนวณถูกต้องตามนิยามมาตรฐาน.
 *
 * บอทตัดสินใจซื้อ-ขายจากตัวเลขพวกนี้ล้วนๆ ถ้าสูตรเพี้ยนไปนิดเดียว
 * บอทจะขาดทุนเงียบๆ โดยไม่มี error ให้เห็น — จึงต้องยึดค่าที่ตรวจด้วยมือได้
 *
 * Developed by Xman Studio.
 */
class IndicatorsTest extends TestCase
{
    // ── SMA ─────────────────────────────────────────────────────────────────

    public function test_sma_averages_each_window(): void
    {
        // [1,2,3,4,5] period 3 → (1+2+3)/3=2, (2+3+4)/3=3, (3+4+5)/3=4
        $this->assertSame([2.0, 3.0, 4.0], Indicators::sma([1, 2, 3, 4, 5], 3));
    }

    public function test_sma_returns_empty_when_data_is_short(): void
    {
        $this->assertSame([], Indicators::sma([1, 2], 3));
        $this->assertSame([], Indicators::sma([1, 2, 3], 0));
    }

    // ── EMA ─────────────────────────────────────────────────────────────────

    public function test_ema_seeds_from_sma_then_smooths(): void
    {
        // period 3 → k = 2/4 = 0.5 · ตัวตั้ง = SMA(1,2,3) = 2
        // จุดถัดไป: (4-2)*0.5+2 = 3 · แล้ว (5-3)*0.5+3 = 4
        $this->assertSame([2.0, 3.0, 4.0], Indicators::ema([1, 2, 3, 4, 5], 3));
    }

    public function test_ema_of_a_flat_series_stays_flat(): void
    {
        $ema = Indicators::ema([10, 10, 10, 10, 10, 10], 3);

        foreach ($ema as $value) {
            $this->assertEqualsWithDelta(10.0, $value, 1e-9);
        }
    }

    public function test_ema_reacts_faster_than_sma(): void
    {
        // ราคากระโดดขึ้นตอนท้าย — EMA ต้องขยับเข้าใกล้ราคาใหม่มากกว่า SMA
        $series = [10, 10, 10, 10, 10, 20];

        $this->assertGreaterThan(
            Indicators::last(Indicators::sma($series, 5)),
            Indicators::last(Indicators::ema($series, 5)),
        );
    }

    // ── RSI ─────────────────────────────────────────────────────────────────

    public function test_rsi_is_100_when_price_only_rises(): void
    {
        $rising = range(1, 20);

        $this->assertEqualsWithDelta(100.0, Indicators::last(Indicators::rsi($rising, 14)), 1e-9);
    }

    public function test_rsi_is_0_when_price_only_falls(): void
    {
        $falling = array_reverse(range(1, 20));

        $this->assertEqualsWithDelta(0.0, Indicators::last(Indicators::rsi($falling, 14)), 1e-9);
    }

    public function test_rsi_of_a_flat_series_is_neutral(): void
    {
        // ไม่มีทั้งกำไรและขาดทุน — ต้องไม่หารศูนย์ และต้องไม่ตอบ 100
        $flat = array_fill(0, 20, 42.0);

        $this->assertSame(50.0, Indicators::last(Indicators::rsi($flat, 14)));
    }

    public function test_rsi_stays_inside_its_bounds(): void
    {
        $prices = [44, 44.34, 44.09, 44.15, 43.61, 44.33, 44.83, 45.10, 45.42,
            45.84, 46.08, 45.89, 46.03, 45.61, 46.28, 46.28, 46.00, 46.03, 46.41, 46.22];

        foreach (Indicators::rsi($prices, 14) as $value) {
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThanOrEqual(100, $value);
        }
    }

    public function test_rsi_needs_more_points_than_its_period(): void
    {
        $this->assertSame([], Indicators::rsi([1, 2, 3], 14));
    }

    // ── ATR ─────────────────────────────────────────────────────────────────

    public function test_atr_of_constant_range_equals_that_range(): void
    {
        // ทุกแท่งกว้าง 2 และปิดกลางแท่ง → true range = 2 ตลอด → ATR = 2
        $candles = array_fill(0, 20, ['high' => 11.0, 'low' => 9.0, 'close' => 10.0]);

        $this->assertEqualsWithDelta(2.0, Indicators::last(Indicators::atr($candles, 14)), 1e-9);
    }

    public function test_atr_counts_the_gap_from_the_previous_close(): void
    {
        // แท่งที่ 2 กระโดดขึ้นทั้งแท่ง — true range ต้องวัดจากราคาปิดก่อนหน้า ไม่ใช่แค่ high-low
        $candles = [
            ['high' => 10.0, 'low' => 9.0, 'close' => 9.5],
            ['high' => 20.0, 'low' => 19.0, 'close' => 19.5],
            ['high' => 20.5, 'low' => 19.5, 'close' => 20.0],
        ];

        $atr = Indicators::atr($candles, 2);

        // TR ของแท่ง 2 = |20 - 9.5| = 10.5 · TR แท่ง 3 = 1 → ATR แรก = (10.5+1)/2
        $this->assertEqualsWithDelta(5.75, $atr[0], 1e-9);
    }

    public function test_atr_returns_empty_when_data_is_short(): void
    {
        $this->assertSame([], Indicators::atr([['high' => 1.0, 'low' => 0.0, 'close' => 0.5]], 14));
    }

    // ── Donchian ────────────────────────────────────────────────────────────

    public function test_donchian_excludes_the_current_candle(): void
    {
        // ถ้ารวมแท่งปัจจุบัน ขอบบนจะเท่ากับ high ของแท่งนั้นเสมอ → "ทะลุกรอบ" ทุกแท่ง
        $candles = [
            ['high' => 10.0, 'low' => 8.0],
            ['high' => 11.0, 'low' => 9.0],
            ['high' => 50.0, 'low' => 10.0], // แท่งปัจจุบันทำ high ใหม่
        ];

        $channel = Indicators::donchian($candles, 2);

        $this->assertSame([11.0], $channel['upper']); // ไม่ใช่ 50
        $this->assertSame([8.0], $channel['lower']);
    }

    public function test_donchian_returns_empty_when_data_is_short(): void
    {
        $channel = Indicators::donchian([['high' => 1.0, 'low' => 0.0]], 20);

        $this->assertSame([], $channel['upper']);
        $this->assertSame([], $channel['lower']);
    }

    // ── stdev / Bollinger ───────────────────────────────────────────────────

    public function test_stdev_of_a_flat_series_is_zero(): void
    {
        $this->assertSame(0.0, Indicators::stdev([5, 5, 5, 5], 4));
    }

    public function test_stdev_matches_the_population_formula(): void
    {
        // [2,4,4,4,5,5,7,9] mean 5 → variance 4 → sd 2
        $this->assertEqualsWithDelta(2.0, Indicators::stdev([2, 4, 4, 4, 5, 5, 7, 9], 8), 1e-9);
    }

    public function test_bollinger_bands_sit_symmetrically_around_the_mean(): void
    {
        $bands = Indicators::bollinger([2, 4, 4, 4, 5, 5, 7, 9], 8, 2.0);

        $this->assertEqualsWithDelta(5.0, $bands['middle'], 1e-9);
        $this->assertEqualsWithDelta(9.0, $bands['upper'], 1e-9);   // 5 + 2*2
        $this->assertEqualsWithDelta(1.0, $bands['lower'], 1e-9);   // 5 - 2*2
    }

    public function test_bollinger_returns_null_when_data_is_short(): void
    {
        $this->assertNull(Indicators::bollinger([1, 2], 20));
    }

    // ── change / drawdown ───────────────────────────────────────────────────

    public function test_change_pct_measures_from_the_lookback_point(): void
    {
        $this->assertEqualsWithDelta(50.0, Indicators::changePct([100, 120, 150], 2), 1e-9);
        $this->assertEqualsWithDelta(-50.0, Indicators::changePct([100, 60, 50], 2), 1e-9);
    }

    public function test_change_pct_is_zero_without_enough_history(): void
    {
        $this->assertSame(0.0, Indicators::changePct([100], 2));
    }

    public function test_drawdown_measures_the_worst_fall_from_a_peak(): void
    {
        // ขึ้นถึง 120 แล้วลงไป 90 → -25%
        $this->assertEqualsWithDelta(-25.0, Indicators::drawdownPct([100, 120, 90, 110]), 1e-9);
    }

    public function test_drawdown_is_zero_when_price_only_rises(): void
    {
        $this->assertSame(0.0, Indicators::drawdownPct([100, 110, 120]));
    }

    public function test_last_returns_null_for_an_empty_series(): void
    {
        $this->assertNull(Indicators::last([]));
        $this->assertSame(3.0, Indicators::last([1, 2, 3]));
    }
}
