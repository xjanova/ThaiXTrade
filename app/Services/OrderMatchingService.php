<?php

namespace App\Services;

use App\Models\Kline;
use App\Models\Order;
use App\Models\Trade;
use App\Models\TradingPair;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE - Order Matching Engine.
 *
 * Matches incoming orders against the internal order book.
 * Price-time priority: best price first, then oldest order.
 * Developed by Xman Studio.
 */
class OrderMatchingService
{
    public function __construct(
        private FeeCalculationService $feeService,
    ) {}

    /**
     * Place a new order and attempt to match it.
     *
     * @return array{order: Order, trades: Trade[]}
     */
    public function placeOrder(
        TradingPair $pair,
        string $walletAddress,
        string $side,
        string $type,
        float $amount,
        float $price = 0,
        ?float $triggerPrice = null,
    ): array {
        $chainId = $pair->chain_id;
        $fees = $pair->getEffectiveFees();
        $feeRate = $side === 'buy'
            ? (float) ($fees['taker_fee'] ?? 0.3)
            : (float) ($fees['maker_fee'] ?? 0.1);

        $order = Order::create([
            'trading_pair_id' => $pair->id,
            'chain_id' => $chainId,
            'wallet_address' => strtolower($walletAddress),
            'side' => $side,
            'type' => $type,
            'price' => $price,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'total' => bcmul((string) $amount, (string) $price, 18),
            'trigger_price' => $triggerPrice,
            'fee_rate' => $feeRate,
            'status' => $type === 'stop-limit' ? 'triggered' : 'open',
        ]);

        $trades = [];

        // Market and limit orders: attempt matching immediately
        if ($type !== 'stop-limit') {
            $trades = $this->matchOrder($order);
        }

        return ['order' => $order->fresh(), 'trades' => $trades];
    }

    /**
     * Match an order against the opposite side of the book.
     *
     * @return Trade[]
     */
    public function matchOrder(Order $order): array
    {
        $trades = [];

        // Get opposite side orders sorted by price-time priority
        $oppositeOrders = $this->getMatchableOrders($order);

        foreach ($oppositeOrders as $counterOrder) {
            if (! $order->isFillable()) {
                break;
            }

            // Skip self-trade
            if (strtolower($counterOrder->wallet_address) === strtolower($order->wallet_address)) {
                continue;
            }

            // Check price compatibility
            if (! $this->pricesMatch($order, $counterOrder)) {
                break; // No more matches possible (sorted by price)
            }

            // Calculate fill
            $fillAmount = min(
                (float) $order->remaining_amount,
                (float) $counterOrder->remaining_amount,
            );

            if ($fillAmount <= 0) {
                continue;
            }

            // Execute trade
            $trade = $this->executeTrade($order, $counterOrder, $fillAmount);
            if ($trade) {
                $trades[] = $trade;
            }
        }

        return $trades;
    }

