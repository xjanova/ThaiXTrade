<?php

namespace App\Services\AiBot\Advisor;

/**
 * TPIX TRADE — สัญญาของที่ปรึกษา AI.
 *
 * ⚠️ ที่ปรึกษาให้ "ความเห็น" เท่านั้น ไม่มีเมธอดใดที่สั่งเทรดได้
 *    เจตนาออกแบบให้เป็นแบบนี้ — ตัวตัดสินใจจริงคือกลยุทธ์ที่เขียนเป็นกฎ
 *    ซึ่งตรวจย้อนหลังได้ว่าทำไมถึงทำแบบนั้น ต่างจากคำตอบของ LLM ที่ไม่คงที่
 *
 * Developed by Xman Studio.
 */
interface Advisor
{
    /** ชื่อผู้ให้บริการ (gemini / openai / null) */
    public function name(): string;

    /** พร้อมใช้งานไหม (มีคีย์ + ยังไม่เกินโควตาวันนี้) */
    public function isAvailable(): bool;

    /**
     * ขอความเห็นจากบริบทที่เตรียมไว้.
     *
     * @param  array  $context  ผลงานย้อนหลัง + สภาพตลาด + ข่าว (ไม่มีข้อมูลระบุตัวผู้ใช้)
     * @return array{ok: bool, provider: string, text: string, reason?: string}
     */
    public function advise(array $context): array;
}
