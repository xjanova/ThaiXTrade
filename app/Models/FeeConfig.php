<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FeeConfig Model.
 *
 * Defines fee structures for different transaction types on the platform.
 * Supports per-chain configuration with maker/taker fee model.
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property float $maker_fee
 * @property float $taker_fee
 * @property float $min_amount
 * @property float $max_amount
 * @property int|null $chain_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Chain|null $chain
 */
class FeeConfig extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fee_configs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'type',
        'maker_fee',
        'taker_fee',
        'min_amount',
        'max_amount',
        'chain_id',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'maker_fee' => 'decimal:4',
            'taker_fee' => 'decimal:4',
            'min_amount' => 'decimal:8',
            'max_amount' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the chain associated with this fee configuration.
     */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active fee configs.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by fee type (e.g., 'swap', 'trade', 'withdrawal').
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
