<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * TPIX TRADE - Order Model (Internal Order Book).
 *
 * Represents a buy/sell order on the TPIX DEX.
 * Matched internally by OrderMatchingService.
 * Developed by Xman Studio.
 */
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'trading_pair_id',
        'chain_id',
        'wallet_address',
        'side',
        'type',
        'price',
        'amount',
        'filled_amount',
        'remaining_amount',
        'total',
        'trigger_price',
        'fee_rate',
        'fee_amount',
        'status',
        'filled_at',
        'cancelled_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:18',
            'amount' => 'decimal:18',
            'filled_amount' => 'decimal:18',
            'remaining_amount' => 'decimal:18',
            'total' => 'decimal:18',
            'trigger_price' => 'decimal:18',
            'fee_rate' => 'decimal:4',
            'fee_amount' => 'decimal:18',
            'filled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->uuid = $order->uuid ?: (string) Str::uuid();
            $order->remaining_amount = $order->remaining_amount ?? $order->amount;
            $order->filled_amount = $order->filled_amount ?? '0';
            $order->fee_amount = $order->fee_amount ?? '0';
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

    public function makerTrades(): HasMany
    {
        return $this->hasMany(Trade::class, 'maker_order_id');
    }

    public function takerTrades(): HasMany
    {
        return $this->hasMany(Trade::class, 'taker_order_id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'partially_filled']);
    }

    public function scopeBuys(Builder $query): Builder
    {
        return $query->where('side', 'buy');
    }

    public function scopeSells(Builder $query): Builder
    {
        return $query->where('side', 'sell');
    }

    public function scopeForPair(Builder $query, int $pairId): Builder
    {
        return $query->where('trading_pair_id', $pairId);
    }

    // =========================================================================
    // Methods
    // =========================================================================

    public function isFillable(): bool
    {
        return in_array($this->status, ['open', 'partially_filled']);
    }

    /**
     * Apply a partial or full fill to this order.
     * Named applyFill() to avoid conflict with Eloquent Model::fill().
     */
    public function applyFill(float $fillAmount, float $fillFee): void
    {
        // ป้องกันค่าติดลบ (อาจเกิดจาก bug หรือ manipulation)
        if ($fillAmount <= 0) {
            throw new \InvalidArgumentException('Fill amount must be positive.');
        }
        if ($fillFee < 0) {
            throw new \InvalidArgumentException('Fill fee cannot be negative.');
        }

        $this->filled_amount = bcadd((string) $this->filled_amount, (string) $fillAmount, 18);
        $this->remaining_amount = bcsub((string) $this->amount, (string) $this->filled_amount, 18);
        $this->fee_amount = bcadd((string) $this->fee_amount, (string) $fillFee, 18);

        if (bccomp((string) $this->remaining_amount, '0', 18) <= 0) {
            $this->status = 'filled';
            $this->filled_at = now();
            $this->remaining_amount = 0;
        } else {
            $this->status = 'partially_filled';
        }

        $this->save();
    }
}
