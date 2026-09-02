<?php

namespace App\Services\AiBot\Analyst;

use App\Models\AiMarketView;
use App\Services\AiBotService;
use App\Services\MarketDataService;
use Illuminate\Support\Carbon;

/**
 * TPIX TRADE — ให้คะแนน "คำตัดสินของ AI" เทียบกับราคาที่เกิดขึ้นจริงหลังจากนั้น.
 *
 * ยกออกมาจาก aibot:analyst-report เพราะมีผู้ใช้สามฝ่ายที่ต้องได้เลขชุดเดียวกัน:
 *   1. รายงานให้เจ้าของอ่าน (คำสั่งเดิม)
 *   2. ตาราง calibration ที่ AiViewGate ใช้แทน "ความมั่นใจที่ AI รายงานเอง"
 *      (ออดิท 2 ก.ย. 2026: ความมั่นใจ ≥ 0.8 ทายถูก 35% ขยับ −50 bps — กลับหัว)
 *   3. ส่วน "ความจำ" ใน prompt — ให้ AI เห็นผลของคำตัดสินตัวเองรอบก่อนๆ
 *
 * กติกาที่ห้ามข้าม: ราคา "ณ เวลาที่ตัดสิน" ต้องเป็นแท่งที่ปิดก่อนหรือเท่ากับเวลานั้น
 * ใช้แท่งอนาคตเมื่อไหร่ = ให้คะแนนด้วยข้อมูลที่ AI ไม่มีทางเห็น ผลจะสวยเกินจริงทุกครั้ง
 *
 * Developed by Xman Studio.
 */
class AnalystScorer
{
    /** @var array<string, array<int, float>> ราคาต่อเหรียญ — ดึงครั้งเดียวต่อรอบให้คะแนน */
    private array $series = [];

    public function __construct(
        private readonly MarketDataService $market,
        private readonly AiBotService $bots,
    ) {}

    /**
     * จับคู่ทุกคำตัดสินในมุมมองที่ให้มา กับราคาที่ +horizon ชั่วโมงหลังจากนั้น.
     *
     * @param  iterable<AiMarketView>  $views
     * @return list<array{
     *     view_id: int, at: string, symbol: string, stance: string, score: float,
     *     confidence: float, p_up: float|null, move_bps: float, shortlisted: bool,
     *     correct: bool|null, beat_cost: bool|null
     * }>
     */
    public function score(iterable $views, int $horizonHours = 4): array
    {
        $views = collect($views)->values();

        if ($views->isEmpty()) {
            return [];
        }

        $costBps = $this->bots->roundTripCostBps();

        /*
         * ความลึกของแท่งเทียนต้องคลุมมุมมองที่เก่าที่สุด ไม่ใช่เลขคงที่
         * เดิมฮาร์ดโค้ด 200 แท่ง → --days=14 ทำให้มุมมองช่วงต้นถูกข้ามเงียบๆ
         */
        $oldest = $views->min('created_at');
        $hours = $oldest instanceof Carbon ? (int) ceil($oldest->diffInHours(now())) : 24;
        $bars = min(500, $hours + $horizonHours + 48);

        $symbols = $views
            ->flatMap(fn (AiMarketView $v) => array_keys((array) $v->coins))
            ->unique()
            ->values();

        foreach ($symbols as $symbol) {
            $this->series[$symbol] ??= $this->priceSeries($symbol, $bars);
        }

        $calls = [];

        foreach ($views as $view) {
            $shortlist = array_map(fn (string $pair) => AiMarketView::baseOf($pair), $view->shortlistPairs());

            foreach ((array) $view->coins as $symbol => $entry) {
                $series = $this->series[$symbol] ?? [];

                $at = self::priceAt($series, $view->created_at->getTimestamp());
                $later = self::priceAt($series, $view->created_at->copy()->addHours($horizonHours)->getTimestamp());

                // ยังไม่ถึงเวลาวัด หรือไม่มีราคาของเหรียญนี้ — ข้าม ไม่เดา
                if ($at === null || $later === null || $at <= 0) {
                    continue;
                }

                // ต้องเห็นราคา "หลัง" horizon จริง ไม่ใช่ราคาเดิมที่ยังไม่ขยับเพราะแท่งใหม่ยังไม่มา
                if ($view->created_at->copy()->addHours($horizonHours)->gt(now())) {
                    continue;
                }

                $stance = (string) ($entry['stance'] ?? 'hold');
                $move = (($later - $at) / $at) * 10000;

                $calls[] = [
                    'view_id' => (int) $view->id,
                    'at' => $view->created_at->toDateTimeString(),
                    'symbol' => (string) $symbol,
                    'stance' => $stance,
                    'score' => (float) ($entry['score'] ?? 0),
                    'confidence' => (float) $view->confidence,
                    'p_up' => isset($entry['p_up']) ? (float) $entry['p_up'] : null,
                    'move_bps' => round($move, 2),
                    'shortlisted' => in_array($symbol, $shortlist, true),
                    'correct' => self::correct($stance, $move),
                    'beat_cost' => self::beatCost($stance, $move, $costBps),
                ];
            }
        }

        return $calls;
    }

