<?php

namespace App\Services\Stabilizer;

use App\Models\StabilityDecision;
use App\Models\StabilityReport;
use App\Models\StabilityTransaction;
use App\Services\AiTextService;
use App\Support\Wei;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * รายงานสาธารณะรายวันของ TPIX Stability Reserve.
 *
 * มีสองชั้นโดยตั้งใจ:
 *   facts   = ข้อความที่ระบบประกอบเองจากตัวเลขจริง (ไม่ใช้ AI) — มีเสมอ ตรวจสอบได้
 *   summary = AI เรียบเรียงตัวเลขชุดเดียวกันเป็นภาษาคนอ่าน — ไม่มีคีย์/AI ตอบไม่ผ่านด่าน = ว่าง
 *
 * AI ไม่ได้รับสิทธิ์ตัดสินใจอะไรเลย และคำตอบที่มีคำสัญญา/การันตีราคา/อ้างว่า AI คุมราคา
 * จะถูกทิ้งทั้งก้อน (ใช้ facts แทน) — ข้อความบนหน้าสาธารณะต้องไม่ทำให้ใครเข้าใจผิด
 *
 * Developed by Xman Studio
 */
class StabilityReportBuilder
{
    /** คำที่ห้ามโผล่ในบทสรุปของ AI — เจอ = ทิ้งทั้งบทสรุป */
    private const FORBIDDEN = [
        'guarantee', 'guaranteed', 'promise', 'risk-free', 'risk free', 'will rise', 'will go up',
        'price target', 'buy now', 'sell now', 'ai controls', 'ai-controlled', 'controlled by ai',
        'รับประกัน', 'การันตี', 'สัญญาว่า', 'ไม่มีความเสี่ยง', 'ราคาจะขึ้น', 'ควรซื้อ', 'ควรขาย', 'ai ควบคุม', 'ai คุม',
    ];

    public function __construct(
        private AiTextService $ai,
        private StabilizerSettings $settings,
        private StabilizerMarket $market,
    ) {}

    public function build(CarbonInterface $date): StabilityReport
    {
        $start = Carbon::parse($date->format('Y-m-d'), config('app.timezone'))->startOfDay();
        $end = $start->copy()->endOfDay();
        $stats = $this->stats($start, $end);
        [$factsTh, $factsEn] = $this->facts($stats);

        $summary = ['th' => null, 'en' => null, 'provider' => null, 'model' => null];
        if (config('stabilizer.report.ai_enabled', true) && $stats['decisions'] > 0) {
            $summary = $this->aiSummary($stats);
        }

        $report = StabilityReport::updateOrCreate(
            ['report_date' => $start->format('Y-m-d')],
            [
                'stats' => $stats,
                'facts_th' => $factsTh,
                'facts_en' => $factsEn,
                'summary_th' => $summary['th'],
                'summary_en' => $summary['en'],
                'ai_provider' => $summary['provider'],
                'ai_model' => $summary['model'],
                'generated_at' => now(),
            ],
        );
        Cache::forget(StabilizerService::PUBLIC_CACHE_KEY);

        return $report;
    }

