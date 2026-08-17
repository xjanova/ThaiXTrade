<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Indicators;
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

        // ต่ำกว่าเกณฑ์ 0 จุด = แรง 0.5, ต่ำกว่า 20 จุดขึ้นไป = แรงเต็ม
        $strength = min(1.0, 0.5 + (($oversold - $rsi) / 20) * 0.5);

        return Signal::buy($strength, "RSI {$meta['rsi']} เข้าเขตขายมากเกินไป — เข้าซื้อสวน", $meta);
    }
}
