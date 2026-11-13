<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — ก๊อปปี้เทรด: บอทผู้ตามถือของ "ตามมาสเตอร์" ด้วยทุนและกรอบความเสี่ยงของผู้ตามเอง.
 *
 * คลาสนี้ไม่รู้กฎของมาสเตอร์เลย — เห็นแค่สิ่งที่ engine ส่งมาทาง params['_copy']
 * (CopyTradeService::signalContext): มาสเตอร์ถือของอยู่ไหม ใช้ทุนไปกี่ส่วน และเข้าที่ราคาเท่าไหร่
 *
 *   ไม่มีของ + มาสเตอร์เปิดไม้ใหม่หลังเริ่มตาม   → เข้า ด้วยสัดส่วนทุนเดียวกับมาสเตอร์ (ของเพดานผู้ตาม)
 *   มีของ + มาสเตอร์ปิดไม้                        → ปิดตาม
 *   มีของ + มาสเตอร์เติมไม้จนสัดส่วนห่างกันชัดเจน   → เติมตาม
 *
 * stop loss / take profit / ขาดทุนต่อวันของผู้ตามทำงานก่อนถึงคลาสนี้เสมอ (BotRunner ขั้น 4) —
 * ผู้ตามออกก่อนมาสเตอร์ได้ แต่ไม่มีวันถือเกินกรอบที่ตัวเองตั้ง
 *
 * Developed by Xman Studio.
 */
class CopyStrategy implements Strategy
{
    public function code(): string
    {
        return 'copy';
    }

    public function minCandles(array $params): int
    {
        return 30;
    }

    /** มาสเตอร์ที่ทยอยสะสมต้องตามได้ — การเติมถูกคุมด้วยสัดส่วนทุนของมาสเตอร์ ไม่ใช่เติมทุกรอบ */
    public function allowsPyramiding(): bool
    {
        return true;
    }

    /** ผู้ตามเลือกตามคน ไม่ได้เลือกตาม AI ของแพลตฟอร์ม — AI ปิดไม้ที่ก๊อปปี้มาไม่ได้ (ด่านความเสี่ยงแบบกฎยังทำงาน) */
    public function acceptsAiExit(): bool
    {
        return false;
    }

    public function withAiRelief(array $params, float $reliefPoints): array
    {
        return $params;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        $copy = is_array($params['_copy'] ?? null) ? $params['_copy'] : ['state' => 'detached'];

        if (($copy['state'] ?? '') !== 'ok') {
            return Signal::hold($position
                ? 'เลิกตามมาสเตอร์แล้ว — ไม้ที่ถืออยู่เป็นของคุณ ปิดเองได้ทุกเมื่อ หรือปล่อยให้ stop loss / take profit ของคุณทำงาน'
                : 'บอทตัวนี้ไม่ได้ตามมาสเตอร์คนไหนอยู่ — เลือกมาสเตอร์จากหน้า Copy Trade');
        }

        if ($candles === []) {
            return Signal::hold('ยังดึงแท่งเทียนของคู่นี้ไม่ได้');
        }

        $close = (float) $candles[count($candles) - 1]['close'];
        $holding = (bool) ($copy['holding'] ?? false);
        $exposure = max(0.0, min(1.0, (float) ($copy['exposure'] ?? 0)));
        $entry = (float) ($copy['entry_market'] ?? 0);
        $meta = ['master_holding' => $holding, 'master_exposure' => round($exposure, 4)];

        // ไม่ไล่ราคา: เข้าช้ากว่ามาสเตอร์แล้วราคาวิ่งไปไกล = ได้ราคาคนละจุด ผลของผู้ตามจะไม่เหมือนที่เห็นบนกระดาน
        $maxDrift = (float) config('aibot.copy_trade.follow.max_entry_drift_pct', 1.0);
        $drift = $entry > 0 ? ($close - $entry) / $entry * 100 : 0.0;

        if ($position) {
            if (! $holding) {
                return Signal::sell(1.0, 'มาสเตอร์ปิดไม้แล้ว — ปิดตาม', $meta);
            }

            $cap = max(1.0, (float) ($copy['follower_cap'] ?? 100));
            $cost = (float) $position['qty'] * (float) $position['entry'];
            $gap = $cap * $exposure - $cost;
            $step = max((float) config('aibot.copy_trade.follow.min_add_usd', 10), $cap * 0.1);

            if ($gap >= $step && $drift <= $maxDrift) {
                return Signal::buy(min(1.0, $gap / $cap), 'มาสเตอร์เติมไม้ — เติมตามสัดส่วนทุน', $meta);
            }

            return Signal::hold('ถือตามมาสเตอร์', $meta);
        }

        if (! $holding) {
            return Signal::hold('มาสเตอร์ยังไม่ได้ถือไม้ — รอไม้ถัดไป', $meta);
        }

        if (! ($copy['opened_after_follow'] ?? false)) {
            return Signal::hold('มาสเตอร์เปิดไม้นี้ไว้ก่อนคุณเริ่มตาม — รอไม้ถัดไป', $meta);
        }

        if ($drift > $maxDrift) {
            return Signal::hold(sprintf('ราคาวิ่งเกินจุดเข้าของมาสเตอร์ไปแล้ว %.2f%% (ยอมได้ %.2f%%) — ไม่ไล่ราคา', $drift, $maxDrift), $meta);
        }

        if ($exposure <= 0) {
            return Signal::hold('มาสเตอร์ยังไม่ได้ถือไม้ — รอไม้ถัดไป', $meta);
        }

        // ความแรงสัญญาณ = สัดส่วนทุนของมาสเตอร์ → PositionSizer ได้งบ = เพดานของผู้ตาม × สัดส่วนนั้น
        return Signal::buy($exposure, 'มาสเตอร์เปิดไม้ — เข้าตามด้วยสัดส่วนทุนเดียวกัน', $meta);
    }
}
