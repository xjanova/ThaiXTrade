<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\StakingPool;
use App\Models\StakingPosition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * TPIX TRADE — Staking Service
 * จัดการ Stake TPIX — ได้ APY 5%-200% ตาม lock period.
 * Rewards pool: 1.4B TPIX over 3 years (ending 2028).
 */
class StakingService
{
    /**
     * เช็คว่า admin เปิดใช้ staking หรือไม่.
     */
    public function isEnabled(): bool
    {
        $val = SiteSetting::get('trading', 'staking_enabled');

        return $val === null || $val === true || $val === '1' || $val === 'true';
    }

    /**
     * ดึง pools ที่ active (cached 60s).
     */
    public function getActivePools(): Collection
    {
        return Cache::remember('staking:pools', 60, function () {
            return StakingPool::active()->orderBy('lock_days')->get();
        });
    }

    /**
     * Stake TPIX เข้า pool.
     */
    public function stake(string $wallet, int $poolId, string $amount, ?string $txHash = null): StakingPosition
    {
        $pool = StakingPool::active()->findOrFail($poolId);

        // ตรวจ min/max
        if (bccomp($amount, $pool->min_stake, 18) < 0) {
            throw new \InvalidArgumentException("Minimum stake is {$pool->min_stake} TPIX");
        }

        if (bccomp($amount, $pool->max_stake, 18) > 0) {
            throw new \InvalidArgumentException('Maximum stake is '.number_format((float) $pool->max_stake).' TPIX');
        }

        // ตรวจ capacity
        if ($pool->is_full) {
            throw new \InvalidArgumentException('Pool is full');
        }

        return DB::transaction(function () use ($pool, $wallet, $amount, $txHash) {
            $unlockAt = $pool->lock_days > 0
                ? now()->addDays($pool->lock_days)
                : null;

            $position = StakingPosition::create([
                'staking_pool_id' => $pool->id,
                'wallet_address' => strtolower($wallet),
                'amount' => $amount,
                'staked_at' => now(),
                'unlock_at' => $unlockAt,
                'last_reward_at' => now(),
                'status' => 'active',
                'tx_hash' => $txHash,
            ]);

            // อัปเดต pool total
            $pool->increment('total_staked', (float) $amount);

            Cache::forget('staking:pools');
            Cache::forget('staking:stats');

            return $position->load('pool');
        });
    }

    /**
     * Claim rewards — คำนวณ pending reward + บันทึก.
     */
    public function claimRewards(int $positionId, string $wallet): StakingPosition
    {
        $position = StakingPosition::active()
            ->byWallet($wallet)
            ->with('pool')
            ->findOrFail($positionId);

        $pending = $position->pending_reward;

        if (bccomp($pending, '0', 18) <= 0) {
            throw new \InvalidArgumentException('No pending rewards to claim');
        }

        return DB::transaction(function () use ($position, $pending) {
            $position->update([
                'reward_earned' => bcadd($position->reward_earned, $pending, 18),
                'last_reward_at' => now(),
            ]);

            $position->pool->increment('total_rewards_paid', (float) $pending);

            Cache::forget('staking:stats');

            return $position->fresh('pool');
        });
    }

    /**
     * Unstake — ตรวจ unlock แล้วถอน + claim rewards ที่เหลือ.
     */
    public function unstake(int $positionId, string $wallet, ?string $withdrawTxHash = null): StakingPosition
    {
        $position = StakingPosition::active()
            ->byWallet($wallet)
            ->with('pool')
            ->findOrFail($positionId);

        if (! $position->is_unlocked) {
            throw new \InvalidArgumentException("Locked until {$position->unlock_at->format('Y-m-d H:i')} ({$position->days_remaining} days remaining)");
        }

        return DB::transaction(function () use ($position, $withdrawTxHash) {
            // Claim remaining rewards
            $pending = $position->pending_reward;
            $totalReward = bcadd($position->reward_earned, $pending, 18);

            $position->update([
                'reward_earned' => $totalReward,
                'last_reward_at' => now(),
                'status' => 'withdrawn',
                'withdraw_tx_hash' => $withdrawTxHash,
            ]);

            // ลด pool total
            $position->pool->decrement('total_staked', (float) $position->amount);

            if (bccomp($pending, '0', 18) > 0) {
                $position->pool->increment('total_rewards_paid', (float) $pending);
            }

            Cache::forget('staking:pools');
            Cache::forget('staking:stats');

            return $position->fresh('pool');
        });
    }

    /**
     * ดึง positions ของ wallet.
     */
    public function getPositions(string $wallet): Collection
    {
        return StakingPosition::byWallet($wallet)
            ->with('pool')
            ->latest('staked_at')
            ->get();
    }

    /**
     * สถิติ staking ทั้งระบบ.
     */
    public function getStats(): array
    {
        return Cache::remember('staking:stats', 60, function () {
            return [
                'tvl' => (float) StakingPool::active()->sum('total_staked'),
                'total_stakers' => StakingPosition::active()->distinct('wallet_address')->count(),
                'total_rewards_paid' => (float) StakingPool::sum('total_rewards_paid'),
                'pools_count' => StakingPool::active()->count(),
                'active_positions' => StakingPosition::active()->count(),
            ];
        });
    }
}
