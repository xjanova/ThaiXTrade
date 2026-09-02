<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — สัญญาของกลยุทธ์ทุกตัว.
 *
 * ข้อกำหนดที่ทุกกลยุทธ์ต้องทำตาม:
 *  1. เป็น pure — ตัดสินใจจาก $candles + $params + $position เท่านั้น
 *     ห้ามอ่านเวลาปัจจุบัน ฐานข้อมูล หรือเครือข่าย (ไม่งั้นทดสอบซ้ำไม่ได้ผลเดิม)
 *  2. ข้อมูลไม่พอ → คืน hold พร้อมเหตุผล ไม่ใช่เดา
 *  3. ไม่สั่งขายเมื่อไม่มีของ และไม่สั่งซื้อซ้ำเมื่อถือของอยู่แล้ว
 *     (การคุมขนาดไม้เป็นหน้าที่ของ engine ไม่ใช่ของกลยุทธ์)
 *
 * @phpstan-type Candle array{time: int, open: float, high: float, low: float, close: float, volume: float}
 *
 * Developed by Xman Studio.
 */
interface Strategy
{
    /** รหัสตรงกับ config/aibot.php */
    public function code(): string;

    /** จำนวนแท่งขั้นต่ำที่ต้องมีถึงจะตัดสินใจได้ */
    public function minCandles(array $params): int;

    /**
     * กลยุทธ์นี้ "เติมไม้" ทับของที่ถืออยู่ได้ไหม.
     *
     * ปกติคืน false — สั่งซื้อซ้ำตอนถือของอยู่คือบั๊กที่ทำให้ไม้บวมเกินกรอบเสี่ยง
     * ยกเว้น DCA ที่การทยอยสะสมคือหัวใจของกลยุทธ์ (ต้นทุนจะถูกเฉลี่ยใหม่ทุกไม้)
     */
    public function allowsPyramiding(): bool;

    /**
     * ยอมให้ "มุมมองตลาดของ AI" สั่งปิดไม้ของกลยุทธ์นี้ไหม.
     *
     * กลยุทธ์ที่ออกแบบมาให้ "ถือผ่านขาลง" (DCA ทยอยสะสม · กริดรอเด้ง) ต้องตอบ false —
     * คำสั่ง exit ใบเดียวจากรอบวิเคราะห์เคยมีสิทธิ์ปิดไม้ทุกกลยุทธ์พร้อมกัน ทั้งที่
     * exit 4 ครั้งแรกบน prod ผิด 75% (ราคาขึ้นต่อ +17 bps) ด่านความเสี่ยงแบบกฎ
     * (ตลาดพัง/ข่าวร้าย) และ stop ของผู้ใช้ยังทำงานตามปกติไม่ว่าตอบอะไร
     */
    public function acceptsAiExit(): bool;

    /**
     * ให้ AI "ผ่อนเกณฑ์" ของกลยุทธ์นี้ในหน่วยของมันเอง.
     *
     * $reliefPoints = 0..8 (จาก AiViewGate) — เดิม BotRunner ผ่อนได้แค่ confidence_min
     * ของ ai_signal ตัวเดียว อีก 6 กลยุทธ์ไม่มีคีย์นั้น AI จึงเหลือแต่อำนาจ "เบรก"
     * แต่ละกลยุทธ์รู้เองว่า "ผ่อน 8 จุด" แปลว่าอะไรในภาษาของมัน (RSI +4 · ATR −0.2 ·
     * วอลุ่ม −0.4 เท่า) และกลยุทธ์ที่ไม่ควรถูกผ่อน (DCA/กริด) คืน $params เดิม
     *
     * @param  array<string, mixed>  $params  พารามิเตอร์ที่ผ่าน sanitize แล้ว
     * @return array<string, mixed>
     */
    public function withAiRelief(array $params, float $reliefPoints): array;

    /**
     * @param  list<array>  $candles  เรียงจากเก่า → ใหม่ แท่งสุดท้ายคือปัจจุบัน
     * @param  array<string, mixed>  $params  พารามิเตอร์ที่ผ่านการ clamp แล้ว
     * @param  array{qty: float, entry: float, entry_market?: float, bars_held?: int}|null  $position  สถานะที่ถืออยู่ (null = ยังไม่มีของ)
     */
    public function decide(array $candles, array $params, ?array $position): Signal;
}
