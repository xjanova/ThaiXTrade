<?php

namespace App\Http\Controllers;

use App\Services\Kyc\KycGate;
use App\Services\Kyc\ThaipromptKycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * TPIX TRADE — ยืนยันตัวตนด้วยบัญชี Thaiprompt.
 *
 *   POST /kyc/thaiprompt/connect   ยินยอม → ไปหน้าอนุญาตของ Thaiprompt
 *   GET  /kyc/thaiprompt/callback  กลับมาจาก Thaiprompt → ผูกบัญชี + ลงผล
 *   POST /kyc/thaiprompt/refresh   ถามผลซ้ำ (หน้าเว็บเรียกระหว่างรอลูกค้าทำ eKYC ในแอป)
 *
 * ทุกเส้นอยู่หลัง 'auth' — ผล KYC ผูกกับบัญชี ไม่ใช่กับกระเป๋า (เหมือน KycController)
 *
 * Developed by Xman Studio.
 */
class ThaipromptKycController extends Controller
{
    public function __construct(
        private readonly ThaipromptKycService $thaiprompt,
        private readonly KycGate $gate,
    ) {}

    public function connect(Request $request): Response
    {
        // ยินยอมฝั่งเราก่อน (ว่าจะรับผลจาก Thaiprompt) แล้วลูกค้ายังต้องกดอนุญาตที่ Thaiprompt อีกชั้น
        $request->validate(['consent' => ['accepted']]);

        try {
            $url = $this->thaiprompt->beginAuthorization($request->user(), $request->session(), $request->ip());
        } catch (RuntimeException $e) {
            return back()->withErrors(['kyc' => $e->getMessage()]);
        }

        // ออกนอกโดเมน — Inertia ต้องใช้ location() (409 + X-Inertia-Location) ไม่ใช่ redirect ธรรมดา
        return Inertia::location($url);
    }

    /**
     * กลับมาจากหน้าอนุญาตของ Thaiprompt — รับทั้งทางเว็บและทางแอปมือถือ.
     *
     * ⚠️ เส้นนี้อยู่นอก 'auth' โดยตั้งใจ: ทางแอปเปิดในเบราว์เซอร์ของมือถือที่ไม่มี session เว็บ
     *    ทางเว็บยังบังคับล็อกอินเองด้านล่าง (และ state ผูกกับ session อยู่แล้ว)
     */
    public function callback(Request $request): RedirectResponse|Response
    {
        if ($this->thaiprompt->isAppState($request->query('state'))) {
            $deepLink = $this->thaiprompt->captureAppCallback($request->query());

            // หน้าเล็กๆ พาเด้งกลับเข้าแอป — เบราว์เซอร์บางตัวไม่ยอมเด้ง custom scheme เองโดยไม่มีคนกด
            return response()
                ->view('kyc.thaiprompt-return', [
                    'deepLink' => $deepLink,
                    'ok' => str_contains($deepLink, 'result=ok'),
                    'denied' => str_contains($deepLink, 'result=denied'),
                ])
                ->header('Cache-Control', 'no-store, private')
                ->header('Referrer-Policy', 'no-referrer');
        }

        if (! $request->user()) {
            return redirect()->route('login')->withErrors([
                'kyc' => 'เซสชันหมดอายุระหว่างไปยืนยันที่ Thaiprompt — เข้าสู่ระบบแล้วกดเชื่อมใหม่อีกครั้ง',
            ]);
        }

        try {
            $link = $this->thaiprompt->completeAuthorization(
                $request->user(),
                $request->session(),
                $request->query(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('kyc.index')->withErrors(['kyc' => $e->getMessage()]);
        }

        return redirect()->route('kyc.index')->with(
            'success',
            $link->isApproved()
                ? 'ยืนยันตัวตนสำเร็จด้วยบัญชี Thaiprompt — ใช้บริการที่ต้องยืนยันตัวตนได้ทันที'
                : 'เชื่อมบัญชี Thaiprompt แล้ว — เปิดแอป Thaiprompt เพื่อยืนยันตัวตนให้เสร็จ หน้านี้จะอัปเดตให้เอง',
        );
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $link = $user->thaipromptKycLink;

        if (! $link) {
            return response()->json(['success' => false, 'message' => 'ยังไม่ได้เชื่อมบัญชี Thaiprompt'], 404);
        }

        try {
            $link = $this->thaiprompt->refresh($link);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'thaiprompt' => $link->fresh()?->toOwnerArray(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'thaiprompt' => $link->toOwnerArray(),
            'gate' => $this->gate->statusFor($user),
        ]);
    }
}
