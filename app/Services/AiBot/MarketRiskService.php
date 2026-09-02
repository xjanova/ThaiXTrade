<?php

namespace App\Services\AiBot;

use App\Models\MarketNews;
use App\Services\MarketDataService;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — ด่านความเสี่ยงก่อนบอทลงมือ.
 *
 * ตอบคำถามเดียวทุกรอบ: "ตอนนี้ควรเข้าไม้เต็ม ลดขนาด หรือเทออก"
 *
 * รวมสองสัญญาณที่เป็นอิสระจากกัน:
 *   1. พฤติกรรมราคา — จับได้เร็วที่สุด ตลาดพังก่อนข่าวออกเสมอ
 *   2. ข่าว          — บอกได้ว่า "ทำไม" และช่วยยืนยันว่าไม่ใช่แค่ noise
 *
 * ใช้ค่าที่มากกว่าระหว่างสองฝั่ง (ไม่เฉลี่ย) — เพราะถ้าฝั่งใดฝั่งหนึ่งบอกว่าอันตราย
 * การเฉลี่ยจะกลบสัญญาณนั้นจนบอทเดินเข้ากองไฟ
 *
 * Developed by Xman Studio.
 */
class MarketRiskService
{
    /** หนึ่งแท่งกินเวลากี่นาที — ใช้แปลงเกณฑ์ที่ตั้งเป็นชั่วโมงให้เป็นจำนวนแท่ง */
    private const MINUTES_PER_BAR = ['1m' => 1, '5m' => 5, '15m' => 15, '1h' => 60, '4h' => 240, '1d' => 1440];

    public function __construct(
        private readonly MarketDataService $market,
        private readonly NewsFeedService $news,
    ) {}

    /**
     * ประเมินความเสี่ยงของคู่เทรดหนึ่ง.
     *
     * @return array{
     *     level: string, score: float, size_multiplier: float, force_exit: bool,
     *     market: array, news: array, reasons: list<string>
     * }
     */
    public function assess(
        string $pair,
        ?array $candles = null,
        string $timeframe = '1h',
        bool $includeNews = true,
    ): array {
        $marketRisk = $this->assessMarket($pair, $candles, $timeframe);

        /*
         * ผู้ใช้ปิดด่านข่าวได้จริง (ช่อง "หยุดเทรดช่วงข่าวแรง" ในฟอร์ม)
         * ปิดแล้วต้องได้คะแนนศูนย์จริงๆ ไม่ใช่เก็บค่าไว้แล้วยังใช้ตัดสินอยู่ดี
         */
        $newsRisk = $includeNews
            ? $this->assessNews($pair)
            : ['score' => 0.0, 'headlines' => [], 'reasons' => [], 'count' => 0, 'total_recent' => 0, 'last_ingested_at' => null];

        $score = max($marketRisk['score'], $newsRisk['score']);
        $level = $this->levelFor($score);
        $config = config("aibot_risk.levels.{$level}");

        return [
            'level' => $level,
            'score' => round($score, 3),
            'size_multiplier' => (float) $config['size_multiplier'],
            'force_exit' => (bool) $config['force_exit'],
            /*
             * ประเมินจากราคาได้จริงไหม — ยกขึ้นมาระดับบนสุดให้ผู้เรียกเห็นง่าย
             *
             * เดิมธงนี้ซ่อนอยู่ใน market.available หน้าจอจึงไม่มีใครอ่าน แล้ววาด
             * "สงบ · ความเสี่ยง 0% · ขนาดไม้ 100%" ให้คู่ที่เราไม่มีข้อมูลราคาเลย
             * (เช่น TPIX/USDT) = ยืนยันความปลอดภัยให้ผู้ใช้ก่อนจ่ายเงินโดยไม่มีฐาน
             */
            'available' => (bool) ($marketRisk['available'] ?? true),
            'market' => $marketRisk,
            'news' => $newsRisk,
            'reasons' => array_merge($marketRisk['reasons'], $newsRisk['reasons']),
        ];
    }

