<?php

namespace App\Http\Middleware;

use App\Services\WalletSessionService;
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
 *
 * ตัวนับ throttle:trading (AppServiceProvider::tradingLimit) ใช้ requestedWallet() + proves() ชุดเดียวกันนี้
 */
class VerifyWalletOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $walletAddress = self::requestedWallet($request);

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

        /*
         * มีเลขกระเป๋าใน path แล้วยังส่ง wallet_address มาเป็นอีกใบ → ปฏิเสธ
         *
         * ⚠️ เดิม requestedWallet() เลือก body/query ก่อน path และไม่เคยเทียบสองค่านี้ แต่ controller อ่านจาก path
         *    = เซ็นกระเป๋าตัวเอง A แล้วยิง GET /token-sale/purchases/{เหยื่อ}?wallet_address={A}
         *    ด่านตรวจ A (ผ่าน) ส่วน controller ส่งยอดซื้อ tx hash uuid เลขอ้างอิงโอนธนาคาร
         *    และชื่อผู้รับประโยชน์ของเหยื่อกลับมา (ข้อมูลส่วนบุคคล — PDPA)
         *    ตอนนี้กระเป๋าใน path คือกระเป๋าที่ต้องพิสูจน์ ค่าอื่นที่ส่งมาต้องเป็นใบเดียวกัน
         *    (array หรือค่าแปลกๆ ก็ถือว่าไม่ตรง — ห้ามเดาว่าตั้งใจหมายถึงใบไหน)
         */
        $routeWallet = self::routeWallet($request);
        if (is_string($routeWallet) && $routeWallet !== ''
            && $claimedWallet !== null && $claimedWallet !== ''
            && ! (is_string($claimedWallet) && strtolower($claimedWallet) === strtolower($routeWallet))) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WALLET_MISMATCH',
                    'message' => 'กระเป๋าในลิงก์ต้องเป็นกระเป๋าเดียวกับที่ยืนยันตัวตน',
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

        if (self::proves($request, $normalizedAddress)) {
            return $next($request);
        }

        return $this->reject($request, $normalizedAddress);
    }

    /**
     * กระเป๋าที่คำขออ้างถึง — ค่าดิบ ยังไม่ตรวจรูปแบบ (อาจเป็น null หรือ array ก็ได้).
     *
     * ที่อยู่กระเป๋าอาจมาได้ 3 ทาง: body, query string หรือ route parameter
     *
     * ⚠️ เดิมอ่านแค่ body/query ทำให้ route แบบ /purchases/{walletAddress}
     *    ที่ย้ายมาอยู่หลัง middleware นี้ "ไม่ถูกป้องกันเลย" — middleware
     *    มองไม่เห็นค่า จึงตกไปเส้นทาง fail-open ใน handle() ทุกครั้ง
     *
     * ลำดับ: path ก่อน body/query — controller ของ route แบบนี้อ่านกระเป๋าจาก path เสมอ
     * กระเป๋าที่ต้องพิสูจน์จึงต้องเป็นใบใน path (handle() ปฏิเสธเมื่อ body/query ส่งมาคนละใบ)
     *
     * ⚠️ เดิมเลือก body/query ก่อน path — ส่ง ?wallet_address= ของตัวเองแนบ path ของเหยื่อ
     *    ด่านก็ตรวจกระเป๋าเรา แต่ controller ส่งข้อมูลของเหยื่อกลับมา (IDOR)
     *
     * ตัวนับ throttle:trading หากระเป๋าด้วยลำดับนี้ด้วย — ถ้าหาคนละแบบ คำขอของเจ้าของจะไปลงถังผิดใบ
     */
    public static function requestedWallet(Request $request): mixed
    {
        return self::routeWallet($request)
            ?? $request->input('wallet_address')
            ?? $request->query('wallet_address')
            /*
             * ⚠️ token factory ตั้งชื่อฟิลด์ว่า creator_address ไม่ใช่ wallet_address
             *    middleware จึงหาไม่เจอ แล้วตกเส้นทาง fail-open ใน handle() ทุกครั้ง
             *    ผลคือ POST /api/v1/token-factory/create ยิงได้โดยไม่ต้องพิสูจน์อะไร
             *    และใส่กระเป๋าของคนอื่นเป็นเจ้าของเหรียญได้ด้วย
             */
            ?? $request->input('creator_address');
    }

    /**
     * เลขกระเป๋าใน path ของ route (/purchases/{walletAddress} ฯลฯ) — null ถ้า route ไม่มี.
     */
    private static function routeWallet(Request $request): mixed
    {
        return $request->route('walletAddress') ?? $request->route('wallet_address');
    }

    /**
     * คำขอนี้พิสูจน์ได้ไหมว่าเป็นเจ้าของ $wallet — 3 ทางตามหัวไฟล์ ไม่บันทึกล็อก ไม่ตอบอะไร.
     *
     * ⚠️ ตัวนับ throttle:trading เรียกตัวนี้ก่อนด่านจะทำงาน (throttle รันก่อนเสมอ) เพื่อเลือกถังที่นับ
     *    กฎความเป็นเจ้าของต้องมีที่เดียว — ถ้าตัวนับกับด่านตัดสินต่างกัน คนที่ไม่ใช่เจ้าของจะกลับมา
     *    กินโควตาของเจ้าของได้อีก
     */
    public static function proves(Request $request, string $wallet): bool
    {
        $wallet = strtolower($wallet);

        /*
         * แอปมือถือ: โทเคนเซสชันแบบยาว (ออกให้หลังเซ็นผ่าน ดู WalletSessionService)
         *
         * ใช้แทนแคช 4 ชั่วโมง + IP ได้ทั้งอ่านและเขียน — มือถือเปลี่ยน IP ตลอดและ
         * เปิดแอปวันละหลายรอบ ถ้าบังคับเงื่อนไขของเบราว์เซอร์ ผู้ใช้ต้องไปเซ็นที่
         * TPIX Wallet ใหม่ทุกครั้ง โทเคนต้องเป็นของกระเป๋าเดียวกับที่ขอเท่านั้น
         * โทเคนผิด/หมดอายุไม่ปฏิเสธทันที — ตกไปตรวจแคชตามปกติ (แล้วค่อย 403 ที่นั่น)
         */
        $sessionToken = $request->headers->get(WalletSessionService::HEADER);

        if ($sessionToken && app(WalletSessionService::class)->authorizes($sessionToken, $wallet)) {
            return true;
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
            && $sessionUser->wallet_address === $wallet
            && ! $sessionUser->is_banned) {
            return true;
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
        $verifiedData = Cache::get("wallet_verified:{$wallet}");

        // IP must match the one used during verification (prevent session hijacking)
        return is_array($verifiedData) && ($verifiedData['ip'] ?? null) === $request->ip();
    }

    /**
     * ไม่ผ่านทุกทาง — บอกเหตุผลตามแถวแคชลายเซ็น (ไม่เคยเซ็น/หมดอายุ หรือเซ็นมาจาก IP อื่น).
     */
    private function reject(Request $request, string $wallet): Response
    {
        $verifiedData = Cache::get("wallet_verified:{$wallet}");

        // บันทึกเฉพาะคำขอเขียนเหมือนเดิม — หน้าเว็บยิง GET ตามรอบ (บางหน้าทุก 15 วินาที)
        // และยิงตั้งแต่เชื่อมกระเป๋าก่อนเซ็น ถ้าบันทึกด้วย ล็อกจะท่วมด้วยเรื่องปกติ
        $isWrite = ! $request->isMethodSafe();

        if (! $verifiedData || ! is_array($verifiedData)) {
            if ($isWrite) {
                Log::warning('Wallet ownership not verified for write operation.', [
                    'wallet' => $wallet,
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

        // มีแถวแคชแต่ proves() ไม่ผ่าน = เซ็นมาจาก IP อื่น
        if ($isWrite) {
            Log::warning('Wallet verification IP mismatch.', [
                'wallet' => $wallet,
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
}
