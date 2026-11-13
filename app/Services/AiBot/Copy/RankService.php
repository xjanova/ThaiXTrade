<?php

namespace App\Services\AiBot\Copy;

use App\Models\AiBotTrade;
use App\Models\AiCopyMaster;
use App\Models\AiCopyRankHistory;
use Illuminate\Support\Carbon;

/**
 * TPIX TRADE — ระดับ (rank) ของมาสเตอร์: ขึ้นตามผลงานเงินจริง ลงเมื่อผลงานแย่ลง.
 *
 * เจ้าของสั่ง 2026-11-13: "มีระบบ rank เพื่อเพิ่มความน่าเชื่อถือ … ยิ่งผลงานโดดเด่นก็ rank สูง แต่ก็ลดได้ถ้าผลงานเริ่มแย่"
 *
 * ═══ คะแนน 0–100 จากไม้เงินจริงที่ปิดแล้วเท่านั้น ═══
 *   return       ผลตอบแทนต่อทุนที่ใช้จริง
 *   drawdown     ขาดทุนสูงสุดระหว่างทาง (ยิ่งน้อยยิ่งได้)
 *   consistency  สัดส่วนสัปดาห์ที่ปิดบวก ในสัปดาห์ที่มีไม้ปิด (กำไรก้อนเดียวครั้งเดียวไม่ใช่ความสม่ำเสมอ)
 *   sample       จำนวนไม้ที่ปิด (ตัวอย่างน้อย = เชื่อได้น้อย)
 *   followers    สัดส่วนผู้ตามที่ผลรวมเป็นบวก (ยังไม่มีผู้ตามที่ปิดไม้ = ได้ครึ่งเดียว ไม่บวกไม่ลบ)
 *
 * ⚠️ ไม่มีส่วนแบ่งแพลตฟอร์ม ค่าบริการ หรือดาวรีวิวในสูตร — ระดับซื้อไม่ได้ และรีวิวปั๊มระดับไม่ได้
 *
 * ═══ กันระดับแกว่ง ═══
 *   ขึ้น: ทันทีที่คะแนนถึงเกณฑ์ของระดับที่สูงกว่า (ผลงานที่ทำได้แล้วคือของจริง)
 *   ลง: ต้องต่ำกว่าเกณฑ์ของระดับปัจจุบันเกิน demote_margin "ต่อเนื่อง" ครบ grace_days ก่อน
 *       — สัปดาห์แย่สัปดาห์เดียวไม่ทำให้ตราหาย แต่ผลงานที่แย่ลงจริงลดระดับแน่นอน
 *
 * ตรรกะอ้างเลขระดับ 0..N เท่านั้น — ชื่อและตราของแต่ละระดับอยู่ใน config เปลี่ยนได้โดยไม่แตะไฟล์นี้
 *
 * Developed by Xman Studio.
 */
class RankService
{
    public const REASON_PROMOTED = 'promoted';

    public const REASON_DEMOTED = 'demoted';

    /** ผลงานต่ำกว่าเกณฑ์มาสเตอร์ครบช่วงผ่อน — ถอดระดับทั้งหมด */
    public const REASON_BELOW_FLOOR = 'below_floor';

    public function __construct(private readonly CopyTradeService $copy) {}

    /**
     * คะแนนของมาสเตอร์ตอนนี้.
     *
     * @return array{score: float, meets_floor: bool, components: array<string, float>, record: array}
     */
    public function score(AiCopyMaster $master, ?Carbon $now = null): array
    {
        $now ??= now();
        $bot = $master->bot;

        if (! $bot) {
            return ['score' => 0.0, 'meets_floor' => false, 'components' => [], 'record' => []];
        }

        $rule = (array) config('aibot.copy_trade.rank', []);
        $weights = (array) ($rule['weights'] ?? []);
        $eligibility = $this->copy->eligibility($bot);
        $record = $eligibility['record'];

        $returnFull = max(1.0, (float) ($rule['return_full_pct'] ?? 40));
        $ddCeiling = max(1.0, (float) config('aibot.copy_trade.master.max_drawdown_pct', 25));
        $sampleFull = max(1, (int) ($rule['sample_full_trades'] ?? 100));

        $unit = [
            'return' => max(0.0, min(1.0, $record['return_pct'] / $returnFull)),
            'drawdown' => max(0.0, 1 - min(1.0, $record['max_drawdown_pct'] / $ddCeiling)),
            'consistency' => $this->consistency($bot->id, (int) ($rule['consistency_weeks'] ?? 12), $now),
            'sample' => min(1.0, $record['closed'] / $sampleFull),
            'followers' => $this->followerOutcome($master),
        ];

        $components = [];
        foreach ($unit as $key => $value) {
            $components[$key] = round($value * (float) ($weights[$key] ?? 0), 2);
        }

        return [
            'score' => round(array_sum($components), 2),
            'meets_floor' => $eligibility['eligible'],
            'components' => $components,
            'record' => $record,
        ];
    }

