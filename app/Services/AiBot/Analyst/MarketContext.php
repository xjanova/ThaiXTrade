<?php

namespace App\Services\AiBot\Analyst;

use App\Models\AiBotPosition;
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

        return [
            'coins' => $coins,
            'headlines' => $this->headlines(
                array_column($coins, 'symbol'),
                (int) ($config['max_headlines'] ?? 20),
            ),
            'holdings' => array_values($held),
            'cost_bps' => round($this->bots->roundTripCostBps(), 2),
            'generated_at' => now()->toDateTimeString(),
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

        $stats = array_fill_keys($universe, ['count' => 0, 'panic' => 0.0, 'sentiment' => 0.0]);

        foreach ($rows as $row) {
            foreach ((array) $row->symbols as $symbol) {
                if (! isset($stats[$symbol])) {
                    continue;
                }

                $stats[$symbol]['count']++;
                $stats[$symbol]['panic'] = max($stats[$symbol]['panic'], (float) $row->panic_score);
                $stats[$symbol]['sentiment'] += (float) $row->sentiment;
            }
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
            $news = $newsStats[$symbol] ?? ['count' => 0, 'panic' => 0.0, 'sentiment' => 0.0];
            $isHeld = isset($held[$symbol]);

            $rows[] = [
                'symbol' => $symbol,
                'name' => (string) config("aibot_coins.coins.{$symbol}.name", $symbol),
                'price' => $ticker ? round($ticker['price'], 6) : null,
                'change_24h_pct' => $ticker ? round($ticker['change'], 2) : null,
                'volume_24h_usd' => $ticker ? round($ticker['volume']) : null,
                'news_count' => $news['count'],
                'worst_panic' => round($news['panic'], 3),
                'sentiment' => round($news['sentiment'], 3),
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
        $rows = MarketNews::query()
            ->recent(24 * 60)
            ->orderByDesc('panic_score')
            ->orderByDesc('published_at')
            ->limit($limit * 3)
            ->get(['title', 'source', 'symbols', 'panic_score', 'published_at']);

        $picked = [];

        foreach ($rows as $row) {
            $tagged = array_values(array_intersect((array) $row->symbols, $symbols));

            /*
             * ข่าวที่ไม่ได้แท็กเหรียญไหนเลยก็ยังมีค่า — ข่าวมหภาค (เฟด ดอกเบี้ย
             * กฎหมาย) ลากทั้งตลาดโดยไม่เอ่ยชื่อเหรียญสักตัว ซึ่งเป็นข่าวประเภทที่
             * AI อ่านได้ดีกว่าตัวจับคำสำคัญของเราเสียอีก
             */
            if ($tagged === [] && (array) $row->symbols !== []) {
                continue;
            }

            $picked[] = [
                'title' => (string) $row->title,
                'source' => (string) $row->source,
                'symbols' => $tagged,
                'panic' => round((float) $row->panic_score, 3),
                'at' => $row->published_at?->toDateTimeString() ?? '',
            ];

            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }
}
