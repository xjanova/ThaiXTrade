<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Indicators;
use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — เบรกเอาต์กรอบราคา (Donchian) พร้อม stop ตามความผันผวน (ATR).
 *
 * เข้า: ราคาปิดทะลุขอบบนของกรอบ N แท่งก่อนหน้า
 * ออก: ราคาปิดหลุดขอบล่าง หรือหลุด stop ที่คำนวณจาก ATR × ตัวคูณ
 *
 * ทิศทาง short ยังไม่รองรับจริงในระบบ (ไม่มีตลาดยืมเหรียญ) — เลือก 'short' ไว้
 * จะได้ hold พร้อมเหตุผล ไม่ใช่แกล้งทำเป็นเปิด short ได้
 *
 * Developed by Xman Studio.
 */
class BreakoutStrategy implements Strategy
{
    public function code(): string
    {
        return 'breakout';
    }

    public function minCandles(array $params): int
    {
        return max((int) ($params['channel_period'] ?? 20), 14) + 16;
    }

    public function allowsPyramiding(): bool
    {
        return false;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        if (count($candles) < $this->minCandles($params)) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอสำหรับคำนวณกรอบราคา');
        }

        $period = (int) ($params['channel_period'] ?? 20);
        $atrMultiple = (float) ($params['atr_multiple'] ?? 2);
        // ค่าปริยายเป็น long — ทั้งเอนจินเป็น spot ฝั่งซื้ออย่างเดียว
        // (บอทเก่าที่บันทึก 'both' ไว้ ถูก sanitizeParams ยกมาเป็น long ให้แล้วตอนรัน)
        $direction = $params['direction'] ?? 'long';

        $channel = Indicators::donchian($candles, $period);
        $upper = Indicators::last($channel['upper']);
        $lower = Indicators::last($channel['lower']);
        $atr = Indicators::last(Indicators::atr($candles, 14));
        $close = (float) $candles[count($candles) - 1]['close'];

        if ($upper === null || $lower === null || $atr === null) {
            return Signal::hold('คำนวณกรอบราคา/ATR ไม่ได้');
        }

        $meta = [
            'close' => round($close, 8),
            'upper' => round($upper, 8),
            'lower' => round($lower, 8),
            'atr' => round($atr, 8),
        ];

        if ($position) {
            // วาง stop จากราคาตลาดตอนเข้าไม้ ไม่ใช่ต้นทุนที่รวมค่าธรรมเนียมไว้แล้ว
            // (ต้นทุนลอยเหนือตลาด ~18 bps — ใช้มันวาง stop = ตัดขาดทุนตัวเองทันที)
            $base = (float) ($position['entry_market'] ?? $position['entry']);
            $stop = $base - $atr * $atrMultiple;
            $meta['stop'] = round($stop, 8);

            if ($close <= $stop) {
                return Signal::sell(1.0, 'ราคาหลุด stop ที่คำนวณจาก ATR', $meta);
            }

            if ($close < $lower) {
                return Signal::sell(1.0, 'ราคาปิดหลุดขอบล่างของกรอบ', $meta);
            }

            return Signal::hold('ถือต่อ ยังไม่หลุดกรอบและยังไม่ถึง stop', $meta);
        }

        if ($direction === 'short') {
            return Signal::hold('ทิศทาง short ยังไม่เปิดใช้งานในระบบนี้', $meta);
        }

        if ($close <= $upper) {
            return Signal::hold('ราคายังไม่ทะลุขอบบนของกรอบ', $meta);
        }

        // ทะลุแรงแค่ไหนเทียบกับความผันผวนปกติ — ทะลุนิดเดียวมักเป็นสัญญาณหลอก
        $breakoutSize = $atr > 0 ? ($close - $upper) / $atr : 0.0;
        $strength = min(1.0, 0.45 + $breakoutSize);
        $meta['breakout_atr'] = round($breakoutSize, 4);

        return Signal::buy($strength, 'ราคาปิดทะลุขอบบนของกรอบราคา', $meta);
    }
}
