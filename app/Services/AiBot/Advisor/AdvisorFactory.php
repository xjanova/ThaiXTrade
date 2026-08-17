<?php

namespace App\Services\AiBot\Advisor;

use App\Models\AiBotPlan;

/**
 * TPIX TRADE — เลือกที่ปรึกษาตามแพลนของผู้ใช้.
 *
 * ฟรี → Gemini (โควตาฟรี) · เสียเงิน → OpenAI (เราออกค่า API จากค่าเช่า)
 *
 * ไม่มีคีย์ก็ไม่ throw — คืน NullAdvisor แทน เพราะระบบต้องทำงานได้ตามปกติ
 * แม้ยังไม่ได้ต่อ LLM (บอทตัดสินใจจากกฎ คำแนะนำเป็นของแถม)
 *
 * คีย์มาจาก AdvisorSettings ซึ่งอ่านที่แอดมินกรอกในหลังบ้านก่อน แล้วค่อยตกไป `.env`
 *
 * Developed by Xman Studio.
 */
class AdvisorFactory
{
    public function __construct(private readonly AdvisorSettings $settings) {}

    /** สร้างที่ปรึกษาที่เหมาะกับแพลนนี้ */
    public function forPlan(?AiBotPlan $plan): Advisor
    {
        if (! $this->settings->enabled()) {
            return new NullAdvisor('ปิดระบบที่ปรึกษาไว้ชั่วคราว');
        }

        return $this->make($this->settings->providerForTier($plan?->tier ?? 'free'));
    }

    public function make(string $providerName): Advisor
    {
        if (! $this->settings->enabled()) {
            return new NullAdvisor('ปิดระบบที่ปรึกษาไว้ชั่วคราว');
        }

        return $this->build($providerName);
    }

    /**
     * สร้างโดยไม่สนสวิตช์เปิด/ปิด — ใช้ตอนแอดมินกดทดสอบคีย์.
     *
     * แยกออกมาเพราะการทดสอบคีย์ต้องยิงได้จริงแม้ยังปิดบริการอยู่ ไม่งั้นแอดมิน
     * จะตั้งค่าไม่ได้เลย: เปิดบริการก่อนก็เสี่ยงปล่อยคีย์ผิดออกไปหาผู้ใช้จริง
     */
    public function makeForTest(string $providerName): Advisor
    {
        return $this->build($providerName);
    }

    private function build(string $providerName): Advisor
    {
        $config = $this->settings->providerConfig($providerName);

        if (! $config) {
            return new NullAdvisor("ไม่รู้จักที่ปรึกษาชื่อ {$providerName}");
        }

        // ยังไม่ใส่คีย์ = ถือว่ายังไม่ได้ตั้งค่า ไม่ใช่ error
        //
        // เช็กที่ตัวคีย์ตรงๆ ไม่ใช่ผ่าน isAvailable() เพราะ isAvailable() เป็นเท็จ
        // ได้อีกกรณีคือใช้โควตาวันนี้หมดแล้ว — เหมารวมเป็น "ยังไม่ได้ตั้งคีย์"
        // จะทำให้แอดมินไปนั่งแก้คีย์ที่ถูกอยู่แล้ว ทั้งที่ต้องรอวันใหม่เฉยๆ
        if (trim((string) ($config['api_key'] ?? '')) === '') {
            return new NullAdvisor("ยังไม่ได้ตั้งคีย์ของ {$providerName}");
        }

        return match ($config['driver'] ?? $providerName) {
            'gemini' => new GeminiAdvisor($config),
            'openai' => new OpenAiAdvisor($config),
            default => new NullAdvisor("ไม่รองรับไดรเวอร์ {$providerName}"),
        };
    }
}