    /**
     * ความเสี่ยงจากพฤติกรรมราคา — ย่อแรง ผันผวนพุ่ง วอลุ่มพุ่ง.
     */
    private function assessMarket(string $pair, ?array $candles, string $timeframe = '1h'): array
    {
        $candles = $candles ?? $this->market->getKlines($pair, $timeframe, 120);

        if (count($candles) < 30) {
            return ['score' => 0.0, 'reasons' => [], 'available' => false];
        }

        $normalized = array_map(fn ($c) => [
            'high' => (float) $c['high'],
            'low' => (float) $c['low'],
            'close' => (float) $c['close'],
            'volume' => (float) $c['volume'],
        ], $candles);

        $closes = array_column($normalized, 'close');
        $thresholds = config('aibot_risk.market');

        /*
         * เกณฑ์ตั้งเป็น "ชั่วโมง" จึงต้องแปลงเป็นจำนวนแท่งตาม timeframe ของบอท
         *
         * เดิมนับ 1 แท่ง = 1 ชั่วโมง และ 24 แท่ง = 24 ชั่วโมง ตายตัว ซึ่งถูกเฉพาะ
         * บอท 1h เท่านั้น — บอท 1d อ่าน "ร่วง 7% ใน 1 ชั่วโมง" จากราคาที่ร่วงใน
         * หนึ่ง "วัน" แล้วสั่งเทออก ส่วนบอท 5m อ่าน "24 ชั่วโมง" จากช่วง 2 ชั่วโมง
         * จนเกณฑ์ไม่มีวันแตะ = ด่านที่โฆษณาไว้ไม่ทำงานเลย
         * ข้อความที่โชว์ให้ผู้ใช้ก็เป็นเท็จไปด้วยทั้งสองทาง
         */
        $minutesPerBar = self::MINUTES_PER_BAR[$timeframe] ?? 60;

        // แท่งที่ยาวกว่าหน้าต่างที่ถาม วัดละเอียดกว่า 1 แท่งไม่ได้ (4h/1d → 1 แท่ง)
        $bars1h = max(1, (int) round(60 / $minutesPerBar));
        $bars24h = max(1, (int) round(1440 / $minutesPerBar));

        $change1h = Indicators::changePct($closes, min($bars1h, count($closes) - 1));
        $change24h = Indicators::changePct($closes, min($bars24h, count($closes) - 1));

        $score = 0.0;
        $reasons = [];

        // ── ย่อระยะสั้น ──
        if ($change1h <= $thresholds['drawdown_1h_panic']) {
            $score = max($score, 0.85);
            $reasons[] = sprintf('ราคาร่วง %.1f%% ใน 1 ชั่วโมง', $change1h);
        } elseif ($change1h <= $thresholds['drawdown_1h_caution']) {
            $score = max($score, 0.4);
            $reasons[] = sprintf('ราคาย่อ %.1f%% ใน 1 ชั่วโมง', $change1h);
        }

        // ── ย่อระยะวัน ──
        if ($change24h <= $thresholds['drawdown_24h_panic']) {
            $score = max($score, 0.8);
            $reasons[] = sprintf('ราคาร่วง %.1f%% ใน 24 ชั่วโมง', $change24h);
        } elseif ($change24h <= $thresholds['drawdown_24h_caution']) {
            $score = max($score, 0.4);
            $reasons[] = sprintf('ราคาย่อ %.1f%% ใน 24 ชั่วโมง', $change24h);
        }

        // ── ความผันผวนพุ่ง (ATR ล่าสุดเทียบค่าเฉลี่ยของ ATR เอง) ──
        $atrSeries = Indicators::atr($normalized, 14);
        $atrNow = Indicators::last($atrSeries);
        $atrAvg = count($atrSeries) >= 20 ? array_sum($atrSeries) / count($atrSeries) : null;

        if ($atrNow !== null && $atrAvg !== null && $atrAvg > 0) {
            $ratio = $atrNow / $atrAvg;
            if ($ratio >= $thresholds['volatility_spike']) {
                $score = max($score, 0.55);
                $reasons[] = sprintf('ความผันผวนพุ่งเป็น %.1f เท่าของปกติ', $ratio);
            }
        }

        // ── วอลุ่มพุ่ง — คนแห่เข้าออกพร้อมกัน ──
        $volumes = array_column($normalized, 'volume');
        $avgVolume = Indicators::last(Indicators::sma($volumes, 20));
        $lastVolume = (float) $volumes[count($volumes) - 1];

        if ($avgVolume !== null && $avgVolume > 0 && $lastVolume / $avgVolume >= $thresholds['volume_spike']) {
            // วอลุ่มพุ่งอย่างเดียวไม่ใช่เรื่องร้าย (ขาขึ้นก็พุ่ง) — นับเฉพาะตอนราคาลง
            if ($change1h < 0) {
                $score = max($score, 0.5);
                $reasons[] = sprintf('วอลุ่มพุ่ง %.1f เท่าพร้อมราคาลง', $lastVolume / $avgVolume);
            }
        }

        return [
            'score' => round($score, 3),
            'change_1h' => round($change1h, 2),
            'change_24h' => round($change24h, 2),
            'reasons' => $reasons,
            'available' => true,
        ];
    }

