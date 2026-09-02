<?php

namespace App\Services\AiBot\Analyst;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiMarketView;

/**
 * TPIX TRADE — แปลง "มุมมองของ AI" เป็นอำนาจที่มีขอบเขตต่อบอทหนึ่งตัว.
 *
 * แยกออกจาก MarketAnalyst โดยตั้งใจ: ตัวโน้นคุยกับ OpenAI (ช้า มีค่าใช้จ่าย
 * ทดสอบยาก) ส่วนตัวนี้เป็นตรรกะล้วนที่อ่านแถวในฐานข้อมูล — ทดสอบได้ทุกกรณี
 * โดยไม่ต้องมีคีย์และไม่ต้องต่อเน็ต ซึ่งจำเป็น เพราะนี่คือชั้นที่ตัดสินว่าเงิน
 * ของผู้ใช้จะถูกเอาไปเสี่ยงเท่าไหร่
 *
 * ═══ AI ทำอะไรได้บ้าง (ขอบเขตอยู่ที่ config/aibot_analyst.php) ═══
 *   ห้ามเข้าไม้ใหม่ · ลด/เพิ่มขนาดไม้ · ขอให้ปิดไม้ · ลดเกณฑ์ความมั่นใจของกลยุทธ์
 *
 * ═══ ทำไม่ได้ ═══
 *   สั่งซื้อเองทั้งที่กฎยังไม่ให้สัญญาณ — คำตอบ LLM ไม่คงที่ ถ้าให้เป็นต้นทาง
 *   ของคำสั่งซื้อ จะย้อนตรวจไม่ได้ว่าทำไมถึงเสียเงิน และทดสอบย้อนหลังไม่ได้เลย
 *
 * Developed by Xman Studio.
 */
class AiViewGate
{
    public function __construct(private readonly AnalystCalibration $calibration) {}

