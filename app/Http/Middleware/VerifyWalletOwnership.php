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
 * 3. แคช `wallet_verified:{address}` 4 ชั่วโมง — คำขอเขียนต้องมาจาก IP เดียวกับตอนเซ็น
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

        // SECURITY FIX: GET requests ก็ต้องตรวจ verified session
        // เพราะ route group นี้มีข้อมูลส่วนตัว (orders, balances, transactions)
        // ป้องกัน attacker query wallet_address ของคนอื่น
        // GET requests ไม่ต้อง check IP (เพราะเป็น read-only)
        if ($request->isMethod('GET')) {
            $cacheKey = "wallet_verified:{$normalizedAddress}";
            $verifiedData = Cache::get($cacheKey);
            if (! $verifiedData || ! is_array($verifiedData)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'WALLET_NOT_VERIFIED',
                        'message' => 'Wallet ownership not verified.',
                    ],
                ], 403);
            }

            return $next($request);
        }

        // Write requests (POST/PUT/DELETE): require signature verification
        // Check 1: Must have a verified session from WalletController::verifySignature()
        $cacheKey = "wallet_verified:{$normalizedAddress}";
        $verifiedData = Cache::get($cacheKey);

        if (! $verifiedData || ! is_array($verifiedData)) {
            Log::warning('Wallet ownership not verified for write operation.', [
                'wallet' => $normalizedAddress,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WALLET_NOT_VERIFIED',
                    'message' => 'Wallet ownership not verified. Please reconnect your wallet.',
                ],
            ], 403);
        }

        // Check 2: IP must match the one used during verification (prevent session hijacking)
        if (($verifiedData['ip'] ?? null) !== $request->ip()) {
            Log::warning('Wallet verification IP mismatch.', [
                'wallet' => $normalizedAddress,
                'verified_ip' => $verifiedData['ip'] ?? 'unknown',
                'request_ip' => $request->ip(),
            ]);

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
