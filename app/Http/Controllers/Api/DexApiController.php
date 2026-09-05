<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kline;
use App\Models\Token;
use App\Models\TradingPair;
use App\Models\Transaction;
use App\Services\ChainResolver;
use App\Services\TpixDexService;
use App\Support\Wei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * DexApiController — ข้อมูลสาธารณะของ TPIX DEX (AMM บนเชน 4289).
 *
 * หน้าเทรด/สวอป/สภาพคล่อง และแอปมือถือ อ่านจากที่นี่:
 *   GET /api/v1/dex/config              ที่อยู่สัญญา + ready (ถ้าไม่ ready หน้าเว็บต้อง fail-closed)
 *   GET /api/v1/dex/pairs               คู่เทรดบนเชน TPIX พร้อมที่อยู่/decimals/พูล
 *   GET /api/v1/dex/ticker/{symbol}     ราคากลาง + สถิติ 24 ชม. จากแท่ง 1 นาที
 *   GET /api/v1/dex/klines/{symbol}     แท่งเทียน (รวมจาก 1 นาทีเป็นช่วงที่ขอ)
 *   GET /api/v1/dex/orderbook/{symbol}  ความลึกสังเคราะห์จาก reserve (AMM ไม่มีออร์เดอร์จริง)
 *   GET /api/v1/dex/trades/{symbol}     สวอปล่าสุดที่ผู้ใช้บันทึกผ่านระบบ
 *
 * การสวอปจริงทำในเบราว์เซอร์ผ่านกระเป๋าของผู้ใช้ — ปลายทางนี้อ่านอย่างเดียว
 *
 * Developed by Xman Studio
 */
class DexApiController extends Controller
{
    private const INTERVAL_SECONDS = [
        '1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600, '4h' => 14400, '1d' => 86400, '1w' => 604800,
    ];

    public function __construct(
        private TpixDexService $dex,
        private ChainResolver $chains,
    ) {}

    public function config(): JsonResponse
    {
        $cfg = Cache::remember('dex:config:public', 60, fn () => $this->dex->config());

        return response()->json(['success' => true, 'data' => $cfg]);
    }

