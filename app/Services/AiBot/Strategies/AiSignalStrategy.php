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

        $directional = $this->directionalStrength($closes);
        $total = $this->blend($scores, $directional);

        // แปลงคะแนน -1..+1 เป็นความมั่นใจ 0..100 (0 คะแนน = 50%)
        $confidence = 50 + $total * 50;
        $threshold = (float) ($params['confidence_min'] ?? 65);
        $mode = $params['mode'] ?? 'balanced';

        $meta = array_map(fn ($v) => round($v, 3), $scores);
        $meta['directional'] = round($directional, 3);
        $meta['score'] = round($total, 3);
        $meta['confidence'] = round($confidence, 1);
        $meta['threshold'] = $threshold;
        $meta['mode'] = $mode;

        if ($position) {
            // ออกเมื่อความมั่นใจฝั่งขายถึงเกณฑ์เดียวกัน (สมมาตร ไม่ลำเอียงให้ถือค้าง)
            $sellConfidence = round(100 - $confidence, 1);

            if ($sellConfidence >= $threshold) {
                /*
                 * ⚠️ ต้องรายงานความมั่นใจ "ฝั่งขาย" ไม่ใช่ meta['confidence']
                 *
                 * meta['confidence'] คือความมั่นใจฝั่งซื้อ — เอามาใส่ข้อความขายแล้ว
                 * ผู้ใช้จะอ่านว่า "ขายเพราะมั่นใจ 1.2%" ทั้งที่ของจริงคือมั่นใจ 98.8%
                 * ว่าควรขาย ตัวเลขขัดกับการกระทำเองจนดูเหมือนบอทตัดสินใจมั่ว
                 */
                $meta['sell_confidence'] = $sellConfidence;

                return Signal::sell(1.0, "โมเดลกลับข้าง ความมั่นใจฝั่งขาย {$sellConfidence}%", $meta);
            }

            return Signal::hold('ถือต่อ โมเดลยังไม่กลับข้าง', $meta);
        }

        if ($confidence < $threshold) {
            return Signal::hold("ความมั่นใจ {$meta['confidence']}% ยังไม่ถึงเกณฑ์ {$threshold}%", $meta);
        }

        $bias = self::MODE_BIAS[$mode] ?? 1.0;

        /*
         * ตัดเพดานความเชื่อมั่นก่อนคูณสไตล์ ไม่ใช่หลัง
         *
         * คูณก่อนแล้วค่อยตัด ทำให้สไตล์ "ระมัดระวัง" กับ "ดุดัน" ได้ขนาดไม้เท่ากัน
         * ทุกครั้งที่ความมั่นใจสูงพอ (ทั้งคู่ทะลุ 1.0 แล้วโดนตัดเหลือ 1.0 เท่ากัน)
         * — ผู้ใช้เลือกสไตล์แล้วไม่มีผลอะไรเลยในสถานการณ์ที่บอทมั่นใจที่สุด
         */
        $conviction = min(1.0, 0.5 + ($confidence - $threshold) / 40);
        $strength = min(1.0, $conviction * $bias);

        return Signal::buy($strength, "โมเดลรวมสัญญาณให้ความมั่นใจ {$meta['confidence']}%", $meta);
    }

    /**
     * รวมสี่มุมมองเป็นคะแนนเดียว โดยให้น้ำหนักตามสภาพตลาด ไม่ใช่น้ำหนักตายตัว.
     *
     * ⚠️ เดิมใช้น้ำหนักคงที่ trend .30 + momentum .25 + reversion .25 + position .20
     *    ซึ่งหักล้างกันเองโดยโครงสร้าง: สองตัวแรกตามเทรนด์ สองตัวหลังสวนค่าเฉลี่ย
     *    ตลาดขาขึ้นสม่ำเสมอจึงได้ trend=+1, momentum=+1 พร้อมกับ reversion=-1,
     *    position=-1 → รวมได้ .55 − .45 = 0.10 → ความมั่นใจ 55% เพดานตายตัว
     *    ไม่ว่าจะขึ้นชันแค่ไหน (วัดจริง: +0.5%/แท่ง → 56.6 · +5%/แท่ง → 55.5)
     *    ค่าปริยาย confidence_min = 65 จึงไม่มีวันถึง — กลยุทธ์ที่เป็นจุดขายของ
     *    แพลน VIP ไม่เคยออกสัญญาณซื้อเลยแม้แต่ครั้งเดียว
     *
     * วิธีใหม่: ดูก่อนว่าตลาด "มีทิศทาง" หรือ "ออกข้าง" แล้วค่อยเลือกว่าจะฟังใคร
     * - มีทิศทางชัด → ฟังฝั่งตามเทรนด์ ส่วนฝั่งสวนค่าเฉลี่ยเบาลงจนเงียบ
     *   (ในตลาดที่วิ่ง การที่ RSI สูงและราคาชนขอบบนคือ "อาการของเทรนด์" ไม่ใช่
     *    สัญญาณว่าแพง — ใช้มันค้านเทรนด์เท่ากับแปลอาการผิด)
     * - ออกข้าง → ฟังฝั่งสวนค่าเฉลี่ย เพราะการซื้อตอนชนขอบล่างคือของจริงในกรอบ
     *
     * @param  array{trend: float, momentum: float, reversion: float, position: float}  $scores
     */
    private function blend(array $scores, float $directional): float
    {
        // ออกข้างสุด = ฟังสองฝั่งเท่ากัน · มีทิศทางสุด = ฟังฝั่งตามเทรนด์ล้วน
        $followWeight = 0.5 + 0.5 * $directional;

        $follow = $scores['trend'] * 0.55 + $scores['momentum'] * 0.45;
        $revert = $scores['reversion'] * 0.55 + $scores['position'] * 0.45;

        return max(-1.0, min(1.0, $follow * $followWeight + $revert * (1.0 - $followWeight)));
    }

    /**
     * ตลาดกำลัง "เดินทางเดียว" แค่ไหน (0 = ออกข้าง · 1 = เดินตรงไม่ย้อนเลย).
     *
     * ⚠️ ห้ามใช้ความชันเป็นตัววัด — นี่คือจุดที่เคยพลาดและอันตรายที่สุด
     *
     * เดิมใช้ `abs(trend)` ซึ่งเป็นคะแนนความชันของ EMA (สุดสเกลที่ห่างกัน 2%)
     * ตลาดที่ขึ้น 90 แท่งติดโดยไม่มีแท่งแดงเลยแต่ขึ้นช้าๆ ได้ trend เพียง 0.07
     * → ระบบอ่านว่า "เกือบออกข้าง" → ให้น้ำหนักฝั่งสวนค่าเฉลี่ยเกือบครึ่ง
     * ซึ่งตอนนั้นติดลบเต็มเพดาน (RSI 100 · ราคาขี่ขอบบน) → **คะแนนรวมพลิกข้าง**
     * วัดจริงด้วย random walk 300 รอบ: ขาขึ้นช้าๆ สั่งขาย 52.9% และไม่เคยสั่งซื้อ
     * ถูกทิศเลยสักครั้ง — ลูกค้าที่จ่าย 2,400 TPIX/วันได้บอทที่สวนตลาดทุกไม้
     *
     * ตัววัดที่ถูกคือ efficiency ratio (Kaufman): ระยะทางสุทธิ ÷ ระยะทางที่เดินจริง
     * เดินตรงไม่ย้อน = ใกล้ 1 ไม่ว่าจะชันแค่ไหน · แกว่งไปมา = ใกล้ 0
     * ตรงกับสิ่งที่คอมเมนต์ด้านบนอธิบายไว้ตั้งแต่แรกว่า "มีทิศทาง" คืออะไร
     *
     * @param  list<float>  $closes
     */
    private function directionalStrength(array $closes, int $lookback = 20): float
    {
        $count = count($closes);

        if ($count <= $lookback) {
            return 0.0;
        }

        $window = array_slice($closes, -($lookback + 1));
        $net = abs($window[count($window) - 1] - $window[0]);

        $path = 0.0;
        for ($i = 1; $i < count($window); $i++) {
            $path += abs($window[$i] - $window[$i - 1]);
        }

        // ราคาไม่ขยับเลยทั้งหน้าต่าง = ไม่มีทิศทาง (และกันหารศูนย์ไปในตัว)
        return $path > 0 ? max(0.0, min(1.0, $net / $path)) : 0.0;
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
