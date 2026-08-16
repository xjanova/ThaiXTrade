<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InfraHeartbeat;
use App\Models\SystemAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * InfraAlertController.
 *
 * รับ heartbeat + เหตุวิกฤตจาก watchdog บนเซิร์ฟเวอร์โครงสร้างพื้นฐาน
 * (TPIX-Coin: infrastructure/scripts/chain-watchdog.sh — cron ทุก 1 นาที)
 *
 * ความปลอดภัย:
 * - Bearer token ต้องตรงกับ TPIX_INFRA_ALERT_TOKEN (.env) — เทียบแบบ
 *   constant-time ด้วย hash_equals กัน timing attack
 * - ยังไม่ตั้ง token = ปิดระบบ (503) ไม่ใช่เปิดโล่ง — default deny
 * - จำกัด payload ด้วย validation ทุก field, ไม่มี field ไหนถูก echo กลับดิบๆ
 */
class InfraAlertController extends Controller
{
    /**
     * POST /api/infra/heartbeat — สัญญาณชีพ "ทุก check ฝั่งเชนผ่าน".
     *
     * นอกจากบันทึกชีพ ยังปิดเหตุร้ายที่ระบบปิดเองได้ของ node นั้น
     * (chain_stalled ฯลฯ) เพราะ heartbeat ยิงเฉพาะตอนเชนกลับมาปกติแล้ว
     */
    public function heartbeat(Request $request): JsonResponse
    {
        if ($denied = $this->denyInvalidToken($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'node' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'block' => ['nullable', 'integer', 'min:0'],
        ]);

        InfraHeartbeat::updateOrCreate(
            ['node' => $validated['node']],
            ['last_block' => $validated['block'] ?? 0, 'last_seen_at' => now()],
        );

        $resolved = SystemAlert::resolveKeys(
            $validated['node'],
            SystemAlert::AUTO_RESOLVE_KEYS,
            'auto:heartbeat',
        );

        return response()->json(['ok' => true, 'auto_resolved' => $resolved]);
    }

    /**
     * POST /api/infra/alert — ยกเหตุขึ้นคาดแดงหลังบ้าน.
     */
    public function alert(Request $request): JsonResponse
    {
        if ($denied = $this->denyInvalidToken($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'node' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'severity' => ['required', 'in:critical,warning,info'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $alert = SystemAlert::raise(
            $validated['node'],
            $validated['key'],
            $validated['severity'],
            $validated['message'],
        );

        // เก็บ trail ฝั่งเซิร์ฟเวอร์ด้วย — คาดแดงโดนกดรับทราบแล้วหาย แต่ log อยู่
        Log::warning('infra.alert received', [
            'node' => $validated['node'],
            'key' => $validated['key'],
            'severity' => $validated['severity'],
            'occurrences' => $alert->occurrences,
        ]);

        return response()->json(['ok' => true, 'id' => $alert->id, 'occurrences' => $alert->occurrences]);
    }

    /**
     * ตรวจ Bearer token แบบ constant-time — คืน response ปฏิเสธ หรือ null ถ้าผ่าน.
     */
    private function denyInvalidToken(Request $request): ?JsonResponse
    {
        $expected = (string) config('services.infra_alerts.token', '');

        if ($expected === '') {
            return response()->json(['error' => 'infra alerts not configured'], 503);
        }

        $given = (string) ($request->bearerToken() ?? '');

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return null;
    }
}