    /**
     * ประเมินอิทธิพลของ AI ต่อบอทตัวนี้ในรอบนี้.
     *
     * @param  bool  $hasPosition  ถือของอยู่ไหม (ใช้ตัดสินว่าจะพิจารณา exit ไหม)
     * @return array{
     *     applied: bool,
     *     view_id: int|null,
     *     scope: string|null,
     *     size_multiplier: float,
     *     block_entry: bool,
     *     force_exit: bool,
     *     confidence_relief: float,
     *     stance: string|null,
     *     reasons: list<string>
     * }
     */
    public function evaluate(AiBotConfig $bot, ?AiBotPlan $plan, bool $hasPosition): array
    {
        $idle = self::idle();

        if (! config('aibot_analyst.enabled', false)) {
            return $idle;
        }

        if (config('aibot_analyst.shadow_mode', false)) {
            /*
             * โหมดเงา — AI คิดและถูกบันทึกครบ แต่ไม่มีสิทธิ์แตะเงินแม้แต่บาทเดียว
             *
             * ออกตรงนี้ "หลัง" เช็คสวิตช์ใหญ่ แต่ "ก่อน" อ่านมุมมอง เพราะไม่ต้องใช้
             * มุมมองเลยในโหมดนี้ — ประหยัดคิวรีทุกติ๊กของบอททุกตัว
             *
             * ตัวรอบวิเคราะห์ (MarketAnalyst) ไม่ได้ดูค่านี้โดยตั้งใจ: มันต้องเดิน
             * และบันทึกต่อไป ไม่งั้นสองวันจะผ่านไปโดยไม่มีข้อมูลให้ตัดสินอะไรเลย
             */
            return $idle + ['shadow' => true];
        }

        $view = $this->viewFor($plan);

        if (! $view) {
            /*
             * ไม่มีมุมมองที่ยังไม่หมดอายุ = ถอยไปใช้กฎล้วน ไม่ใช่หยุดเทรด
             *
             * เคสนี้เกิดได้จริงและต้องไม่พัง: OpenAI ล่ม · โควตาหมด · cron ตาย
             * (ดู incident ของ NetWix ที่ schedule:run หายจาก crontab แล้วไม่มี
             * อะไรฟ้องเลย 9 วัน) กฎล้วนคือสิ่งที่ระบบใช้มาตลอดและใช้ได้อยู่แล้ว
             */
            return $idle;
        }

        $limits = (array) config('aibot_analyst.limits', []);
        $minConfidence = (float) ($limits['min_confidence'] ?? 0.55);

        if ((float) $view->confidence < $minConfidence) {
            // AI เองยังไม่มั่นใจ — เอาความเห็นที่มันไม่มั่นใจมาถ่วงการตัดสินใจ
            // เรื่องเงินไม่คุ้ม ปล่อยให้กฎทำงานตามปกติ
            return $idle + ['view_id' => $view->id];
        }

        $coin = $view->forPair($bot->pair);
        $stance = $coin['stance'] ?? null;

        $reasons = [];
        $blockEntry = false;
        $forceExit = false;
        $relief = 0.0;

        /*
         * ตัวคูณขนาดไม้จากภาพรวมตลาด — คูณกับของด่านความเสี่ยงอีกที ไม่ใช่แทนที่
         * (ด่านความเสี่ยงดูราคาและข่าวแบบกฎ ส่วนตัวนี้ดูภาพกว้าง คนละมุมกัน
         *  มุมไหนบอกให้เบา ต้องเบาตามมุมนั้น)
         */
        $size = (float) $view->size_multiplier;

        if ($view->regime === 'risk_off') {
            $reasons[] = 'AI มองตลาดเป็นขาลง — ลดขนาดไม้';
        }

        if ($stance === AiMarketView::STANCE_AVOID) {
            $blockEntry = true;
            $reasons[] = 'AI ไม่แนะนำเหรียญนี้รอบนี้: '.($coin['why'] ?: 'ไม่ระบุเหตุผล');
        }

        if ($stance === AiMarketView::STANCE_EXIT && $hasPosition) {
            /*
             * เกณฑ์การสั่งขายสูงกว่าเกณฑ์ทั่วไป เพราะการออกจากตลาดมีต้นทุนจริง
             * 0.36% ต่อรอบ ถ้าให้สั่งขายง่ายๆ ค่าธรรมเนียมจะกินพอร์ตมากกว่าที่
             * มันช่วยหนีทัน — เหตุผลเดียวกับเกณฑ์เทออกของด่านข่าว
             *
             * ถ้ามีประวัติพอ ใช้ "อัตราทายถูกจริงของ exit ที่ความมั่นใจระดับนี้" แทน
             * ตัวเลขที่ AI รายงานเอง (ซึ่งออดิทพบว่ากลับหัว) — ประวัติแย่ = ห้ามขาย
             * แม้มันจะมั่นใจ 0.95 · ประวัติดี = ขายได้แม้ต่ำกว่าเกณฑ์ดิบ
             */
            $exitConfidence = (float) ($limits['exit_confidence'] ?? 0.75);
            $history = $this->calibration->hitRate('exit', (float) $view->confidence);

            $allowed = $history === null
                ? (float) $view->confidence >= $exitConfidence
                : $history >= (float) ($limits['calibrated_exit_min'] ?? 0.55);

            if ($allowed) {
                $forceExit = true;
                $reasons[] = 'AI สั่งปิดไม้: '.($coin['why'] ?: 'ความเสี่ยงสูงขึ้นชัดเจน')
                    .($history === null ? '' : sprintf(' (ประวัติถูก %.0f%%)', $history * 100));
            } else {
                // มั่นใจไม่พอจะจ่ายค่าออก (หรือประวัติบอกว่าไม่คุ้ม) — แต่พอจะห้ามเติมไม้ใหม่
                $blockEntry = true;
                $reasons[] = $history === null
                    ? 'AI เริ่มไม่ชอบเหรียญนี้ แต่ยังมั่นใจไม่พอจะสั่งปิดไม้'
                    : sprintf('AI ขอปิดไม้ แต่ประวัติ exit ที่ความมั่นใจระดับนี้ถูกแค่ %.0f%% — ไม่จ่ายค่าออก', $history * 100);
            }
        }

        if ($stance === AiMarketView::STANCE_BUY && ! $hasPosition) {
            /*
             * ช่วยกลยุทธ์ได้ แต่ช่วยเองไม่ได้ — ลดเกณฑ์ความมั่นใจลงตามส่วน
             * ของคะแนนที่ AI ให้ ทำให้สัญญาณที่ "เกือบผ่าน" ได้ผ่าน
             * แต่สัญญาณอ่อนๆ ยังไม่ทะลุ (เพดาน 8 จุดจาก 100)
             *
             * ประวัติ buy ที่ความมั่นใจระดับนี้แพ้มากกว่าชนะ = ไม่ผ่อนเกณฑ์ให้เลย
             * (ออดิท: buy 21 ครั้ง ถูก 38% ขยับ −44 bps — การผ่อนเกณฑ์ตามคำแนะนำ
             *  แบบนั้นคือการช่วยให้บอทเข้าไม้ที่แพ้บ่อยขึ้น)
             */
            $history = $this->calibration->hitRate('buy', (float) $view->confidence);

            if ($history !== null && $history < (float) ($limits['calibrated_relief_min'] ?? 0.5)) {
                $reasons[] = sprintf('AI เห็นด้วยกับฝั่งซื้อ แต่ประวัติ buy ที่ความมั่นใจระดับนี้ถูกแค่ %.0f%% — ไม่ผ่อนเกณฑ์', $history * 100);
            } else {
                $max = (float) ($limits['confidence_relief_max'] ?? 8.0);
                $relief = round($max * max(0.0, (float) ($coin['score'] ?? 0)) * (float) $view->confidence, 2);

                if ($relief > 0) {
                    $reasons[] = "AI เห็นด้วยกับฝั่งซื้อ — ผ่อนเกณฑ์ความมั่นใจลง {$relief} จุด";
                }
            }
        }

        return [
            'applied' => true,
            'view_id' => $view->id,
            'scope' => $view->scope,
            'size_multiplier' => $size,
            'block_entry' => $blockEntry,
            'force_exit' => $forceExit,
            'confidence_relief' => $relief,
            'stance' => $stance,
            'reasons' => $reasons,
        ];
    }

