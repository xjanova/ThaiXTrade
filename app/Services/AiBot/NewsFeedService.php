<?php

namespace App\Services\AiBot;

use App\Models\MarketNews;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ดึงข่าวตลาดจริงจาก RSS แล้วให้คะแนนความตื่นตระหนก.
 *
 * ทำไมใช้ RSS ไม่ใช่ API ที่ต้องมีคีย์: ต้องทำงานได้ทันทีบนเซิร์ฟเวอร์โปรดักชัน
 * โดยไม่ต้องรอใครไปสมัครคีย์ และไม่มีโควตาให้หมดกลางทางตอนตลาดกำลังพัง
 * (ช่วงที่ข่าวสำคัญที่สุดคือช่วงที่ทุกคนยิง API พร้อมกัน)
 *
 * การให้คะแนนเป็นแบบคำสำคัญ + น้ำหนัก — ตรวจย้อนหลังได้ว่าทำไมบอทถึงตัดสินใจแบบนั้น
 *
 * Developed by Xman Studio.
 */
class NewsFeedService
{
    /**
     * ดึงทุกฟีดแล้วบันทึกข่าวใหม่.
     *
     * @param  list<string>  $priorityCoins  เหรียญที่ต้องดึงข่าวทุกรอบไม่ว่าจะถึงคิวหรือไม่
     *                                       (เหรียญที่บอทถือของอยู่ + ที่ AI คัดไว้)
     * @return array{fetched: int, stored: int, failed: list<string>, coins: list<string>}
     */
    public function sync(array $priorityCoins = []): array
    {
        $fetched = 0;
        $stored = 0;
        $failed = [];

        foreach (config('aibot_risk.feeds', []) as $feed) {
            try {
                $items = $this->fetchFeed($feed['url']);
            } catch (\Throwable $e) {
                // ฟีดเดียวล่มต้องไม่ทำให้รอบนี้ล้มทั้งหมด — ข่าวจากแหล่งอื่นยังมีค่า
                $failed[] = $feed['source'];
                Log::warning('AI bot news feed failed', ['source' => $feed['source'], 'error' => $e->getMessage()]);

                continue;
            }

            $fetched += count($items);

            foreach ($items as $item) {
                if ($this->store($item, $feed)) {
                    $stored++;
                }
            }
        }

        $coins = $this->coinsThisRound($priorityCoins);

        foreach ($coins as $symbol) {
            $result = $this->syncCoin($symbol);

            $fetched += $result['fetched'];
            $stored += $result['stored'];

            if ($result['failed']) {
                $failed[] = "coin:{$symbol}";
            }
        }

        $this->prune();

        return ['fetched' => $fetched, 'stored' => $stored, 'failed' => $failed, 'coins' => $coins];
    }

    /**
     * ดึงข่าวของเหรียญเดียวจากฟีดรายเหรียญ.
     *
     * ข่าวจากฟีดนี้ถูก **แท็กเป็นเหรียญนั้นตรงๆ** ไม่ต้องเดาจากพาดหัว —
     * เพราะเรารู้อยู่แล้วว่ายิงคำค้นอะไรไป แม่นกว่าการอ่านพาดหัวมาก
     * และเป็นทางเดียวที่เหรียญอย่าง OP / NEAR / ETC จะมีข่าวได้เลย
     * (ตัวย่อเป็นคำอังกฤษปกติ จับจากพาดหัวไม่ได้ — ดู config/aibot_coins.php)
     *
     * @return array{fetched: int, stored: int, failed: bool}
     */
    public function syncCoin(string $symbol): array
    {
        $coin = config("aibot_coins.coins.{$symbol}");
        $query = $coin['query'] ?? null;

        if (! $query) {
            // เหรียญที่ไม่มีคำค้น (เช่น TPIX ที่ไม่มีสำนักข่าวไหนเขียนถึง)
            return ['fetched' => 0, 'stored' => 0, 'failed' => false];
        }

        $url = sprintf((string) config('aibot_coins.coin_feed_url'), urlencode($query));

        try {
            $items = $this->fetchFeed($url);
        } catch (\Throwable $e) {
            Log::warning('AI bot coin feed failed', ['coin' => $symbol, 'error' => $e->getMessage()]);

            return ['fetched' => 0, 'stored' => 0, 'failed' => true];
        }

        $items = array_slice($items, 0, (int) config('aibot_coins.items_per_coin', 8));

        $feed = [
            'source' => 'gnews:'.mb_strtolower($symbol),
            'weight' => (float) config('aibot_coins.coin_feed_weight', 0.75),
        ];

        $stored = 0;

        foreach ($items as $item) {
            if ($this->store($item, $feed, [$symbol])) {
                $stored++;
            }
        }

        return ['fetched' => count($items), 'stored' => $stored, 'failed' => false];
    }

