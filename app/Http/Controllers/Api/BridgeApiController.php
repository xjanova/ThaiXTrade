<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBridgeJob;
use App\Models\BridgeTransaction;
use App\Services\BridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — Bridge API Controller (Production)
 * API สำหรับ cross-chain bridge: TPIX Chain ↔ BSC
 *
 * Flow:
 * 1. Frontend: user sign tx (burn/transfer) → ได้ tx_hash
 * 2. POST /bridge/initiate with tx_hash → dispatch ProcessBridgeJob
 * 3. Job: verify on-chain → execute target transfer → complete
 *
 * Developed by Xman Studio
 */
class BridgeApiController extends Controller
{
    public function __construct(
        private BridgeService $bridgeService,
    ) {}

    /**
     * GET /api/v1/bridge/info — ข้อมูล bridge (fee, limits, chains, addresses).
     */
    public function info(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->bridgeService->getInfo(),
        ]);
    }

    /**
     * POST /api/v1/bridge/initiate — เริ่ม bridge transaction.
     *
     * ถ้ามี tx_hash → dispatch ProcessBridgeJob ทันที
     * ถ้าไม่มี tx_hash → สร้าง pending record (frontend จะ submit tx_hash ทีหลัง)
     */
    public function initiate(Request $request): JsonResponse
    {
        if (! $this->bridgeService->isEnabled()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'BRIDGE_DISABLED', 'message' => 'Bridge is currently disabled.'],
            ], 503);
        }

        $validated = $request->validate([
            'wallet_address' => 'required|regex:/^0x[a-fA-F0-9]{40}$/',
            'amount' => 'required|numeric|min:10',
            'direction' => 'required|in:bsc_to_tpix,tpix_to_bsc',
            'tx_hash' => 'nullable|regex:/^0x[a-fA-F0-9]{64}$/',
        ]);

        try {
            $tx = $this->bridgeService->initiateBridge(
                $validated['wallet_address'],
                $validated['amount'],
                $validated['direction'],
                $validated['tx_hash'] ?? null,
            );

            // ถ้ามี tx_hash → dispatch job ให้ verify + execute ทันที
            if ($tx->source_tx_hash) {
                ProcessBridgeJob::dispatch($tx);
                Log::info('Bridge job dispatched', [
                    'bridge_id' => $tx->id,
                    'direction' => $tx->direction,
                    'amount' => $tx->amount,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $tx->id,
                    'direction' => $tx->direction,
                    'amount' => $tx->amount,
                    'fee' => $tx->fee,
                    'receive_amount' => $tx->receive_amount,
                    'status' => $tx->status,
                    'source_tx_hash' => $tx->source_tx_hash,
                    'estimated_time' => '2-5 minutes',
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * POST /api/v1/bridge/retry/{id} — Retry failed bridge transaction.
     */
    public function retry(int $id, Request $request): JsonResponse
    {
        $walletAddress = strtolower($request->input('wallet_address', ''));
        $tx = BridgeTransaction::findOrFail($id);

        // ตรวจว่าเป็นเจ้าของ
        if ($tx->wallet_address !== $walletAddress) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Not your transaction'],
            ], 403);
        }

        if ($tx->status !== 'failed') {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_STATUS', 'message' => 'Only failed transactions can be retried'],
            ], 422);
        }

        if (! $tx->source_tx_hash) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NO_TX_HASH', 'message' => 'No source transaction hash to verify'],
            ], 422);
        }

        $tx->update(['status' => 'processing', 'error_message' => null]);
        ProcessBridgeJob::dispatch($tx);

        return response()->json([
            'success' => true,
            'data' => ['id' => $tx->id, 'status' => 'processing', 'message' => 'Retry dispatched'],
        ]);
    }

    /**
     * GET /api/v1/bridge/history/{wallet} — ประวัติ bridge ของ wallet.
     */
    public function history(string $wallet): JsonResponse
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            return response()->json(['success' => false, 'error' => 'Invalid wallet address'], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $this->bridgeService->getHistory($wallet),
        ]);
    }

    /**
     * GET /api/v1/bridge/status/{id} — สถานะ tx เดี่ยว.
     */
    public function status(int $id): JsonResponse
    {
        $tx = BridgeTransaction::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $tx->id,
                'direction' => $tx->direction,
                'amount' => $tx->amount,
                'fee' => $tx->fee,
                'receive_amount' => $tx->receive_amount,
                'status' => $tx->status,
                'source_tx_hash' => $tx->source_tx_hash,
                'target_tx_hash' => $tx->target_tx_hash,
                'error_message' => $tx->error_message,
                'verified_at' => $tx->verified_at?->toIso8601String(),
                'completed_at' => $tx->completed_at?->toIso8601String(),
                'created_at' => $tx->created_at->toIso8601String(),
            ],
        ]);
    }
}
