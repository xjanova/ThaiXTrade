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
     * @return array{fetched: int, stored: int, failed: list<string>}
     */
    public function sync(): array
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

        $this->prune();

        return ['fetched' => $fetched, 'stored' => $stored, 'failed' => $failed];
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

    /** @return bool true = เป็นข่าวใหม่ที่เพิ่งบันทึก */
    private function store(array $item, array $feed): bool
    {
        $hash = hash('sha256', $item['url']);

        if (MarketNews::where('url_hash', $hash)->exists()) {
            return false;
        }

        $scored = $this->score($item['title']);

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
        ];
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

        $names = [
            'BTC' => ['bitcoin', 'btc'],
            'ETH' => ['ethereum', 'ether', 'eth'],
            'BNB' => ['binance coin', 'bnb'],
            'SOL' => ['solana', 'sol'],
            'XRP' => ['ripple', 'xrp'],
            'ADA' => ['cardano', 'ada'],
            'DOGE' => ['dogecoin', 'doge'],
            'AVAX' => ['avalanche', 'avax'],
            'DOT' => ['polkadot'],
            'LINK' => ['chainlink'],
            'SHIB' => ['shiba inu', 'shib'],
            'PEPE' => ['pepe'],
            'USDT' => ['tether', 'usdt'],
        ];

        foreach ($names as $symbol => $aliases) {
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
