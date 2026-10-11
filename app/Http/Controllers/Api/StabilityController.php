<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Stabilizer\StabilizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/v1/stability — ข้อมูลสาธารณะของ TPIX Stability Reserve (อ่านอย่างเดียว).
 *
 * กฎ ค่าที่ใช้จริง กระเป๋า ยอดคงเหลือ กราฟกรอบราคา ทุกไม้พร้อมลิงก์ ทุกการตัดสินใจล่าสุด
 * และรายงานรายวัน — ไม่มีความลับใด ๆ (ไม่มี path ของ keystore / passphrase / raw tx)
 *
 * แคช 30 วินาทีฝั่งเซิร์ฟเวอร์ + throttle ของกลุ่ม /api/v1 กันยิงถล่มจนไปกระทบ RPC
 *
 * Developed by Xman Studio
 */
class StabilityController extends Controller
{
    public function show(StabilizerService $service): JsonResponse
    {
        try {
            $data = $service->publicState();
        } catch (\Throwable $e) {
            Log::warning('stability api: state unavailable', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'STABILITY_UNAVAILABLE', 'message' => 'Stability data is temporarily unavailable'],
            ], 503);
        }

        return response()->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'public, max-age=30');
    }
}
