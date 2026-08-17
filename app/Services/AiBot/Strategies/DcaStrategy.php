<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Indicators;
use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — ทยอยสะสมตามรอบเวลา (DCA) + เพิ่มไม้ตอนราคาย่อ.
 *
 * เข้า: ครบรอบตามจำนวนแท่งที่เทียบเท่า interval_hours (คำนวณจาก timeframe ที่บอทใช้)
 *      และเพิ่มน้ำหนักไม้เมื่อราคาต่ำกว่า SMA 20 เกิน dip_boost_pct
 * ออก: DCA แบบคลาสสิกไม่มีจุดขาย — ปล่อยให้ take profit / stop loss ของกรอบ
 *      ความเสี่ยงเป็นคนปิดไม้ (engine จัดการให้) กลยุทธ์นี้จึงไม่สั่งขายเอง
 *
 * ใช้ "จำนวนแท่งตั้งแต่เข้าไม้ล่าสุด" แทนเวลาจริง เพื่อให้เป็น pure function
 * (ทดสอบซ้ำได้ผลเดิม ไม่ขึ้นกับนาฬิกาเครื่อง)
 *
 * Developed by Xman Studio.
 */
class DcaStrategy implements Strategy
{
    public function code(): string
    {
        return 'dca';
    }

    public function minCandles(array $params): int
    {
        return 21;
    }

    public function allowsPyramiding(): bool
    {
        // ทยอยสะสมคือหัวใจของ DCA — ต้องเติมไม้ทับของเดิมได้ แล้วเฉลี่ยต้นทุนใหม่
        return true;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        if (count($candles) < $this->minCandles($params)) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอสำหรับคำนวณค่าเฉลี่ย');
        }

        $closes = array_column($candles, 'close');
        $close = (float) $closes[count($closes) - 1];
        $sma = Indicators::last(Indicators::sma($closes, 20));

        if ($sma === null || $sma <= 0) {
            return Signal::hold('คำนวณค่าเฉลี่ยไม่ได้');
        }

        $dipPct = (($sma - $close) / $sma) * 100;
        $boostThreshold = (float) ($params['dip_boost_pct'] ?? 3);

        $meta = [
            'close' => round($close, 8),
            'sma20' => round($sma, 8),
            'dip_pct' => round($dipPct, 3),
        ];

        // ครบรอบหรือยัง — engine ส่ง bars_since_last_entry มาให้ผ่าน params
        $barsSinceEntry = (int) ($params['_bars_since_entry'] ?? PHP_INT_MAX);
        $intervalBars = max(1, (int) ($params['_interval_bars'] ?? 1));
        $meta['bars_since_entry'] = $barsSinceEntry === PHP_INT_MAX ? null : $barsSinceEntry;
        $meta['interval_bars'] = $intervalBars;

        if ($barsSinceEntry < $intervalBars) {
            return Signal::hold('ยังไม่ครบรอบเข้าซื้อถัดไป', $meta);
        }

        // ย่อลึก = ซื้อหนักขึ้น (แต่ไม่เกินเต็มไม้) · ไม่ย่อก็ยังซื้อตามรอบปกติ
        $strength = $dipPct >= $boostThreshold
            ? min(1.0, 0.7 + ($dipPct - $boostThreshold) / 20)
            : 0.5;

        $reason = $dipPct >= $boostThreshold
            ? 'ครบรอบเข้าซื้อ และราคาย่อต่ำกว่าค่าเฉลี่ย — เพิ่มไม้'
            : 'ครบรอบเข้าซื้อตามแผนทยอยสะสม';

        return Signal::buy($strength, $reason, $meta);
    }
}
