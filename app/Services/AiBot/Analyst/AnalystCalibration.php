<?php

namespace App\Services\AiBot\Analyst;

use App\Models\AiMarketView;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — "AI มั่นใจเท่านี้ แล้วทายถูกจริงกี่เปอร์เซ็นต์" วัดจากประวัติของมันเอง.
 *
 * ═══ ทำไมต้องมี ═══
 * ออดิท 2 ก.ย. 2026 (30 รอบ): ความมั่นใจ ≥ 0.8 → ทายถูก 35% ราคาขยับ −50 bps
 * ขณะที่ 0.6–0.8 → ถูก 40% ขยับ +30 bps — ตัวเลขที่ LLM รายงานว่า "มั่นใจ"
 * กลับหัวกับผลจริง แต่ AiViewGate เคยใช้มันเป็นด่านเงินตรงๆ (0.55 / 0.75)
 *
 * ตัวนี้แทนที่ความมั่นใจที่รายงานเอง ด้วย "อัตราทายถูกเชิงประจักษ์" ของ
 * (ท่าที × ช่วงความมั่นใจ) จากคำตัดสินย้อนหลัง ถ้ายังมีตัวอย่างไม่พอ
 * (< min_samples ต่อช่อง) จะตอบ null และด่านถอยไปใช้เกณฑ์เดิม — ซื่อสัตย์
 * ว่ายังไม่รู้ ดีกว่าแกล้งรู้จากตัวเลข 3 ครั้ง
 *
 * ตารางถูกสร้างเป็นรอบ (aibot:calibrate ทุกวัน) แล้วเก็บใน cache — บอทถามได้
 * ทุกติ๊กโดยไม่ต้องดึงราคาย้อนหลัง
 *
 * Developed by Xman Studio.
 */
class AnalystCalibration
{
    public const CACHE_KEY = 'aibot:analyst:calibration';

    public const BUCKETS = ['low', 'mid', 'high'];

    public function __construct(private readonly AnalystScorer $scorer) {}

    /**
     * ประกอบตารางจากมุมมองย้อนหลัง แล้วเก็บลง cache.
     *
     * @return array{built_at: string, days: int, horizon: int, samples: int, buckets: array<string, array<string, array{n: int, hit_rate: float|null, avg_move_bps: float|null}>>}
     */
    public function rebuild(?int $days = null, ?int $horizon = null): array
    {
        $days ??= (int) config('aibot_analyst.calibration.days', 14);
        $horizon ??= (int) config('aibot_analyst.calibration.horizon_hours', 4);

        $views = AiMarketView::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at')
            ->get();

        $calls = $this->scorer->score($views, $horizon);
        $table = self::tabulate($calls);

        $result = [
            'built_at' => now()->toIso8601String(),
            'days' => $days,
            'horizon' => $horizon,
            'samples' => count(array_filter($calls, fn ($c) => $c['correct'] !== null)),
            'brier' => AnalystScorer::brier($calls),
            'buckets' => $table,
        ];

        Cache::put(self::CACHE_KEY, $result, now()->addHours((int) config('aibot_analyst.calibration.ttl_hours', 36)));

        return $result;
    }

    /**
     * นับต่อ (ท่าที × ช่วงความมั่นใจ) — pure ทดสอบได้โดยไม่ต้องมีราคา.
     *
     * @param  list<array>  $calls
     * @return array<string, array<string, array{n: int, hit_rate: float|null, avg_move_bps: float|null}>>
     */
    public static function tabulate(array $calls): array
    {
        $table = [];

        foreach (['buy', 'avoid', 'exit'] as $stance) {
            foreach (self::BUCKETS as $bucket) {
                $table[$stance][$bucket] = ['n' => 0, 'hit_rate' => null, 'avg_move_bps' => null];
            }
        }

        $acc = [];

        foreach ($calls as $call) {
            if ($call['correct'] === null || ! isset($table[$call['stance']])) {
                continue;
            }

            $bucket = self::bucketOf((float) $call['confidence']);
            $acc[$call['stance']][$bucket]['n'] = ($acc[$call['stance']][$bucket]['n'] ?? 0) + 1;
            $acc[$call['stance']][$bucket]['hits'] = ($acc[$call['stance']][$bucket]['hits'] ?? 0) + ($call['correct'] ? 1 : 0);
            $acc[$call['stance']][$bucket]['move'] = ($acc[$call['stance']][$bucket]['move'] ?? 0.0) + (float) $call['move_bps'];
        }

        foreach ($acc as $stance => $buckets) {
            foreach ($buckets as $bucket => $a) {
                $table[$stance][$bucket] = [
                    'n' => $a['n'],
                    'hit_rate' => round($a['hits'] / $a['n'], 3),
                    'avg_move_bps' => round($a['move'] / $a['n'], 1),
                ];
            }
        }

        return $table;
    }

    /** ตารางล่าสุดจาก cache (null = ยังไม่เคยสร้าง / หมดอายุ) */
    public function table(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    /**
     * อัตราทายถูกเชิงประจักษ์ของท่าทีนี้ที่ระดับความมั่นใจนี้ — null เมื่อยังไม่มีข้อมูลพอ.
     */
    public function hitRate(string $stance, float $confidence): ?float
    {
        $table = $this->table();
        $cell = $table['buckets'][$stance][self::bucketOf($confidence)] ?? null;

        if (! $cell || (int) $cell['n'] < (int) config('aibot_analyst.calibration.min_samples', 15)) {
            return null;
        }

        return $cell['hit_rate'];
    }

    /** ช่วงความมั่นใจ — ช่วงเดียวกับที่รายงาน aibot:analyst-report ใช้ */
    public static function bucketOf(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.8 => 'high',
            $confidence >= 0.6 => 'mid',
            default => 'low',
        };
    }
}