    public function pairs(): JsonResponse
    {
        $data = Cache::remember('dex:pairs:public', 30, function () {
            $chain = $this->tpixChain();
            if (! $chain) {
                return [];
            }

            return TradingPair::query()
                ->where('chain_id', $chain->id)
                ->where('execution_mode', 'onchain')
                ->with(['baseToken', 'quoteToken'])
                ->orderBy('sort_order')
                ->orderBy('symbol')
                ->get()
                ->map(fn (TradingPair $p) => $this->pairPayload($p))
                ->values()
                ->all();
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function ticker(string $symbol): JsonResponse
    {
        $pair = $this->findPair($symbol);
        if (! $pair) {
            return $this->notFound($symbol);
        }

        $data = Cache::remember("dex:ticker:{$pair->id}", 5, fn () => $this->tickerFor($pair));

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function klines(string $symbol, Request $request): JsonResponse
    {
        $pair = $this->findPair($symbol);
        if (! $pair) {
            return $this->notFound($symbol);
        }

        $interval = (string) $request->query('interval', '1h');
        if (! isset(self::INTERVAL_SECONDS[$interval])) {
            $interval = '1h';
        }
        $limit = max(1, min((int) $request->query('limit', 300), 1000));

        $data = Cache::remember(
            "dex:klines:{$pair->id}:{$interval}:{$limit}",
            15,
            fn () => $this->aggregateKlines($pair, $interval, $limit),
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function orderbook(string $symbol, Request $request): JsonResponse
    {
        $pair = $this->findPair($symbol);
        if (! $pair) {
            return $this->notFound($symbol);
        }

        $levels = max(1, min((int) $request->query('limit', 12), 40));
        $data = Cache::remember("dex:depth:{$pair->id}:{$levels}", 5, fn () => $this->depthFor($pair, $levels));

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function trades(string $symbol, Request $request): JsonResponse
    {
        $pair = $this->findPair($symbol);
        if (! $pair) {
            return $this->notFound($symbol);
        }

        $limit = max(1, min((int) $request->query('limit', 50), 200));
        $base = strtolower($pair->baseToken->contract_address);
        $quote = strtolower($pair->quoteToken->contract_address);

        $rows = Transaction::query()
            ->where('type', 'swap')
            ->where('chain_id', $pair->chain_id)
            ->where('status', 'confirmed')
            ->where(function ($q) use ($base, $quote) {
                $q->where(fn ($w) => $w->where('from_token', $quote)->where('to_token', $base))
                    ->orWhere(fn ($w) => $w->where('from_token', $base)->where('to_token', $quote));
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (Transaction $tx) use ($base) {
                $isBuy = strtolower((string) $tx->to_token) === $base;
                $amount = (float) ($isBuy ? $tx->to_amount : $tx->from_amount);
                $total = (float) ($isBuy ? $tx->from_amount : $tx->to_amount);

                return [
                    'id' => $tx->uuid,
                    'price' => $amount > 0 ? round($total / $amount, 12) : 0,
                    'amount' => $amount,
                    'total' => $total,
                    'side' => $isBuy ? 'buy' : 'sell',
                    'tx_hash' => $tx->tx_hash,
                    'time' => $tx->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    // =========================================================================
    // helpers
    // =========================================================================

    private function tpixChain()
    {
        return $this->chains->resolve((int) config('blockchain.tpix_chain_id', 4289));
    }

    private function findPair(string $symbol): ?TradingPair
    {
        $chain = $this->tpixChain();
        if (! $chain) {
            return null;
        }

        $normalized = strtoupper(str_replace(['/', '_'], '-', $symbol));

        return TradingPair::query()
            ->where('chain_id', $chain->id)
            ->where('execution_mode', 'onchain')
            ->where('symbol', $normalized)
            ->with(['baseToken', 'quoteToken'])
            ->first();
    }

    private function notFound(string $symbol): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => 'PAIR_NOT_FOUND', 'message' => "ไม่มีคู่ {$symbol} บน TPIX DEX"],
        ], 404);
    }

    private function pairPayload(TradingPair $p): array
    {
        return [
            'symbol' => $p->symbol,
            'base_asset' => $p->baseToken?->symbol,
            'quote_asset' => $p->quoteToken?->symbol,
            'base_address' => $p->baseToken?->contract_address,
            'quote_address' => $p->quoteToken?->contract_address,
            'base_decimals' => (int) ($p->baseToken?->decimals ?? 18),
            'quote_decimals' => (int) ($p->quoteToken?->decimals ?? 18),
            'base_logo' => $p->baseToken?->logo_url,
            'quote_logo' => $p->quoteToken?->logo_url,
            'pair_address' => $p->dex_pair_address,
            'is_active' => (bool) $p->is_active,
            'chain_id' => (int) config('blockchain.tpix_chain_id', 4289),
        ];
    }

    /**
     * ราคากลางสดจาก reserve + สถิติ 24 ชม. จากแท่ง 1 นาทีที่ dex:sync บันทึกไว้.
     */
    private function tickerFor(TradingPair $pair): array
    {
        $base = $pair->baseToken;
        $quote = $pair->quoteToken;

        $pool = $this->dex->poolFor($base->contract_address, $quote->contract_address);
        $price = $pool
            ? TpixDexService::midPrice($pool['reserveBase'], $pool['reserveQuote'], (int) $base->decimals, (int) $quote->decimals)
            : null;

        $since = now()->subDay();
        $stats = Kline::forPair($pair->id)->forInterval('1m')
            ->where('open_time', '>=', $since)
            ->selectRaw('MAX(high) as high, MIN(low) as low, SUM(volume) as volume, SUM(quote_volume) as quote_volume')
            ->first();
        $first = Kline::forPair($pair->id)->forInterval('1m')
            ->where('open_time', '>=', $since)
            ->orderBy('open_time')
            ->value('open');

        $last = $price !== null ? (float) $price : 0.0;
        $open = (float) ($first ?? 0);
        $change = ($open > 0 && $last > 0) ? (($last - $open) / $open) * 100 : 0.0;

        return [
            'symbol' => $pair->symbol,
            'price' => $last,
            'lastPrice' => $last,
            'change_24h' => round($change, 2),
            'priceChangePercent' => round($change, 2),
            'high_24h' => (float) ($stats->high ?? $last),
            'low_24h' => (float) ($stats->low ?? $last),
            'volume_24h' => (float) ($stats->volume ?? 0),
            'quote_volume_24h' => (float) ($stats->quote_volume ?? 0),
            'reserve_base' => $pool ? Wei::format($pool['reserveBase'], (int) $base->decimals) : '0',
            'reserve_quote' => $pool ? Wei::format($pool['reserveQuote'], (int) $quote->decimals) : '0',
            'pair_address' => $pool['pair'] ?? $pair->dex_pair_address,
            'has_liquidity' => $pool !== null && $price !== null,
            'source' => 'dex',
        ];
    }

    /**
     * รวมแท่ง 1 นาทีเป็นช่วงที่ขอ — รูปแบบเดียวกับ Binance kline array ที่กราฟใช้อยู่.
     */
    private function aggregateKlines(TradingPair $pair, string $interval, int $limit): array
    {
        $seconds = self::INTERVAL_SECONDS[$interval];
        $since = now()->subSeconds($seconds * $limit)->startOfMinute();

        $rows = Kline::forPair($pair->id)->forInterval('1m')
            ->where('open_time', '>=', $since)
            ->orderBy('open_time')
            ->get(['open_time', 'open', 'high', 'low', 'close', 'volume', 'quote_volume', 'trade_count']);

        $buckets = [];
        foreach ($rows as $row) {
            $ts = $row->open_time->getTimestamp();
            $bucket = intdiv($ts, $seconds) * $seconds;

            if (! isset($buckets[$bucket])) {
                $buckets[$bucket] = [
                    'open' => (float) $row->open,
                    'high' => (float) $row->high,
                    'low' => (float) $row->low,
                    'close' => (float) $row->close,
                    'volume' => 0.0,
                    'quote_volume' => 0.0,
                    'count' => 0,
                ];
            }

            $b = &$buckets[$bucket];
            $b['high'] = max($b['high'], (float) $row->high);
            $b['low'] = min($b['low'], (float) $row->low);
            $b['close'] = (float) $row->close;
            $b['volume'] += (float) $row->volume;
            $b['quote_volume'] += (float) $row->quote_volume;
            $b['count'] += (int) $row->trade_count;
            unset($b);
        }

        ksort($buckets);
        $out = [];
        foreach (array_slice($buckets, -$limit, null, true) as $openTs => $b) {
            $out[] = [
                $openTs * 1000,
                number_format($b['open'], 8, '.', ''),
                number_format($b['high'], 8, '.', ''),
                number_format($b['low'], 8, '.', ''),
                number_format($b['close'], 8, '.', ''),
                number_format($b['volume'], 8, '.', ''),
                ($openTs + $seconds) * 1000 - 1,
                number_format($b['quote_volume'], 8, '.', ''),
                $b['count'],
                '0', '0', '0',
            ];
        }

        return $out;
    }

    /**
     * ความลึกสังเคราะห์: ขั้นละ 0.5% ของ reserve — บอกผู้ใช้ว่าซื้อ/ขายก้อนขนาดไหนแล้วราคาเลื่อนเท่าไร.
     */
    private function depthFor(TradingPair $pair, int $levels): array
    {
        $base = $pair->baseToken;
        $quote = $pair->quoteToken;
        $pool = $this->dex->poolFor($base->contract_address, $quote->contract_address);

        if (! $pool || bccomp($pool['reserveBase'], '0', 0) <= 0 || bccomp($pool['reserveQuote'], '0', 0) <= 0) {
            return ['bids' => [], 'asks' => [], 'synthetic' => true];
        }

        $bd = (int) $base->decimals;
        $qd = (int) $quote->decimals;
        $bids = [];
        $asks = [];
        $prevBuyOut = '0';
        $prevBuyIn = '0';
        $prevSellIn = '0';
        $prevSellOut = '0';

        for ($i = 1; $i <= $levels; $i++) {
            $fraction = (string) ($i * 5); // ‰ ของ reserve

            // ฝั่ง ask: ซื้อ base ด้วย quote ก้อน = fraction‰ ของ reserveQuote
            $quoteIn = bcdiv(bcmul($pool['reserveQuote'], $fraction, 0), '1000', 0);
            $baseOut = TpixDexService::amountOut($quoteIn, $pool['reserveQuote'], $pool['reserveBase']);
            $stepIn = bcsub($quoteIn, $prevBuyIn, 0);
            $stepOut = bcsub($baseOut, $prevBuyOut, 0);
            if (bccomp($stepOut, '0', 0) > 0) {
                $asks[] = [
                    (float) TpixDexService::midPrice($stepOut, $stepIn, $bd, $qd),
                    (float) Wei::format($stepOut, $bd),
                ];
            }
            $prevBuyIn = $quoteIn;
            $prevBuyOut = $baseOut;

            // ฝั่ง bid: ขาย base ก้อน = fraction‰ ของ reserveBase
            $baseIn = bcdiv(bcmul($pool['reserveBase'], $fraction, 0), '1000', 0);
            $quoteOut = TpixDexService::amountOut($baseIn, $pool['reserveBase'], $pool['reserveQuote']);
            $sIn = bcsub($baseIn, $prevSellIn, 0);
            $sOut = bcsub($quoteOut, $prevSellOut, 0);
            if (bccomp($sIn, '0', 0) > 0) {
                $bids[] = [
                    (float) TpixDexService::midPrice($sIn, $sOut, $bd, $qd),
                    (float) Wei::format($sIn, $bd),
                ];
            }
            $prevSellIn = $baseIn;
            $prevSellOut = $quoteOut;
        }

        return ['bids' => $bids, 'asks' => $asks, 'synthetic' => true];
    }
}