    /** ระดับที่คะแนนนี้ควรได้ (0 = ไม่มีระดับ) — ผลงานต่ำกว่าเกณฑ์มาสเตอร์ไม่มีระดับไม่ว่าคะแนนเท่าไหร่ */
    public function tierFor(float $score, bool $meetsFloor): int
    {
        if (! $meetsFloor) {
            return 0;
        }

        $tier = 0;

        foreach ($this->tiers() as $number => $spec) {
            if ($score >= (float) $spec['min_score']) {
                $tier = max($tier, (int) $number);
            }
        }

        return $tier;
    }

    /** ประเมินระดับของมาสเตอร์หนึ่งคนตามกติกากันแกว่ง แล้วบันทึกประวัติเมื่อระดับเปลี่ยน */
    public function evaluate(AiCopyMaster $master, ?Carbon $now = null): AiCopyMaster
    {
        $now ??= now();
        $result = $this->score($master, $now);
        $current = (int) $master->rank_tier;
        $target = $this->tierFor($result['score'], $result['meets_floor']);
        $belowSince = $master->rank_below_since;
        $next = $current;
        $reason = null;

        if ($target > $current) {
            $next = $target;
            $reason = self::REASON_PROMOTED;
            $belowSince = null;
        } elseif ($target === $current || $this->withinMargin($result, $current)) {
            // ยังอยู่ในระดับ หรือต่ำกว่าเกณฑ์นิดเดียว (ภายในระยะผ่อน) — ไม่นับเป็นช่วงที่กำลังจะลดระดับ
            $belowSince = null;
        } elseif ($belowSince === null) {
            // เริ่มนับช่วงผ่อน — ยังไม่ลดระดับรอบนี้
            $belowSince = $now;
        } elseif ($belowSince->lte($now->copy()->subDays(max(0, (int) config('aibot.copy_trade.rank.grace_days', 7))))) {
            $next = $target;
            $reason = $result['meets_floor'] ? self::REASON_DEMOTED : self::REASON_BELOW_FLOOR;
            $belowSince = null;
        }

        $master->update([
            'rank_tier' => $next,
            'rank_score' => $result['score'],
            'rank_below_since' => $belowSince,
            'rank_evaluated_at' => $now,
        ]);

        if ($reason !== null) {
            AiCopyRankHistory::create([
                'ai_copy_master_id' => $master->id,
                'from_tier' => $current,
                'to_tier' => $next,
                'score' => $result['score'],
                'components' => $result['components'],
                'reason' => $reason,
                'changed_at' => $now,
            ]);
        }

        return $master->fresh();
    }

    /**
     * ระดับที่ใช้แสดงผล — เลขระดับ + คีย์กลาง + ชื่อจาก config (null = ยังไม่มีระดับ).
     *
     * @return array{tier: int, key: string, name_th: string, name_en: string}|null
     */
    public function present(int $tier): ?array
    {
        $spec = $this->tiers()[$tier] ?? null;

        return $spec === null ? null : [
            'tier' => $tier,
            'key' => (string) $spec['key'],
            'name_th' => (string) $spec['name_th'],
            'name_en' => (string) $spec['name_en'],
        ];
    }

    /** @return array<int, array{key: string, min_score: float|int, name_th: string, name_en: string}> */
    private function tiers(): array
    {
        return (array) config('aibot.copy_trade.rank.tiers', []);
    }

    /** คะแนนต่ำกว่าเกณฑ์ของระดับปัจจุบันไม่เกินระยะผ่อน และผลงานยังผ่านเกณฑ์มาสเตอร์ */
    private function withinMargin(array $result, int $current): bool
    {
        $spec = $this->tiers()[$current] ?? null;

        if ($spec === null || ! $result['meets_floor']) {
            return false;
        }

        return $result['score'] >= (float) $spec['min_score'] - (float) config('aibot.copy_trade.rank.demote_margin', 5);
    }

    /** สัดส่วนสัปดาห์ที่ปิดบวก ในสัปดาห์ที่มีไม้เงินจริงปิด ย้อนหลัง N สัปดาห์ (ไม่มีไม้เลย = 0) */
    private function consistency(int $botId, int $weeks, Carbon $now): float
    {
        $byWeek = [];

        AiBotTrade::where('ai_bot_config_id', $botId)
            ->where('mode', TrackRecord::MODE)
            ->where('side', 'sell')
            ->where('created_at', '>=', $now->copy()->subWeeks(max(1, $weeks)))
            ->get(['realized_pnl', 'created_at'])
            ->each(function (AiBotTrade $trade) use (&$byWeek) {
                $week = $trade->created_at->format('o-W');
                $byWeek[$week] = ($byWeek[$week] ?? 0.0) + (float) $trade->realized_pnl;
            });

        if ($byWeek === []) {
            return 0.0;
        }

        return count(array_filter($byWeek, fn (float $pnl) => $pnl > 0)) / count($byWeek);
    }

    /** สัดส่วนผู้ตามที่ผลรวมเงินจริงเป็นบวก — นับเฉพาะผู้ตามที่ปิดไม้ที่ก๊อปปี้ไปแล้ว */
    private function followerOutcome(AiCopyMaster $master): float
    {
        $follows = $master->follows()->where('last_settled_trade_id', '>', 0)->get(['realized_pnl']);

        if ($follows->isEmpty()) {
            return 0.5;
        }

        return $follows->filter(fn ($follow) => $follow->realized_pnl > 0)->count() / $follows->count();
    }
}
