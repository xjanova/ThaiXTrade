<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — Bridge Transaction Model
 * บันทึก cross-chain bridge TPIX Chain ↔ BSC.
 */
class BridgeTransaction extends Model
{
    protected $fillable = [
        'wallet_address', 'direction', 'amount', 'fee',
        'source_chain_id', 'target_chain_id',
        'source_tx_hash', 'target_tx_hash',
        'status', 'error_message',
    ];

    protected $casts = [
        'amount' => 'decimal:18',
        'fee' => 'decimal:18',
    ];

    // === Scopes ===

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByWallet($query, string $wallet)
    {
        return $query->where('wallet_address', strtolower($wallet));
    }

    public function scopeBscToTpix($query)
    {
        return $query->where('direction', 'bsc_to_tpix');
    }

    public function scopeTpixToBsc($query)
    {
        return $query->where('direction', 'tpix_to_bsc');
    }

    // === Helpers ===

    public function getReceiveAmountAttribute(): string
    {
        return bcsub($this->amount, $this->fee, 18);
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }
}
