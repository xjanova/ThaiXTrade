<?php

namespace App\Services\AiBot\Analyst;

use App\Models\AiBotPosition;
use App\Models\AiMarketView;
use App\Models\MarketNews;
use App\Models\TradingPair;
use App\Services\AiBotService;
use App\Services\MarketDataService;

/**
 * TPIX TRADE — ประกอบ "สิ่งที่ AI จะได้เห็น" ก่อนถามความเห็น.
 *
 * แยกออกจาก MarketAnalyst เพราะสองอย่างนี้พังคนละแบบและทดสอบคนละวิธี:
 * ตัวนี้เป็นการรวบรวมข้อมูลล้วน (ทดสอบได้โดยไม่ต้องมีคีย์ ไม่ต้องต่อเน็ตออกนอก)
 * ส่วนตัวโน้นคือการคุยกับผู้ให้บริการภายนอกและแปลคำตอบ
 *
 * ⚠️ ห้ามใส่ที่อยู่กระเป๋า อีเมล หรืออะไรที่ระบุตัวผู้ใช้ลงในบริบทเด็ดขาด
 *    ข้อมูลนี้ออกไปนอกระบบเราและเราควบคุมไม่ได้ว่าปลายทางเก็บไว้นานแค่ไหน
 *    สถานะการถือครองจึงถูกรวมเป็นยอดรวมต่อเหรียญเท่านั้น ไม่แยกรายกระเป๋า
 *
 * Developed by Xman Studio.
 */
class MarketContext
{
    public function __construct(
        private readonly MarketDataService $market,
        private readonly AiBotService $bots,
        private readonly CoinSnapshot $snapshot,
        private readonly AnalystScorer $scorer,
    ) {}

