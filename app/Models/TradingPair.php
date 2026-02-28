<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TradingPair Model.
 *
 * Represents a trading pair (e.g., ETH/USDT) on a specific chain.
 * Supports fee overrides per pair that take precedence over global FeeConfig.
 *
 * @property int $id
 * @property int $base_token_id
 * @property int $quote_token_id
 * @property int $chain_id
 * @property string $symbol
 * @property bool $is_active
 * @property float $min_trade_amount
 * @property float $max_trade_amount
 * @property int $price_precision
 * @property int $amount_precision
 * @property float|null $maker_fee_override
 * @property float|null $taker_fee_override
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Token $baseToken
 * @property-read Token $quoteToken
 * @property-read Chain $chain
 */
class TradingPair extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'trading_pairs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'base_token_id',
        'quote_token_id',
        'chain_id',
        'symbol',
        'is_active',
        'min_trade_amount',
        'max_trade_amount',
        'price_precision',
        'amount_precision',
        'maker_fee_override',
        'taker_fee_override',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'min_trade_amount' => 'decimal:8',
            'max_trade_amount' => 'decimal:8',
            'maker_fee_override' => 'decimal:4',
            'taker_fee_override' => 'decimal:4',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the base token for this trading pair.
     */
    public function baseToken(): BelongsTo
    {
        return $this->belongsTo(Token::class, 'base_token_id');
    }

    /**
     * Get the quote token for this trading pair.
     */
    public function quoteToken(): BelongsTo
    {
        return $this->belongsTo(Token::class, 'quote_token_id');
    }

    /**
     * Get the chain this trading pair operates on.
     */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active trading pairs.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // =========================================================================
    // Methods
    // =========================================================================

    /**
     * Get the effective maker and taker fees for this trading pair.
     *
     * Uses pair-specific overrides if set, otherwise falls back to the
     * default FeeConfig for the 'trade' type on this chain.
     *
     * @return array{maker_fee: string, taker_fee: string}
     */
    public function getEffectiveFees(): array
    {
        $makerFee = $this->maker_fee_override;
        $takerFee = $this->taker_fee_override;

        if (is_null($makerFee) || is_null($takerFee)) {
            $defaultConfig = FeeConfig::active()
                ->byType('trade')
                ->where('chain_id', $this->chain_id)
                ->first();

            if ($defaultConfig) {
                $makerFee = $makerFee ?? $defaultConfig->maker_fee;
                $takerFee = $takerFee ?? $defaultConfig->taker_fee;
            }
        }

        return [
            'maker_fee' => $makerFee ?? '0.0000',
            'taker_fee' => $takerFee ?? '0.0000',
        ];
    }
}
