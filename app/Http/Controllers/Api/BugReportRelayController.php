<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ทางผ่านรายงานบั๊กจากหน้าเว็บไปยังระบบกลางของ xman studio.
 *
 * เจ้าของสั่ง: "ทำระบบ bug report หลังบ้านไว้สำหรับรายงานข้อผิดพลาดจากแอพและโปรแกรม
 * ทั้งหมด เพื่อให้ตรวจสอบได้ทันที ไม่เดา" — ระบบกลางมีอยู่แล้ว (ทุกแอปในบ้านใช้)
 * จึงไม่สร้างที่เก็บซ้อน: แอปมือถือยิงตรง ส่วนหน้าเว็บยิงผ่านที่นี่เพราะติด CORS
 *
 * ส่งต่อไม่ได้ (เซิร์ฟเวอร์กลางล่ม) ไม่ตอบ error กลับ — บันทึกทั้งก้อนลง log ของเรา
 * แล้วตอบ 202 รายงานที่ผู้ใช้อุตส่าห์ส่งมาต้องไม่หายไปเฉยๆ
 *
 * Developed by Xman Studio.
 */
class BugReportRelayController extends Controller
{
    public const PRODUCTS = ['tpix-web', 'tpix-trade', 'tpix-wallet', 'tpix-masternode'];

    public const TYPES = ['bug', 'crash', 'performance', 'feature_request', 'misclassification'];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_name' => ['required', 'string', 'in:'.implode(',', self::PRODUCTS)],
            'product_version' => ['nullable', 'string', 'max:20'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'os_version' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'report_type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'stack_trace' => ['nullable', 'string', 'max:12000'],
            'metadata' => ['nullable', 'array'],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
            'severity' => ['nullable', 'in:minor,moderate,major,critical'],
        ]);

        $metadata = (array) ($validated['metadata'] ?? []);
        $metadata['relay'] = [
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
            'received_at' => now()->toIso8601String(),
        ];

        $payload = array_merge($validated, ['metadata' => $metadata]);

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('services.bug_reports.user_agent'),
                'Accept' => 'application/json',
            ])
                ->timeout(8)
                ->post((string) config('services.bug_reports.endpoint'), $payload);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'data' => ['id' => $response->json('data.id'), 'forwarded' => true],
                ], 201);
            }

            Log::warning('bug-report: ระบบกลางปฏิเสธ — เก็บไว้ใน log แทน', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::warning('bug-report: ส่งต่อไม่ได้ — เก็บไว้ใน log แทน', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        return response()->json(['success' => true, 'data' => ['id' => null, 'forwarded' => false]], 202);
    }
}
