<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Indicators;
use App\Services\AiBot\MarketRegime;
use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — ตามเทรนด์ด้วย EMA ตัดกัน + ยืนยันด้วยวอลุ่ม.
 *
 * เข้า: EMA เร็วตัดขึ้นเหนือ EMA ช้า (แท่งก่อนหน้ายังอยู่ใต้) และวอลุ่มแท่งล่าสุด
 *      มากกว่าค่าเฉลี่ย 20 แท่ง (ถ้าเปิดตัวกรองวอลุ่ม)
 * ออก: EMA เร็วตัดลงใต้ EMA ช้า
 *
 * ที่ต้องเช็ค "แท่งก่อนหน้าอยู่คนละฝั่ง" เพราะถ้าเช็คแค่ fast > slow มันจะเป็นจริง
 * ตลอดช่วงขาขึ้น → สั่งซื้อซ้ำทุกแท่ง กลายเป็นไล่ราคาแทนที่จะเข้าตอนเริ่มเทรนด์
 *
 * Developed by Xman Studio.
 */
class MomentumStrategy implements Strategy
{
    public function code(): string
    {
        return 'momentum';
    }

    public function minCandles(array $params): int
    {
        // ต้องมีมากพอให้ EMA ช้าคำนวณได้ "สองจุด" ถึงจะรู้ว่าตัดกันหรือยัง
        return (int) ($params['slow_ema'] ?? 26) + 22;
    }

    public function allowsPyramiding(): bool
    {
        return false;
    }

    public function acceptsAiExit(): bool
    {
        return true;
    }

    /** ผ่อน = ยอมรับวอลุ่มที่บางลง (8 จุด → ต้องการแค่ 0.6 เท่าของค่าเฉลี่ย แทน 1.0) */
    public function withAiRelief(array $params, float $reliefPoints): array
    {
        if ($reliefPoints > 0) {
            $params['volume_ratio'] = max(0.5, (float) ($params['volume_ratio'] ?? 1.0) - $reliefPoints / 20);
        }

        return $params;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        if (count($candles) < $this->minCandles($params)) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอสำหรับคำนวณ EMA');
        }

        $fastPeriod = (int) ($params['fast_ema'] ?? 12);
        $slowPeriod = (int) ($params['slow_ema'] ?? 26);

        if ($fastPeriod >= $slowPeriod) {
            return Signal::hold('ตั้งค่าผิด: EMA เร็วต้องน้อยกว่า EMA ช้า');
        }

        $closes = array_column($candles, 'close');
        $close = (float) $closes[count($closes) - 1];
        $fast = Indicators::ema($closes, $fastPeriod);
        $slow = Indicators::ema($closes, $slowPeriod);

