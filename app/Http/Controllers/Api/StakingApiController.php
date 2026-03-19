<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StakingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TPIX TRADE — Staking API Controller
 * API สำหรับ stake TPIX บน TPIX Chain — APY 5%-200%.
 */
class StakingApiController extends Controller
{
    public function __construct(
        private StakingService $stakingService,
    ) {}

    /**
     * GET /api/v1/staking/pools — pools ทั้งหมด.
     */
    public function pools(): JsonResponse
    {
        if (! $this->stakingService->isEnabled()) {
            return response()->json(['success' => false, 'error' => ['code' => 'DISABLED', 'message' => 'Staking is currently disabled']], 503);
        }

        $pools = $this->stakingService->getActivePools();

        return response()->json([
            'success' => true,
            'data' => $pools->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'lock_days' => $p->lock_days,
                'apy_percent' => (float) $p->apy_percent,
                'min_stake' => (float) $p->min_stake,
                'max_stake' => (float) $p->max_stake,
                'total_staked' => (float) $p->total_staked,
                'max_pool_size' => (float) $p->max_pool_size,
                'available_capacity' => (float) $p->available_capacity,
                'is_full' => $p->is_full,
                'stakers_count' => $p->stakers_count,
            ]),
        ]);
    }

    /**
     * POST /api/v1/staking/stake — stake TPIX เข้า pool.
     */
    public function stake(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => 'required|regex:/^0x[a-fA-F0-9]{40}$/',
            'pool_id' => 'required|integer|exists:staking_pools,id',
            'amount' => 'required|numeric|min:10',
            'tx_hash' => 'nullable|regex:/^0x[a-fA-F0-9]{64}$/',
        ]);

        try {
            $position = $this->stakingService->stake(
                $validated['wallet_address'],
                $validated['pool_id'],
                $validated['amount'],
                $validated['tx_hash'] ?? null,
            );

            return response()->json(['success' => true, 'data' => $position]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * POST /api/v1/staking/claim/{id} — claim rewards.
     */
    public function claim(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => 'required|regex:/^0x[a-fA-F0-9]{40}$/',
        ]);

        try {
            $position = $this->stakingService->claimRewards($id, $validated['wallet_address']);

            return response()->json(['success' => true, 'data' => $position]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CLAIM_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * POST /api/v1/staking/unstake/{id} — ถอน stake + claim remaining.
     */
    public function unstake(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => 'required|regex:/^0x[a-fA-F0-9]{40}$/',
            'withdraw_tx_hash' => 'nullable|regex:/^0x[a-fA-F0-9]{64}$/',
        ]);

        try {
            $position = $this->stakingService->unstake(
                $id,
                $validated['wallet_address'],
                $validated['withdraw_tx_hash'] ?? null,
            );

            return response()->json(['success' => true, 'data' => $position]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'UNSTAKE_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * GET /api/v1/staking/positions/{wallet} — positions ของ wallet.
     */
    public function positions(string $wallet): JsonResponse
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            return response()->json(['success' => false, 'error' => 'Invalid wallet address'], 400);
        }

        $positions = $this->stakingService->getPositions($wallet);

        return response()->json([
            'success' => true,
            'data' => $positions->map(fn ($p) => [
                'id' => $p->id,
                'pool' => $p->pool?->name,
                'lock_days' => $p->pool?->lock_days,
                'apy_percent' => (float) ($p->pool?->apy_percent ?? 0),
                'amount' => (float) $p->amount,
                'reward_earned' => (float) $p->reward_earned,
                'pending_reward' => (float) $p->pending_reward,
                'total_earned' => (float) $p->total_earned,
                'staked_at' => $p->staked_at?->toISOString(),
                'unlock_at' => $p->unlock_at?->toISOString(),
                'is_unlocked' => $p->is_unlocked,
                'days_remaining' => $p->days_remaining,
                'status' => $p->status,
            ]),
        ]);
    }

    /**
     * GET /api/v1/staking/stats — สถิติ staking ทั้งระบบ.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->stakingService->getStats(),
        ]);
    }
}
