<?php

namespace App\Services\AiBot\Advisor;

/**
 * TPIX TRADE — ที่ปรึกษาที่ไม่ทำอะไร ใช้เมื่อยังไม่ได้ตั้งคีย์.
 *
 * มีไว้เพื่อให้โค้ดที่เรียกไม่ต้องเช็ก null ทุกจุด และเพื่อให้ระบบทำงานได้
 * ตามปกติแม้ยังไม่ได้ต่อ LLM — บอทยังเทรดตามกฎได้เหมือนเดิม แค่ไม่มีคำแนะนำ
 *
 * Developed by Xman Studio.
 */
class NullAdvisor implements Advisor
{
    public function __construct(private readonly string $reason = 'ยังไม่ได้ตั้งค่าที่ปรึกษา AI') {}

    public function name(): string
    {
        return 'null';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function advise(array $context): array
    {
        return ['ok' => false, 'provider' => 'null', 'text' => '', 'reason' => $this->reason];
    }
}
