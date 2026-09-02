<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Indicators;
use App\Services\AiBot\MarketRegime;
use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — สวนกลับค่าเฉลี่ยด้วย RSI.
 *
 * เข้า: RSI ต่ำกว่าระดับ oversold (ขายมากเกินไป)
 * ออก: RSI สูงกว่าระดับ overbought (ซื้อมากเกินไป)
 *
 * ยิ่ง RSI ต่ำกว่าเกณฑ์มาก น้ำหนักไม้ยิ่งมาก — แต่ตัดที่ 1.0
 *
 * Developed by Xman Studio.
 */
class MeanReversionStrategy implements Strategy
{
    public function code(): string
    {
        return 'mean_reversion';
    }

    public function minCandles(array $params): int
    {
        return (int) ($params['rsi_period'] ?? 14) + 5;
    }

    public function allowsPyramiding(): bool
    {
        return false;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        if (count($candles) < $this->minCandles($params)) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอสำหรับคำนวณ RSI');
        }

        $period = (int) ($params['rsi_period'] ?? 14);
        $oversold = (float) ($params['oversold'] ?? 30);
        $overbought = (float) ($params['overbought'] ?? 70);

        if ($oversold >= $overbought) {
            return Signal::hold('ตั้งค่าผิด: ระดับ oversold ต้องน้อยกว่า overbought');
        }

        $rsi = Indicators::last(Indicators::rsi(array_column($candles, 'close'), $period));

        if ($rsi === null) {
            return Signal::hold('คำนวณ RSI ไม่ได้');
        }

        $meta = ['rsi' => round($rsi, 2), 'oversold' => $oversold, 'overbought' => $overbought];

        if ($position) {
            return $rsi >= $overbought
                ? Signal::sell(1.0, "RSI {$meta['rsi']} เข้าเขตซื้อมากเกินไป — ขายทำกำไร", $meta)
                : Signal::hold('ถือต่อ RSI ยังไม่ถึงเขตขาย', $meta);
        }

        if ($rsi > $oversold) {
            return Signal::hold('RSI ยังไม่ต่ำพอที่จะเข้าซื้อ', $meta);
        }

        /*
         * RSI ต่ำในขาลงใหญ่คือ "มีดตก" ไม่ใช่ของถูก
         *
         * backtest 180 วัน (2 ก.ย. 2026): กลยุทธ์นี้บน SOL ที่อยู่ใต้ EMA 200 เกือบตลอด
         * ได้ edge −66 bps · บน ETH ที่ออกข้าง/ขาขึ้น +70 bps — สิ่งที่ต่างกันคือ
         * ทิศของเทรนด์ใหญ่ ไม่ใช่ RSI ส่วนการย่อ "ในขาขึ้น" ยังซื้อได้ตามปกติ
         * เพราะนั่นคือฉากที่กลยุทธ์นี้ทำเงินจริง
         */
        if (filter_var($params['regime_filter'] ?? true, FILTER_VALIDATE_BOOL)) {
            $regime = MarketRegime::assess($candles);
            $tolerance = (float) ($params['max_below_ema_pct'] ?? 5);
            $meta['trend'] = $regime['trend'];
            $meta['above_ema_pct'] = $regime['above_ema_pct'];

            /*
             * ใช้ "ระยะ" ใต้เส้น ไม่ใช่แค่ "อยู่ใต้เส้น" — การย่อที่ RSI < 30 มักพาราคา
             * ลงไปใต้ EMA 200 นิดหน่อยเสมอ ถ้าห้ามทันทีที่ต่ำกว่าเส้น backtest 180 วัน
             * เหลือไม้ 26 → 5 (BTC) และ 28 → 2 (ETH) คือฆ่ากลยุทธ์ทิ้ง สิ่งที่ต้องกัน
             * คือ "ลึกใต้เส้นหลายเปอร์เซ็นต์" แบบ SOL ที่ทำ edge −66 bps
             */
            if ($regime['above_ema_pct'] !== null && $regime['above_ema_pct'] < -$tolerance) {
                return Signal::hold(sprintf(
                    'RSI %s ต่ำ แต่ราคาอยู่ใต้เส้นเทรนด์ใหญ่ (EMA 200) ถึง %.1f%% (ยอมได้ %.1f%%) — ไม่รับมีดตก',
                    $meta['rsi'],
                    abs($regime['above_ema_pct']),
                    $tolerance,
                ), $meta);
            }
        }

        // ต่ำกว่าเกณฑ์ 0 จุด = แรง 0.5, ต่ำกว่า 20 จุดขึ้นไป = แรงเต็ม
        $strength = min(1.0, 0.5 + (($oversold - $rsi) / 20) * 0.5);

        return Signal::buy($strength, "RSI {$meta['rsi']} เข้าเขตขายมากเกินไป — เข้าซื้อสวน", $meta);
    }
}
