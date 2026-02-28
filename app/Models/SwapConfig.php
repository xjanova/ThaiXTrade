<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SwapConfig Model.
 *
 * Stores DEX swap router configurations per chain.
 * Supports multiple protocols (e.g., Uniswap V2, V3, PancakeSwap).
 *
 * @property int $id
 * @property int $chain_id
 * @property string $router_address
 * @property string|null $factory_address
 * @property string $protocol
 * @property string $name
 * @property float $slippage_tolerance
 * @property bool $is_active
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Chain $chain
 */
class SwapConfig extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'swap_configs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'chain_id',
        'router_address',
        'factory_address',
        'protocol',
        'name',
        'slippage_tolerance',
        'is_active',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slippage_tolerance' => 'decimal:2',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the chain this swap configuration belongs to.
     */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active swap configurations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