    /**
     * "AI ไม่มีผลกับรอบนี้" — รูปแบบเดียวกับผลของ evaluate() ทุกประการ.
     *
     * แยกออกมาเพราะมีผู้เรียกมากกว่าหนึ่งที่ต้องการค่านี้: ตัวประเมินเองเมื่อ
     * ระบบปิด/ไม่มีมุมมอง และ BotRunner เมื่อเจ้าของบอทปิดสวิตช์ `ai_gate`
     * (บอทกลุ่มควบคุมของการทดลอง A/B — ต้องได้ค่าเดียวกันเป๊ะ ไม่ใช่โครงสร้างคล้ายๆ)
     *
     * @return array{applied: bool, view_id: null, scope: null, size_multiplier: float, block_entry: bool, force_exit: bool, confidence_relief: float, stance: null, reasons: list<string>}
     */
    public static function idle(): array
    {
        return [
            'applied' => false,
            'view_id' => null,
            'scope' => null,
            'size_multiplier' => 1.0,
            'block_entry' => false,
            'force_exit' => false,
            'confidence_relief' => 0.0,
            'stance' => null,
            'reasons' => [],
        ];
    }

    /**
     * มุมมองที่บอทตัวนี้มีสิทธิ์ใช้.
     *
     * แพลนสูงได้รอบสั้น (15 นาที) ซึ่งสดกว่า · แพลนต่ำได้รอบใหญ่ (4 ชม.)
     * ถ้ารอบสั้นหมดอายุหรือยังไม่มี ให้ตกไปใช้รอบใหญ่ — ดีกว่าไม่มีอะไรเลย
     */
    public function viewFor(?AiBotPlan $plan): ?AiMarketView
    {
        foreach ([AiMarketView::SCOPE_TACTICAL, AiMarketView::SCOPE_STRATEGIC] as $scope) {
            if (! $this->planAllows($plan, $scope)) {
                continue;
            }

            $view = AiMarketView::latestFor($scope);

            if ($view) {
                return $view;
            }
        }

        return null;
    }

    /** แพลนนี้เข้าถึงรอบวิเคราะห์ระดับนี้ได้ไหม */
    public function planAllows(?AiBotPlan $plan, string $scope): bool
    {
        $required = (string) config("aibot_analyst.scopes.{$scope}.min_tier", 'free');

        $planRank = AiBotPlan::TIER_RANK[$plan?->tier ?? 'free'] ?? 0;
        $requiredRank = AiBotPlan::TIER_RANK[$required] ?? 0;

        return $planRank >= $requiredRank;
    }
}