    /** @return array<string, mixed> */
    public function stats(CarbonInterface $start, CarbonInterface $end): array
    {
        $decisions = StabilityDecision::whereBetween('decided_at', [$start, $end])->orderBy('decided_at')->get();
        $params = $this->settings->params();

        $byAction = $decisions->countBy('action')->all();
        $byReason = $decisions->countBy('reason_code')->sortDesc()->all();

        $priced = $decisions->filter(fn ($d) => $d->pool_price !== null);
        $banded = $priced->filter(fn ($d) => $d->band_low !== null && $d->band_high !== null);
        $inside = $banded->filter(fn ($d) => bccomp((string) $d->pool_price, (string) $d->band_low, 18) >= 0
            && bccomp((string) $d->pool_price, (string) $d->band_high, 18) <= 0);

        $prices = $priced->map(fn ($d) => (string) $d->pool_price)->values();
        $sorted = $prices->sort(fn ($a, $b) => bccomp($a, $b, 18))->values();
        $last = $banded->last();

        $swaps = StabilityTransaction::swaps()->whereBetween('signed_at', [$start, $end])->orderBy('signed_at')->get();
        $confirmed = $swaps->where('status', StabilityTransaction::STATUS_CONFIRMED);

        // อ่าน decimals จากสัญญา (แคช 1 ชม.) — อ่านไม่ได้ค่อยใช้ค่าใน config
        try {
            $decimals = $this->market->usdtDecimals();
        } catch (\Throwable) {
            $decimals = (int) config('stabilizer.usdt_decimals', 6);
        }

        $sumIn = fn ($side) => Wei::sum($confirmed->where('side', $side)->pluck('amount_in_wei')->all());
        $sumOut = fn ($side) => Wei::sum($confirmed->where('side', $side)->map(fn ($t) => $t->actual_out_wei ?? $t->expected_out_wei ?? '0')->all());

        return [
            'date' => $start->format('Y-m-d'),
            'timezone' => (string) config('app.timezone'),
            'decisions' => $decisions->count(),
            'by_action' => $byAction,
            'by_reason' => $byReason,
            'inside_band_pct' => $banded->count() > 0 ? round($inside->count() * 100 / $banded->count(), 1) : null,
            'price_first' => $prices->first(),
            'price_last' => $prices->last(),
            'price_min' => $sorted->first(),
            'price_max' => $sorted->last(),
            'reference_last' => $last?->reference_price,
            'band_low_last' => $last?->band_low,
            'band_high_last' => $last?->band_high,
            'buys' => $confirmed->where('side', 'buy')->count(),
            'sells' => $confirmed->where('side', 'sell')->count(),
            'usdt_spent' => Wei::format($sumIn('buy'), $decimals),
            'tpix_bought' => Wei::format($sumOut('buy')),
            'tpix_sold' => Wei::format($sumIn('sell')),
            'usdt_received' => Wei::format($sumOut('sell'), $decimals),
            'failed_or_dropped' => $swaps->whereIn('status', [StabilityTransaction::STATUS_FAILED, StabilityTransaction::STATUS_DROPPED])->count(),
            'trades' => $swaps->map(fn ($t) => [
                'side' => $t->side,
                'status' => $t->status,
                'amount_in' => Wei::format((string) $t->amount_in_wei, $t->token_in === 'usdt' ? $decimals : 18),
                'tx_hash' => $t->tx_hash,
            ])->values()->all(),
            'band_pct' => $params['band_pct'],
            'daily_budget_usdt' => $params['daily_budget_usdt'],
            'daily_budget_tpix' => $params['daily_budget_tpix'],
            'active_at_report' => $this->settings->active(),
            'pause_reason' => $this->settings->pauseReason(),
        ];
    }

