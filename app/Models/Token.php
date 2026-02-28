<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Token Model
 *
 * Represents a token/cryptocurrency deployed on a specific blockchain.
 * Stores contract details, metadata, and relationships to trading pairs.
 *
 * @property int $id
 * @property int $chain_id
 * @property string $name
 * @property string $symbol
 * @property string $contract_address
 * @property int $decimals
 * @property string|null $logo
 * @property string|null $coingecko_id
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Chain $chain
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TradingPair> $basePairs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TradingPair> $quotePairs
 */
class Token extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tokens';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'chain_id',
        'name',
        'symbol',
        'contract_address',
        'decimals',
        'logo',
        'coingecko_id',
        'is_active',
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
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the chain this token belongs to.
     */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    /**
     * Get trading pairs where this token is the base token.
     */
    public function basePairs(): HasMany
    {
        return $this->hasMany(TradingPair::class, 'base_token_id');
    }

    /**
     * Get trading pairs where this token is the quote token.
     */
    public function quotePairs(): HasMany
    {
        return $this->hasMany(TradingPair::class, 'quote_token_id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active tokens.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
