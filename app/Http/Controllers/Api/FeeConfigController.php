<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\BridgeService;
use App\Services\FeeCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — Unified Fee Configuration API
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
        private BridgeService $bridgeService,
    ) {}

    /**
     * GET /api/v1/fees — Unified fee config for wallet apps.
     *
     * Returns swap fee %, bridge fee %, fee wallet addresses, and limits.
     * Response format matches what the Flutter FeeService expects.
     */
    public function index(): JsonResponse
    {
        $data = Cache::remember('api:fees:unified', 300, function () {
            // Swap fee config
            $swapFeeRate = $this->feeCalculationService->getEffectiveFeeRate('swap');
            $feeCollectorWallet = SiteSetting::get('trading', 'fee_collector_wallet', '');

            // Bridge fee config
            $bridgeInfo = $this->bridgeService->getInfo();

            return [
                'swap' => [
                    'feePercent' => round($swapFeeRate, 4),
                    'feeWallet' => $feeCollectorWallet,
                    'enabled' => ! empty($feeCollectorWallet),
                ],
                'bridge' => [
                    'feePercent' => $bridgeInfo['fee_percent'],
                    'feeWallet' => $bridgeInfo['treasury_address'],
                    'minAmount' => $bridgeInfo['min_amount'],
                    'maxAmount' => $bridgeInfo['max_amount'],
                    'minFee' => $bridgeInfo['min_fee'],
                    'estimatedMinutes' => 5,
                    'enabled' => $bridgeInfo['enabled'],
                ],
            ];
        });

        return response()->json($data);
    }
}
