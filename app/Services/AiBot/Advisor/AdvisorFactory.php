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
 * Developed by Xman Studio.
 */
class AdvisorFactory
{
    /** สร้างที่ปรึกษาที่เหมาะกับแพลนนี้ */
    public function forPlan(?AiBotPlan $plan): Advisor
    {
        if (! config('aibot_advisor.enabled', true)) {
            return new NullAdvisor('ปิดระบบที่ปรึกษาไว้ชั่วคราว');
        }

        $tier = $plan?->tier ?? 'free';
        $providerName = config("aibot_advisor.by_tier.{$tier}")
            ?? config('aibot_advisor.by_tier.free', 'gemini');

        return $this->make($providerName);
    }

    public function make(string $providerName): Advisor
    {
        $config = config("aibot_advisor.providers.{$providerName}");

        if (! $config) {
            return new NullAdvisor("ไม่รู้จักที่ปรึกษาชื่อ {$providerName}");
        }

        $advisor = match ($config['driver'] ?? $providerName) {
            'gemini' => new GeminiAdvisor($config),
            'openai' => new OpenAiAdvisor($config),
            default => new NullAdvisor("ไม่รองรับไดรเวอร์ {$providerName}"),
        };

        // ยังไม่ใส่คีย์ = ถือว่ายังไม่ได้ตั้งค่า ไม่ใช่ error
        return $advisor->isAvailable() || $advisor instanceof NullAdvisor
            ? $advisor
            : new NullAdvisor('ยังไม่ได้ตั้งคีย์ของ '.$advisor->name());
    }
}
