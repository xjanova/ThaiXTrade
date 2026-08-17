<?php

namespace App\Services\AiBot;

/**
 * TPIX TRADE — ตัวชี้วัดทางเทคนิคสำหรับ AI TRADE engine.
 *
 * ฟังก์ชันทั้งหมดเป็น pure — รับอาเรย์ตัวเลข คืนอาเรย์ตัวเลข ไม่มี state ไม่แตะ I/O
 * ทำให้ทดสอบค่าที่คำนวณได้ตรงกับที่คิดด้วยมือ (ดู IndicatorsTest)
 *
 * กติกาที่ยึดทั้งไฟล์:
 *  - ข้อมูลไม่พอ → คืนอาเรย์ว่าง ไม่ใช่ค่ามั่ว (ผู้เรียกต้องเช็คเอง)
 *  - ผลลัพธ์ "เรียงตามเวลาเหมือน input" และมีความยาวเท่าจำนวนจุดที่คำนวณได้จริง
 *    (ไม่ pad ศูนย์ข้างหน้า — ศูนย์ปลอมคือที่มาของสัญญาณผิดที่หาสาเหตุยากที่สุด)
 *
 * Developed by Xman Studio.
 */
class Indicators
{
    /**
     * Simple Moving Average.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function sma(array $values, int $period): array
    {
        if ($period < 1 || count($values) < $period) {
            return [];
        }

        $out = [];
        $sum = (float) array_sum(array_slice($values, 0, $period));
        $out[] = $sum / $period;

        for ($i = $period; $i < count($values); $i++) {
            $sum += $values[$i] - $values[$i - $period];
            $out[] = $sum / $period;
        }

        return $out;
    }

    /**
     * Exponential Moving Average — ค่าแรกใช้ SMA เป็นตัวตั้ง (แบบมาตรฐาน).
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function ema(array $values, int $period): array
    {
        if ($period < 1 || count($values) < $period) {
            return [];
        }

        $k = 2 / ($period + 1);
        $ema = (float) array_sum(array_slice($values, 0, $period)) / $period;
        $out = [$ema];

        for ($i = $period; $i < count($values); $i++) {
            $ema = ($values[$i] - $ema) * $k + $ema;
            $out[] = $ema;
        }

        return $out;
    }

    /**
     * Relative Strength Index (Wilder smoothing).
     *
     * @param  list<float>  $values
     * @return list<float> ยาว count($values) - $period
     */
    public static function rsi(array $values, int $period = 14): array
    {
        if ($period < 1 || count($values) <= $period) {
            return [];
        }

        $gains = 0.0;
        $losses = 0.0;

        for ($i = 1; $i <= $period; $i++) {
            $change = $values[$i] - $values[$i - 1];
            $change >= 0 ? $gains += $change : $losses -= $change;
        }

        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        $out = [self::rsiFrom($avgGain, $avgLoss)];

        for ($i = $period + 1; $i < count($values); $i++) {
            $change = $values[$i] - $values[$i - 1];
            $gain = $change > 0 ? $change : 0.0;
            $loss = $change < 0 ? -$change : 0.0;

            $avgGain = ($avgGain * ($period - 1) + $gain) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $loss) / $period;

            $out[] = self::rsiFrom($avgGain, $avgLoss);
        }

