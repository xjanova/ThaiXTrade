<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgePublicInfo;
use Illuminate\Http\JsonResponse;

/**
 * TPIX TRADE — API ของสะพาน USDT หลายเชน → TPIX Chain (อ่านอย่างเดียวทั้งหมด).
 *
 * ไม่มี endpoint ที่ "สั่งให้ mint/จ่าย" — ผู้ใช้โอน USDT เข้า vault หรือ burn บนเชนเอง
 * แล้วตัวสแกนเห็นจากเชนจริง สิ่งที่หน้าเว็บส่งมาจึงปลอมยอดหรือปลอมผู้รับไม่ได้เลย
 * (สะพานเดิมรับ tx hash + จำนวนจากหน้าเว็บแล้วไปตรวจทีหลัง — ทางนี้ไม่มีช่องนั้นแล้ว)
 *
 * รูปแบบตอบ: { success, data } หรือ { success: false, error: { code, message } }
 *
 * Developed by Xman Studio
 */
class BridgeApiController extends Controller
{
    public function __construct(
        private BridgePublicInfo $info,
        private BridgeConfig $config,
    ) {}

    /** GET /api/v1/bridge/info — เชน · ที่อยู่ vault/สัญญา · วงเงิน · ค่าธรรมเนียม · เปิดอยู่ไหม */
    public function info(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->info->info()]);
    }

    /** GET /api/v1/bridge/status/{chainId}/{txHash} — สถานะเงินฝากของธุรกรรมบนเชนต้นทาง */
    public function status(string $chainId, string $txHash): JsonResponse
    {
        if (! ctype_digit($chainId) || $this->config->chain((int) $chainId) === null) {
            return $this->error('UNSUPPORTED_CHAIN', 'This chain is not supported by the bridge', 422);
        }

        if (! preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
            return $this->error('INVALID_TX_HASH', 'Transaction hash must be 0x followed by 64 hex characters', 422);
        }

        return response()->json(['success' => true, 'data' => $this->info->depositStatus((int) $chainId, $txHash)]);
    }

    /** GET /api/v1/bridge/withdrawals/{txHash} — สถานะการถอน (ธุรกรรม burn บนเชน TPIX) */
    public function withdrawal(string $txHash): JsonResponse
    {
        if (! preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
            return $this->error('INVALID_TX_HASH', 'Transaction hash must be 0x followed by 64 hex characters', 422);
        }

        return response()->json(['success' => true, 'data' => $this->info->withdrawalStatus($txHash)]);
    }

    /** GET /api/v1/bridge/history/{wallet} — ฝาก/ถอนล่าสุดของกระเป๋า (ข้อมูลบนเชนสาธารณะอยู่แล้ว) */
    public function history(string $wallet): JsonResponse
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            return $this->error('INVALID_ADDRESS', 'Wallet address must be 0x followed by 40 hex characters', 422);
        }

        return response()->json(['success' => true, 'data' => $this->info->history($wallet)]);
    }

    /** GET /api/v1/bridge/reserves — proof of reserves (ผลกระทบยอดล่าสุด) */
    public function reserves(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->info->reserves()]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
