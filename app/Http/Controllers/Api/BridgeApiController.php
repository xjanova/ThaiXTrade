<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BridgeTransaction;
use App\Services\BridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TPIX TRADE — Bridge API Controller
 * API สำหรับ cross-chain bridge: TPIX Chain ↔ BSC.
 */
class BridgeApiController extends Controller
{
    public function __construct(
        private BridgeService $bridgeService,
    ) {}

    /**
     * GET /api/v1/bridge/info — ข้อมูล bridge (fee, limits, chains).
     */
    public function info(): JsonResponse
    {
        $info = $this->bridgeService->getInfo();
        $info['enabled'] = $this->bridgeService->isEnabled();

        return response()->json([
            'success' => true,
            'data' => $info,
        ]);
    }

    /**
     * POST /api/v1/bridge/initiate — เริ่ม bridge transaction.
     */
    public function initiate(Request $request): JsonResponse
    {
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

            return response()->json([
                'success' => true,
                'data' => $tx,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }
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
            'data' => $tx,
        ]);
    }
}
