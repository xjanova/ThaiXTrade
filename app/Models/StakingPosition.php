<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — Staking Position Model
 * แต่ละ stake ของผู้ใช้ — คำนวณ reward ตาม APY + elapsed time.
 */
class StakingPosition extends Model
{
    protected $fillable = [
        'staking_pool_id', 'wallet_address', 'amount', 'reward_earned',
        'staked_at', 'unlock_at', 'last_reward_at', 'status',
        'tx_hash', 'withdraw_tx_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:18',
        'reward_earned' => 'decimal:18',
        'staked_at' => 'datetime',
        'unlock_at' => 'datetime',
        'last_reward_at' => 'datetime',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(StakingPool::class, 'staking_pool_id');
    }

    // === Scopes ===

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByWallet($query, string $wallet)
    {
        return $query->where('wallet_address', strtolower($wallet));
    }

    // === Computed ===

    /**
     * คำนวณ pending reward ตาม APY + วันที่ผ่านมา.
     * formula: amount * (apy / 100) / 365 * elapsed_days.
     */
    public function getPendingRewardAttribute(): string
    {
        if ($this->status !== 'active' || ! $this->pool) {
            return '0';
        }

        $from = $this->last_reward_at ?? $this->staked_at;
        $daysElapsed = Carbon::parse($from)->diffInSeconds(now()) / 86400;

        if ($daysElapsed <= 0) {
            return '0';
        }

        $dailyRate = bcdiv((string) $this->pool->apy_percent, '36500', 18);
        $reward = bcmul(bcmul($this->amount, $dailyRate, 18), (string) $daysElapsed, 18);

        return $reward;
    }

    public function getIsUnlockedAttribute(): bool
    {
        if (! $this->unlock_at) {
            return true; // Flexible pool
        }

        return now()->gte($this->unlock_at);
    }

    public function getDaysRemainingAttribute(): int
    {
        if (! $this->unlock_at || $this->is_unlocked) {
            return 0;
        }

        return (int) now()->diffInDays($this->unlock_at, false);
    }

    public function getTotalEarnedAttribute(): string
    {
        return bcadd($this->reward_earned, $this->pending_reward, 18);
    }
}
