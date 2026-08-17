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
     * @param  list<array>  $candles  เรียงจากเก่า → ใหม่ แท่งสุดท้ายคือปัจจุบัน
     * @param  array<string, mixed>  $params  พารามิเตอร์ที่ผ่านการ clamp แล้ว
     * @param  array{qty: float, entry: float}|null  $position  สถานะที่ถืออยู่ (null = ยังไม่มีของ)
     */
    public function decide(array $candles, array $params, ?array $position): Signal;
}
