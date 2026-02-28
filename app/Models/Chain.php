<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chain Model
 *
 * Represents a blockchain network (e.g., Ethereum, BSC, Polygon).
 * Stores chain-specific configuration including RPC endpoints and explorer URLs.
 *
 * @property int $id
 * @property string $name
 * @property string $symbol
 * @property string|null $chain_id_hex
 * @property string $rpc_url
 * @property string|null $explorer_url
 * @property string|null $logo
 * @property bool $is_testnet
 * @property bool $is_active
 * @property string $native_currency_name
 * @property string $native_currency_symbol
 * @property int $native_currency_decimals
 * @property int $block_confirmations
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Token> $tokens
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TradingPair> $tradingPairs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SwapConfig> $swapConfigs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, FeeConfig> $feeConfigs
 */
class Chain extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chains';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'symbol',
        'chain_id_hex',
        'rpc_url',
        'explorer_url',
        'logo',
        'is_testnet',
        'is_active',
        'native_currency_name',
        'native_currency_symbol',
        'native_currency_decimals',
        'block_confirmations',
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
            'is_testnet' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the tokens on this chain.
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class);
    }

    /**
     * Get the trading pairs on this chain.
     */
    public function tradingPairs(): HasMany
    {
        return $this->hasMany(TradingPair::class);
    }

    /**
     * Get the swap configurations for this chain.
     */
    public function swapConfigs(): HasMany
    {
        return $this->hasMany(SwapConfig::class);
    }

    /**
     * Get the fee configurations for this chain.
     */
    public function feeConfigs(): HasMany
    {
        return $this->hasMany(FeeConfig::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active chains.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include mainnet chains (exclude testnets).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeMainnet($query)
    {
        return $query->where('is_testnet', false);
    }

    /**
     * Scope a query to order chains by their sort order.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $direction
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query, string $direction = 'asc')
    {
        return $query->orderBy('sort_order', $direction);
    }
}
