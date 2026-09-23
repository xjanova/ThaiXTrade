<?php

namespace App\Services\AiBot\Analyst;

use App\Models\AiMarketView;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

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

        $brier = AnalystScorer::brier($calls);
        $brierSamples = count(array_filter($calls, fn ($c) => $c['p_up'] !== null));

        $result = [
            'built_at' => now()->toIso8601String(),
            'days' => $days,
            'horizon' => $horizon,
            'samples' => count(array_filter($calls, fn ($c) => $c['correct'] !== null)),
            /*
             * Brier ของ p_up วัดที่ horizon เดียวกับตาราง — ค่าปริยาย 24 ชม. ตรงกับที่ prompt
             * ขอ (p_up_24h) · เดิมตารางวัดที่ 4 ชม. จึงเอาความน่าจะเป็นของ 24 ชม. ไปเทียบกับ
             * ผลของ 4 ชม. (คนละคำถามกัน)
             */
            'brier' => $brier,
            'brier_samples' => $brierSamples,
            // คำตัดสินอำนาจคิดตอนสร้างตาราง เพื่อให้ hysteresis เห็นคำตัดสินรอบก่อน (ดู verdictFor)
            'verdict' => self::verdictFor($brier, $brierSamples, $this->table()['verdict'] ?? null),
            'buckets' => $table,
        ];

        Cache::put(self::CACHE_KEY, $result, now()->addHours((int) config('aibot_analyst.calibration.ttl_hours', 36)));

        /*
         * เก็บลงไฟล์ด้วย — cache ถูกล้างทุกครั้งที่ deploy (post-deploy สั่ง cache:clear)
         * ถ้าอยู่ใน cache อย่างเดียว AI ที่ถูกลดสิทธิ์จะได้อำนาจคืนทุกครั้งที่ deploy
         * ไปจนถึงรอบ calibrate ถัดไป (รีวิว 2026-09-23)
         */
        $store = (string) config('aibot_analyst.calibration.store', '');

        if ($store !== '') {
            File::ensureDirectoryExists(dirname($store));
            File::put($store, json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        return $result;
    }

    /**
     * คำตัดสินอำนาจแบบมี hysteresis — ถูกลดสิทธิ์แล้วต้องดีขึ้น "ชัดเจน" ถึงจะได้คืน.
     *
     * รีวิว 2026-09-23: เกณฑ์เดียว 0.25 + ตัวอย่างรายเหรียญที่ขยับตามกัน → AI ที่ Brier วนรอบ
     * 0.25 จะได้/เสียอำนาจสลับไปมาทุกวัน ซึ่งแย่กว่าการตัดสินอย่างใดอย่างหนึ่งไปเลย
     */
    public static function verdictFor(?float $brier, int $samples, ?string $previous): string
    {
        $minSamples = (int) config('aibot_analyst.authority.min_samples', 60);
        $maxBrier = (float) config('aibot_analyst.authority.max_brier', 0.25);
        $margin = (float) config('aibot_analyst.authority.regain_margin', 0.01);

        if ($brier === null || $samples < $minSamples) {
            return 'unproven';
        }

        if ($previous === 'no_skill') {
            return $brier < $maxBrier - $margin ? 'skilled' : 'no_skill';
        }

        return $brier >= $maxBrier ? 'no_skill' : 'skilled';
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

    /**
     * ตารางล่าสุด — cache ก่อน ถ้าหาย (deploy สั่ง cache:clear) อ่านจากไฟล์ที่ rebuild เก็บไว้
     * แล้วอุ่น cache กลับ · null = ยังไม่เคยสร้าง หรือเก่าเกิน ttl_hours (cron ตายเกิน 1 รอบครึ่ง).
     */
    public function table(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $store = (string) config('aibot_analyst.calibration.store', '');

        if ($store === '' || ! File::exists($store)) {
            return null;
        }

        $stored = json_decode((string) File::get($store), true);

        if (! is_array($stored) || empty($stored['built_at'])) {
            return null;
        }

        try {
            $expires = Carbon::parse((string) $stored['built_at'])
                ->addHours((int) config('aibot_analyst.calibration.ttl_hours', 36));
        } catch (\Throwable) {
            return null;   // ไฟล์เสียต้องไม่ทำให้บอทหยุด — ถือว่ายังไม่มีตาราง
        }

        if ($expires->isPast()) {
            return null;
        }

        Cache::put(self::CACHE_KEY, $stored, $expires);

        return $stored;
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

    /**
     * AI มีฝีมือที่วัดได้พอจะได้รับอำนาจเหนือเงินไหม.
     *
     * ═══ ทำไมต้องมี ═══
     * ออดิทกองบอท R3 (2 → 23 ก.ย. 2026, 123 รอบ · 270 คำตัดสิน วัดที่ 24 ชม.):
     *   p_up Brier 0.292 — แย่กว่าตอบ 0.5 ทุกครั้ง (0.250) · p_up ≥ 0.75 → ขึ้นจริง 33%
     *   buy มัธยฐาน −83 bps · avoid ขึ้น 79% (+101 bps) · เหรียญที่ AI ให้ buy แพ้ BTC −98 bps/วัน
     * ความเห็นที่ "ทำนายกลับข้าง" ยังได้สิทธิ์ห้ามเข้าไม้ ลดขนาดไม้ และย้ายเหรียญอยู่ครบ —
     * อำนาจต้องมาจากผลงานที่วัดได้ ไม่ใช่มาพร้อมการเปิดสวิตช์
     *
     * ═══ คำตัดสิน ═══
     *   unproven — ยังมีตัวอย่างไม่พอ (หรือยังไม่เคยสร้างตาราง) → ใช้กติกาเดิมรายช่อง
     *   no_skill — Brier ≥ max_brier บนตัวอย่างพอแล้ว → AI ไม่มีสิทธิ์แตะเงินเลย
     *   skilled  — ดีกว่าโยนเหรียญ → ใช้อำนาจตามกติกาเดิม (ยังถูกคุมรายช่องด้วยตาราง)
     *
     * AI ยังวิเคราะห์และถูกให้คะแนนต่อทุกรอบ — ฝีมือดีขึ้นเมื่อไหร่ ได้อำนาจคืนเองในรอบ
     * calibrate ถัดไป โดยไม่ต้องมีคนมาเปิดสวิตช์
     *
     * @return array{verdict: string, brier: float|null, samples: int, reason: string}
     */
    public function skill(): array
    {
        $table = $this->table();
        $brier = isset($table['brier']) ? (float) $table['brier'] : null;
        $samples = (int) ($table['brier_samples'] ?? 0);

        $minSamples = (int) config('aibot_analyst.authority.min_samples', 60);
        $maxBrier = (float) config('aibot_analyst.authority.max_brier', 0.25);

        // ตารางรุ่นใหม่มีคำตัดสินที่ผ่าน hysteresis แล้ว — ตารางรุ่นเก่าคิดสดจากตัวเลข
        $verdict = (string) ($table['verdict'] ?? self::verdictFor($brier, $samples, null));

        if ($verdict === 'unproven') {
            return [
                'verdict' => 'unproven',
                'brier' => $brier,
                'samples' => $samples,
                'reason' => "ยังวัดฝีมือ AI ไม่ได้ (ตัวอย่าง {$samples}/{$minSamples})",
            ];
        }

        if ($verdict === 'no_skill') {
            return [
                'verdict' => 'no_skill',
                'brier' => $brier,
                'samples' => $samples,
                'reason' => sprintf(
                    'AI ยังทายไม่ดีกว่าโยนเหรียญ (Brier %.3f ≥ %.2f จาก %d คำตัดสิน) — ดูได้อย่างเดียว ไม่มีสิทธิ์แตะเงิน',
                    $brier,
                    $maxBrier,
                    $samples,
                ),
            ];
        }

        return [
            'verdict' => 'skilled',
            'brier' => $brier,
            'samples' => $samples,
            'reason' => sprintf('AI ทายดีกว่าโยนเหรียญ (Brier %.3f จาก %d คำตัดสิน)', $brier, $samples),
        ];
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
