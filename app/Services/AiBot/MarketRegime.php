<?php

namespace App\Services\AiBot;

/**
 * TPIX TRADE — ตลาดตอนนี้ "มีทิศทาง" หรือ "ออกข้าง" และไปทางไหน.
 *
 * ═══ ทำไมต้องมี ═══
 * backtest 180 วัน (2 ก.ย. 2026) ชี้ชัดว่ากลยุทธ์แต่ละตัวชนะเฉพาะสภาพตลาดของมัน:
 *   - mean_reversion บน SOL ที่เป็นขาลง: edge −66 bps (ซื้อสวนเทรนด์แล้วโดนลากลง)
 *     บน ETH ที่ออกข้าง: +70 bps
 *   - grid ชนะเฉพาะช่วง sideways · momentum/breakout ชนะเฉพาะช่วงที่มีเทรนด์จริง
 * กลยุทธ์ที่ "เปิดตลอด" จึงได้ผลรวมเป็นศูนย์ — กำไรจากช่วงที่ใช่ถูกช่วงที่ไม่ใช่กินหมด
 * ตัวนี้ให้ทุกกลยุทธ์ถามคำถามเดียวกันก่อนเข้าไม้: "ตอนนี้เป็นตลาดของฉันไหม"
 *
 * ═══ ตัววัด ═══
 *   efficiency ratio (Kaufman) = ระยะทางสุทธิ ÷ ระยะทางที่เดินจริง ใน N แท่ง
 *     ใกล้ 1 = เดินตรงไม่ย้อน (มีทิศทาง) · ใกล้ 0 = แกว่งไปมา (ออกข้าง)
 *     ⚠️ ห้ามใช้ความชันของ EMA เป็นตัววัด — ai_signal เคยพลาดตรงนี้ (ขาขึ้นช้าๆ 90 แท่ง
 *        ได้ความชันเกือบศูนย์ แล้วระบบอ่านว่าออกข้าง จนสั่งขายสวนตลอด)
 *   ทิศทาง = ราคาปิดเทียบ EMA ยาว (ค่าปริยาย 200 แท่ง) — เหนือ = ขาขึ้น ใต้ = ขาลง
 *   atr_pct = ATR(14) ÷ ราคา — ความผันผวนต่อแท่ง ใช้เทียบกับต้นทุนเข้า-ออก
 *
 * pure function ทั้งไฟล์ — รับแท่งเทียน คืนตัวเลข ไม่แตะเวลา/DB
 *
 * Developed by Xman Studio.
 */
final class MarketRegime
{
    public const TRENDING_UP = 'trending_up';

    public const TRENDING_DOWN = 'trending_down';

    public const RANGING = 'ranging';

    /** ER สูงกว่านี้ = มีทิศทาง (ค่าปริยายที่ backtest แล้วใช้ได้ทั้ง BTC/ETH) */
    public const DEFAULT_ER_TRENDING = 0.35;

    /**
     * @param  list<array{close: float, high: float, low: float}>  $candles  เก่า → ใหม่
     * @param  int  $erLookback  จำนวนแท่งของ efficiency ratio
     * @param  int  $emaPeriod  EMA ยาวที่ใช้บอกทิศ (ข้อมูลไม่พอ → ใช้เท่าที่มี ไม่ใช่เดา)
     * @return array{regime: string, er: float, trend: string, ema: float|null, atr_pct: float, above_ema_pct: float|null}
     */
    public static function assess(array $candles, int $erLookback = 20, int $emaPeriod = 200, float $erTrending = self::DEFAULT_ER_TRENDING): array
    {
        $closes = array_map(fn ($c) => (float) $c['close'], $candles);
        $count = count($closes);
        $close = $count > 0 ? $closes[$count - 1] : 0.0;

        $er = self::efficiencyRatio($closes, $erLookback);

        /*
         * EMA ยาวต้องการข้อมูลอย่างน้อยเท่าคาบ — บอทที่ดึงได้ 150 แท่งกับ EMA 200
         * จะไม่มีทิศให้ดู ในกรณีนั้นถอยไปใช้คาบที่พอดีกับข้อมูล (ไม่ต่ำกว่า 50)
         * ดีกว่าตอบ "ไม่รู้" แล้วปล่อยให้กลยุทธ์เข้าไม้เหมือนไม่มีตัวกรอง
         */
        $period = min($emaPeriod, max(50, $count - 1));
        $ema = $count > $period ? Indicators::last(Indicators::ema($closes, $period)) : null;

        $aboveEmaPct = ($ema !== null && $ema > 0) ? (($close - $ema) / $ema) * 100 : null;

        $trend = 'flat';
        if ($aboveEmaPct !== null) {
            $trend = $aboveEmaPct > 0 ? 'up' : 'down';
        }

        $atr = Indicators::last(Indicators::atr($candles, 14));
        $atrPct = ($atr !== null && $close > 0) ? ($atr / $close) * 100 : 0.0;

        if ($er >= $erTrending && $trend !== 'flat') {
            $regime = $trend === 'up' ? self::TRENDING_UP : self::TRENDING_DOWN;
        } else {
            $regime = self::RANGING;
        }

        return [
            'regime' => $regime,
            'er' => round($er, 4),
            'trend' => $trend,
            'ema' => $ema,
            'atr_pct' => round($atrPct, 4),
            'above_ema_pct' => $aboveEmaPct === null ? null : round($aboveEmaPct, 3),
        ];
    }

    /**
     * Kaufman efficiency ratio ของหน้าต่างล่าสุด (0 = แกว่งไปมา · 1 = เดินตรง).
     *
     * @param  list<float>  $closes
     */
    public static function efficiencyRatio(array $closes, int $lookback = 20): float
    {
        $count = count($closes);

        if ($lookback < 1 || $count <= $lookback) {
            return 0.0;
        }

        $window = array_slice($closes, -($lookback + 1));
        $net = abs($window[count($window) - 1] - $window[0]);

        $path = 0.0;
        for ($i = 1; $i < count($window); $i++) {
            $path += abs($window[$i] - $window[$i - 1]);
        }

        return $path > 0 ? max(0.0, min(1.0, $net / $path)) : 0.0;
    }
}
