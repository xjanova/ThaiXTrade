<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * TPIX TRADE - Trade Model (Executed Trades).
 *
 * Records each matched trade between maker and taker orders.
 * Used for trade history, kline aggregation, and price feeds.
 * Developed by Xman Studio.
 */
class Trade extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'trading_pair_id',
        'chain_id',
        'maker_order_id',
        'taker_order_id',
        'maker_wallet',
        'taker_wallet',
        'side',
        'price',
        'amount',
        'total',
        'maker_fee',
        'taker_fee',
        'tx_hash',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:18',
            'amount' => 'decimal:18',
            'total' => 'decimal:18',
            'maker_fee' => 'decimal:18',
            'taker_fee' => 'decimal:18',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Trade $trade) {
            $trade->uuid = $trade->uuid ?: (string) Str::uuid();
        });
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    public function tradingPair(): BelongsTo
    {
        return $this->belongsTo(TradingPair::class);
    }

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    public function makerOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'maker_order_id');
    }

    public function takerOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'taker_order_id');
    }
}