    /**
     * เหรียญที่ถึงคิวดึงข่าวรอบนี้.
     *
     * แบ่งเป็นชุดตาม "นาฬิกา" ไม่ใช่ตัวนับที่เก็บไว้ที่ไหนสักแห่ง — ตัวนับใน cache
     * จะถูกล้างทุกครั้งที่ deploy (`config:cache` + `cache:clear` อยู่ในสคริปต์ deploy)
     * แล้วการหมุนจะรีเซ็ตกลับไปชุดแรกเสมอ เหรียญท้ายรายการจะไม่มีวันถูกดึงเลย
     *
     * คิดจากเวลาแทน → ไม่มีสถานะให้หาย หมุนครบทุกชุดแน่นอน และย้อนตรวจได้ว่า
     * เวลาไหนควรได้เหรียญชุดใด
     *
     * @param  list<string>  $priorityCoins
     * @return list<string>
     */
    public function coinsThisRound(array $priorityCoins = []): array
    {
        $known = array_keys((array) config('aibot_coins.coins', []));

        // เหรียญสำคัญมาก่อนเสมอ และไม่กินโควตาการหมุนของรอบนี้
        $priority = array_values(array_intersect($known, array_map('strtoupper', $priorityCoins)));
        $rest = array_values(array_diff($known, $priority));

        $size = max(1, (int) config('aibot_coins.rotation_size', 12));
        $slices = (int) max(1, ceil(count($rest) / $size));

        // ชุดที่เท่าไหร่ — คิดจากจำนวนช่วง 15 นาทีนับจาก epoch
        $slice = intdiv((int) floor(now()->getTimestamp() / 900), 1) % $slices;

        return array_values(array_merge($priority, array_slice($rest, $slice * $size, $size)));
    }

    /**
     * อ่าน RSS หนึ่งฟีด → รายการข่าวดิบ.
     *
     * @return list<array{title: string, url: string, published_at: Carbon}>
     */
    private function fetchFeed(string $url): array
    {
        $response = Http::timeout(15)
            ->withHeaders(['User-Agent' => 'TPIX-TRADE/1.0 (+https://tpix.online)'])
            ->get($url);

        if ($response->failed()) {
            throw new \RuntimeException("HTTP {$response->status()}");
        }

        // ปิด error ของ libxml ไม่ให้ warning ของ feed ที่ไม่สะอาดล้น log
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new \RuntimeException('อ่าน XML ไม่ได้');
        }

        $items = [];

        foreach ($xml->channel->item ?? [] as $node) {
            $title = trim((string) $node->title);
            $link = trim((string) $node->link);

            if ($title === '' || $link === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'url' => $link,
                'published_at' => $this->parseDate((string) $node->pubDate),
            ];
        }

