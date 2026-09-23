<?php

namespace App\Services\AiBot;

/**
 * TPIX TRADE — งบของไม้หนึ่งไม้ คิดจากกรอบความเสี่ยง + พารามิเตอร์ + ความแรงสัญญาณ.
 *
 * ยกออกมาจาก BotRunner เพื่อให้ backtester ใช้สูตรเดียวกัน "เป๊ะ" — ถ้าสองที่คิดขนาด
 * ไม้คนละแบบ ผล backtest จะสวยหรือแย่กว่าของจริงโดยไม่มีใครรู้ว่าเพราะอะไร
 * (BotRunnerTest ยังคุมพฤติกรรมผ่าน engine จริงอยู่ — เปลี่ยนสูตรที่นี่แล้วเทสต์นั้นแดง)
 *
 * เป็น pure function โดยตั้งใจ: ไม่อ่านโมเดล ไม่อ่าน config ไม่อ่านเวลา
 *
 * Developed by Xman Studio.
 */
final class PositionSizer
{
    /**
     * ขนาดไม้ที่ผู้ใช้ตั้งไว้ในพารามิเตอร์ของกลยุทธ์ (null = กลยุทธ์นั้นไม่มีช่องนี้).
     *
     * "ขนาดต่อไม้" ของกริดกับ "งบต่อรอบ" ของ DCA เป็นช่องที่ฟอร์มให้ตั้งมาตลอด
     * แต่เคยไม่มีโค้ดอ่านเลยสักบรรทัด — ผู้ใช้ตั้ง $20 แล้วบอทเข้าไม้ตามเพดานทุน
     * (ค่าปริยาย $100) ห้าเท่าของที่สั่ง
     *
     * @param  array  $params  พารามิเตอร์ที่ผ่าน sanitizeParams แล้ว (มีค่าปริยายครบ)
     * @param  array  $rawParams  ค่าดิบที่บันทึกไว้ — สำรองไว้เผื่อผู้เรียกไม่ได้ล้างค่า
     */
    public static function orderSizeFor(string $strategy, array $params, array $rawParams = []): ?float
    {
        $key = match ($strategy) {
            'grid' => 'order_size_usd',
            'dca' => 'budget_usd',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $value = $params[$key] ?? $rawParams[$key] ?? null;

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /**
     * งบของไม้นี้.
     *
     * ⚠️ ค่าที่ผู้ใช้กรอกเป็น "จำนวนเงิน" ไม่ใช่ "เพดาน" — ต้องใช้เต็มจำนวน
     *    เอาไปคูณความแรงสัญญาณทำให้ตั้ง $25 แล้วลงจริง $12.50 ทุกรอบ (DCA คืน
     *    strength 0.5 ในรอบปกติ) = ลูกค้าได้ครึ่งเดียวของที่สั่งโดยไม่มีอะไรบอก
     *
     *    ตัวคูณความเสี่ยงยังคูณอยู่เสมอ — นั่นคือด่านความปลอดภัยที่ต้องชนะทุกอย่าง
     *    (ตลาดอันตราย = ลดขนาดไม้ ไม่ว่าผู้ใช้สั่งเท่าไหร่)
     *
     * ไม่มีช่องขนาดไม้ → เพดานทุน × ความแรงสัญญาณ × ตัวคูณความเสี่ยง
     * มีช่องขนาดไม้   → min(ขนาดไม้, เพดานทุน) × ตัวคูณความเสี่ยง
     *
     * @param  array  $risk  กรอบความเสี่ยงของบอท (max_position_usd …)
     * @param  float  $strength  ความแรงสัญญาณ 0..1
     * @param  float  $riskMultiplier  ตัวคูณจากด่านความเสี่ยง × มุมมอง AI
     */
    public static function budget(
        string $strategy,
        array $risk,
        array $params,
        float $strength,
        float $riskMultiplier,
        array $rawParams = [],
    ): float {
        $maxPosition = (float) ($risk['max_position_usd'] ?? 100);
        $perOrder = self::orderSizeFor($strategy, $params, $rawParams);

        /*
         * DCA "เพิ่มไม้เมื่อราคาย่อ" (dip_boost_pct) — ความแรงสัญญาณคือขนาดไม้ที่ต้องได้จริง
         *
         * กลยุทธ์ให้ strength 0.5 ตอนซื้อตามรอบปกติ และ 0.7–1.0 ตอนราคาย่อต่ำกว่า SMA20
         * เกินเกณฑ์ พร้อมเหตุผล "ราคาย่อ — เพิ่มไม้" แต่เดิมที่นี่ใช้งบคงที่เสมอ ไม้ตอนย่อจึง
         * เท่ากับไม้ปกติทุกครั้ง (R3: DCA ทุกไม้ $24.98) ฟีเจอร์ที่เทมเพลตโฆษณาไว้ไม่เคยเกิดขึ้น
         * ตอนนี้: ปกติ = งบที่ตั้ง (×1) · ย่อ = ×1.4 ถึง ×2 — ยังถูกเพดานทุนต่อไม้คุมเหมือนเดิม
         * (กริดยังใช้ขนาดคงที่ต่อชั้น — ชั้นของกริดคือตัวกำหนดขนาดอยู่แล้ว)
         */
        if ($perOrder !== null && $strategy === 'dca') {
            $perOrder *= max(1.0, $strength / 0.5);
        }

        if ($perOrder !== null) {
            return round(min($perOrder, $maxPosition) * $riskMultiplier, 2);
        }

        return round($maxPosition * $strength * $riskMultiplier, 2);
    }
}
