<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Indicators;
use App\Services\AiBot\MarketRegime;
use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — ตารางเทรด (Grid) ในกรอบราคา.
 *
 * กึ่งกลางกรอบ = SMA 20 · กรอบ = ±range_pct/2 · แบ่งเป็น grid_levels ชั้น
 * เข้า: ราคาลงมาต่ำกว่ากึ่งกลางอย่างน้อย 1 ชั้น (ซื้อของถูกในกรอบ)
 * ออก: ราคาขึ้นเหนือราคาที่เข้าไปแล้วอย่างน้อย 1 ชั้น (เก็บกำไรทีละชั้น)
 *
 * หลุดกรอบล่าง = ตลาดไม่ sideway แล้ว → หยุดเข้าใหม่ (กริดขาดทุนหนักสุดตอนเทรนด์ลง)
 *
 * Developed by Xman Studio.
 */
class GridStrategy implements Strategy
{
    public function code(): string
    {
        return 'grid';
    }

    public function minCandles(array $params): int
    {
        return 25;
    }

    public function allowsPyramiding(): bool
    {
        return false;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        if (count($candles) < $this->minCandles($params)) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอสำหรับหากรอบราคา');
        }

        $levels = max(2, (int) ($params['grid_levels'] ?? 10));
        $rangePct = (float) ($params['range_pct'] ?? 6);

        $closes = array_column($candles, 'close');
        $center = Indicators::last(Indicators::sma($closes, 20));
        $close = (float) $closes[count($closes) - 1];

        if ($center === null || $center <= 0) {
            return Signal::hold('หากึ่งกลางกรอบราคาไม่ได้');
        }

        $halfRange = $center * ($rangePct / 100) / 2;
        $upperBound = $center + $halfRange;
        $lowerBound = $center - $halfRange;
        $step = ($halfRange * 2) / $levels;

        $meta = [
            'close' => round($close, 8),
            'center' => round($center, 8),
            'upper' => round($upperBound, 8),
            'lower' => round($lowerBound, 8),
            'step' => round($step, 8),
        ];

        if ($step <= 0) {
            return Signal::hold('กรอบราคาแคบเกินไปจนแบ่งชั้นไม่ได้', $meta);
        }

        if ($position) {
            $target = $position['entry'] + $step;
            $meta['target'] = round($target, 8);

            if ($close >= $target) {
                return Signal::sell(1.0, 'ราคาขึ้นครบหนึ่งชั้นของกริด — เก็บกำไร', $meta);
            }

            if ($close < $lowerBound) {
                return Signal::sell(1.0, 'ราคาหลุดกรอบล่าง — ปิดไม้กันขาดทุนบานปลาย', $meta);
            }

            return Signal::hold('ถือต่อ รอราคาขึ้นครบหนึ่งชั้น', $meta);
        }

        if ($close < $lowerBound) {
            return Signal::hold('ราคาหลุดกรอบล่าง ตลาดไม่ sideway แล้ว — หยุดเข้าใหม่', $meta);
        }

        /*
         * กริดชนะเฉพาะตลาดออกข้าง — ในตลาดที่มีทิศทาง "ราคาลงถึงชั้นซื้อ" เกิดทุกแท่ง
         * แล้วกลายเป็นรับมีดตกทีละชั้น (backtest 180 วัน: ช่วงขาลง edge +20 bps
         * ช่วงออกข้าง +107 bps) ใช้ efficiency ratio วัด: ต่ำกว่า er_max = ออกข้าง
         */
        if (filter_var($params['regime_filter'] ?? true, FILTER_VALIDATE_BOOL)) {
            $erMax = (float) ($params['er_max'] ?? MarketRegime::DEFAULT_ER_TRENDING);
            $regime = MarketRegime::assess($candles, 20, 200, $erMax);
            $meta['er'] = $regime['er'];
            $meta['regime'] = $regime['regime'];

            if ($regime['regime'] !== MarketRegime::RANGING) {
                return Signal::hold(sprintf('ตลาดกำลังมีทิศทาง (ER %.2f ≥ %.2f) — กริดใช้ได้เฉพาะตลาดออกข้าง', $regime['er'], $erMax), $meta);
            }
        }

        if ($close > $center - $step) {
            return Signal::hold('ราคายังไม่ลงถึงชั้นซื้อของกริด', $meta);
        }

        // ลงลึกในกรอบเท่าไหร่ ยิ่งซื้อหนักขึ้น (แต่ไม่เกินเต็มไม้)
        $depth = $halfRange > 0 ? ($center - $close) / $halfRange : 0.0;
        $strength = min(1.0, 0.5 + $depth * 0.5);

        return Signal::buy($strength, 'ราคาลงมาถึงชั้นซื้อของกริด', $meta);
    }
}
