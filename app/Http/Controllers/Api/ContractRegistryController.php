<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContractRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ContractRegistryController — ให้สคริปต์ deploy ลงทะเบียนที่อยู่สัญญาเข้ามาเอง.
 *
 * เป้าหมาย: หลัง deploy เสร็จไม่ต้องทำอะไรอีก
 * เดิมต้อง ssh เข้าเซิร์ฟไปแก้ .env แล้ว config:cache ซึ่งลืมง่ายที่สุดในกระบวนการ
 * — deploy สำเร็จแต่เว็บยังไม่รู้จักสัญญา แล้วก็เงียบไม่มีอะไรฟ้อง
 *
 * ความปลอดภัย (ที่อยู่สัญญาคุมทางเดินของเงิน ต้องแน่นกว่าปกติ):
 *   - Bearer token ต้องตรงกับ CONTRACT_REGISTRY_TOKEN (.env) เทียบด้วย hash_equals
 *   - ยังไม่ตั้ง token = ปิดระบบ (503) ไม่ใช่เปิดโล่ง — default deny
 *   - รับเฉพาะชื่อสัญญาที่ระบบรู้จัก (allowlist ใน ContractRegistry::CONTRACTS)
 *   - ตรวจ eth_getCode กับเชนจริงก่อนรับ — ที่อยู่ที่ไม่มีโค้ดถูกปฏิเสธ
 *     (กันทั้งการพิมพ์ผิด และกันคนชี้ไปที่ address ที่ยังไม่มีสัญญา)
 *   - บันทึกค่าเดิมทุกครั้งที่เปลี่ยน
 *
 * Developed by Xman Studio
 */
class ContractRegistryController extends Controller
{
    public function __construct(private ContractRegistry $registry) {}

    /**
     * GET /api/infra/contracts — ดูสถานะปัจจุบัน (ต้องมี token เหมือนกัน).
     */
    public function show(Request $request): JsonResponse
    {
        if ($deny = $this->denyInvalidToken($request)) {
            return $deny;
        }

        return response()->json(['ok' => true, 'contracts' => $this->registry->status()]);
    }

    /**
     * POST /api/infra/contracts — ลงทะเบียนที่อยู่สัญญาหลัง deploy.
     *
     * body: { "contracts": { "masternode_registry": "0x...", "token_factory_v2": "0x..." } }
     */
    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyInvalidToken($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'contracts' => ['required', 'array', 'min:1'],
            'contracts.*' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        $applied = [];
        $rejected = [];

        foreach ($validated['contracts'] as $key => $address) {
            if (! isset(ContractRegistry::CONTRACTS[$key])) {
                $rejected[$key] = 'ไม่รู้จักสัญญาชื่อนี้';

                continue;
            }

            // ที่อยู่ต้องมีโค้ดอยู่จริงบนเชน — ไม่งั้นเรากำลังชี้ระบบไปที่ความว่างเปล่า
            if (! $this->registry->hasCode($address)) {
                $rejected[$key] = "eth_getCode คืน 0x — ไม่มีสัญญาอยู่ที่ {$address}";

                continue;
            }

            $result = $this->registry->set($key, $address);

            if ($result['ok']) {
                $applied[$key] = ['address' => $address, 'previous' => $result['previous']];
            } else {
                $rejected[$key] = $result['message'];
            }
        }

        Log::info('ContractRegistry: ลงทะเบียนสัญญาจากสคริปต์ deploy', [
            'applied' => array_keys($applied),
            'rejected' => array_keys($rejected),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'ok' => $rejected === [],
            'applied' => $applied,
            'rejected' => $rejected,
            'contracts' => $this->registry->status(),
        ], $rejected === [] ? 200 : 422);
    }

    /**
     * ตรวจ Bearer token แบบ constant-time — คืน response ปฏิเสธ หรือ null ถ้าผ่าน.
     */
    private function denyInvalidToken(Request $request): ?JsonResponse
    {
        $expected = (string) config('services.contract_registry.token', '');

        if ($expected === '') {
            return response()->json([
                'error' => 'contract registry not configured',
                'hint' => 'ตั้ง CONTRACT_REGISTRY_TOKEN ใน .env ก่อน',
            ], 503);
        }

        $given = (string) ($request->bearerToken() ?? '');

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return null;
    }
}
