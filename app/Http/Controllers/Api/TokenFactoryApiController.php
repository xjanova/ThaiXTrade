<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FactoryToken;
use App\Models\KycSubmission;
use App\Services\Kyc\KycGate;
use App\Services\TokenFactory\SourceVerifyPurchases;
use App\Services\TokenFactory\TokenFactoryException;
use App\Services\TokenFactoryService;
use App\Services\WalletSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TokenFactoryApiController extends Controller
{
    /** โลโก้ที่ยังไม่ผูกกับเหรียญ เก็บได้กี่ไฟล์ต่อกระเป๋า */
    private const LOGO_KEEP_PER_WALLET = 3;

    public function __construct(
        private TokenFactoryService $tokenFactoryService,
        private KycGate $kycGate,
    ) {}

    /**
     * ดึง Token ที่ deployed และลิสต์แล้ว (public listing) — เฉพาะช่องที่เปิดเผยได้.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $tokens = $this->tokenFactoryService->getDeployedTokens(is_string($search) ? $search : null);

        return response()->json([
            'success' => true,
            'data' => array_map(fn (FactoryToken $t) => $t->toPublicArray(), $tokens->items()),
            'meta' => [
                'current_page' => $tokens->currentPage(),
                'per_page' => $tokens->perPage(),
                'total' => $tokens->total(),
                'last_page' => $tokens->lastPage(),
            ],
        ]);
    }

    /**
     * ดึง Token ของ creator (ผ่าน VerifyWalletOwnership แล้ว — เห็นข้อมูลของตัวเองครบ).
     */
    public function myTokens(Request $request): JsonResponse
    {
        $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        $tokens = $this->tokenFactoryService->getTokensByCreator(
            $request->input('wallet_address')
        );

        return response()->json([
            'success' => true,
            'data' => $tokens->items(),
            'meta' => [
                'current_page' => $tokens->currentPage(),
                'per_page' => $tokens->perPage(),
                'total' => $tokens->total(),
                'last_page' => $tokens->lastPage(),
            ],
        ]);
    }

    /**
     * ขอใบเสนอราคาค่าธรรมเนียม — ตรวจฟอร์ม/นโยบายครบเหมือนตอนสร้างจริง แล้วล็อกยอด.
     *
     * POST /api/v1/token-factory/quote
     */
    public function quote(Request $request): JsonResponse
    {
        $creator = $this->creator($request);
        if ($creator === null) {
            return $this->walletRequired();
        }

        try {
            $prepared = $this->tokenFactoryService->prepare($request->all(), $creator);
            $this->assertKyc($request, $prepared['decision']);

            $quote = $this->tokenFactoryService->quote($prepared, $creator);
        } catch (TokenFactoryException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json(['success' => true, 'data' => $quote->toApiArray()]);
    }

    /**
     * ตรวจสถานะธุรกรรมค่าธรรมเนียม (หน้าเว็บถามซ้ำระหว่างรอบล็อกยืนยัน).
     *
     * POST /api/v1/token-factory/fee-status
     */
    public function feeStatus(Request $request): JsonResponse
    {
        $creator = $this->creator($request);
        if ($creator === null) {
            return $this->walletRequired();
        }

        $request->validate([
            'quote_id' => ['required', 'uuid'],
            'fee_tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
        ], [
            'quote_id.*' => 'รหัสใบเสนอราคาไม่ถูกต้อง',
            'fee_tx_hash.*' => 'รูปแบบ tx hash ไม่ถูกต้อง',
        ]);

        try {
            $status = $this->tokenFactoryService->feeStatus(
                $request->input('quote_id'),
                $request->input('fee_tx_hash'),
                $creator,
            );
        } catch (TokenFactoryException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json(['success' => true, 'data' => array_merge(['confirmed' => true], $status)]);
    }

    /**
     * สร้าง Token ใหม่ — ต้องมีใบเสนอราคา และ (ถ้าไม่ฟรี) ธุรกรรมค่าธรรมเนียมที่ยืนยันบนเชนแล้ว.
     *
     * POST /api/v1/token-factory/create
     */
    public function store(Request $request): JsonResponse
    {
        $creator = $this->creator($request);
        if ($creator === null) {
            return $this->walletRequired();
        }

        try {
            $prepared = $this->tokenFactoryService->prepare($request->all(), $creator, forCreate: true);
            $this->assertKyc($request, $prepared['decision']);

            $token = $this->tokenFactoryService->createToken($prepared, $creator);
        } catch (TokenFactoryException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json([
            'success' => true,
            'data' => $token,
            'message' => $token->status === 'deploying'
                ? 'อนุมัติอัตโนมัติแล้ว กำลังสร้างเหรียญบนเชน'
                : 'ส่งคำขอสร้างเหรียญแล้ว รอผู้ดูแลตรวจสอบ',
        ], 201);
    }

    /**
     * ใบเสนอราคาค่ายืนยันซอร์สโค้ดบน explorer ของเหรียญที่ deploy แล้ว (เจ้าของเท่านั้น).
     *
     * POST /api/v1/token-factory/{id}/source-verify/quote
     */
    public function sourceVerifyQuote(Request $request, SourceVerifyPurchases $purchases, int $id): JsonResponse
    {
        $creator = $this->creator($request);
        if ($creator === null) {
            return $this->walletRequired();
        }

        try {
            $quote = $purchases->quote(FactoryToken::findOrFail($id), $creator);
        } catch (TokenFactoryException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json(['success' => true, 'data' => $quote->toApiArray()]);
    }

    /**
     * ส่งธุรกรรมค่ายืนยันซอร์ส — ตรวจบนเชนแล้วสั่ง explorer ยืนยัน.
     *
     * POST /api/v1/token-factory/{id}/source-verify
     * ยืนยันไม่ครบบล็อก → 202 FEE_PENDING ให้หน้าเว็บถามซ้ำ (หรือถาม /fee-status ด้วยใบเสนอราคาเดียวกัน)
     */
    public function sourceVerifyPurchase(Request $request, SourceVerifyPurchases $purchases, int $id): JsonResponse
    {
        $creator = $this->creator($request);
        if ($creator === null) {
            return $this->walletRequired();
        }

        $request->validate([
            'quote_id' => ['required', 'uuid'],
            'fee_tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
        ], [
            'quote_id.*' => 'รหัสใบเสนอราคาไม่ถูกต้อง',
            'fee_tx_hash.*' => 'รูปแบบ tx hash ไม่ถูกต้อง',
        ]);

        try {
            $token = $purchases->purchase(
                FactoryToken::findOrFail($id),
                $request->input('quote_id'),
                $request->input('fee_tx_hash'),
                $creator,
            );
        } catch (TokenFactoryException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json([
            'success' => true,
            'data' => $token,
            'message' => 'ชำระค่ายืนยันซอร์สแล้ว — ระบบกำลังส่งซอร์สโค้ดให้ explorer ตรวจ (ใช้เวลาไม่กี่นาที)',
        ]);
    }

    /**
     * รายละเอียด Token.
     *
     * คนทั่วไปเห็นเฉพาะช่องที่เปิดเผยได้ — metadata (deploy_error/stderr, ตัวเลือกย่อย,
     * เหตุผลที่ถูกปฏิเสธ, ข้อมูลค่าธรรมเนียม) เห็นได้เฉพาะเจ้าของคำขอ (กระเป๋าที่ยืนยันแล้ว) และแอดมิน
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $token = FactoryToken::with('chain')->find($id);

        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'ไม่พบเหรียญนี้'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->canSeeFullDetails($request, $token) ? $token : $token->toPublicArray(),
        ]);
    }

    /**
     * ดึง factory config สำหรับ frontend — นโยบายที่ใช้จริง (ประเภท/ตัวเลือกที่เปิด ค่าธรรมเนียม เพดาน คำเตือน).
     */
    public function config(): JsonResponse
    {
        $config = $this->tokenFactoryService->getFactoryConfig();
        $readiness = $this->tokenFactoryService->isFactoryReady();

        return response()->json([
            'success' => true,
            'data' => array_merge($config, [
                'ready' => $readiness['ready'],
                'issues' => $readiness['issues'],
                'awaiting_deploy' => $readiness['awaiting_deploy'],
            ]),
        ]);
    }

    /**
     * คำนวณค่าธรรมเนียมตามนโยบาย (แหล่งเดียวกับใบเสนอราคา).
     *
     * POST /api/v1/token-factory/calculate-fee
     */
    public function calculateFee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token_type' => 'required|string|max:30',
            'sub_options' => 'nullable|array',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->tokenFactoryService->calculateFee($validated),
        ]);
    }

    /**
     * Upload logo สำหรับ token (ก่อนหรือหลังสร้าง).
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        /*
         * ⛔ ห้ามรับ svg เด็ดขาด
         * SVG ไม่ใช่รูปภาพเฉย ๆ — มันคือ XML ที่ฝัง <script> ได้ พอเก็บลง disk
         * "public" แล้วคืน /storage/... ออกไป = ผู้ใช้คนไหนก็ฝัง JS ลงบนโดเมนเราเอง
         * CSP ใน public_html/.htaccess เป็น script-src 'self' 'unsafe-inline'
         * จึงกันไม่ได้ ทางเดียวคือไม่รับไฟล์ชนิดนี้ตั้งแต่ต้นทาง
         */
        $validated = $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            /*
             * ต้องบังคับที่นี่ ไม่ใช่พึ่ง VerifyWalletOwnership อย่างเดียว
             * middleware ตัวนั้น "ปล่อยผ่าน" เมื่อหาที่อยู่กระเป๋าในคำขอไม่เจอ
             * และหน้าเว็บเดิมไม่เคยส่งฟิลด์นี้มาเลย = ด่านกระเป๋าไม่เคยทำงานกับ
             * route นี้สักครั้ง ใครโหลดหน้าเว็บได้ก็อัปไฟล์ขึ้นโดเมนได้
             */
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        /*
         * แยกโฟลเดอร์ตามกระเป๋า แล้วเก็บย้อนหลังแค่ LOGO_KEEP_PER_WALLET ไฟล์
         *
         * เดิมกองรวมใน token-logos/ ไม่ผูกกับใคร ไม่มีใครลบ → อัปกี่ไฟล์ก็ค้างถาวร
         * เครื่องนี้เป็นเว็บเซิร์ฟเวอร์รวมที่มีเว็บอื่นอีกหลายสิบเว็บ ดิสก์เต็ม =
         * ล่มทั้งเครื่อง ไม่ใช่แค่ TPIX
         *
         * ⚠️ ด่าน kyc:token_factory ที่ route ยังไม่กันอะไรจนกว่าจะเปิด KYC ที่
         *    /admin/kyc (KycGate::requires() คืน false เมื่อสวิตช์ใหญ่ปิด)
         *    เพดานต่อกระเป๋าตรงนี้จึงเป็นตัวจำกัดดิสก์ตัวเดียวที่ทำงานอยู่ตอนนี้
         */
        $wallet = strtolower($validated['wallet_address']);
        $dir = 'token-logos/'.$wallet;
        $disk = Storage::disk('public');

        /*
         * ไฟล์ที่เหรียญซึ่ง deploy แล้วใช้อยู่ไม่นับ/ไม่ลบ — เดิมผู้สร้างอัปรูปใหม่ 3 ครั้ง
         * (เช่นเตรียมเหรียญตัวถัดไป) โลโก้ของเหรียญที่ลิสต์แล้วก็หายไปเงียบ ๆ
         * ทั้งหน้าเว็บและ /tokenlist.json ได้ลิงก์ 404 (ที่ deploy แล้วต้องผ่านแอดมินอนุมัติ
         * + จ่ายค่าธรรมเนียม จึงใช้ช่องนี้ถมดิสก์ไม่ได้)
         */
        $inUse = FactoryToken::query()
            ->where('creator_address', $wallet)
            ->where('status', 'deployed')
            ->whereNotNull('logo_url')
            ->pluck('logo_url')
            ->map(fn (string $url) => ltrim(preg_replace('#^/?storage/#', '', $url) ?? '', '/'))
            ->flip();

        $stale = collect($disk->files($dir))
            ->reject(fn (string $file) => $inUse->has($file))
            ->sortByDesc(fn (string $file) => $disk->lastModified($file))
            ->slice(self::LOGO_KEEP_PER_WALLET - 1);

        foreach ($stale as $file) {
            $disk->delete($file);
        }

        $path = $request->file('logo')->store($dir, 'public');

        return response()->json([
            'success' => true,
            'data' => [
                'logo_url' => '/storage/'.$path,
                'path' => $path,
            ],
        ]);
    }

    // =========================================================================
    //  ภายใน
    // =========================================================================

    /**
     * กระเป๋าที่พิสูจน์ความเป็นเจ้าของแล้ว = ผู้สร้างเหรียญ.
     *
     * VerifyWalletOwnership ใส่ wallet_address (ตัวพิมพ์เล็ก) ให้ทุกคำขอที่ผ่านด่าน
     * และปฏิเสธเองถ้า wallet_address กับ creator_address ไม่ตรงกัน — ห้ามอ่าน creator_address ตรง ๆ
     */
    private function creator(Request $request): ?string
    {
        $wallet = $request->input('wallet_address');

        return is_string($wallet) && preg_match('/^0x[a-f0-9]{40}$/', $wallet) ? $wallet : null;
    }

    private function walletRequired(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => 'WALLET_REQUIRED', 'message' => 'กรุณาเชื่อมต่อและยืนยันกระเป๋าก่อนสร้างเหรียญ'],
        ], 422);
    }

    /**
     * นโยบายสั่ง "ต้อง KYC" — บังคับเอง ไม่ขึ้นกับสวิตช์ใหญ่ของ /admin/kyc.
     *
     * @throws TokenFactoryException
     */
    private function assertKyc(Request $request, array $decision): void
    {
        if (! $decision['require_kyc']) {
            return;
        }

        $user = $this->kycGate->resolveUser($request);

        if (! $user || ! $this->kycGate->satisfiesLevel($user, KycSubmission::LEVEL_BASIC)) {
            throw new TokenFactoryException('KYC_REQUIRED', 'ต้องยืนยันตัวตน (KYC) ก่อนสร้างเหรียญประเภทนี้', 403);
        }
    }

    private function canSeeFullDetails(Request $request, FactoryToken $token): bool
    {
        if (Auth::guard('admin')->check()) {
            return true;
        }

        $wallet = strtolower((string) $request->query('wallet_address', ''));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet) || ! hash_equals((string) $token->creator_address, $wallet)) {
            return false;
        }

        /*
         * ต้องเป็นโทเคนเซสชันของกระเป๋าเท่านั้น
         *
         * ⚠️ ห้ามดูแคช wallet_verified: — แคชนั้นผูกกับกระเป๋า ไม่ได้ผูกกับคนที่ถาม
         *    ใครรู้เลขกระเป๋าของผู้สร้าง (เห็นได้บนเชน) ก็ใส่ ?wallet_address= แล้วอ่าน metadata ได้
         *    ตลอดช่วงที่แคชของเจ้าของยังไม่หมดอายุ — ผู้สร้างบนเว็บดูรายละเอียดเต็มที่ /my-tokens อยู่แล้ว
         */
        $session = $request->headers->get(WalletSessionService::HEADER);

        return $session !== null && app(WalletSessionService::class)->authorizes($session, $wallet);
    }
}
