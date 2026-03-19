<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TPIX TRADE — Staking Pool Model
 * กำหนด pool: lock period + APY + capacity.
 */
class StakingPool extends Model
{
    protected $fillable = [
        'name', 'lock_days', 'apy_percent', 'min_stake', 'max_stake',
        'total_staked', 'total_rewards_paid', 'max_pool_size', 'is_active',
    ];

    protected $casts = [
        'apy_percent' => 'decimal:2',
        'total_staked' => 'decimal:18',
        'total_rewards_paid' => 'decimal:18',
        'max_pool_size' => 'decimal:18',
        'min_stake' => 'decimal:18',
        'max_stake' => 'decimal:18',
        'is_active' => 'boolean',
    ];

    public function positions(): HasMany
    {
        return $this->hasMany(StakingPosition::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getAvailableCapacityAttribute(): string
    {
        return bcsub($this->max_pool_size, $this->total_staked, 18);
    }

    public function getIsFullAttribute(): bool
    {
        return bccomp($this->total_staked, $this->max_pool_size, 18) >= 0;
    }

    public function getStakersCountAttribute(): int
    {
        return $this->positions()->where('status', 'active')->count();
    }
}
