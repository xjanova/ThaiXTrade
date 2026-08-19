<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TPIX TRADE — ทุกกลยุทธ์ต้อง "ตัดสินใจได้จริง" และตัดสินใจเหมือนเดิมทุกครั้ง.
 *
 * เจ้าของบอกเองว่าเรื่องนี้เป็นคดีความได้ ถ้าลูกค้าจ่ายเงินแล้วบอททำงานไม่ถูกต้อง
 * ชุดนี้จึงเป็นด่านอัตโนมัติที่รันทุกครั้งที่ CI ทำงาน ไม่ใช่การทดสอบด้วยมือครั้งเดียว
 *
 * สองอย่างที่คุมไว้:
 *   1. **คงที่** — ป้อนแท่งเทียนชุดเดิมต้องได้คำตอบเดิมทุกรอบ ถ้าแกว่งแปลว่ามีอะไร
 *      สุ่มหรืออิงเวลาแอบอยู่ ซึ่งทำให้ผลย้อนหลังเชื่อไม่ได้เลย
 *   2. **ลงมือเป็น** — ต้องมีอย่างน้อยหนึ่งฉากที่ซื้อหรือขายจริง กลยุทธ์ที่ตอบ "ถือ"
 *      ทุกฉากคือกลยุทธ์ที่ลูกค้าจ่ายเงินแล้วไม่ได้อะไรกลับไปเลย
 *
 * ดูรายละเอียดรายฉากได้ด้วยตาเมื่อไหร่ก็ได้:
 *   php artisan aibot:probe
 *   php artisan aibot:probe --with-position
 *
 * Developed by Xman Studio.
 */
class AiBotStrategyProbeTest extends TestCase
{
    /** ฝั่งเข้าไม้ — ทุกกลยุทธ์ต้องมีฉากที่ลงมือ และผลต้องคงที่ */
    public function test_every_strategy_decides_consistently_when_flat(): void
    {
        $this->artisan('aibot:probe --repeat=3 --assert')->assertSuccessful();
    }

    /**
     * ฝั่งออกไม้ — คำถามที่น่ากลัวที่สุดคือ "บอทขายเป็นไหม".
     *
     * บอทที่เข้าไม้เป็นแต่ออกไม่เป็น จะถือของไว้ลงเหวโดยไม่ทำอะไรเลย
     */
    public function test_every_strategy_decides_consistently_while_holding(): void
    {
        $this->artisan('aibot:probe --repeat=3 --assert --with-position')->assertSuccessful();
    }
}
