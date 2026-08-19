<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Indicators;
use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — สแกลป์รอบสั้น เก็บกำไรทีละไม่กี่ bps.
 *
 * ⚠️ ข้อจำกัดที่ต้องรู้: กลยุทธ์สแกลป์ของจริงอ่านความไม่สมดุลของสมุดคำสั่งแบบ
 *    ทุกวินาที ระบบนี้ตัดสินใจจากแท่งเทียนรอบละหลายนาที จึงใช้ "โมเมนตัมสั้น +
 *    ความผันผวนที่ยังไม่บาน" เป็นตัวแทน ไม่ใช่การอ่าน order book จริง
 *    เขียนไว้ตรงนี้เพื่อไม่ให้เข้าใจผิดว่าเป็นสแกลป์ระดับ tick
 *
 * เข้า: ราคาขยับขึ้นต่อเนื่อง 2 แท่ง และความผันผวน (ATR/ราคา) ไม่เกินเพดาน
 * ออก: ได้กำไรถึงเป้า (bps) หรือราคาย้อนกลับลง
 *
 * Developed by Xman Studio.
 */
class ScalpingStrategy implements Strategy
{
    public function code(): string
    {
        return 'scalping';
    }

    public function minCandles(array $params): int
    {
        return 20;
    }

    public function allowsPyramiding(): bool
    {
        return false;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        if (count($candles) < $this->minCandles($params)) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอ');
        }

        $targetBps = (float) ($params['target_bps'] ?? 45);
        $maxVolatilityBps = (float) ($params['max_volatility_bps'] ?? $params['max_spread_bps'] ?? 80);

        /*
         * "พักระหว่างไม้" ที่ผู้ใช้ตั้งไว้ — ต้องมีผลจริง
         *
         * เวลามาจาก engine (`_seconds_since_trade`) เพราะกลยุทธ์เห็นแต่แท่งเทียน
         * ไม่รู้ว่าไม้ล่าสุดปิดไปกี่วินาที ค่านี้ไม่มีอยู่ = ยังไม่เคยเทรด จึงไม่ต้องพัก
         */
        $cooldown = (float) ($params['cooldown_sec'] ?? 0);
        $sinceTrade = $params['_seconds_since_trade'] ?? PHP_INT_MAX;

        if (! $position && $cooldown > 0 && $sinceTrade < $cooldown) {
            return Signal::hold(sprintf('พักระหว่างไม้ อีก %d วิ', (int) ceil($cooldown - $sinceTrade)));
        }

        $closes = array_column($candles, 'close');
        $close = (float) $closes[count($closes) - 1];
        $prev = (float) $closes[count($closes) - 2];
        $prev2 = (float) $closes[count($closes) - 3];

        $atr = Indicators::last(Indicators::atr($candles, 14));
        // ใช้ ATR เทียบราคาเป็นตัวแทน "ต้นทุนการเข้าออก" — ผันผวนสูง = สเปรดกว้าง
        $volatilityBps = ($atr !== null && $close > 0) ? ($atr / $close) * 10000 : 0.0;

        $meta = [
            'close' => round($close, 8),
            'volatility_bps' => round($volatilityBps, 1),
            'max_volatility_bps' => $maxVolatilityBps,
            'target_bps' => $targetBps,
        ];

        if ($position) {
            $gainBps = $position['entry'] > 0
                ? (($close - $position['entry']) / $position['entry']) * 10000
                : 0.0;
            $meta['gain_bps'] = round($gainBps, 1);

            if ($gainBps >= $targetBps) {
                return Signal::sell(1.0, 'ถึงเป้ากำไรของรอบสแกลป์', $meta);
            }

            if ($close < $prev && $prev < $prev2) {
                return Signal::sell(1.0, 'ราคาย้อนกลับสองแท่งติด — ตัดไม้', $meta);
            }

            return Signal::hold('ถือต่อ ยังไม่ถึงเป้าและยังไม่ย้อน', $meta);
        }

        /*
         * ผันผวนเกินเพดาน = ต้นทุนเข้าออกกินกำไรรอบสั้น ไม่คุ้มเข้า
         *
         * ⚠️ เดิมเขียน `> $maxSpreadBps * 10` โดยไม่มีคำอธิบาย — ผู้ใช้ตั้ง 8
         *    แต่เกณฑ์จริงคือ 80 ต่างกันสิบเท่าโดยไม่มีอะไรบอก และค่าที่วัดคือ
         *    ATR ต่อราคา ไม่ใช่สเปรด bid/ask ตามชื่อช่อง
         *
         *    ที่แย่กว่าคือ meta ของทุกไม้เก็บ `volatility_bps: 79` คู่กับ
         *    `max_spread_bps: 8` ไว้บนคำสั่งซื้อ = หลักฐานที่ขัดกันเองในระบบเรา
         *
         *    ตอนนี้เทียบตรงๆ กับค่าที่ผู้ใช้ตั้ง และเปลี่ยนชื่อช่องใน config
         *    ให้ตรงกับสิ่งที่วัดจริง (ความผันผวนต่อแท่ง ไม่ใช่สเปรด)
         */
        if ($volatilityBps > $maxVolatilityBps) {
            return Signal::hold('ความผันผวนสูงเกินไปสำหรับรอบสั้น', $meta);
        }

        if (! ($close > $prev && $prev > $prev2)) {
            return Signal::hold('ยังไม่มีโมเมนตัมขาขึ้นต่อเนื่อง', $meta);
        }

        return Signal::buy(0.6, 'ราคาขยับขึ้นต่อเนื่องในตลาดที่ยังนิ่ง', $meta);
    }
}