        return $out;
    }

    /** ไม่มีการขาดทุนเลย = RSI 100 (หารศูนย์ไม่ได้) */
    private static function rsiFrom(float $avgGain, float $avgLoss): float
    {
        if ($avgLoss <= 0.0) {
            return $avgGain > 0 ? 100.0 : 50.0;
        }

        return 100 - (100 / (1 + $avgGain / $avgLoss));
    }

    /**
     * Average True Range (Wilder) — ใช้วัดความผันผวนเพื่อคำนวณระยะ stop.
     *
     * @param  list<array{high: float, low: float, close: float}>  $candles
     * @return list<float>
     */
    public static function atr(array $candles, int $period = 14): array
    {
        if ($period < 1 || count($candles) <= $period) {
            return [];
        }

        $trueRanges = [];
        for ($i = 1; $i < count($candles); $i++) {
            $trueRanges[] = max(
                $candles[$i]['high'] - $candles[$i]['low'],
                abs($candles[$i]['high'] - $candles[$i - 1]['close']),
                abs($candles[$i]['low'] - $candles[$i - 1]['close']),
            );
        }

        if (count($trueRanges) < $period) {
            return [];
        }

        $atr = (float) array_sum(array_slice($trueRanges, 0, $period)) / $period;
        $out = [$atr];

        for ($i = $period; $i < count($trueRanges); $i++) {
            $atr = ($atr * ($period - 1) + $trueRanges[$i]) / $period;
            $out[] = $atr;
        }

        return $out;
    }

    /**
     * Donchian channel — สูงสุด/ต่ำสุดของ N แท่งก่อนหน้า (ไม่รวมแท่งปัจจุบัน).
     *
     * ที่ "ไม่รวมแท่งปัจจุบัน" สำคัญมาก: ถ้ารวม ราคาที่ทำ high ใหม่จะเท่ากับขอบบนเสมอ
     * → เงื่อนไข "ทะลุกรอบ" เป็นจริงทุกแท่ง กลายเป็นสัญญาณขยะ
     *
     * @param  list<array{high: float, low: float}>  $candles
     * @return array{upper: list<float>, lower: list<float>}
     */
    public static function donchian(array $candles, int $period = 20): array
    {
        if ($period < 1 || count($candles) <= $period) {
            return ['upper' => [], 'lower' => []];
        }

        $upper = [];
        $lower = [];

        for ($i = $period; $i < count($candles); $i++) {
            $window = array_slice($candles, $i - $period, $period);
            $upper[] = (float) max(array_column($window, 'high'));
            $lower[] = (float) min(array_column($window, 'low'));
        }

        return ['upper' => $upper, 'lower' => $lower];
    }

    /**
     * ส่วนเบี่ยงเบนมาตรฐาน (population) ของหน้าต่างล่าสุด.
     *
     * @param  list<float>  $values
     */
    public static function stdev(array $values, int $period): float
    {
        if ($period < 1 || count($values) < $period) {
            return 0.0;
        }

        $window = array_slice($values, -$period);
        $mean = (float) array_sum($window) / $period;
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $window)) / $period;

        return sqrt($variance);
    }

    /**
     * Bollinger Bands ของจุดล่าสุด.
     *
     * @param  list<float>  $values
     * @return array{middle: float, upper: float, lower: float}|null
     */
    public static function bollinger(array $values, int $period = 20, float $multiplier = 2.0): ?array
    {
        if (count($values) < $period) {
            return null;
        }

        $sma = (float) array_sum(array_slice($values, -$period)) / $period;
        $sd = self::stdev($values, $period);

        return [
            'middle' => $sma,
            'upper' => $sma + $multiplier * $sd,
            'lower' => $sma - $multiplier * $sd,
        ];
    }

    /**
     * เปอร์เซ็นต์การเปลี่ยนแปลงจาก N แท่งก่อนถึงแท่งล่าสุด.
     *
     * @param  list<float>  $values
     */
    public static function changePct(array $values, int $lookback): float
    {
        if ($lookback < 1 || count($values) <= $lookback) {
            return 0.0;
        }

        $from = $values[count($values) - 1 - $lookback];
        $to = $values[count($values) - 1];

        return $from > 0 ? (($to - $from) / $from) * 100 : 0.0;
    }

    /**
     * ราคาต่ำสุดหลังจากจุดสูงสุดล่าสุด — ใช้วัด drawdown ของช่วงที่ดู.
     *
     * @param  list<float>  $values
     * @return float เปอร์เซ็นต์ติดลบ (0 = ไม่มีการย่อ)
     */
    public static function drawdownPct(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $peak = $values[0];
        $worst = 0.0;

        foreach ($values as $value) {
            $peak = max($peak, $value);
            if ($peak > 0) {
                $worst = min($worst, (($value - $peak) / $peak) * 100);
            }
        }

        return $worst;
    }

    /** ค่าสุดท้ายของอาเรย์ (null ถ้าว่าง) */
    public static function last(array $values): ?float
    {
        return $values === [] ? null : (float) $values[array_key_last($values)];
    }
}
