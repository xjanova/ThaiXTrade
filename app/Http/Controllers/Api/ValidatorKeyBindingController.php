<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Validator\ValidatorBindingRefused;
use App\Services\Validator\ValidatorKeyBindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ValidatorKeyBindingController — แอปมาสเตอร์โหนดส่งการผูกกุญแจ validator เข้ามา.
 *
 * POST /api/v1/validators/key-binding        ผูก (ลายเซ็น 2 ใบ ดู ValidatorKeyBindingService)
 * GET  /api/v1/validators/key-binding/{wallet} ขั้นปัจจุบันของกระเป๋านี้
 *
 * ไม่ต้องล็อกอิน — ลายเซ็นคือการพิสูจน์ตัวตน ข้อมูลในคำตอบเป็นข้อมูลสาธารณะบนเชนอยู่แล้ว
 * (ที่อยู่ validator + BLS key ปรากฏใน extraData ของทุกบล็อกหลังโหวตเข้า)
 *
 * Developed by Xman Studio.
 */
class ValidatorKeyBindingController extends Controller
{
    public function __construct(private readonly ValidatorKeyBindingService $bindings) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chain_id' => ['required', 'integer'],
            'staking_wallet' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{40}$/'],
            'validator_address' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{40}$/'],
            'bls_public_key' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{96}$/'],
            'node_id' => ['required', 'string', 'regex:/^16Uiu2HA[1-9A-HJ-NP-Za-km-z]{40,60}$/'],
            'signed_at' => ['required', 'integer', 'min:1'],
            'staking_signature' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{130}$/'],
            'validator_signature' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{130}$/'],
        ]);

        try {
            $binding = $this->bindings->bind($validated);
        } catch (ValidatorBindingRefused $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
            ], $e->status);
        }

        Log::info('Validator key bound', [
            'staking_wallet' => $binding->staking_wallet,
            'validator_address' => $binding->validator_address,
            'node_id' => $binding->node_id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->bindings->statusFor($binding->staking_wallet),
        ]);
    }

    public function show(string $wallet): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->bindings->statusFor($wallet)]);
    }
}
