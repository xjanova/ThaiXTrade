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

    /** ข่าวมีผลแค่ไหน — ตัวเลือก news_mode ของบอท (ดู config/aibot.php common_params) */
    public const NEWS_OFF = 'off';

    public const NEWS_BLOCK_ENTRIES = 'block_entries';

    public const NEWS_CONFIRM_EXIT = 'confirm_exit';

    public const NEWS_IMMEDIATE_EXIT = 'immediate_exit';

    public function __construct(
        private readonly MarketDataService $market,
        private readonly NewsFeedService $news,
    ) {}

    /**
     * ประเมินความเสี่ยงของคู่เทรดหนึ่ง.
     *
     * @param  string|bool  $newsMode  โหมดข่าวของบอท (true/false รุ่นเก่า = confirm_exit/off)
     * @param  float|null  $livePrice  ราคาสดของแท่งที่กำลังวิ่ง — ใช้ยืนยันข่าวร้ายเท่านั้น
     * @return array{
     *     level: string, score: float, size_multiplier: float, force_exit: bool,
     *     market: array, news: array, reasons: list<string>
     * }
     */
    public function assess(
        string $pair,
        ?array $candles = null,
        string $timeframe = '1h',
        string|bool $newsMode = self::NEWS_CONFIRM_EXIT,
        ?float $livePrice = null,
    ): array {
        if (is_bool($newsMode)) {
            $newsMode = $newsMode ? self::NEWS_CONFIRM_EXIT : self::NEWS_OFF;
        }

        $includeNews = $newsMode !== self::NEWS_OFF;
        $marketRisk = $this->assessMarket($pair, $candles, $timeframe);

        /*
         * ราคาสดเทียบราคาปิดของแท่งล่าสุดที่ปิดแล้ว — ใช้ยืนยันข่าวร้ายเท่านั้น (ไม่เข้าคะแนนราคา
         * เพราะด่านราคาตั้งใจดูเฉพาะแท่งที่ปิดแล้ว ดูเหตุผลที่ BotRunner::candles)
         */
        $lastClose = $candles !== null && $candles !== [] ? (float) $candles[count($candles) - 1]['close'] : 0.0;
        $marketRisk['live_change_pct'] = ($livePrice !== null && $lastClose > 0)
            ? round(($livePrice - $lastClose) / $lastClose * 100, 3)
            : null;

        /*
         * ผู้ใช้ปิดด่านข่าวได้จริง (news_mode = off ในฟอร์ม)
         * ปิดแล้วต้องได้คะแนนศูนย์จริงๆ ไม่ใช่เก็บค่าไว้แล้วยังใช้ตัดสินอยู่ดี
         */
        $newsRisk = $includeNews
            ? $this->assessNews($pair)
            : ['score' => 0.0, 'headlines' => [], 'reasons' => [], 'count' => 0, 'total_recent' => 0, 'last_ingested_at' => null];

        $score = max($marketRisk['score'], $newsRisk['score']);
        $level = $this->levelFor($score);
        $config = config("aibot_risk.levels.{$level}");

        $sizeMultiplier = (float) $config['size_multiplier'];
        $forceExit = (bool) $config['force_exit'];
        $reasons = array_merge($marketRisk['reasons'], $newsRisk['reasons']);
        $newsUnconfirmed = false;

        /*
         * ข่าวสั่ง "ห้ามซื้อเพิ่ม" ได้เอง แต่สั่ง "เทออก" ได้เฉพาะเมื่อราคายืนยัน
         *
         * ⚠️ ออดิทกองบอท R3 (2 → 23 ก.ย. 2026, 15 บอท): ด่านข่าวสั่งเทออกทั้งฝูง 3 ครั้ง
         *    จากคำในพาดหัวคำเดียว — ทั้งสามครั้งราคาไม่มีอาการเลย (1 ชม. ก่อนข่าว −3 ถึง −6 bps)
         *      "Celsius sues BitMEX ... over 2020 crash liquidations"  (คดีของปี 2020)
         *      "Whitehats move 52 bitcoin from the Coldcard hack to a recovery trust" (ข่าวกู้คืน)
         *    หลังเทออก BTC ขึ้นต่อ +801 และ +129 bps ใน 72/24 ชม. และบอทที่ถูกเตะออก
         *    กลับเข้าไม่ได้จนกว่ากลยุทธ์จะให้สัญญาณใหม่ — เล่นซ้ำช่วงเดียวกันด้วย backtester
         *    (เข้าไม้ตรงกับของจริงทุกไม้) ได้กำไรรวม +19.83 เทียบกับของจริง +11.64:
         *    ด่านข่าวกินกำไรไป ~41% และ DCA เสียมากสุด (+4.45 แทน +10.20)
         *
         * ตลาดพังจริงราคาตอบรับภายในไม่กี่นาทีเสมอ (หลักเดิมของไฟล์นี้: "ตลาดพังก่อนข่าวออก")
         * ข่าวแรงที่ราคายังนิ่งจึงควรทำให้ "หยุดซื้อ" (ถูก ไม่เสียอะไร) ไม่ใช่ "ขายทิ้ง"
         * (จ่ายต้นทุนไป-กลับ 36 bps + ตกรถ)
         *
         * ผู้ใช้เลือกเองได้รายบอท (news_mode):
         *   confirm_exit   (ปริยาย) ขายเมื่อราคายืนยัน
         *   block_entries  ข่าวไม่มีสิทธิ์สั่งขายเลย — แค่งดเปิดไม้ใหม่
         *   immediate_exit ขายทันทีเมื่อข่าวถึงขั้น panic (พฤติกรรมเดิม)
         *
         * ⚠️ "ข่าวเป็นตัวสั่งขาย" = ราคาอย่างเดียวยังไม่ถึงขั้นสั่งขาย — ไม่ใช่ "คะแนนข่าวสูงกว่า"
         *    รีวิว 2026-09-23: นิยามเดิม (ข่าว > ราคา) ทำให้ตลาดร่วง 10% ในชั่วโมงเดียว + ข่าวร้าย
         *    ในโหมด block_entries ไม่ขายหนีเลย = ปลอดภัยน้อยกว่าปิดข่าวทิ้ง (off ยังขาย)
         *    ทุกโหมดคุมได้แค่ส่วนที่ข่าวเพิ่มเข้ามา ห้ามลบการขายหนีที่ราคาสั่งเอง
         */
        $marketForcesExit = (bool) config('aibot_risk.levels.'.$this->levelFor($marketRisk['score']).'.force_exit', false);
        $newsDriven = $forceExit && ! $marketForcesExit;

        if ($newsDriven && $newsMode !== self::NEWS_IMMEDIATE_EXIT
            && ($newsMode === self::NEWS_BLOCK_ENTRIES || ! $this->priceConfirmsNews($marketRisk))) {
            $newsUnconfirmed = true;
            $forceExit = false;
            $sizeMultiplier = 0.0;
            $level = 'elevated';
            // ต่ำกว่าเกณฑ์ panic เสมอ — ระดับกับคะแนนต้องไม่ขัดกันเวลาหน้าเว็บวาดแถบ
            $score = min($score, (float) config('aibot_risk.levels.panic.min_score', 0.8) - 0.01);
            $reasons[] = $newsMode === self::NEWS_BLOCK_ENTRIES
                ? 'ข่าวแรง — งดเปิดไม้ใหม่ (ตั้งค่าไว้ไม่ให้ข่าวสั่งขาย)'
                : 'ข่าวแรงแต่ราคายังไม่ตอบรับ — งดเข้าไม้ใหม่ แต่ไม่เทของที่ถืออยู่';
        }

        return [
            'level' => $level,
            'score' => round($score, 3),
            'size_multiplier' => $sizeMultiplier,
            'force_exit' => $forceExit,
            // ข่าวถึงขั้นเทออกได้ แต่ราคายังไม่ยืนยัน (หรือตั้งไม่ให้ข่าวสั่งขาย) — หน้าเว็บ/บันทึกแยกกรณีนี้ได้
            'news_unconfirmed' => $newsUnconfirmed,
            'news_mode' => $newsMode,
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
            'reasons' => $reasons,
        ];
    }

    /**
     * ราคาตอบรับข่าวร้ายแล้วหรือยัง — เงื่อนไขที่ข่าวต้องมีก่อนจะสั่งเทออกได้.
     *
     * ยืนยันได้สามทาง (อย่างใดอย่างหนึ่ง):
     *   1. ด่านราคาเองเห็นความเสี่ยงถึงระดับ caution แล้ว (ย่อ 1 ชม./24 ชม.,
     *      ความผันผวนพุ่ง, วอลุ่มพุ่งพร้อมราคาลง)
     *   2. แท่งล่าสุดที่ปิดแล้วร่วงเกิน confirm_change_1h_pct
     *   3. ⭐ ราคาสดร่วงจากราคาปิดล่าสุดเกิน confirm_change_1h_pct — ทางหลักของข่าวที่เพิ่งออก
     *      (รีวิว 2026-09-23: ข่าวแรงอยู่ระดับ panic แค่ ~10–36 นาทีก่อนความสดลด
     *       บอท 4h/1d ไม่มีแท่งปิดใหม่ในช่วงนั้น ถ้ายืนยันด้วยแท่งปิดอย่างเดียว ข่าวแทบ
     *       ไม่มีทางสั่งปิดไม้ได้เลยแม้ราคาร่วง 5% กลางแท่ง)
     *
     * ไม่มีข้อมูลราคา = ยืนยันไม่ได้ (บอทที่ไม่มีแท่งเทียนก็ไม่ได้เทรดอยู่แล้ว)
     */
    private function priceConfirmsNews(array $marketRisk): bool
    {
        if (! ($marketRisk['available'] ?? false)) {
            return false;
        }

        $minScore = (float) config('aibot_risk.news_exit.confirm_market_score', 0.35);
        $maxDrop = (float) config('aibot_risk.news_exit.confirm_change_1h_pct', -1.5);
        $live = $marketRisk['live_change_pct'] ?? null;

        return (float) $marketRisk['score'] >= $minScore
            || (float) ($marketRisk['change_1h'] ?? 0.0) <= $maxDrop
            || ($live !== null && (float) $live <= $maxDrop);
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
