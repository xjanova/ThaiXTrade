<?php

namespace App\Services;

use App\Models\TradingPair;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MarketDataService.
 *
 * Fetches real-time market data from Binance public API.
 * Provides tickers, order books, trades, klines, and token prices.
 * All responses are cached briefly to avoid rate limits.
 */
class MarketDataService
{
    private string $baseUrl = 'https://api.binance.com/api/v3';

    /**
     * Get 24h ticker data for all symbols or a specific one.
     */
    public function getTickers(?string $symbol = null): array
    {
        $cacheKey = $symbol ? "market:ticker:{$symbol}" : 'market:tickers:all';
        $ttl = 10; // 10 seconds

        return Cache::remember($cacheKey, $ttl, function () use ($symbol) {
            try {
                $url = "{$this->baseUrl}/ticker/24hr";
                $params = $symbol ? ['symbol' => $this->toBinanceSymbol($symbol)] : [];

                $response = Http::timeout(10)->get($url, $params);

                if ($response->failed()) {
                    return [];
                }

                $data = $response->json();

                // Single symbol returns object, multiple returns array
                if ($symbol) {
                    return [$this->formatTicker($data)];
                }

                // Build allowlist of admin-configured pairs from DB.
                // Mobile + web markets list MUST mirror admin's TradingPair table —
                // not Binance's full list. Falls back to top-50 only when DB is empty
                // (initial deployment / no admin config yet).
                $adminPairs = TradingPair::active()
                    ->pluck('symbol')
                    ->map(fn ($s) => $this->toBinanceSymbol($s))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $collection = collect($data)
                    ->filter(fn ($t) => str_ends_with($t['symbol'], 'USDT'));

                if (! empty($adminPairs)) {
                    $allow = array_flip($adminPairs);
                    $collection = $collection->filter(fn ($t) => isset($allow[$t['symbol']]));
                } else {
                    // Fallback — top 50 by volume (legacy behavior, only when DB empty)
                    $collection = $collection
                        ->sortByDesc(fn ($t) => (float) $t['quoteVolume'])
                        ->take(50);
                }

                return $collection
                    ->values()
                    ->map(fn ($t) => $this->formatTicker($t))
                    ->all();
            } catch (\Exception $e) {
                Log::warning('Market tickers fetch failed', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Get order book depth for a symbol.
     */
    public function getOrderBook(string $symbol, int $limit = 20): array
    {
        $cacheKey = "market:orderbook:{$symbol}:{$limit}";

        return Cache::remember($cacheKey, 5, function () use ($symbol, $limit) {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/depth", [
                    'symbol' => $this->toBinanceSymbol($symbol),
                    'limit' => min($limit, 100),
                ]);

                if ($response->failed()) {
                    return ['bids' => [], 'asks' => []];
                }

                return $response->json();
            } catch (\Exception $e) {
                Log::warning('Order book fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);

                return ['bids' => [], 'asks' => []];
            }
        });
    }

    /**
     * Get recent trades for a symbol.
     */
    public function getRecentTrades(string $symbol, int $limit = 50): array
    {
        $cacheKey = "market:trades:{$symbol}:{$limit}";

        return Cache::remember($cacheKey, 5, function () use ($symbol, $limit) {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/trades", [
                    'symbol' => $this->toBinanceSymbol($symbol),
                    'limit' => min($limit, 500),
                ]);

                if ($response->failed()) {
                    return [];
                }

                return collect($response->json())->map(fn ($t) => [
                    'id' => $t['id'],
                    'price' => $t['price'],
                    'qty' => $t['qty'],
                    'quoteQty' => $t['quoteQty'],
                    'time' => $t['time'],
                    'isBuyerMaker' => $t['isBuyerMaker'],
                ])->all();
            } catch (\Exception $e) {
                Log::warning('Trades fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Get klines (candlestick) data.
     */
    public function getKlines(string $symbol, string $interval = '1h', int $limit = 100): array
    {
        $cacheKey = "market:klines:{$symbol}:{$interval}:{$limit}";

        return Cache::remember($cacheKey, 30, function () use ($symbol, $interval, $limit) {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/klines", [
                    'symbol' => $this->toBinanceSymbol($symbol),
                    'interval' => $interval,
                    'limit' => min($limit, 500),
                ]);

                if ($response->failed()) {
                    return [];
                }

                return collect($response->json())->map(fn ($k) => [
                    'time' => $k[0],
                    'open' => $k[1],
                    'high' => $k[2],
                    'low' => $k[3],
                    'close' => $k[4],
                    'volume' => $k[5],
                    'closeTime' => $k[6],
                    'quoteVolume' => $k[7],
                    'trades' => $k[8],
                ])->all();
            } catch (\Exception $e) {
                Log::warning('Klines fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * funding rate ล่าสุดของสัญญา perpetual (%) — บอกว่าฝั่งไหน "จ่ายเงินเพื่อถือ".
     *
     * บวกมาก = ฝั่ง long แน่นและจ่ายแพง (มักเป็นจุดที่ตลาดร้อนเกิน) · ลบ = ฝั่ง short จ่าย
     * เป็นข้อมูลที่นักวิเคราะห์คนดูก่อนเสมอแต่ AI ของเราไม่เคยได้เห็น
     * มาจาก Binance futures สาธารณะ ไม่ต้องมีคีย์ · ไม่มีสัญญาของเหรียญนี้ = null
     */
    public function getFundingRate(string $symbol): ?float
    {
        $cacheKey = "market:funding:{$symbol}";

        return Cache::remember($cacheKey, 1800, function () use ($symbol) {
            try {
                $response = Http::timeout(8)->get('https://fapi.binance.com/fapi/v1/premiumIndex', [
                    'symbol' => $this->toBinanceSymbol($symbol),
                ]);

                if ($response->failed()) {
                    return null;
                }

                $rate = $response->json('lastFundingRate');

                return is_numeric($rate) ? round((float) $rate * 100, 4) : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * ดัชนี Fear & Greed (0 = กลัวสุดขีด · 100 = โลภสุดขีด) จาก alternative.me — ฟรี ไม่ต้องมีคีย์.
     *
     * @return array{value: int, label: string}|null
     */
    public function getFearGreedIndex(): ?array
    {
        return Cache::remember('market:fear-greed', 3600, function () {
            try {
                $response = Http::timeout(8)->get('https://api.alternative.me/fng/', ['limit' => 1]);

                if ($response->failed()) {
                    return null;
                }

                $row = $response->json('data.0');

                if (! is_array($row) || ! isset($row['value'])) {
                    return null;
                }

                return ['value' => (int) $row['value'], 'label' => (string) ($row['value_classification'] ?? '')];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * แท่งเทียนย้อนหลังในช่วงเวลาที่กำหนด — ไล่ดึงทีละหน้าจนครบ (สำหรับ backtest).
     *
     * getKlines() ให้ได้มากสุด 500 แท่งล่าสุดเท่านั้น ซึ่งพอสำหรับบอทที่เดินสด
     * แต่ backtest 90 วันบน 1h ต้องการ 2,160 แท่ง และต้องเป็นช่วงที่ "เลือกได้"
     * Binance ให้หน้าละ 1,000 แท่งพร้อม startTime — จึงวนหน้าจนเลย endTime
     *
     * ไม่แคช: ผู้เรียก (KlineArchive) เก็บลงไฟล์เองเป็นคลังถาวร ซึ่งเหมาะกว่าแคช
     * ที่หมดอายุ เพราะแท่งที่ปิดแล้วไม่มีวันเปลี่ยน
     *
     * @return list<array{time:int,open:string,high:string,low:string,close:string,volume:string,closeTime:int}>
     *
     * @throws \RuntimeException เมื่อตลาดตอบไม่สำเร็จ — backtest บนข้อมูลไม่ครบต้องล้ม ไม่ใช่เงียบ
     */
    public function getKlinesBetween(string $symbol, string $interval, int $startMs, int $endMs): array
    {
        $stepMs = \App\Services\AiBot\Timeframe::milliseconds($interval);
        $out = [];
        $cursor = $startMs;

        while ($cursor < $endMs) {
            $response = Http::timeout(15)->get("{$this->baseUrl}/klines", [
                'symbol' => $this->toBinanceSymbol($symbol),
                'interval' => $interval,
                'startTime' => $cursor,
                'endTime' => $endMs,
                'limit' => 1000,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException("ดึงแท่งเทียน {$symbol} {$interval} ไม่สำเร็จ: HTTP {$response->status()}");
            }

            $rows = $response->json();

            if (! is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $k) {
                $out[] = [
                    'time' => (int) $k[0],
                    'open' => $k[1],
                    'high' => $k[2],
                    'low' => $k[3],
                    'close' => $k[4],
                    'volume' => $k[5],
                    'closeTime' => (int) $k[6],
                ];
            }

            $last = (int) $rows[count($rows) - 1][0];

            // กันวนไม่รู้จบถ้าปลายทางคืนหน้าเดิม
            if ($last < $cursor || count($rows) < 1000) {
                break;
            }

            $cursor = $last + $stepMs;
        }

        return $out;
    }

    /**
     * Get compact close-price series for many symbols at once (mini sparklines).
     *
     * The web pair lists render one tiny trend line per row. Letting the browser
     * call Binance once per row would mean ~20 cross-origin requests per page
     * view and burn the shared rate limit, so the fan-out happens here and the
     * result is cached long enough that a page refresh costs nothing.
     *
     * @param  array<int, string>  $symbols  canonical symbols, e.g. ['BTC-USDT']
     * @return array<string, array<int, float>> symbol => close prices (oldest first)
     */
    public function getSparklines(array $symbols, string $interval = '1h', int $limit = 24): array
    {
        $interval = in_array($interval, ['15m', '1h', '4h', '1d'], true) ? $interval : '1h';
        $limit = max(8, min($limit, 96));

        $symbols = collect($symbols)
            ->map(fn ($s) => strtoupper(trim((string) $s)))
            ->filter()
            ->unique()
            ->values();

        $out = [];
        $missing = [];

        foreach ($symbols as $symbol) {
            $cached = Cache::get($this->sparklineKey($symbol, $interval, $limit));

            if (is_array($cached)) {
                $out[$symbol] = $cached;
            } else {
                $missing[] = $symbol;
            }
        }

        if ($missing !== []) {
            $out += $this->fetchSparklines($missing, $interval, $limit);
        }

        // คืนตามลำดับที่ขอมา เพื่อให้ผู้เรียกจับคู่กับรายการของตัวเองได้ตรง
        return $symbols->mapWithKeys(fn ($s) => [$s => $out[$s] ?? []])->all();
    }

    private function sparklineKey(string $symbol, string $interval, int $limit): string
    {
        return "market:sparkline:{$symbol}:{$interval}:{$limit}";
    }

    /**
     * ดึงเส้นกราฟของหลายเหรียญ "พร้อมกัน" ไม่ใช่ทีละตัว.
     *
     * ⚠️ เดิมวนยิงทีละเหรียญ วัดได้ 14.6 วินาทีสำหรับ 25 เหรียญตอนแคชเย็น
     *    ปลายทางแต่ละครั้งใช้ ~0.6 วิ ซึ่งเร็วพอ — ที่ช้าคือการต่อคิวกัน 25 รอบ
     *
     *    ผลที่ผู้ใช้เห็นคือ "กราฟย่อมาไม่ครบ" เพราะกว่าจะครบก็ผ่านไปสิบกว่าวินาที
     *    หรือคำขอถูกตัดกลางทางไปก่อน แล้วฝั่งหน้าเว็บจำ "ไม่มีข้อมูล" ไว้อีก 5 นาที
     *    — ไม่มี error ให้เห็นสักบรรทัด เพราะไม่มีอะไรผิดพลาดจริงๆ แค่ช้า
     *
     * @return array<string, array<int, float>>
     */
    private function fetchSparklines(array $symbols, string $interval, int $limit): array
    {
        try {
            $responses = Http::pool(fn (Pool $pool) => collect($symbols)
                ->map(fn (string $symbol) => $pool->as($symbol)
                    ->timeout(8)
                    ->get("{$this->baseUrl}/klines", [
                        'symbol' => $this->toBinanceSymbol($symbol),
                        'interval' => $interval,
                        'limit' => $limit,
                    ]))
                ->all());
        } catch (\Throwable $e) {
            Log::warning('Sparkline pool failed', ['error' => $e->getMessage(), 'count' => count($symbols)]);

            return [];
        }

        $out = [];
        $failed = [];

        foreach ($symbols as $symbol) {
            $response = $responses[$symbol] ?? null;

            // ยิงไม่สำเร็จ ≠ เหรียญนี้ไม่มีข้อมูล — ห้ามเก็บผลว่างลงแคช
            // ไม่งั้นปลายทางล่มแวบเดียวจะทำให้กราฟหายไปทั้งหน้านาน 5 นาที
            if (! $response instanceof Response || $response->failed()) {
                $failed[] = $symbol;
                $out[$symbol] = [];

                continue;
            }

            $series = collect($response->json())
                ->map(fn ($k) => (float) ($k[4] ?? 0))   // close
                ->filter(fn ($p) => $p > 0)
                ->values()
                ->all();

            // เหรียญที่ไม่มีอยู่บน Binance (เช่น TPIX ก่อนเปิดเชน) ได้ชุดว่างจริงๆ
            // เก็บแคชไว้ได้ ไม่ต้องไปถามซ้ำทุกรอบ
            Cache::put($this->sparklineKey($symbol, $interval, $limit), $series, 300);
            $out[$symbol] = $series;
        }

        if ($failed !== []) {
            Log::warning('Sparkline symbols failed', ['symbols' => $failed]);
        }

        return $out;
    }

    /**
     * Get available trading pairs from Binance.
     */
    public function getPairs(): array
    {
        return Cache::remember('market:pairs', 300, function () {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/exchangeInfo");

                if ($response->failed()) {
                    return [];
                }

                return collect($response->json()['symbols'] ?? [])
                    ->filter(fn ($s) => $s['status'] === 'TRADING' && $s['quoteAsset'] === 'USDT')
                    ->sortBy('symbol')
                    ->take(100)
                    ->values()
                    ->map(fn ($s) => [
                        'symbol' => $s['symbol'],
                        'baseAsset' => $s['baseAsset'],
                        'quoteAsset' => $s['quoteAsset'],
                        'status' => $s['status'],
                    ])
                    ->all();
            } catch (\Exception $e) {
                Log::warning('Pairs fetch failed', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Get token price from Binance.
     */
    public function getTokenPrice(string $symbol): ?array
    {
        $cacheKey = "market:price:{$symbol}";

        return Cache::remember($cacheKey, 10, function () use ($symbol) {
            try {
                $binanceSymbol = strtoupper(str_replace(['-', '/'], '', $symbol));
                if (! str_ends_with($binanceSymbol, 'USDT')) {
                    $binanceSymbol .= 'USDT';
                }

                $response = Http::timeout(10)->get("{$this->baseUrl}/ticker/24hr", [
                    'symbol' => $binanceSymbol,
                ]);

                if ($response->failed()) {
                    return null;
                }

                return $this->formatTicker($response->json());
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /**
     * Get top gainers from Binance.
     */
    public function getTopGainers(int $limit = 4): array
    {
        $tickers = $this->getTickers();

        return collect($tickers)
            ->sortByDesc('priceChangePercent')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Get top volume tokens from Binance.
     */
    public function getTopVolume(int $limit = 4): array
    {
        $tickers = $this->getTickers();

        return collect($tickers)
            ->sortByDesc('quoteVolume')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Format a ticker response to standard format.
     */
    private function formatTicker(array $data): array
    {
        $rawSymbol = $data['symbol'] ?? '';
        // Strip trailing USDT to get base — canonicalize to BASE-USDT format
        // so client parsers (mobile + web) can split on "-" to separate base/quote.
        $baseAsset = str_ends_with($rawSymbol, 'USDT')
            ? substr($rawSymbol, 0, -4)
            : $rawSymbol;
        $canonicalSymbol = $baseAsset.'-USDT';

        return [
            'symbol' => $canonicalSymbol,         // "BTC-USDT" (canonical)
            'binance_symbol' => $rawSymbol,       // "BTCUSDT" (kept for WS subscribe)
            'baseAsset' => $baseAsset,
            'quoteAsset' => 'USDT',
            // Both camelCase + snake_case for client compat
            'price' => $data['lastPrice'] ?? '0',
            'lastPrice' => $data['lastPrice'] ?? '0',
            'last_price' => $data['lastPrice'] ?? '0',
            'priceChange' => $data['priceChange'] ?? '0',
            'price_change' => $data['priceChange'] ?? '0',
            'priceChangePercent' => $data['priceChangePercent'] ?? '0',
            'price_change_percent' => $data['priceChangePercent'] ?? '0',
            'high' => $data['highPrice'] ?? '0',
            'high_24h' => $data['highPrice'] ?? '0',
            'low' => $data['lowPrice'] ?? '0',
            'low_24h' => $data['lowPrice'] ?? '0',
            'volume' => $data['volume'] ?? '0',
            'volume_24h' => $data['volume'] ?? '0',
            'quoteVolume' => $data['quoteVolume'] ?? '0',
            'quote_volume_24h' => $data['quoteVolume'] ?? '0',
            'openPrice' => $data['openPrice'] ?? '0',
        ];
    }

    /**
     * คู่นี้มีแท่งเทียนให้ใช้จริงไหม — ตอบได้สามอย่าง ไม่ใช่สอง.
     *
     * getKlines() คืน [] ทั้งตอน "ไม่มีคู่นี้จริงๆ" และตอน "เน็ตล่ม" ซึ่งแยกไม่ออก
     * ใครเอาไปตัดสินใจต่อจึงกันคนใช้ผิดกลุ่มได้ง่ายมาก — เน็ตสะดุดครั้งเดียว
     * กลายเป็น "คู่นี้ใช้ไม่ได้" ถาวรในสายตาผู้ใช้
     *
     * @return bool|null true = มีข้อมูลพอ · false = ตลาดยืนยันว่าไม่มีคู่นี้
     *                   · null = ถามไม่สำเร็จ ตัดสินไม่ได้ (ผู้เรียกควรปล่อยผ่าน)
     */
    public function hasKlines(string $symbol, string $interval = '1h'): ?bool
    {
        try {
            $response = Http::timeout(6)->get("{$this->baseUrl}/klines", [
                'symbol' => $this->toBinanceSymbol($symbol),
                'interval' => $interval,
                'limit' => 40,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ตรวจแท่งเทียนของคู่เทรดไม่สำเร็จ', ['symbol' => $symbol, 'error' => $e->getMessage()]);

            return null;
        }

        // 400 = ตลาดบอกเองว่าไม่รู้จักคู่นี้ (Invalid symbol) → ตอบ false ได้เต็มปาก
        if ($response->clientError()) {
            return false;
        }

        // 5xx / ถูกจำกัดอัตรา = ปัญหาฝั่งเรากับตลาด ไม่ใช่คำตอบเรื่องคู่เทรด
        if ($response->failed()) {
            return null;
        }

        $rows = $response->json();

        return is_array($rows) && count($rows) >= 30;
    }

    /**
     * Convert our symbol format to Binance format.
     * e.g. BTC-USDT or BTC/USDT -> BTCUSDT.
     */
    private function toBinanceSymbol(string $symbol): string
    {
        return strtoupper(str_replace(['-', '/'], '', $symbol));
    }
}
