<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyWalletOwnership Middleware.
 *
 * ผ่านได้ 3 ทาง (ทุกทางเริ่มจากลายเซ็นที่ WalletController::verifySignature ตรวจด้วย ecrecover แล้ว):
 * 1. แอปมือถือ: โทเคน X-Wallet-Session ของกระเป๋าใบนี้ (WalletSessionService, 30 วัน)
 * 2. เว็บ: session ของผู้ใช้ที่ผูกกระเป๋าใบนี้ไว้ (ผูกได้เฉพาะหลังเซ็นผ่าน)
 * 3. แคช `wallet_verified:{address}` 4 ชั่วโมง — ทั้งอ่านและเขียนต้องมาจาก IP เดียวกับตอนเซ็น
 */
class VerifyWalletOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * ที่อยู่กระเป๋าอาจมาได้ 3 ทาง: body, query string หรือ route parameter
         *
         * ⚠️ เดิมอ่านแค่ body/query ทำให้ route แบบ /purchases/{walletAddress}
         *    ที่ย้ายมาอยู่หลัง middleware นี้ "ไม่ถูกป้องกันเลย" — middleware
         *    มองไม่เห็นค่า จึงตกไปเส้นทาง fail-open ด้านล่างทุกครั้ง
         */
        $walletAddress = $request->input('wallet_address')
            ?? $request->query('wallet_address')
            ?? $request->route('walletAddress')
            ?? $request->route('wallet_address')
            /*
             * ⚠️ token factory ตั้งชื่อฟิลด์ว่า creator_address ไม่ใช่ wallet_address
             *    middleware จึงหาไม่เจอ แล้วตกเส้นทาง fail-open ด้านล่างทุกครั้ง
             *    ผลคือ POST /api/v1/token-factory/create ยิงได้โดยไม่ต้องพิสูจน์อะไร
             *    และใส่กระเป๋าของคนอื่นเป็นเจ้าของเหรียญได้ด้วย
             */
            ?? $request->input('creator_address');

        /*
         * ส่งมาทั้ง wallet_address และ creator_address แต่คนละกระเป๋า → ปฏิเสธ
         *
         * ⚠️ เดิมใช้ wallet_address พิสูจน์ตัว แล้ว controller เอา creator_address ไปเป็นเจ้าของเหรียญ
         *    = เซ็นด้วยกระเป๋าตัวเอง แต่สร้างเหรียญที่ขึ้นชื่อเหยื่อเป็นผู้สร้างได้
         *    กระเป๋าที่ผ่านด่านนี้คือ "ผู้สร้าง" เสมอ
         */
        $creatorAddress = $request->input('creator_address');
        $claimedWallet = $request->input('wallet_address') ?? $request->query('wallet_address');
        if (is_string($creatorAddress) && $creatorAddress !== ''
            && is_string($claimedWallet) && $claimedWallet !== ''
            && strtolower($creatorAddress) !== strtolower($claimedWallet)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WALLET_MISMATCH',
                    'message' => 'กระเป๋าผู้สร้างต้องเป็นกระเป๋าเดียวกับที่ยืนยันตัวตน',
                ],
            ], 403);
        }

        // If no wallet address in request, let the controller handle it
        if (! $walletAddress) {
            return $next($request);
        }

        // Validate wallet address format
        // is_string ก่อน — ?wallet_address[]=x ส่งมาเป็น array แล้ว preg_match โยน TypeError = 500
        if (! is_string($walletAddress) || ! preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_WALLET',
                    'message' => 'Invalid wallet address format.',
                ],
            ], 422);
        }

        // Normalize to lowercase for consistent lookups
        $normalizedAddress = strtolower($walletAddress);
        $request->merge(['wallet_address' => $normalizedAddress]);

        /*
         * แอปมือถือ: โทเคนเซสชันแบบยาว (ออกให้หลังเซ็นผ่าน ดู WalletSessionService)
         *
         * ใช้แทนแคช 4 ชั่วโมง + IP ได้ทั้งอ่านและเขียน — มือถือเปลี่ยน IP ตลอดและ
         * เปิดแอปวันละหลายรอบ ถ้าบังคับเงื่อนไขของเบราว์เซอร์ ผู้ใช้ต้องไปเซ็นที่
         * TPIX Wallet ใหม่ทุกครั้ง โทเคนต้องเป็นของกระเป๋าเดียวกับที่ขอเท่านั้น
         * โทเคนผิด/หมดอายุไม่ปฏิเสธทันที — ตกไปตรวจแคชตามปกติ (แล้วค่อย 403 ที่นั่น)
         */
        $sessionToken = $request->headers->get(\App\Services\WalletSessionService::HEADER);

        if ($sessionToken && app(\App\Services\WalletSessionService::class)->authorizes($sessionToken, $normalizedAddress)) {
            return $next($request);
        }

        /*
         * เว็บ: เข้าสู่ระบบอยู่ (session หรือคุกกี้จำฉันไว้) และกระเป๋าที่ขอคือกระเป๋าที่ผูกกับบัญชีนั้น
         *
         * กระเป๋าผูกเข้าบัญชีได้ทางเดียวคือหลัง ecrecover ผ่าน (WalletIdentityService::linkVerified)
         * session จึงเป็นหลักฐานที่ผูกกับ "คนที่ถาม" จริง ๆ — ดีกว่าแคช 4 ชั่วโมงที่ผูกกับเลขกระเป๋า
         * คำขอเขียนผ่าน CSRF ของ Sanctum อยู่แล้ว (session มาจาก EnsureFrontendRequestsAreStateful)
         *
         * ⚠️ เดิมเว็บพึ่งแคช + IP อย่างเดียว — มือถือเปลี่ยน IP ตลอด (สลับ Wi-Fi/4G, CGNAT ของค่ายมือถือ)
         *    กดอะไรก็โดน WALLET_IP_MISMATCH และพ้น 4 ชั่วโมงก็ WALLET_NOT_VERIFIED ทั้งที่ยังเข้าสู่ระบบอยู่
         *    ผู้ใช้เห็นว่า "เชื่อมกระเป๋าแล้ว" แต่ทำอะไรไม่ได้ ต้องสลับไปเซ็นในแอปกระเป๋าซ้ำแล้วซ้ำอีก
         */
        $sessionUser = $request->user();

        if ($sessionUser
            && $sessionUser->wallet_address === $normalizedAddress
            && ! $sessionUser->is_banned) {
            return $next($request);
        }

        /*
         * ที่เหลือคือแคชลายเซ็น 4 ชั่วโมง — ใช้ได้เฉพาะจาก IP เดียวกับตอนเซ็น ทั้งอ่านและเขียน
         *
         * ⚠️ เดิม GET ผ่านแค่มีแถวแคช — แถวนั้นผูกกับเลขกระเป๋า ไม่ได้ผูกกับคนที่ถาม
         *    เลขกระเป๋าเห็นได้บนเชน ใครก็ใส่ ?wallet_address= ของคนอื่นแล้วอ่านอีเมล สถานะ KYC
         *    ประวัติเทรด ยอดเงินของเขาได้ตลอด 4 ชั่วโมงหลังเจ้าของเซ็น (ข้อมูลส่วนบุคคล — PDPA)
         *    เว็บผ่านด้วย session ข้างบน แอปผ่านด้วยโทเคน ทางนี้เหลือไว้ให้ผู้เรียกที่มีแค่ลายเซ็น
         *    (แอปรุ่นก่อนมีโทเคน, เว็บที่ session ไม่ติด) — เงื่อนไขเดียวกับที่ใช้เขียนอยู่แล้ว
         *    KycGate::resolveUser() ใช้เงื่อนไขเดียวกัน แก้ที่นี่ต้องแก้ที่นั่นด้วย
         */
        $cacheKey = "wallet_verified:{$normalizedAddress}";
        $verifiedData = Cache::get($cacheKey);

        // บันทึกเฉพาะคำขอเขียนเหมือนเดิม — หน้าเว็บยิง GET ตามรอบ (บางหน้าทุก 15 วินาที)
        // และยิงตั้งแต่เชื่อมกระเป๋าก่อนเซ็น ถ้าบันทึกด้วย ล็อกจะท่วมด้วยเรื่องปกติ
        $isWrite = ! $request->isMethodSafe();

        if (! $verifiedData || ! is_array($verifiedData)) {
            if ($isWrite) {
                Log::warning('Wallet ownership not verified for write operation.', [
                    'wallet' => $normalizedAddress,
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WALLET_NOT_VERIFIED',
                    'message' => 'Wallet ownership not verified. Please reconnect your wallet.',
                ],
            ], 403);
        }

        // IP must match the one used during verification (prevent session hijacking)
        if (($verifiedData['ip'] ?? null) !== $request->ip()) {
            if ($isWrite) {
                Log::warning('Wallet verification IP mismatch.', [
                    'wallet' => $normalizedAddress,
                    'verified_ip' => $verifiedData['ip'] ?? 'unknown',
                    'request_ip' => $request->ip(),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WALLET_IP_MISMATCH',
                    'message' => 'Wallet verification session invalid. Please reconnect.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
