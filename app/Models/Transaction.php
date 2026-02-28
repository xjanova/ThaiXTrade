<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Transaction Model.
 *
 * Records blockchain transactions including swaps, trades, and transfers.
 * Auto-generates UUID on creation for external reference.
 *
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property string $wallet_address
 * @property int $chain_id
 * @property string|null $from_token
 * @property string|null $to_token
 * @property string $from_amount
 * @property string $to_amount
 * @property string $fee_amount
 * @property string|null $fee_currency
 * @property string|null $tx_hash
 * @property string $status
 * @property int|null $block_number
 * @property string|null $gas_used
 * @property string|null $gas_price
 * @property array|null $metadata
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Chain $chain
 */
class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'transactions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'type',
        'wallet_address',
        'chain_id',
        'from_token',
        'to_token',
        'from_amount',
        'to_amount',
        'fee_amount',
        'fee_currency',
        'tx_hash',
        'status',
        'block_number',
        'gas_used',
        'gas_price',
        'metadata',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_amount' => 'decimal:18',
            'to_amount' => 'decimal:18',
            'fee_amount' => 'decimal:18',
            'metadata' => 'array',
            'status' => 'string',
        ];
    }

    // =========================================================================
    // Boot
    // =========================================================================

    /**
     * The "booted" method of the model.
     * Auto-generates a UUID when creating a new transaction.
     */
    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction) {
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }
        });
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the chain this transaction was executed on.
     */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to filter by transaction status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by transaction type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to filter by wallet address.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByWallet($query, string $walletAddress)
    {
        return $query->where('wallet_address', $walletAddress);
    }

    /**
     * Scope a query to get recent transactions, ordered by newest first.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $limit = 50)
    {
        return $query->orderByDesc('created_at')->limit($limit);
    }
}
