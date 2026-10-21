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
 * Swap part cached for 5 minutes · bridge part follows BridgePublicInfo (30 s, cleared on switch change).
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
        // แคชเฉพาะส่วน swap (คีย์ใหม่ — คีย์เดิม api:fees:unified:* เก็บทั้งก้อนรวมสะพาน ห้ามอ่านซ้ำเป็นส่วน swap)
        $cacheKey = 'api:fees:swap:'.($chainId ?? 'global');

        $swap = Cache::remember($cacheKey, 300, function () use ($chainId) {
            // Swap fee config — chain-specific if chainId provided
            $swapFeeRate = $this->feeCalculationService->getEffectiveFeeRate('swap', $chainId);
            $feeCollectorWallet = SiteSetting::get('trading', 'fee_collector_wallet', '');

            return [
                'feePercent' => round($swapFeeRate, 4),
                'feeWallet' => $feeCollectorWallet,
                'enabled' => ! empty($feeCollectorWallet),
                'chainId' => $chainId,
            ];
        });

        return response()->json([
            'swap' => $swap,
            'bridge' => $this->bridgePart(),
        ]);
    }

    /**
     * สะพาน USDT → TPIX Chain — อ่านจาก BridgePublicInfo ทุกครั้ง (แคชของมันเอง 30 วิ และถูกล้างทันที
     * เมื่อสวิตช์สะพานเปลี่ยน) ไม่ห่อด้วยแคช 5 นาทีของค่าธรรมเนียม swap — เดิมสะพานถูกหยุดอัตโนมัติแล้ว
     * ที่นี่ยังตอบ webEnabled = true ต่ออีกถึง 5 นาที.
     *
     * รูปแบบเดิมที่แอปมือถือ (FeeConfig ใน api_models.dart) อ่านอยู่ — คงชื่อช่องเดิมไว้ครบ เพิ่มได้อย่างเดียว
     *
     * ⚠️ enabled ของแอปเป็น false เสมอ: หน้า Bridge ในแอปยังเป็นฟอร์มเปล่าที่กดแล้วขึ้น
     *    "coming soon" (ยังไม่ได้ port สะพาน USDT) ถ้าส่ง true แอปจะโชว์ฟอร์มให้กรอกแล้วไม่ทำอะไร
     *    สะพานบนเว็บดูที่ webEnabled · ปลดเป็นค่าจริงเมื่อแอปรองรับแล้ว
     */
    private function bridgePart(): array
    {
        $bridgeInfo = $this->bridgeInfo->info();
        $enabled = array_values(array_filter($bridgeInfo['chains'], fn ($c) => $c['enabled']));
        $etas = array_column($enabled, 'eta_minutes');

        return [
            'asset' => 'USDT',
            // ไม่มีค่าธรรมเนียมเป็นเปอร์เซ็นต์ — ฝากหักคงที่ (minFee) · ถอนหักคงที่ต่อเชน (chains[].withdrawFee)
            'feePercent' => 0,
            // สะพานไม่มีกระเป๋ารับค่าธรรมเนียมแยก — ว่างไว้กันโค้ดฝั่งแอปเผลอโอนเข้าที่อยู่ที่ไม่ใช่ vault
            'feeWallet' => '',
            'minAmount' => (float) $bridgeInfo['limits']['min_deposit'],
            // ฝากต่อครั้งได้จริง = min(วงเงินเว็บ, maxPerTx บนสัญญา) — เกินกว่านี้ mint ไม่ได้
            'maxAmount' => (float) ($bridgeInfo['deposit_max_per_tx'] ?? $bridgeInfo['limits']['max_deposit_per_tx']),
            'minFee' => (float) ($bridgeInfo['limits']['deposit_fee'] ?? 0),
            // เชนที่เร็วที่สุดในบรรดาที่เปิดอยู่ (BSC ~2 นาที) — รายละเอียดต่อเชนอยู่ใน chains
            // ไม่มีเชนเปิด = คงค่าเดิม 20 (แอปเดิมอ่านเป็น int เสมอ ห้ามส่ง null)
            'estimatedMinutes' => $etas === [] ? 20 : (int) min($etas),
            'enabled' => false,
            'webEnabled' => (bool) $bridgeInfo['enabled'],
            'chains' => array_map(fn ($c) => [
                'chainId' => $c['chain_id'],
                'name' => $c['name'],
                'withdrawFee' => (float) $c['withdraw_fee'],
                'estimatedMinutes' => $c['eta_minutes'],
            ], $enabled),
        ];
    }
}