        return $items;
    }

    /**
     * แปลง pubDate ของ RSS เป็นเวลาโซนเดียวกับแอพ.
     *
     * ⚠️ ต้อง setTimezone เสมอ ห้ามเก็บดิบ
     *    ฟีดข่าวส่ง pubDate มาเป็น GMT/UTC ("Sun, 17 Aug 2026 09:13:02 +0000")
     *    ถ้าเก็บตามนั้นแล้วไปเทียบกับ now() ที่เป็น Asia/Bangkok ข่าวจะดู "เก่ากว่าจริง 7 ชั่วโมง"
     *    ด้วยหน้าต่าง 180 นาที = ไม่มีข่าวไหนผ่านเกณฑ์ได้เลย ด่านข่าวตาบอดสนิท
     *
     *    เจอจริงบนโปรดักชัน 2026-08-17: ดึงข่าวสำเร็จ 10 ข่าว แต่ total_recent = 0 ตลอด
     */
    private function parseDate(string $raw): Carbon
    {
        try {
            return $raw !== ''
                ? Carbon::parse($raw)->setTimezone(config('app.timezone'))
                : now();
        } catch (\Throwable) {
            return now();
        }
    }

    /**
     * @param  list<string>  $forceSymbols  แท็กเหรียญที่รู้จากต้นทางแล้ว (ฟีดรายเหรียญ)
     *                                      รวมกับที่จับได้จากพาดหัว ไม่ใช่แทนที่ —
     *                                      ข่าว "Solana ETF ดัน Bitcoin" ควรติดทั้งสองเหรียญ
     * @return bool true = เป็นข่าวใหม่ที่เพิ่งบันทึก
     */
    private function store(array $item, array $feed, array $forceSymbols = []): bool
    {
        $hash = hash('sha256', $item['url']);

        if (MarketNews::where('url_hash', $hash)->exists()) {
            return false;
        }

        $scored = $this->score($item['title']);

        if ($forceSymbols !== []) {
            $scored['symbols'] = array_values(array_unique(array_merge($scored['symbols'], $forceSymbols)));
        }

        MarketNews::create([
            'source' => $feed['source'],
            'title' => mb_substr($item['title'], 0, 500),
            'url_hash' => $hash,
            'url' => $item['url'],
            'published_at' => $item['published_at'],
            // ข่าวจากแหล่งที่เชื่อถือน้อยกว่าถูกลดน้ำหนักลง
            'panic_score' => round($scored['panic'] * ($feed['weight'] ?? 1.0), 3),
            'sentiment' => $scored['sentiment'],
            'symbols' => $scored['symbols'],
            'matched_terms' => $scored['terms'],
        ]);

        return true;
    }

    /**
     * ให้คะแนนหัวข้อข่าว.
     *
     * @return array{panic: float, sentiment: float, symbols: list<string>, terms: list<string>}
     */
    public function score(string $title): array
    {
        $haystack = mb_strtolower($title);

        $panic = 0.0;
        $terms = [];
        foreach (config('aibot_risk.panic_terms', []) as $term => $weight) {
            if ($this->mentions($haystack, $term)) {
                // ใช้คำที่แรงที่สุดเป็นตัวตั้ง แล้วบวกส่วนเพิ่มจากคำอื่นแบบลดหลั่น
                $panic = max($panic, (float) $weight);
                $terms[] = $term;
            }
        }

        // หลายคำร้ายพร้อมกัน = ข่าวหนักกว่าข่าวที่มีคำเดียว (แต่ไม่เกิน 1)
        if (count($terms) > 1) {
            $panic = min(1.0, $panic + (count($terms) - 1) * 0.08);
        }

        $positive = 0.0;
        foreach (config('aibot_risk.positive_terms', []) as $term => $weight) {
            if ($this->mentions($haystack, $term)) {
                $positive = max($positive, (float) $weight);
            }
        }

        return [
            'panic' => round($panic, 3),
            'sentiment' => round($positive - $panic, 3),
            'symbols' => $this->detectSymbols($title),
            'terms' => $terms,
            'scope' => $this->scopeOf($title),
        ];
    }

    /**
     * ข่าวนี้เป็นเรื่อง "ระดับตลาด" หรือ "โปรโตคอลเดียว".
     *
     * ใช้ตัดสินข่าวที่ไม่ได้แท็กเหรียญไหนเลย — ด่านความเสี่ยงต้องรู้ว่าจะให้
     * ข่าวนั้นลากทุกเหรียญ (exchange ล่ม) หรือแค่ทำให้ระวัง (แอปเดียวโดนแฮก)
     * ดูเหตุผลและตัวอย่างจริงใน config/aibot_risk.php → market_scope_terms
     *
     * @return 'market'|'local'
     */
    public function scopeOf(string $title): string
    {
        $haystack = mb_strtolower($title);

        foreach ((array) config('aibot_risk.market_scope_terms', []) as $term) {
            if ($this->mentions($haystack, (string) $term)) {
                return 'market';
            }
        }

        return 'local';
    }

    /**
     * เทียบคำสำคัญแบบ "ทั้งคำ" เท่านั้น.
     *
     * ⚠️ ห้ามใช้ str_contains ตรงๆ กับคำสั้น — วัดจากฟีดจริงแล้วพบว่า `ban`
     *    ไปแมตช์กับ bankers / banks / bank ทำให้ข่าวธนาคารที่เป็นกลางหรือเป็นข่าวดี
     *    ได้คะแนนตื่นตระหนก 0.70 → บอทจะเทของทิ้งเพราะข่าวดี
     *
     * วลีหลายคำ (เช่น "rug pull") ใช้ขอบคำเหมือนกันได้ เพราะขอบอยู่หัวท้ายของวลี
     */
    private function mentions(string $haystack, string $term): bool
    {
        return preg_match('/\b'.preg_quote($term, '/').'\b/u', $haystack) === 1;
    }

    /**
     * หาเหรียญที่ข่าวพาดพิง — เทียบทั้งชื่อเต็มและตัวย่อ.
     *
     * @return list<string>
     */
    private function detectSymbols(string $title): array
    {
        $haystack = mb_strtolower($title);
        $found = [];

        /*
         * พจนานุกรมย้ายไป config/aibot_coins.php แล้ว
         *
         * เดิมฝังไว้ในเมธอดนี้ 13 เหรียญ ขณะที่ระบบเปิดเทรดจริง 70 คู่ — ข่าว 52%
         * ไม่ถูกแท็กเลยสักเหรียญ (วัดบน prod 28 ส.ค.: 247 จาก 478 แถว) และ 8 เหรียญ
         * ที่เปิดเทรดอยู่มีข่าว 0 ข่าวตลอด 14 วัน ด่านข่าวจึงตาบอดสำหรับเหรียญพวกนั้น
         *
         * ที่ย้ายออกมาไม่ใช่แค่เรื่องความยาว — คู่เทรดเพิ่มได้จากหลังบ้าน แต่พจนานุกรม
         * แก้ได้เฉพาะตอน deploy การเอามาไว้ที่ config ทำให้เห็นช่องว่างและเติมได้ทันที
         */
        foreach ((array) config('aibot_coins.coins', []) as $symbol => $coin) {
            $aliases = $coin['aliases'] ?? [];

            foreach ($aliases as $alias) {
                // ขอบคำ — กัน "sol" ไปแมตช์กับ "solution" หรือ "sold"
                if (preg_match('/\b'.preg_quote($alias, '/').'\b/u', $haystack)) {
                    $found[] = $symbol;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** ลบข่าวเก่าทิ้งตามนโยบายเก็บข้อมูล */
    private function prune(): void
    {
        $days = (int) config('aibot_risk.retention_days', 14);

        MarketNews::where('published_at', '<', now()->subDays($days))->delete();
    }
}