    /**
     * Get the current order book for a trading pair.
     *
     * @return array{bids: array, asks: array}
     */
    public function getOrderBook(int $tradingPairId, int $limit = 25): array
    {
        // Bids (buy orders): highest price first
        $bids = Order::forPair($tradingPairId)
            ->open()
            ->buys()
            ->where('price', '>', 0)
            ->selectRaw('price, SUM(remaining_amount) as total_amount, COUNT(*) as order_count')
            ->groupBy('price')
            ->orderByDesc('price')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'price' => number_format((float) $row->price, 8, '.', ''),
                'amount' => number_format((float) $row->total_amount, 8, '.', ''),
                'count' => $row->order_count,
            ])
            ->toArray();

        // Asks (sell orders): lowest price first
        $asks = Order::forPair($tradingPairId)
            ->open()
            ->sells()
            ->where('price', '>', 0)
            ->selectRaw('price, SUM(remaining_amount) as total_amount, COUNT(*) as order_count')
            ->groupBy('price')
            ->orderBy('price')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'price' => number_format((float) $row->price, 8, '.', ''),
                'amount' => number_format((float) $row->total_amount, 8, '.', ''),
                'count' => $row->order_count,
            ])
            ->toArray();

        return ['bids' => $bids, 'asks' => $asks];
    }

    /**
     * Get recent trades for a trading pair.
     */
    public function getRecentTrades(int $tradingPairId, int $limit = 50): array
    {
        return Trade::where('trading_pair_id', $tradingPairId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Trade $t) => [
                'id' => $t->uuid,
                'price' => number_format((float) $t->price, 8, '.', ''),
                'amount' => number_format((float) $t->amount, 8, '.', ''),
                'total' => number_format((float) $t->total, 8, '.', ''),
                'side' => $t->side,
                'time' => $t->created_at->getTimestampMs(),
            ])
            ->toArray();
    }

    /**
     * Get 24h ticker stats for a trading pair.
     */
    public function getTicker24h(int $tradingPairId): array
    {
        $since = now()->subHours(24);

        $stats = Trade::where('trading_pair_id', $tradingPairId)
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as trade_count,
                SUM(amount) as volume,
                SUM(total) as quote_volume,
                MAX(price) as high,
                MIN(price) as low,
                (SELECT price FROM trades t2 WHERE t2.trading_pair_id = trades.trading_pair_id ORDER BY t2.created_at DESC LIMIT 1) as last_price,
                (SELECT price FROM trades t3 WHERE t3.trading_pair_id = trades.trading_pair_id AND t3.created_at < ? ORDER BY t3.created_at DESC LIMIT 1) as open_price
            ', [$since])
            ->first();

        $lastPrice = (float) ($stats->last_price ?? 0);
        $openPrice = (float) ($stats->open_price ?? $lastPrice);
        $change = $openPrice > 0 ? (($lastPrice - $openPrice) / $openPrice) * 100 : 0;

        return [
            'price' => $lastPrice,
            'open' => $openPrice,
            'high' => (float) ($stats->high ?? $lastPrice),
            'low' => (float) ($stats->low ?? $lastPrice),
            'volume' => (float) ($stats->volume ?? 0),
            'quote_volume' => (float) ($stats->quote_volume ?? 0),
            'change_24h' => round($change, 2),
            'trade_count' => (int) ($stats->trade_count ?? 0),
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    private function getMatchableOrders(Order $order): \Illuminate\Database\Eloquent\Collection
    {
        $query = Order::forPair($order->trading_pair_id)
            ->open()
            ->where('price', '>', 0);

        if ($order->side === 'buy') {
            // Match against sells: lowest price first, then oldest
            $query->sells()->orderBy('price')->orderBy('created_at');
        } else {
            // Match against buys: highest price first, then oldest
            $query->buys()->orderByDesc('price')->orderBy('created_at');
        }

        return $query->limit(100)->get();
    }

    private function pricesMatch(Order $incoming, Order $existing): bool
    {
        // Market orders match any price
        if ($incoming->type === 'market') {
            return true;
        }

        if ($incoming->side === 'buy') {
            // Buy limit: incoming price >= existing sell price
            return bccomp((string) $incoming->price, (string) $existing->price, 18) >= 0;
        }

        // Sell limit: incoming price <= existing buy price
        return bccomp((string) $incoming->price, (string) $existing->price, 18) <= 0;
    }

    private function executeTrade(Order $taker, Order $maker, float $fillAmount): ?Trade
    {
        return DB::transaction(function () use ($taker, $maker, $fillAmount) {
            $executionPrice = (float) $maker->price; // Execute at maker's price
            $total = (float) bcmul((string) $fillAmount, (string) $executionPrice, 18);

            // Calculate fees
            $makerFee = (float) bcmul((string) $total, bcdiv((string) $maker->fee_rate, '100', 12), 18);
            $takerFee = (float) bcmul((string) $total, bcdiv((string) $taker->fee_rate, '100', 12), 18);

            // Create trade record
            $trade = Trade::create([
                'trading_pair_id' => $taker->trading_pair_id,
                'chain_id' => $taker->chain_id,
                'maker_order_id' => $maker->id,
                'taker_order_id' => $taker->id,
                'maker_wallet' => $maker->wallet_address,
                'taker_wallet' => $taker->wallet_address,
                'side' => $taker->side,
                'price' => $executionPrice,
                'amount' => $fillAmount,
                'total' => $total,
                'maker_fee' => $makerFee,
                'taker_fee' => $takerFee,
            ]);

            // Update orders
            $maker->applyFill($fillAmount, $makerFee);
            $taker->applyFill($fillAmount, $takerFee);

            // Update kline
            $this->updateKline($trade);

            Log::info('Trade executed', [
                'trade_id' => $trade->uuid,
                'pair' => $taker->trading_pair_id,
                'price' => $executionPrice,
                'amount' => $fillAmount,
                'maker' => $maker->wallet_address,
                'taker' => $taker->wallet_address,
            ]);

            return $trade;
        });
    }

    /**
     * Update kline candles when a trade occurs.
     */
    private function updateKline(Trade $trade): void
    {
        $intervals = [
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '4h' => 14400,
            '1d' => 86400,
        ];

        $price = (float) $trade->price;
        $volume = (float) $trade->amount;
        $quoteVolume = (float) $trade->total;
        $pairId = $trade->trading_pair_id;

        foreach ($intervals as $interval => $seconds) {
            $openTime = date('Y-m-d H:i:s',
                (int) floor($trade->created_at->timestamp / $seconds) * $seconds
            );

            // Try to find existing kline for this period
            $kline = Kline::where('trading_pair_id', $pairId)
                ->where('interval', $interval)
                ->where('open_time', $openTime)
                ->first();

            if ($kline) {
                // Update existing candle
                $kline->update([
                    'high' => max((float) $kline->high, $price),
                    'low' => min((float) $kline->low, $price),
                    'close' => $price,
                    'volume' => bcadd((string) $kline->volume, (string) $volume, 18),
                    'quote_volume' => bcadd((string) $kline->quote_volume, (string) $quoteVolume, 18),
                    'trade_count' => $kline->trade_count + 1,
                ]);
            } else {
                // Create new candle
                Kline::create([
                    'trading_pair_id' => $pairId,
                    'interval' => $interval,
                    'open_time' => $openTime,
                    'open' => $price,
                    'high' => $price,
                    'low' => $price,
                    'close' => $price,
                    'volume' => $volume,
                    'quote_volume' => $quoteVolume,
                    'trade_count' => 1,
                ]);
            }
        }
    }
}