    /**
     * ความเสี่ยงจากข่าวในช่วงเวลาที่กำหนด — ถ่วงน้ำหนักตามความสดของข่าว.
     */
    private function assessNews(string $pair): array
    {
        $minutes = (int) config('aibot_risk.lookback_minutes', 180);
        $base = strtoupper(explode('/', str_replace('-', '/', $pair))[0]);

        // แคชสั้นๆ — บอทหลายตัวถามพร้อมกันไม่ควรยิง query ซ้ำทุกตัว
        $cacheKey = "aibot:newsrisk:{$base}:{$minutes}";

        return Cache::remember($cacheKey, 60, function () use ($base, $minutes) {
            $since = now()->subMinutes($minutes);

            // นับข่าวทั้งหมดในหน้าต่างเวลาด้วย ไม่ใช่เฉพาะข่าวเสี่ยง
            //
            // เหตุผล: ถ้าดูแต่จำนวนข่าวเสี่ยง เลข 0 จะแปลได้สองอย่างที่ต่างกันมาก
            //   - วันนี้ข่าวเงียบ ไม่มีอะไรน่ากลัว  (ปกติ)
            //   - ตัวดึงข่าวพัง ไม่มีข้อมูลเข้ามาเลย (บอทเสียด่านข่าวไปเงียบๆ)
            // แยกสองอย่างนี้ไม่ออก = ระบบข่าวตายแล้วไม่มีใครรู้
            $totalRecent = MarketNews::where('published_at', '>=', $since)->count();
            $lastIngested = MarketNews::max('created_at');

            $news = MarketNews::where('published_at', '>=', $since)
                ->where('panic_score', '>', 0)
                ->orderByDesc('panic_score')
                ->limit(50)
                ->get();

            if ($news->isEmpty()) {
                return [
                    'score' => 0.0,
                    'headlines' => [],
                    'reasons' => [],
                    'count' => 0,
                    'total_recent' => $totalRecent,
                    'last_ingested_at' => $lastIngested,
                ];
            }

            $score = 0.0;
            $headlines = [];

            foreach ($news as $item) {
                $symbols = $item->symbols ?? [];
                $mentionsThisCoin = in_array($base, $symbols, true);
                $mentionsBitcoin = in_array('BTC', $symbols, true);
                $untagged = $symbols === [];

                if (! $mentionsThisCoin && ! $mentionsBitcoin && ! $untagged) {
                    continue; // ข่าวของเหรียญอื่นไม่เกี่ยวกับคู่นี้
                }

                // ข่าวสดกว่า = น้ำหนักมากกว่า (ลดเชิงเส้นจนหมดอายุ)
                $ageMinutes = max(0, $since->diffInMinutes($item->published_at, false) * -1 + $minutes);
                $freshness = max(0.2, 1 - ($ageMinutes / max(1, $minutes)));

                /*
                 * ความเกี่ยวข้องกับคู่นี้ — สามระดับ ไม่ใช่สอง
                 *
                 *   เอ่ยชื่อเหรียญนี้ตรงๆ          → direct (เต็ม)
                 *   ข่าว BTC หรือข่าวระดับตลาด     → market_wide (ลากทุกเหรียญ)
                 *   ไม่แท็กใคร และเป็นเรื่องเฉพาะ  → untagged_local (แค่ระวัง)
                 *
                 * ⚠️ เดิมข่าวไม่แท็กทุกข่าวถูกนับเป็นระดับตลาด — "Cronos halts blockchain
                 *    after exploit" สั่งเทออกบอท BTC ทั้งฝูง (11 ไม้ บน prod)
                 *    ระดับที่สามจึงมีเพดานใต้เกณฑ์ panic เสมอ: ข่าวโปรโตคอลเดียว
                 *    ทำให้เบามือได้ แต่สั่งหนีไม่ได้ (ดู config/aibot_risk.php)
                 */
                $localOnly = $untagged && ! $mentionsBitcoin
                    && $this->news->scopeOf((string) $item->title) === 'local';

                if ($mentionsThisCoin) {
                    $relevance = (float) config('aibot_risk.news_relevance.direct', 1.0);
                    $cap = 1.0;
                } elseif ($localOnly) {
                    $relevance = (float) config('aibot_risk.news_relevance.untagged_local', 0.45);
                    $cap = (float) config('aibot_risk.news_relevance.untagged_local_cap', 0.79);
                } else {
                    $relevance = (float) config('aibot_risk.news_relevance.market_wide', 0.85);
                    $cap = 1.0;
                }

                $weighted = min($cap, (float) $item->panic_score * $freshness * $relevance);

                if ($weighted > $score) {
                    $score = $weighted;
                }

                if (count($headlines) < 5 && $item->panic_score >= 0.5) {
                    $headlines[] = [
                        'title' => $item->title,
                        'source' => $item->source,
                        'url' => $item->url,
                        'panic_score' => (float) $item->panic_score,
                        'published_at' => $item->published_at->toIso8601String(),
                    ];
                }
            }

            $reasons = [];
            if ($score >= 0.5 && $headlines !== []) {
                $reasons[] = 'ข่าวความเสี่ยงสูง: '.$headlines[0]['title'];
            }

            return [
                'score' => round($score, 3),
                'headlines' => $headlines,
                'reasons' => $reasons,
                'count' => $news->count(),
                'total_recent' => $totalRecent,
                'last_ingested_at' => $lastIngested,
            ];
        });
    }

    /** แปลงคะแนนรวมเป็นระดับความเสี่ยง */
    public function levelFor(float $score): string
    {
        $level = 'calm';

        foreach (config('aibot_risk.levels', []) as $name => $config) {
            if ($score >= (float) $config['min_score']) {
                $level = $name;
            }
        }

        return $level;
    }
}
