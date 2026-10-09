<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use App\Models\User;
use App\Services\Kyc\KycGate;
use App\Services\Kyc\ThaipromptKycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * TPIX TRADE — ยืนยันตัวตนจากแอปมือถือ.
 *
 *   GET  /api/v1/kyc/status               สถานะ KYC + ด่านทุกฟีเจอร์ + การเชื่อม Thaiprompt
 *   POST /api/v1/kyc/thaiprompt/start     ได้ URL หน้าอนุญาตของ Thaiprompt ไปเปิดในเบราว์เซอร์
 *   POST /api/v1/kyc/thaiprompt/complete  แลก "รหัสรับผล" ที่กลับมาทาง deep link tpixtrade://kyc
 *   POST /api/v1/kyc/thaiprompt/refresh   ถามผลซ้ำ (ระหว่างลูกค้าทำ eKYC ในแอป Thaiprompt)
 *
 * อยู่หลัง VerifyWalletOwnership — แอปพิสูจน์ตัวด้วยกระเป๋า (X-Wallet-Session) ไม่ใช่ session เว็บ
 * ผล KYC ผูกกับ "บัญชี" ที่ผูกกระเป๋านี้ (เหมือนหน้าเว็บ) ไม่ใช่กับกระเป๋าเอง
 *
 * Developed by Xman Studio.
 */
class KycApiController extends Controller
{
    public function __construct(
        private readonly KycGate $gate,
        private readonly ThaipromptKycService $thaiprompt,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user) {
            return $this->noAccount();
        }

        return response()->json(['success' => true, 'data' => $this->payload($user)]);
    }

    public function start(Request $request): JsonResponse
    {
        $request->validate(['consent' => ['accepted']]);

        $user = $this->user($request);
        if (! $user) {
            return $this->noAccount();
        }

        try {
            $url = $this->thaiprompt->beginAppAuthorization($user);
        } catch (RuntimeException $e) {
            return $this->fail('THAIPROMPT_UNAVAILABLE', $e->getMessage(), 422);
        }

        return response()->json(['success' => true, 'data' => ['authorize_url' => $url]]);
    }

    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'completion' => ['required', 'string', 'regex:/^[A-Za-z0-9]{48}$/'],
        ]);

        $user = $this->user($request);
        if (! $user) {
            return $this->noAccount();
        }

        try {
            $this->thaiprompt->completeAppAuthorization($user, $validated['completion']);
        } catch (RuntimeException $e) {
            $code = $e->getMessage() === ThaipromptKycService::ERR_TAKEN ? 'THAIPROMPT_TAKEN' : 'THAIPROMPT_LINK_FAILED';

            return $this->fail($code, $e->getMessage(), 422);
        }

        return response()->json(['success' => true, 'data' => $this->payload($user->fresh())]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user) {
            return $this->noAccount();
        }

        $link = $user->thaipromptKycLink;
        if (! $link) {
            return $this->fail('THAIPROMPT_NOT_LINKED', 'ยังไม่ได้เชื่อมบัญชี Thaiprompt', 404);
        }

        try {
            $this->thaiprompt->refresh($link);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $e->getMessage() === ThaipromptKycService::ERR_RECONNECT ? 'THAIPROMPT_RECONNECT' : 'THAIPROMPT_UNAVAILABLE',
                    'message' => $e->getMessage(),
                ],
                'data' => $this->payload($user->fresh()),
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $this->payload($user->fresh())]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * บัญชีของกระเป๋าที่ยิงมา — ใช้ตัวหาเดียวกับด่าน KYC (รู้จักทั้งแคชลายเซ็นและโทเคนของแอป).
     */
    private function user(Request $request): ?User
    {
        return $this->gate->resolveUser($request);
    }

    /**
     * สิ่งที่แอปต้องใช้วาดหน้า "ยืนยันตัวตน" — ไม่มีข้อมูลบัตรและไม่มี token.
     */
    private function payload(User $user): array
    {
        $latest = KycSubmission::query()->where('user_id', $user->id)->latest('id')->first();

        return [
            'gate' => $this->gate->statusFor($user),
            'submission' => $latest ? [
                'status' => $latest->status,
                'level' => $latest->level,
                'source' => $latest->source ?? KycSubmission::SOURCE_MANUAL,
                'submitted_at' => $latest->submitted_at?->toIso8601String(),
                'reviewed_at' => $latest->reviewed_at?->toIso8601String(),
                'reject_reason' => $latest->reject_reason,
            ] : null,
            'thaiprompt' => $this->thaiprompt->ownerPayload($user),
            // แอปตั้งแต่ v1.1.211 ไม่ใช้แล้ว (หน้าเว็บเลิกรับเอกสาร ยืนยันตัวตนทำในแอปเท่านั้น)
            // คงไว้ให้แอปรุ่นเก่าที่ยังมีปุ่ม "ส่งเอกสารที่หน้าเว็บ" — เปิดไปเจอขั้นตอนในแอปแทน
            'web_kyc_url' => url('/kyc'),
        ];
    }

    private function noAccount(): JsonResponse
    {
        // ไม่มีแถวผู้ใช้ของกระเป๋านี้ / ยังไม่ได้เซ็นยืนยัน — แอปพาไปเซ็นใหม่
        return $this->fail('WALLET_NOT_VERIFIED', 'ยืนยันกระเป๋าก่อนดูสถานะการยืนยันตัวตน', 403);
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