    /**
     * ประกอบบริบทของรอบวิเคราะห์.
     *
     * @return array{
     *     coins: list<array>,
     *     headlines: list<array>,
     *     holdings: list<array>,
     *     cost_bps: float,
     *     generated_at: string
     * }
     */
    public function build(string $scope): array
    {
        $config = (array) config("aibot_analyst.scopes.{$scope}", []);

        $universe = $this->universe();
        $tickers = $this->tickerMap();
        $newsStats = $this->newsStats($universe, $scope);
        $held = $this->holdings();

        /*
         * เรียงลำดับความสำคัญก่อนตัด ไม่ใช่ตัดตามลำดับตัวอักษร
         *
         * รอบสั้นดูได้แค่ 25 เหรียญ ถ้าตัดมั่วๆ เหรียญที่มีเงินของผู้ใช้ค้างอยู่
         * อาจหลุดออกจากบริบท แล้ว AI จะไม่มีทางบอกให้ปิดไม้ที่ควรปิดเลย
         *
         * ลำดับ: ถือของอยู่ → มีข่าวสด → ราคาขยับแรง → ที่เหลือ
         */
        $coins = $this->rankCoins($universe, $tickers, $newsStats, $held);
        $coins = array_slice($coins, 0, (int) ($config['max_coins'] ?? 40));
        $coins = $this->withSnapshots($coins);

        return [
            'coins' => $coins,
            'headlines' => $this->headlines(
                array_column($coins, 'symbol'),
                (int) ($config['max_headlines'] ?? 20),
            ),
            'holdings' => array_values($held),
            'macro' => $this->macro(),
            'track_record' => $this->trackRecord(),
            'cost_bps' => round($this->bots->roundTripCostBps(), 2),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * เติมภาพราคาให้เหรียญที่สำคัญที่สุด N ตัว — เทรนด์ใหญ่ ระยะจากจุดสูง/ต่ำ RSI ความผันผวน funding.
     *
     * ทำหลังจัดอันดับและตัดแล้ว เพื่อให้คำขอ (1 ต่อเหรียญ) ไปลงที่เหรียญที่ AI จะได้
     * เห็นจริง ล้มเหลวรายเหรียญ = ช่องว่าง ไม่ใช่ล้มทั้งรอบ
     *
     * @param  list<array>  $coins
     * @return list<array>
     */
    private function withSnapshots(array $coins): array
    {
        $max = (int) config('aibot_analyst.snapshots.max_coins', 40);

        foreach ($coins as $i => $coin) {
            if ($i >= $max) {
                break;
            }

            try {
                $coins[$i] += $this->snapshot->for($coin['symbol']);
            } catch (\Throwable) {
                // เหรียญนี้ไม่มีภาพราคา — AI จะเห็น key หายไป ซึ่งซื่อสัตย์กว่าเลขปลอม
            }
        }

        return $coins;
    }

    /** ภาพรวมที่ไม่ผูกกับเหรียญใด — ตอนนี้คือดัชนี Fear & Greed */
    private function macro(): array
    {
        try {
            return ['fear_greed' => $this->market->getFearGreedIndex()];
        } catch (\Throwable) {
            return ['fear_greed' => null];
        }
    }

    /**
     * ผลของคำตัดสินรอบก่อนของ AI เอง — ให้มันเห็นว่าที่ผ่านมาถูกหรือผิด.
     *
     * LLM ไม่มีความจำข้ามรอบ ทุกรอบมันมั่นใจเท่าเดิมไม่ว่าจะเพิ่งผิดมากี่ครั้ง
     * ออดิท 2 ก.ย. 2026: buy 21 ครั้ง ถูก 38% — ถ้าตัวมันเองได้เห็นตัวเลขนี้ในบริบท
     * อย่างน้อยก็มีโอกาสปรับความมั่นใจลง (และเราวัดได้ว่าปรับจริงไหมด้วย Brier score)
     *
     * @return array{window_days: int, by_stance: array, recent: list<array>}|null
     */
    private function trackRecord(): ?array
    {
        $days = (int) config('aibot_analyst.track_record.days', 3);
        $horizon = (int) config('aibot_analyst.calibration.horizon_hours', 4);

        try {
            $views = AiMarketView::query()
                ->where('created_at', '>=', now()->subDays($days))
                ->where('created_at', '<=', now()->subHours($horizon))
                ->orderBy('created_at')
                ->get();

            if ($views->isEmpty()) {
                return null;
            }

            $calls = $this->scorer->score($views, $horizon);
        } catch (\Throwable) {
            return null;
        }

        if ($calls === []) {
            return null;
        }

        $actionable = array_values(array_filter($calls, fn ($c) => $c['correct'] !== null));
        $recent = array_slice(array_reverse($actionable), 0, (int) config('aibot_analyst.track_record.recent_calls', 6));

        return [
            'window_days' => $days,
            'horizon_hours' => $horizon,
            'by_stance' => $this->scorer->summarizeByStance($calls),
            'recent' => array_map(fn ($c) => [
                'at' => $c['at'],
                'symbol' => $c['symbol'],
                'stance' => $c['stance'],
                'confidence' => $c['confidence'],
                'move_bps' => $c['move_bps'],
                'correct' => $c['correct'],
            ], $recent),
        ];
    }

    /**
     * เหรียญฐานของคู่เทรดที่เปิดใช้งานอยู่.
     *
     * อ่านจากฐานข้อมูลไม่ใช่ค่าคงที่ในโค้ด — แอดมินเปิด/ปิดคู่เทรดได้จากหลังบ้าน
     * และ universe ต้องขยับตามทันที ไม่ใช่รอ deploy รอบหน้า
     *
     * @return list<string>
     */
    public function universe(): array
    {
        $known = array_keys((array) config('aibot_coins.coins', []));

        $bases = TradingPair::query()
            ->where('is_active', true)
            ->pluck('symbol')
            ->map(fn (string $symbol) => strtoupper(explode('/', str_replace('-', '/', $symbol))[0]))
            ->unique();

        /*
         * เอาเฉพาะเหรียญที่มีในพจนานุกรม — เหรียญที่ไม่มีแปลว่าเรายังไม่รู้ว่าจะ
         * หาข่าวมันยังไง ส่งชื่อเปล่าๆ ให้ AI จัดอันดับจึงเป็นการขอให้มันเดา
         *
         * เหรียญที่หลุดจะโผล่ในรายงานของ aibot:analyze --coverage ให้ไปเติม
         */
        return $bases->intersect($known)->values()->all();
    }

    /** เหรียญที่อยู่ในคู่เทรดแต่ยังไม่มีในพจนานุกรม — ช่องว่างที่ต้องไปเติม */
    public function unknownCoins(): array
    {
        $known = array_keys((array) config('aibot_coins.coins', []));

        return TradingPair::query()
            ->where('is_active', true)
            ->pluck('symbol')
            ->map(fn (string $symbol) => strtoupper(explode('/', str_replace('-', '/', $symbol))[0]))
            ->unique()
            ->diff($known)
            ->values()
            ->all();
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /**
     * ราคาและการเปลี่ยนแปลง 24 ชม. ของทุกเหรียญในคำขอเดียว.
     *
     * ใช้ /ticker/24hr ที่คืนมาทั้งตลาดครั้งเดียว ไม่ใช่ยิงทีละเหรียญ 70 ครั้ง
     * (70 คำขอต่อรอบ × 96 รอบ/วัน = 6,720 ครั้ง ซึ่งจะโดนจำกัดแน่นอน)
     *
     * @return array<string, array{price: float, change: float, volume: float}>
     */
    private function tickerMap(): array
    {
        $map = [];

        foreach ($this->market->getTickers() as $ticker) {
            $base = strtoupper((string) ($ticker['baseAsset'] ?? ''));

            if ($base === '') {
                continue;
            }

            $map[$base] = [
                'price' => (float) ($ticker['lastPrice'] ?? 0),
                'change' => (float) ($ticker['priceChangePercent'] ?? 0),
                'volume' => (float) ($ticker['quoteVolume'] ?? 0),
            ];
        }

        return $map;
    }

    /**
     * สรุปข่าวต่อเหรียญในหน้าต่างเวลาของรอบนี้.
     *
     * @param  list<string>  $universe
     * @return array<string, array{count: int, panic: float, sentiment: float}>
     */
    private function newsStats(array $universe, string $scope): array
    {
        /*
         * รอบใหญ่มองย้อนไกลกว่ารอบสั้น — รอบ 4 ชั่วโมงที่ดูข่าวแค่ 3 ชั่วโมง
         * จะพลาดข่าวที่เข้ามาตอนต้นช่วงไปทั้งหมด
         */
        $minutes = $scope === 'strategic' ? 24 * 60 : 180;

        $rows = MarketNews::query()
            ->recent($minutes)
            ->get(['symbols', 'panic_score', 'sentiment']);

        $stats = array_fill_keys($universe, ['count' => 0, 'panic' => 0.0, 'sentiment_sum' => 0.0, 'sentiment_avg' => null]);

        foreach ($rows as $row) {
            foreach ((array) $row->symbols as $symbol) {
                if (! isset($stats[$symbol])) {
                    continue;
                }

                $stats[$symbol]['count']++;
                $stats[$symbol]['panic'] = max($stats[$symbol]['panic'], (float) $row->panic_score);
                $stats[$symbol]['sentiment_sum'] += (float) $row->sentiment;
            }
        }

        /*
         * ค่าเฉลี่ย ไม่ใช่ผลรวม — BTC มีข่าว 41 ข่าว/วัน ผลรวมได้ ±10 ขณะที่เหรียญอื่น ±1
         * โดยไม่มีสเกลบอกใน prompt (ออดิท 2 ก.ย. 2026) AI จึงอ่าน "BTC ลบมาก" ทั้งที่
         * ข่าวแต่ละชิ้นแค่ลบนิดหน่อยแต่มีเยอะ ค่าเฉลี่ยอยู่ใน −1..1 เสมอ เทียบข้ามเหรียญได้
         */
        foreach ($stats as $symbol => $s) {
            $stats[$symbol]['sentiment_avg'] = $s['count'] > 0 ? round($s['sentiment_sum'] / $s['count'], 2) : null;
        }

        return $stats;
    }

    /**
     * ยอดถือครองรวมต่อเหรียญ — ไม่แยกรายกระเป๋า (ดูหมายเหตุความเป็นส่วนตัวด้านบน).
     *
     * @return array<string, array{symbol: string, cost_usd: float, positions: int}>
     */
    private function holdings(): array
    {
        $held = [];

        foreach (AiBotPosition::where('quantity', '>', 0)->get(['pair', 'cost_basis']) as $position) {
            $base = strtoupper(explode('/', str_replace('-', '/', (string) $position->pair))[0]);

            $held[$base] ??= ['symbol' => $base, 'cost_usd' => 0.0, 'positions' => 0];
            $held[$base]['cost_usd'] += (float) $position->cost_basis;
            $held[$base]['positions']++;
        }

        foreach ($held as $symbol => $row) {
            $held[$symbol]['cost_usd'] = round($row['cost_usd'], 2);
        }

        return $held;
    }

    /**
     * จัดอันดับความสำคัญของเหรียญก่อนตัดให้พอดีกับเพดานของรอบ.
     *
     * @return list<array>
     */
    private function rankCoins(array $universe, array $tickers, array $newsStats, array $held): array
    {
        $rows = [];

        foreach ($universe as $symbol) {
            $ticker = $tickers[$symbol] ?? null;
            $news = $newsStats[$symbol] ?? ['count' => 0, 'panic' => 0.0, 'sentiment_avg' => null];
            $isHeld = isset($held[$symbol]);

            $rows[] = [
                'symbol' => $symbol,
                'name' => (string) config("aibot_coins.coins.{$symbol}.name", $symbol),
                'price' => $ticker ? round($ticker['price'], 6) : null,
                'change_24h_pct' => $ticker ? round($ticker['change'], 2) : null,
                'volume_24h_usd' => $ticker ? round($ticker['volume']) : null,
                'news_count' => $news['count'],
                'worst_panic' => round($news['panic'], 3),
                'sentiment_avg' => $news['sentiment_avg'],
                'held' => $isHeld,

                /* ไว้เรียงอย่างเดียว ไม่ส่งให้ AI (ไม่ใช่ข้อมูล เป็นวิธีการของเรา) */
                '_priority' => ($isHeld ? 1000 : 0)
                    + min(100, $news['count'] * 10)
                    + ($ticker ? min(50, abs($ticker['change'])) : 0),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['_priority'] <=> $a['_priority']);

        return array_map(function (array $row) {
            unset($row['_priority']);

            return $row;
        }, $rows);
    }

    /**
     * พาดหัวข่าวที่เกี่ยวกับเหรียญในบริบทรอบนี้.
     *
     * @param  list<string>  $symbols
     * @return list<array{title: string, source: string, symbols: list<string>, panic: float, at: string}>
     */
    private function headlines(array $symbols, int $limit): array
    {
        /*
         * ผสมสามมุม ไม่ใช่เรียงตามความน่ากลัวอย่างเดียว
         *
         * ⚠️ เดิม orderByDesc('panic_score') → AI เห็น 30 ข่าวที่น่ากลัวที่สุดของวัน
         *    เสมอ ไม่ว่าวันนั้นตลาดจะเป็นยังไง ผล: regime = risk_off 19 ใน 30 รอบ
         *    และลดขนาดไม้ทั้งฝูงในช่วงที่ BTC ขึ้น 21% (ออดิท 2 ก.ย. 2026)
         *
         *   ครึ่งหนึ่ง = ล่าสุด (สิ่งที่กำลังเกิด ไม่ว่าจะดีหรือร้าย)
         *   หนึ่งในสี่ = สุดขั้วทั้งสองทาง (|sentiment| สูงสุด — ข่าวดีมากก็ต้องเห็น)
         *   หนึ่งในสี่ = ตื่นตระหนกสูงสุด (สิ่งที่ห้ามพลาดจริงๆ)
         */
        $quarter = max(1, (int) ceil($limit / 4));
        $half = max(1, $limit - 2 * $quarter);
        $fields = ['id', 'title', 'source', 'symbols', 'panic_score', 'sentiment', 'published_at'];

        $recent = MarketNews::query()->recent(24 * 60)->orderByDesc('published_at')->limit($half * 3)->get($fields);
        $polar = MarketNews::query()->recent(24 * 60)->orderByRaw('ABS(sentiment) DESC')->orderByDesc('published_at')->limit($quarter * 3)->get($fields);
        $panic = MarketNews::query()->recent(24 * 60)->orderByDesc('panic_score')->orderByDesc('published_at')->limit($quarter * 3)->get($fields);

        $picked = [];
        $seen = [];

        foreach ([[$recent, $half], [$polar, $quarter], [$panic, $quarter]] as [$rows, $quota]) {
            $taken = 0;

            foreach ($rows as $row) {
                if ($taken >= $quota || isset($seen[$row->id])) {
                    continue;
                }

                $tagged = array_values(array_intersect((array) $row->symbols, $symbols));

                /*
                 * ข่าวที่ไม่ได้แท็กเหรียญไหนเลยก็ยังมีค่า — ข่าวมหภาค (เฟด ดอกเบี้ย
                 * กฎหมาย) ลากทั้งตลาดโดยไม่เอ่ยชื่อเหรียญสักตัว ซึ่งเป็นข่าวประเภทที่
                 * AI อ่านได้ดีกว่าตัวจับคำสำคัญของเราเสียอีก
                 */
                if ($tagged === [] && (array) $row->symbols !== []) {
                    continue;
                }

                $seen[$row->id] = true;
                $taken++;
                $picked[] = [
                    'title' => (string) $row->title,
                    'source' => (string) $row->source,
                    'symbols' => $tagged,
                    'panic' => round((float) $row->panic_score, 3),
                    'sentiment' => round((float) $row->sentiment, 2),
                    'at' => $row->published_at?->toDateTimeString() ?? '',
                ];
            }
        }

        // เรียงตามเวลาให้ AI อ่านเป็นลำดับเหตุการณ์ ไม่ใช่ตามว่าเราหยิบจากถังไหนก่อน
        usort($picked, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($picked, 0, $limit);
    }
}