    /** @return array{0: string, 1: string} ข้อความจากตัวเลขล้วน (ไทย, อังกฤษ) */
    public function facts(array $s): array
    {
        if ($s['decisions'] === 0) {
            return [
                "วันที่ {$s['date']} กองทุนพยุงราคาไม่ได้ทำงาน (ปิดอยู่หรือยังไม่เปิดใช้) — ไม่มีการซื้อขาย",
                "On {$s['date']} the Stability Reserve did not run (switched off or not yet active). No trades were made.",
            ];
        }

        $topReason = array_key_first($s['by_reason']);
        $topCount = $topReason !== null ? $s['by_reason'][$topReason] : 0;
        $inside = $s['inside_band_pct'] !== null ? $s['inside_band_pct'].'%' : '—';

        $th = "วันที่ {$s['date']} ระบบตรวจตลาดตามกฎ {$s['decisions']} ครั้ง"
            ." ราคาพูลอยู่ในกรอบ ±{$s['band_pct']}% เป็นสัดส่วน {$inside} ของการตรวจ"
            ." ซื้อ {$s['buys']} ไม้ (ใช้ {$s['usdt_spent']} USDT ได้ {$s['tpix_bought']} TPIX)"
            ." ขาย {$s['sells']} ไม้ (ขาย {$s['tpix_sold']} TPIX ได้ {$s['usdt_received']} USDT)"
            ." เพดานรายวัน {$s['daily_budget_usdt']} USDT / {$s['daily_budget_tpix']} TPIX"
            ." เหตุผลที่พบบ่อยที่สุด: {$topReason} ({$topCount} ครั้ง)";

        $en = "On {$s['date']} the reserve checked the market under its rules {$s['decisions']} times."
            ." The pool price was inside the ±{$s['band_pct']}% band in {$inside} of checks."
            ." Buys: {$s['buys']} (spent {$s['usdt_spent']} USDT, received {$s['tpix_bought']} TPIX)."
            ." Sells: {$s['sells']} (sold {$s['tpix_sold']} TPIX, received {$s['usdt_received']} USDT)."
            ." Daily limits: {$s['daily_budget_usdt']} USDT / {$s['daily_budget_tpix']} TPIX."
            ." Most common reason: {$topReason} ({$topCount} times).";

        if ($s['failed_or_dropped'] > 0) {
            $th .= " มีธุรกรรมที่ไม่สำเร็จ {$s['failed_or_dropped']} รายการ";
            $en .= " {$s['failed_or_dropped']} transaction(s) did not complete.";
        }

        return [$th, $en];
    }

    /**
     * ให้ AI เรียบเรียงตัวเลขเป็นภาษาคนอ่าน — ล้ม/ไม่มีคีย์/ตอบไม่ผ่านด่าน = คืนค่าว่าง.
     *
     * @return array{th: ?string, en: ?string, provider: ?string, model: ?string}
     */
    private function aiSummary(array $stats): array
    {
        $empty = ['th' => null, 'en' => null, 'provider' => null, 'model' => null];

        $system = 'You write the short daily public report of the TPIX Stability Reserve. '
            .'The reserve is a RULE-BASED program: it trades only when the pool price leaves a published band around a 7-day average, '
            .'with fixed size, impact and budget limits. AI (you) only writes this summary; AI does not make trading decisions and does not control the price. '
            .'Use only the numbers provided. Never predict prices, never promise or guarantee anything, never give investment advice, never say the price is protected. '
            .'Reply with JSON only: {"th": "<Thai, 2-5 sentences>", "en": "<English, 2-5 sentences>"}.';

        // ส่งเฉพาะตัวเลขสรุป — ไม่ส่งที่อยู่กระเป๋า/tx hash ออกไปนอกระบบ (ไม่จำเป็นต่อการเขียน)
        $payload = array_diff_key($stats, ['trades' => true]);

        try {
            $result = $this->ai->chat(
                'Daily statistics (JSON): '.json_encode($payload, JSON_UNESCAPED_UNICODE),
                $system,
                ['temperature' => 0.2, 'max_tokens' => 700],
            );
        } catch (\Throwable $e) {
            Log::warning('stabilizer report: AI call threw', ['error' => $e->getMessage()]);

            return $empty;
        }

        if (! ($result['success'] ?? false)) {
            return $empty;
        }

        $text = trim((string) ($result['content'] ?? ''));
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $json = json_decode($text, true);

        $th = is_array($json) ? trim((string) ($json['th'] ?? '')) : '';
        $en = is_array($json) ? trim((string) ($json['en'] ?? '')) : '';

        if ($th === '' || $en === '' || mb_strlen($th) > 1500 || mb_strlen($en) > 1500 || ! $this->acceptable($th.' '.$en)) {
            Log::info('stabilizer report: AI summary rejected — publishing facts only');

            return $empty;
        }

        return [
            'th' => strip_tags($th),
            'en' => strip_tags($en),
            'provider' => isset($result['provider']) ? (string) $result['provider'] : null,
            'model' => isset($result['model']) ? mb_substr((string) $result['model'], 0, 64) : null,
        ];
    }

    private function acceptable(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::FORBIDDEN as $phrase) {
            if (str_contains($lower, $phrase)) {
                return false;
            }
        }

        return true;
    }
}