        // EMA สองชุดยาวไม่เท่ากัน — ต้องเทียบจากท้ายอาเรย์เสมอ ไม่ใช่ index เดียวกัน
        if (count($fast) < 2 || count($slow) < 2) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอสำหรับคำนวณ EMA');
        }

        $fastNow = $fast[count($fast) - 1];
        $fastPrev = $fast[count($fast) - 2];
        $slowNow = $slow[count($slow) - 1];
        $slowPrev = $slow[count($slow) - 2];

        $crossedUp = $fastPrev <= $slowPrev && $fastNow > $slowNow;
        $crossedDown = $fastPrev >= $slowPrev && $fastNow < $slowNow;

        /*
         * สภาพตลาดใหญ่ — backtest 180 วัน (2 ก.ย. 2026): momentum 1h บน BTC edge 36 bps
         * = เท่าทุนพอดี ชนะ 36% ไม้ที่ "ตัดขึ้นแล้วไปไม่ถึงไหน" คือส่วนที่กินกำไร
         * สามตัวกรองด้านล่างตัดไม้กลุ่มนั้น (ผู้ใช้ปิดได้ทีละตัวในหมวดขั้นสูง)
         */
        $regime = MarketRegime::assess($candles);
        $htfConfirm = filter_var($params['htf_confirm'] ?? true, FILTER_VALIDATE_BOOL);
        $maxHold = (int) ($params['max_hold_bars'] ?? 0);
        $minAtrPct = (float) ($params['min_atr_pct'] ?? 0);

        $meta = [
            'fast' => round($fastNow, 8),
            'slow' => round($slowNow, 8),
            'regime' => $regime['regime'],
            'er' => $regime['er'],
            'atr_pct' => $regime['atr_pct'],
        ];

        if ($position && $crossedDown) {
            return Signal::sell(1.0, 'EMA เร็วตัดลงใต้ EMA ช้า — โมเมนตัมหมด', $meta);
        }

        if ($position) {
            /*
             * time stop: ถือครบแล้วยังไม่กำไร = เทรนด์ที่หวังไม่มา ปิดคืนทุนให้ไม้อื่น
             * วัดจากราคาตลาดตอนเข้า (entry_market) ไม่ใช่ต้นทุนรวมค่าธรรมเนียม —
             * ไม่งั้นไม้ที่ราคาไม่ขยับเลยจะถูกอ่านว่า "ขาดทุน 18 bps" ตลอด
             */
            $barsHeld = (int) ($position['bars_held'] ?? 0);
            $base = (float) ($position['entry_market'] ?? $position['entry']);
            $meta['bars_held'] = $barsHeld;

            if ($maxHold > 0 && $barsHeld >= $maxHold && $close <= $base) {
                return Signal::sell(1.0, "ถือครบ {$maxHold} แท่งแล้วยังไม่กำไร — ปิดไม้คืนทุน (time stop)", $meta);
            }

            return Signal::hold('ถือต่อ เทรนด์ยังไม่กลับตัว', $meta);
        }

        if (! $crossedUp) {
            return Signal::hold('ยังไม่มีสัญญาณตัดขึ้นของ EMA', $meta);
        }

        // ตัดขึ้นใต้เส้นเทรนด์ใหญ่ = เด้งสั้นในขาลง ไม่ใช่เทรนด์ใหม่
        if ($htfConfirm && $regime['trend'] === 'down') {
            return Signal::hold('EMA ตัดขึ้นแต่ราคายังอยู่ใต้เส้นเทรนด์ใหญ่ (EMA 200) — ไม่ซื้อสวนขาลง', $meta);
        }

        // ตลาดนิ่งเกินไป: การเคลื่อนไหวต่อแท่งไม่คุ้มต้นทุนเข้า-ออก 0.36%
        if ($minAtrPct > 0 && $regime['atr_pct'] < $minAtrPct) {
            return Signal::hold(sprintf('ความผันผวนต่ำเกินไป (ATR %.2f%% < %.2f%%) — ไม่คุ้มต้นทุนเข้า-ออก', $regime['atr_pct'], $minAtrPct), $meta);
        }

        if (filter_var($params['volume_filter'] ?? true, FILTER_VALIDATE_BOOL)) {
            $volumes = array_column($candles, 'volume');
            $avgVolume = Indicators::last(Indicators::sma($volumes, 20));
            $currentVolume = (float) $volumes[count($volumes) - 1];
            $meta['volume'] = round($currentVolume, 4);
            $meta['avg_volume'] = round((float) $avgVolume, 4);

            // volume_ratio: วอลุ่มแท่งนี้ต้องไม่น้อยกว่ากี่เท่าของค่าเฉลี่ย (AI ผ่อนลงได้ถึง 0.5)
            $ratio = (float) ($params['volume_ratio'] ?? 1.0);
            $meta['volume_ratio'] = $ratio;

            if ($avgVolume !== null && $currentVolume < $avgVolume * $ratio) {
                return Signal::hold('EMA ตัดขึ้นแต่วอลุ่มไม่ยืนยัน', $meta);
            }
        }

        // ห่างกันมาก = เทรนด์ชัด — ใช้ระยะห่างสัมพัทธ์เป็นน้ำหนักของไม้
        $spread = $slowNow > 0 ? abs($fastNow - $slowNow) / $slowNow : 0.0;
        $strength = min(1.0, 0.5 + $spread * 25);

        return Signal::buy($strength, 'EMA เร็วตัดขึ้นเหนือ EMA ช้า พร้อมวอลุ่มยืนยัน', $meta);
    }
}