    /** ทายถูกไหม — buy ควรขึ้น · avoid/exit ควรลง · hold ไม่ตัดสิน */
    public static function correct(string $stance, float $moveBps): ?bool
    {
        return match ($stance) {
            'buy' => $moveBps > 0,
            'avoid', 'exit' => $moveBps < 0,
            default => null,
        };
    }

    /** ชนะต้นทุนไหม — เกณฑ์ที่แพงกว่าและสำคัญกว่า "ทายถูก" */
    public static function beatCost(string $stance, float $moveBps, float $costBps): ?bool
    {
        return match ($stance) {
            'buy' => $moveBps > $costBps,
            'avoid', 'exit' => $moveBps < -$costBps,
            default => null,
        };
    }

    /**
     * สรุปต่อท่าที — จำนวน · ทายถูก% · ชนะต้นทุน% · ขยับเฉลี่ย.
     *
     * @param  list<array>  $calls
     * @return array<string, array{n: int, correct_pct: float|null, beat_cost_pct: float|null, avg_move_bps: float}>
     */
    public function summarizeByStance(array $calls): array
    {
        $out = [];

        foreach (['buy', 'avoid', 'exit', 'hold'] as $stance) {
            $group = array_values(array_filter($calls, fn ($c) => $c['stance'] === $stance));

            if ($group === []) {
                continue;
            }

            $moves = array_column($group, 'move_bps');
            $judged = array_filter($group, fn ($c) => $c['correct'] !== null);

            $out[$stance] = [
                'n' => count($group),
                'correct_pct' => $judged === [] ? null : round(count(array_filter($judged, fn ($c) => $c['correct'])) / count($judged) * 100, 1),
                'beat_cost_pct' => $judged === [] ? null : round(count(array_filter($judged, fn ($c) => $c['beat_cost'])) / count($judged) * 100, 1),
                'avg_move_bps' => round(array_sum($moves) / count($moves), 1),
            ];
        }

        return $out;
    }

    /**
     * Brier score ของความน่าจะเป็นที่ AI ให้ (p_up) — 0 = สมบูรณ์แบบ · 0.25 = โยนเหรียญ.
     *
     * ใช้ได้เฉพาะคำตัดสินที่มี p_up (มุมมองรุ่นใหม่) คืน null เมื่อไม่มี
     *
     * @param  list<array>  $calls
     */
    public static function brier(array $calls): ?float
    {
        $scored = array_values(array_filter($calls, fn ($c) => $c['p_up'] !== null));

        if ($scored === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($scored as $c) {
            $outcome = $c['move_bps'] > 0 ? 1.0 : 0.0;
            $sum += ($c['p_up'] - $outcome) ** 2;
        }

        return round($sum / count($scored), 4);
    }

    /** @return array<int, float> timestamp(วินาที) => ราคาปิด */
    public function priceSeries(string $symbol, int $bars): array
    {
        try {
            $klines = $this->market->getKlines("{$symbol}/USDT", '1h', $bars);
        } catch (\Throwable) {
            return [];
        }

        $series = [];

        foreach ((array) $klines as $bar) {
            if (! is_array($bar)) {
                continue;
            }

            $series[(int) (((int) ($bar['time'] ?? 0)) / 1000)] = (float) ($bar['close'] ?? 0);
        }

        ksort($series);

        return $series;
    }

    /** ราคา ณ เวลาที่ใกล้ที่สุด "ก่อนหรือเท่ากับ" ที่ขอ — ห้ามใช้แท่งอนาคต */
    public static function priceAt(array $series, int $timestamp): ?float
    {
        $best = null;

        foreach ($series as $ts => $price) {
            if ($ts > $timestamp) {
                break;
            }

            $best = $price;
        }

        return $best;
    }
}
