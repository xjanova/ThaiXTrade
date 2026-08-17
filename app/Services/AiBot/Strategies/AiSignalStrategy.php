<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Indicators;
use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — สัญญาณรวมของ TPIX (ensemble).
 *
 * ⚠️ ชื่อว่า "AI" แต่เป็น **โมเดลกฎรวมคะแนน** ที่ตรวจสอบย้อนหลังได้ ไม่ใช่ LLM
 *    เจตนาชัดเจน: การตัดสินใจที่เอาเงินจริงไปเสี่ยง ต้องอธิบายได้ว่าทำไม
 *    และให้ผลเดิมเมื่อข้อมูลเดิม (LLM ให้คำตอบไม่คงที่และย้อนตรวจไม่ได้)
 *
 * รวม 4 มุมมอง แต่ละมุมให้คะแนน -1..+1 แล้วถ่วงน้ำหนัก:
 *   เทรนด์ (EMA)        30%  — ทิศทางหลัก
 *   โมเมนตัม (ROC)      25%  — แรงส่งระยะสั้น
 *   ค่าเฉลี่ย (RSI)     25%  — ซื้อถูก/ขายแพงเทียบค่ากลาง
 *   ตำแหน่งในกรอบ (BB)  20%  — อยู่ตรงไหนของช่วงราคา
 *
 * ต้องได้คะแนนรวมเกินระดับความมั่นใจที่ผู้ใช้ตั้งไว้ถึงจะลงมือ
 *
 * Developed by Xman Studio.
 */
class AiSignalStrategy implements Strategy
{
    private const WEIGHTS = ['trend' => 0.30, 'momentum' => 0.25, 'reversion' => 0.25, 'position' => 0.20];

    /** ตัวคูณขนาดไม้ตามสไตล์ที่ผู้ใช้เลือก */
    private const MODE_BIAS = ['conservative' => 0.75, 'balanced' => 1.0, 'aggressive' => 1.25];

    public function code(): string
    {
        return 'ai_signal';
    }

    public function minCandles(array $params): int
    {
        return 60;
    }

    public function allowsPyramiding(): bool
    {
        return false;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        if (count($candles) < $this->minCandles($params)) {
            return Signal::hold('ข้อมูลแท่งเทียนยังไม่พอสำหรับโมเดลรวมสัญญาณ');
        }

        $closes = array_column($candles, 'close');
        $scores = $this->scoreAll($closes, $candles);

        if ($scores === null) {
            return Signal::hold('คำนวณสัญญาณไม่ครบทุกมุมมอง');
        }

        $total = 0.0;
        foreach (self::WEIGHTS as $key => $weight) {
            $total += $scores[$key] * $weight;
        }

        // แปลงคะแนน -1..+1 เป็นความมั่นใจ 0..100 (0 คะแนน = 50%)
        $confidence = 50 + $total * 50;
        $threshold = (float) ($params['confidence_min'] ?? 65);
        $mode = $params['mode'] ?? 'balanced';

        $meta = array_map(fn ($v) => round($v, 3), $scores);
        $meta['score'] = round($total, 3);
        $meta['confidence'] = round($confidence, 1);
        $meta['threshold'] = $threshold;
        $meta['mode'] = $mode;

        if ($position) {
            // ออกเมื่อความมั่นใจฝั่งขายถึงเกณฑ์เดียวกัน (สมมาตร ไม่ลำเอียงให้ถือค้าง)
            if ((100 - $confidence) >= $threshold) {
                return Signal::sell(1.0, "โมเดลกลับข้าง ความมั่นใจฝั่งขาย {$meta['confidence']}%", $meta);
            }

            return Signal::hold('ถือต่อ โมเดลยังไม่กลับข้าง', $meta);
        }

        if ($confidence < $threshold) {
            return Signal::hold("ความมั่นใจ {$meta['confidence']}% ยังไม่ถึงเกณฑ์ {$threshold}%", $meta);
        }

        $bias = self::MODE_BIAS[$mode] ?? 1.0;
        // เกินเกณฑ์เท่าไหร่ = มั่นใจเท่านั้น (เกิน 20 จุด = เต็มไม้)
        $strength = min(1.0, (0.5 + ($confidence - $threshold) / 40) * $bias);

        return Signal::buy($strength, "โมเดลรวมสัญญาณให้ความมั่นใจ {$meta['confidence']}%", $meta);
    }

    /**
     * ให้คะแนนแต่ละมุมมองในช่วง -1..+1.
     *
     * @return array{trend: float, momentum: float, reversion: float, position: float}|null
     */
    private function scoreAll(array $closes, array $candles): ?array
    {
        $fast = Indicators::last(Indicators::ema($closes, 12));
        $slow = Indicators::last(Indicators::ema($closes, 26));
        $rsi = Indicators::last(Indicators::rsi($closes, 14));
        $bands = Indicators::bollinger($closes, 20, 2.0);
        $close = (float) $closes[count($closes) - 1];

        if ($fast === null || $slow === null || $rsi === null || $bands === null || $slow <= 0) {
            return null;
        }

        // เทรนด์: EMA เร็วอยู่เหนือ/ใต้ EMA ช้ากี่เปอร์เซ็นต์ (±2% = สุดสเกล)
        $trend = max(-1.0, min(1.0, (($fast - $slow) / $slow) * 50));

        // โมเมนตัม: เปลี่ยนแปลง 10 แท่งล่าสุด (±5% = สุดสเกล)
        $momentum = max(-1.0, min(1.0, Indicators::changePct($closes, 10) / 5));

        // สวนค่าเฉลี่ย: RSI 50 = 0 · RSI 20 = +1 (ซื้อ) · RSI 80 = -1 (ขาย)
        $reversion = max(-1.0, min(1.0, (50 - $rsi) / 30));

        // ตำแหน่งในกรอบ: ชนขอบล่าง = +1 (ถูก) · ชนขอบบน = -1 (แพง)
        $width = $bands['upper'] - $bands['lower'];
        $position = $width > 0
            ? max(-1.0, min(1.0, 1 - 2 * (($close - $bands['lower']) / $width)))
            : 0.0;

        return [
            'trend' => $trend,
            'momentum' => $momentum,
            'reversion' => $reversion,
            'position' => $position,
        ];
    }
}
