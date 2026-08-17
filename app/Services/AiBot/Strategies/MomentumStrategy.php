<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Indicators;
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

        $meta = ['fast' => round($fastNow, 8), 'slow' => round($slowNow, 8)];

        if ($position && $crossedDown) {
            return Signal::sell(1.0, 'EMA เร็วตัดลงใต้ EMA ช้า — โมเมนตัมหมด', $meta);
        }

        if ($position) {
            return Signal::hold('ถือต่อ เทรนด์ยังไม่กลับตัว', $meta);
        }

        if (! $crossedUp) {
            return Signal::hold('ยังไม่มีสัญญาณตัดขึ้นของ EMA', $meta);
        }

        if (filter_var($params['volume_filter'] ?? true, FILTER_VALIDATE_BOOL)) {
            $volumes = array_column($candles, 'volume');
            $avgVolume = Indicators::last(Indicators::sma($volumes, 20));
            $currentVolume = (float) $volumes[count($volumes) - 1];
            $meta['volume'] = round($currentVolume, 4);
            $meta['avg_volume'] = round((float) $avgVolume, 4);

            if ($avgVolume !== null && $currentVolume < $avgVolume) {
                return Signal::hold('EMA ตัดขึ้นแต่วอลุ่มไม่ยืนยัน', $meta);
            }
        }

        // ห่างกันมาก = เทรนด์ชัด — ใช้ระยะห่างสัมพัทธ์เป็นน้ำหนักของไม้
        $spread = $slowNow > 0 ? abs($fastNow - $slowNow) / $slowNow : 0.0;
        $strength = min(1.0, 0.5 + $spread * 25);

        return Signal::buy($strength, 'EMA เร็วตัดขึ้นเหนือ EMA ช้า พร้อมวอลุ่มยืนยัน', $meta);
    }
}
