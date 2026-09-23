<?php

namespace App\Services\AiBot;

/**
 * TPIX TRADE — แนวโน้มใหญ่ของตลาด: ราคาปิดรายวันของ BTC อยู่เหนือหรือใต้ EMA รายวัน.
 *
 * ═══ ทำไมต้องมี ═══
 * backtest 2 ปี (ก.ย. 2024 → ก.ย. 2026) บน BTC/ETH/SOL/BNB/XRP ด้วยกลยุทธ์ตัวจริงทุกตัว:
 * ไม่มีกลยุทธ์ไหนมี edge ข้ามสภาพตลาด — ปีขาขึ้น (ถือเฉยๆ +129%) ได้ ปีขาลง (−34%) เสีย
 * กลยุทธ์ฝั่งซื้ออย่างเดียวส่วนใหญ่แค่ "ขี่ทิศตลาด" ตัวแปรที่ตัดสินผลมากที่สุดจึงไม่ใช่
 * พารามิเตอร์ของกลยุทธ์ แต่คือ "ตลาดใหญ่เป็นขาขึ้นอยู่ไหมตอนเปิดไม้"
 *
 * กรองการเปิดไม้ใหม่ด้วย BTC รายวัน > EMA 50 (ประเมินจากไม้จริงของ backtest ทั้งชุด):
 *   ai_signal +11.6% → +39.2% · breakout +11.3% → +30.9% · dca −0.7% → +20.7%
 *   momentum −12.8% → +2.9% · ปีขาลงของ dca −34.7% → −2.1%
 * และไม่ใช่ค่าบังเอิญ: EMA 50/100/200 ของทั้งเหรียญตัวเองและ BTC ดีขึ้นทุกแบบ
 * (BTC ดีกว่าเหรียญตัวเอง — BTC นำตลาด เหรียญอื่นตามทีหลัง)
 *
 * ═══ pure — ใช้ร่วมกันทั้งบอทจริงและ backtester ═══
 * สองฝั่งต้องตัดสินจากแท่งชุดเดียวกัน: แท่งรายวันที่ "ปิดแล้ว" ย้อนหลังไม่เกิน window แท่ง
 * (EMA ที่เริ่มนับจาก SMA ให้ค่าต่างกันตามความยาวข้อมูล ความยาวต้องเท่ากันถึงจะเทียบได้)
 *
 * Developed by Xman Studio.
 */
final class MacroTrend
{
    /**
     * แท่งรายวันย้อนหลังที่ใช้คำนวณ — ยาวสุดที่ตัวดึงราคาให้ได้ (500 แท่ง ตัดแท่งที่ยังวิ่ง 1).
     *
     * EMA เริ่มจาก SMA ของ N แท่งแรกแล้วเดินต่อ (WINDOW − N) แท่ง น้ำหนักค่าเริ่มที่เหลือ:
     *   EMA 50 ≈ 0% · EMA 100 ≈ 0.03% · EMA 200 ≈ 5%
     * ⚠️ รีวิว 2026-09-23: เดิม 300 แท่ง — EMA 200 เหลือค่าเริ่มหนัก ~37% ตัวเลือก "EMA 200"
     *    จึงเกือบครึ่งหนึ่งคือ SMA ของเมื่อ 100 วันก่อน ไม่ใช่ EMA 200 ตามป้าย
     */
    public const WINDOW = 499;

    /**
     * ประเมินจากแท่งรายวันที่ปิดแล้ว (เก่า → ใหม่).
     *
     * up = null เมื่อข้อมูลไม่พอ — ผู้เรียกต้องถือว่า "ไม่รู้" (ไม่กรอง) ไม่ใช่ "ขาลง"
     *
     * @param  list<array{close: float|string}>  $daily
     * @return array{up: bool|null, close: float|null, ema: float|null, above_pct: float|null}
     */
    public static function assess(array $daily, int $period = 50): array
    {
        $unknown = ['up' => null, 'close' => null, 'ema' => null, 'above_pct' => null];

        $daily = array_slice(array_values($daily), -self::WINDOW);

        if ($period < 2 || count($daily) < $period + 1) {
            return $unknown;
        }

        $closes = array_map(fn ($c) => (float) $c['close'], $daily);
        $ema = Indicators::last(Indicators::ema($closes, $period));
        $close = $closes[count($closes) - 1];

        if ($ema === null || $ema <= 0) {
            return $unknown;
        }

        return [
            'up' => $close > $ema,
            'close' => $close,
            'ema' => round($ema, 8),
            'above_pct' => round(($close - $ema) / $ema * 100, 3),
        ];
    }

    /**
     * สถานะแนวโน้มหลังแต่ละแท่งรายวันปิด — ให้ backtester ถามได้ทุกแท่งโดยไม่คำนวณซ้ำ.
     *
     * @param  list<array{time: int, close: float|string}>  $daily  แท่งรายวันที่ปิดแล้ว (time = เวลาเปิด ms)
     * @return list<array{0: int, 1: bool|null}> [เวลาปิดแท่ง ms, up]
     */
    public static function series(array $daily, int $period = 50): array
    {
        $daily = array_values($daily);
        $out = [];

        foreach ($daily as $i => $candle) {
            $window = array_slice($daily, max(0, $i + 1 - self::WINDOW), min($i + 1, self::WINDOW));
            $out[] = [(int) $candle['time'] + 86_400_000, self::assess($window, $period)['up']];
        }

        return $out;
    }

    /**
     * สถานะ ณ เวลาหนึ่ง จาก series() — ใช้แท่งล่าสุดที่ "ปิดก่อนหรือพอดี" เวลานั้นเท่านั้น.
     *
     * @param  list<array{0: int, 1: bool|null}>  $series
     * @param  int  $cursor  ตำแหน่งที่ค้นค้างไว้ (เดินหน้าอย่างเดียว เพราะ backtest ถามตามเวลา)
     */
    public static function upAt(array $series, int $timeMs, int &$cursor): ?bool
    {
        $count = count($series);

        while ($cursor + 1 < $count && $series[$cursor + 1][0] <= $timeMs) {
            $cursor++;
        }

        return ($series[$cursor][0] ?? PHP_INT_MAX) <= $timeMs ? $series[$cursor][1] : null;
    }
}
