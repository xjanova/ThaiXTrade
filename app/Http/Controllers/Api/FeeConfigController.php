<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\Bridge\BridgePublicInfo;
use App\Services\FeeCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — Unified Fee Configuration API.
 *
 * Returns swap + bridge fee configuration in a single call.
 * Used by the Flutter mobile wallet to display fees and fee wallet addresses.
 * Cached for 5 minutes.
 *
 * Developed by Xman Studio
 */
class FeeConfigController extends Controller
{
    public function __construct(
        private FeeCalculationService $feeCalculationService,
        private BridgePublicInfo $bridgeInfo,
    ) {}

    /**
     * GET /api/v1/fees — Unified fee config for wallet apps.
     *
     * Returns swap fee %, bridge fee %, fee wallet addresses, and limits.
     * Response format matches what the Flutter FeeService expects.
     *
     * Optional query: ?chain_id=X — returns chain-specific swap fee
     * (falls back to global if no chain-specific override exists).
     */
    public function index(Request $request): JsonResponse
    {
        $chainId = $request->integer('chain_id') ?: null;
        $cacheKey = 'api:fees:unified:'.($chainId ?? 'global');

        $data = Cache::remember($cacheKey, 300, function () use ($chainId) {
            // Swap fee config — chain-specific if chainId provided
            $swapFeeRate = $this->feeCalculationService->getEffectiveFeeRate('swap', $chainId);
            $feeCollectorWallet = SiteSetting::get('trading', 'fee_collector_wallet', '');

            // สะพาน USDT → TPIX Chain — ฝากไม่มีค่าธรรมเนียม ถอนคิดเป็นจำนวนคงที่ต่อเชน (ดู /api/v1/bridge/info)
            $bridgeInfo = $this->bridgeInfo->info();

            return [
                'swap' => [
                    'feePercent' => round($swapFeeRate, 4),
                    'feeWallet' => $feeCollectorWallet,
                    'enabled' => ! empty($feeCollectorWallet),
                    'chainId' => $chainId,
                ],
                /*
                 * รูปแบบเดิมที่แอปมือถือ (FeeConfig ใน api_models.dart) อ่านอยู่ — คงชื่อช่องไว้ครบ
                 *
                 * ⚠️ enabled ของแอปเป็น false เสมอ: หน้า Bridge ในแอปยังเป็นฟอร์มเปล่าที่กดแล้วขึ้น
                 *    "coming soon" (ยังไม่ได้ port สะพาน USDT) ถ้าส่ง true แอปจะโชว์ฟอร์มให้กรอกแล้วไม่ทำอะไร
                 *    สะพานบนเว็บดูที่ webEnabled · ปลดเป็นค่าจริงเมื่อแอปรองรับแล้ว
                 */
                'bridge' => [
                    'asset' => 'USDT',
                    'feePercent' => 0,
                    // สะพานไม่มีกระเป๋ารับค่าธรรมเนียมแยก — ว่างไว้กันโค้ดฝั่งแอปเผลอโอนเข้าที่อยู่ที่ไม่ใช่ vault
                    'feeWallet' => '',
                    'minAmount' => (float) $bridgeInfo['limits']['min_deposit'],
                    'maxAmount' => (float) $bridgeInfo['limits']['max_deposit_per_tx'],
                    'minFee' => 0,
                    'estimatedMinutes' => 20,
                    'enabled' => false,
                    'webEnabled' => (bool) $bridgeInfo['enabled'],
                ],
            ];
        });

        return response()->json($data);
    }
}
