<?php

namespace App\Http\Middleware;

use App\Services\Kyc\KycGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TPIX TRADE — ด่านยืนยันตัวตนหน้า route.
 *
 * ใช้แบบ: ->middleware('kyc:ai_bot')
 *
 * ⚠️ ปิดปุ่มบนหน้าจออย่างเดียวกันไม่ได้ — ใครก็ยิง API ตรงด้วย curl ได้
 *    route ที่ควรมีด่านต้องแปะ middleware นี้เสมอ ไม่ใช่พึ่งหน้าเว็บของเรา
 *
 * Developed by Xman Studio.
 */
class EnsureKycApproved
{
    public function __construct(
        private readonly KycGate $gate,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // เว็บมาทาง session · มือถือมาทางลายเซ็นกระเป๋า — resolveUser รับทั้งสองทาง
        $user = $this->gate->resolveUser($request);

        if ($this->gate->passes($user, $feature)) {
            return $next($request);
        }

        $label = (string) config("kyc.features.{$feature}.label_th", $feature);

        $message = $user === null
            ? "ต้องเข้าสู่ระบบและยืนยันตัวตนก่อนใช้{$label}"
            : "ต้องยืนยันตัวตนก่อนใช้{$label}";

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => [
                    // หน้าจอใช้โค้ดนี้พาไปหน้ายืนยันตัวตนได้เลย ไม่ต้องอ่านข้อความ
                    'code' => 'KYC_REQUIRED',
                    'message' => $message,
                    'feature' => $feature,
                    'level' => $this->gate->requiredLevel($feature),
                ],
            ], 403);
        }

        return redirect()
            ->route('kyc.index')
            ->with('error', $message);
    }
}
