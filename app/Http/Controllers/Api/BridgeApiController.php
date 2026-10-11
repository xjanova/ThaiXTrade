<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgePublicInfo;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use App\Services\Bridge\Wtpix\WtpixBridgePublicInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TPIX TRADE — API ของสะพาน (อ่านอย่างเดียวทั้งหมด) สองสินทรัพย์:
 *
 *   ?asset=usdt (ค่าปริยาย) — สะพาน USDT หลายเชน → USDT_TPIX บนเชน 4289
 *   ?asset=tpix             — สะพาน TPIX (4289) ⇄ wTPIX (BSC)
 *
 * ไม่มี endpoint ที่ "สั่งให้ mint/จ่าย/ปล่อย" — ผู้ใช้ทำธุรกรรมบนเชนเอง แล้วตัวสแกนเห็นจากเชนจริง
 * สิ่งที่หน้าเว็บส่งมาจึงปลอมยอดหรือปลอมผู้รับไม่ได้เลย
 *
 * รูปแบบตอบ: { success, data } หรือ { success: false, error: { code, message } }
 * ค่า asset ที่ไม่รู้จัก = 422 INVALID_ASSET (ไม่เดาว่าหมายถึงอะไร)
 *
 * Developed by Xman Studio
 */
class BridgeApiController extends Controller
{
    public const ASSETS = ['usdt', 'tpix'];

    public function __construct(
        private BridgePublicInfo $info,
        private BridgeConfig $config,
        private WtpixBridgePublicInfo $wtpix,
        private WtpixBridgeConfig $wtpixConfig,
    ) {}

    /** GET /api/v1/bridge/info — เชน · ที่อยู่สัญญา · วงเงิน · ค่าธรรมเนียม · เปิดอยู่ไหม */
    public function info(Request $request): JsonResponse
    {
        $asset = $this->asset($request);
        if ($asset instanceof JsonResponse) {
            return $asset;
        }

        return response()->json(['success' => true, 'data' => $asset === 'tpix' ? $this->wtpix->info() : $this->info->info()]);
    }

    /**
     * GET /api/v1/bridge/status/{chainId}/{txHash} — สถานะของธุรกรรมต้นทาง.
     * asset=tpix: chainId 4289 = ล็อก TPIX (→ wTPIX บน BSC) · chainId BSC = เผา wTPIX (→ TPIX).
     */
    public function status(Request $request, string $chainId, string $txHash): JsonResponse
    {
        $asset = $this->asset($request);
        if ($asset instanceof JsonResponse) {
            return $asset;
        }

        $supported = ctype_digit($chainId) && strlen($chainId) <= 12 && ($asset === 'tpix'
            ? $this->wtpix->supportsChain((int) $chainId)
            : $this->config->chain((int) $chainId) !== null);

        if (! $supported) {
            return $this->error('UNSUPPORTED_CHAIN', 'This chain is not supported by the bridge', 422);
        }

        if (! preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
            return $this->error('INVALID_TX_HASH', 'Transaction hash must be 0x followed by 64 hex characters', 422);
        }

        $data = $asset === 'tpix'
            ? $this->wtpix->status((int) $chainId, $txHash)
            : $this->info->depositStatus((int) $chainId, $txHash);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /api/v1/bridge/withdrawals/{txHash} — สถานะการถอน
     * (USDT: burn บนเชน TPIX · asset=tpix: เผา wTPIX บน BSC).
     */
    public function withdrawal(Request $request, string $txHash): JsonResponse
    {
        $asset = $this->asset($request);
        if ($asset instanceof JsonResponse) {
            return $asset;
        }

        if (! preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
            return $this->error('INVALID_TX_HASH', 'Transaction hash must be 0x followed by 64 hex characters', 422);
        }

        $data = $asset === 'tpix'
            ? $this->wtpix->status($this->wtpixConfig->bscChainId(), $txHash)
            : $this->info->withdrawalStatus($txHash);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /api/v1/bridge/history/{wallet} — รายการล่าสุดของกระเป๋า (ข้อมูลบนเชนสาธารณะอยู่แล้ว) */
    public function history(Request $request, string $wallet): JsonResponse
    {
        $asset = $this->asset($request);
        if ($asset instanceof JsonResponse) {
            return $asset;
        }

        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            return $this->error('INVALID_ADDRESS', 'Wallet address must be 0x followed by 40 hex characters', 422);
        }

        return response()->json(['success' => true, 'data' => $asset === 'tpix' ? $this->wtpix->history($wallet) : $this->info->history($wallet)]);
    }

    /** GET /api/v1/bridge/reserves — proof of reserves (ผลกระทบยอดล่าสุด) */
    public function reserves(Request $request): JsonResponse
    {
        $asset = $this->asset($request);
        if ($asset instanceof JsonResponse) {
            return $asset;
        }

        return response()->json(['success' => true, 'data' => $asset === 'tpix' ? $this->wtpix->reserves() : $this->info->reserves()]);
    }

    /** ค่า asset จาก query — ไม่ส่ง = usdt (พฤติกรรมเดิมของแอป/หน้าเว็บ) · ค่าอื่น = 422 */
    private function asset(Request $request): string|JsonResponse
    {
        $raw = $request->query('asset');

        if ($raw === null || $raw === '') {
            return 'usdt';
        }

        if (! is_string($raw) || ! in_array($raw, self::ASSETS, true)) {
            return $this->error('INVALID_ASSET', 'asset must be one of: '.implode(', ', self::ASSETS), 422);
        }

        return $raw;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
