<?php

namespace App\Services\AiBot\Advisor;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — รวมที่มาของการตั้งค่าที่ปรึกษา AI ไว้ที่เดียว.
 *
 * ลำดับความสำคัญ: สิ่งที่แอดมินกรอกในหลังบ้าน → `.env` → ค่าปริยายใน config
 *
 * เหตุที่ต้องมีชั้นนี้: เจ้าของระบบเปลี่ยนคีย์ได้เองจาก /admin/settings โดยไม่ต้อง
 * ssh เข้าเซิร์ฟเวอร์ไปแก้ `.env` แล้วสั่ง `config:cache` ใหม่ — ซึ่งเป็นขั้นตอน
 * ที่ลืมง่ายและพลาดแล้วเงียบ (คีย์ใหม่ไม่ถูกใช้ ทั้งที่ไฟล์แก้ถูกแล้ว)
 *
 * ⚠️ ห้ามให้ชั้นนี้ throw เด็ดขาด
 *    บอทเทรดเรียกผ่านทางนี้ด้วย ถ้าฐานข้อมูลล่มหรือตารางยังไม่ถูก migrate
 *    แล้วเราปล่อย exception ขึ้นไป บอทจะหยุดเทรดเพราะ "ที่ปรึกษา" พัง
 *    ทั้งที่ที่ปรึกษาเป็นแค่ของแถม — ตัวตัดสินใจจริงคือกฎของกลยุทธ์
 *
 * Developed by Xman Studio.
 */
class AdvisorSettings
{
    /** กลุ่มใน site_settings ที่เก็บค่าของที่ปรึกษา */
    public const GROUP = 'advisor';

    /**
     * ชื่อคีย์ต้องไม่ชนกับกลุ่มอื่น.
     *
     * หน้า /admin/settings แบน settings ทุกกลุ่มลงเป็น map ชั้นเดียวโดยใช้ `key`
     * เป็นดัชนี (ไม่รวม group) — ตั้งชื่อซ้ำกับกลุ่มอื่นเมื่อไหร่ ค่าจะทับกันเงียบๆ
     * จึงเติมคำนำหน้า advisor_ ไว้ทุกตัว
     */
    public const KEYS = [
        'advisor_enabled',
        'advisor_gemini_api_key',
        'advisor_gemini_model',
        'advisor_gemini_max_calls',
        'advisor_openai_api_key',
        'advisor_openai_model',
        'advisor_openai_max_calls',
    ];

    /** คีย์ที่ห้ามส่งค่าจริงกลับไปหน้าเว็บ */
    public const SECRET_KEYS = [
        'advisor_gemini_api_key',
        'advisor_openai_api_key',
    ];

    /** อ่านจากฐานข้อมูลครั้งเดียวต่อ request (SiteSetting แคชให้อีกชั้นอยู่แล้ว) */
    private ?array $stored = null;

    /** เปิดใช้ที่ปรึกษาอยู่ไหม — แอดมินปิดได้จากหลังบ้านโดยไม่ต้องลบคีย์ทิ้ง */
    public function enabled(): bool
    {
        $fromAdmin = $this->stored()['advisor_enabled'] ?? null;

        if ($fromAdmin !== null && $fromAdmin !== '') {
            return filter_var($fromAdmin, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('aibot_advisor.enabled', true);
    }

    /**
     * ค่าตั้งของผู้ให้บริการรายหนึ่ง พร้อมคีย์ที่แอดมินกรอกทับแล้ว.
     *
     * คืน null เมื่อไม่รู้จักชื่อผู้ให้บริการ — ผู้เรียกต้องแปลงเป็น NullAdvisor เอง
     */
    public function providerConfig(string $provider): ?array
    {
        $base = config("aibot_advisor.providers.{$provider}");

        if (! is_array($base)) {
            return null;
        }

        $stored = $this->stored();

        $apiKey = $this->firstFilled(
            $stored["advisor_{$provider}_api_key"] ?? null,
            // Gemini ตัวเดียวใช้ได้ทั้งสร้างรูปและที่ปรึกษา — ถ้าแอดมินกรอกช่องสร้างรูป
            // ไว้แล้วก็ไม่ต้องกรอกซ้ำ (ประกาศไว้ในคำอธิบายใต้ช่องกรอกด้วย)
            $provider === 'gemini' ? ($stored['ai_gemini_api_key'] ?? null) : null,
            $base['api_key'] ?? null,
        );

        $model = $this->firstFilled(
            $stored["advisor_{$provider}_model"] ?? null,
            $base['model'] ?? null,
        );

        $maxCalls = $this->firstFilled(
            $stored["advisor_{$provider}_max_calls"] ?? null,
            $base['max_calls_per_day'] ?? null,
        );

        return array_merge($base, [
            'api_key' => trim((string) $apiKey),
            'model' => (string) $model,
            'max_calls_per_day' => max(1, (int) $maxCalls),
        ]);
    }

    /** ผู้ให้บริการที่แพลนระดับนี้ใช้ */
    public function providerForTier(string $tier): string
    {
        return config("aibot_advisor.by_tier.{$tier}")
            ?? config('aibot_advisor.by_tier.free', 'gemini');
    }

    /** ตั้งคีย์ครบพร้อมใช้แล้วหรือยัง — หน้าเว็บใช้ตัดสินว่าจะเปิดปุ่มไหม */
    public function isConfigured(string $provider): bool
    {
        return trim((string) ($this->providerConfig($provider)['api_key'] ?? '')) !== '';
    }

    /** ล้างค่าที่อ่านไว้ — ใช้หลังบันทึกค่าใหม่ในคำขอเดียวกัน */
    public function forget(): void
    {
        $this->stored = null;
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /**
     * ค่าที่แอดมินกรอกไว้ (กลุ่ม advisor + คีย์ Gemini ของกลุ่ม ai ที่ใช้ร่วมกันได้).
     *
     * อ่านไม่ได้ = ถือว่าไม่มีค่าที่แอดมินตั้ง แล้วไปใช้ `.env` แทน ไม่ใช่ error
     */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            $advisor = SiteSetting::getGroup(self::GROUP)->all();
            $sharedGemini = SiteSetting::get('ai', 'gemini_api_key');
        } catch (\Throwable $e) {
            Log::warning('อ่านค่าที่ปรึกษา AI จากฐานข้อมูลไม่ได้ ใช้ค่าจาก .env แทน', [
                'error' => $e->getMessage(),
            ]);

            return $this->stored = [];
        }

        return $this->stored = $advisor + ['ai_gemini_api_key' => $sharedGemini];
    }

    /** ค่าแรกที่ไม่ว่าง (0 กับ '0' ถือว่ามีค่า ต่างจาก ?? ที่ดูแค่ null) */
    private function firstFilled(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
