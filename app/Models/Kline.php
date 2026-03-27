<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE - Kline (Candlestick) Model.
 *
 * Aggregated OHLCV data from real trades.
 * Developed by Xman Studio.
 */
class Kline extends Model
{
    protected $fillable = [
        'trading_pair_id',
        'interval',
        'open_time',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'quote_volume',
        'trade_count',
    ];

    protected function casts(): array
    {
        return [
            'open_time' => 'datetime',
            'open' => 'decimal:18',
            'high' => 'decimal:18',
            'low' => 'decimal:18',
            'close' => 'decimal:18',
            'volume' => 'decimal:18',
            'quote_volume' => 'decimal:18',
        ];
    }

    public function tradingPair(): BelongsTo
    {
        return $this->belongsTo(TradingPair::class);
    }

    public function scopeForPair(Builder $query, int $pairId): Builder
    {
        return $query->where('trading_pair_id', $pairId);
    }

    public function scopeForInterval(Builder $query, string $interval): Builder
    {
        return $query->where('interval', $interval);
    }

    /**
     * Convert to Binance-compatible kline array format.
     */
    public function toKlineArray(): array
    {
        return [
            $this->open_time->getTimestampMs(),
            number_format((float) $this->open, 8, '.', ''),
            number_format((float) $this->high, 8, '.', ''),
            number_format((float) $this->low, 8, '.', ''),
            number_format((float) $this->close, 8, '.', ''),
            number_format((float) $this->volume, 8, '.', ''),
        ];
    }
}
