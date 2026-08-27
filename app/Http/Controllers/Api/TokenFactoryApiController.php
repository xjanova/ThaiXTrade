<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\FactoryToken;
use App\Models\SiteSetting;
use App\Services\TokenFactoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TokenFactoryApiController extends Controller
{
    /** โลโก้ที่ยังไม่ผูกกับเหรียญ เก็บได้กี่ไฟล์ต่อกระเป๋า */
    private const LOGO_KEEP_PER_WALLET = 3;

    public function __construct(
        private TokenFactoryService $tokenFactoryService,
    ) {}

    /**
     * ดึง Token ที่ deployed (public listing).
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $tokens = $this->tokenFactoryService->getDeployedTokens($search);

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
     * ดึง Token ของ creator.
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
     * สร้าง Token ใหม่.
     *
     * ตรวจสอบ: creation_enabled, fee_wallet, symbol uniqueness, max supply
     */
    public function store(Request $request): JsonResponse
    {
        // เช็คว่าระบบเปิดให้สร้างหรือไม่
        if (! filter_var(SiteSetting::get('factory', 'creation_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FACTORY_DISABLED', 'message' => 'Token creation is currently disabled.'],
            ], 403);
        }

        // ตรวจว่า factory พร้อมหรือไม่ (fee_wallet ตั้งค่าแล้ว)
        $readiness = $this->tokenFactoryService->isFactoryReady();
        if (! $readiness['ready']) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FACTORY_NOT_READY',
                    'message' => 'Token Factory is not available yet. '.implode('. ', $readiness['issues']),
                ],
            ], 503);
        }

        $maxSupply = (float) SiteSetting::get('factory', 'max_supply_limit', 999999999999999);
        $nftEnabled = filter_var(SiteSetting::get('factory', 'nft_enabled', true), FILTER_VALIDATE_BOOLEAN);

        $allowedTypes = ['standard', 'mintable', 'burnable', 'mintable_burnable', 'utility', 'reward'];
        if ($nftEnabled) {
            $allowedTypes = array_merge($allowedTypes, ['nft', 'nft_collection']);
        }
        // governance & stablecoin ต้องผ่าน admin review เสมอ
        $allowedTypes = array_merge($allowedTypes, ['governance', 'stablecoin']);

        // รวม testnet chain IDs เข้ากับ chains table สำหรับ validation
        $testnetChainIds = config('blockchain.testnet_chain_ids', [4290, 11155111, 97]);

        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'symbol' => [
                'required', 'string', 'min:2', 'max:20', 'alpha_num',
                function (string $attribute, mixed $value, \Closure $fail) {
                    // Case-insensitive uniqueness check (ไม่นับ rejected/failed)
                    $exists = FactoryToken::whereRaw('UPPER(symbol) = ?', [strtoupper($value)])
                        ->whereNotIn('status', ['rejected', 'failed'])
                        ->exists();
                    if ($exists) {
                        $fail("Token symbol {$value} already exists or is pending approval.");
                    }
                },
            ],
            'decimals' => 'integer|min:0|max:18',
            'total_supply' => "required|numeric|min:1|max:{$maxSupply}",
            'creator_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'chain_id' => ['nullable', 'integer', function ($attr, $val, $fail) use ($testnetChainIds) {
                // อนุญาต testnet chain IDs แม้ไม่อยู่ใน chains table
                if ($val && ! in_array((int) $val, $testnetChainIds, true)) {
                    $existsInDb = Chain::where('chain_id', $val)->exists();
                    if (! $existsInDb) {
                        $fail("Chain ID {$val} is not supported.");
                    }
                }
            }],
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url:https|max:255',
            'logo_url' => ['nullable', 'string', 'max:500', function ($attr, $val, $fail) {
                // อนุญาตทั้ง /storage/... path (จาก upload) และ https:// URL
                if ($val && ! str_starts_with($val, '/storage/') && ! str_starts_with($val, 'https://')) {
                    $fail('Logo must be an uploaded file or HTTPS URL.');
                }
            }],
            'token_type' => 'required|in:'.implode(',', $allowedTypes),
            'token_category' => 'nullable|in:fungible,nft,special',
            'fee_tx_hash' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],

            // Sub-options สำหรับ advanced token features (Phase 1: เก็บ data, Phase 2: deploy จริง)
            'sub_options' => 'nullable|array',
            'sub_options.*' => 'nullable',
        ]);

        try {
            $token = $this->tokenFactoryService->createToken($validated);

            return response()->json([
                'success' => true,
                'data' => $token,
                'message' => $token->status === 'deploying'
                    ? 'Token auto-approved and deployment started!'
                    : 'Token creation request submitted for review.',
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FACTORY_ERROR', 'message' => $e->getMessage()],
            ], 503);
        }
    }

    /**
     * ดึงรายละเอียด Token.
     */
    public function show(int $id): JsonResponse
    {
        $token = FactoryToken::with('chain')->find($id);

        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Token not found.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $token,
        ]);
    }

    /**
     * ดึง factory config สำหรับ frontend.
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
            ]),
        ]);
    }

    /**
     * คำนวณค่าธรรมเนียมแบบ dynamic ตาม options ที่เลือก.
     *
     * POST /api/v1/token-factory/calculate-fee
     */
    public function calculateFee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token_category' => 'required|in:fungible,nft,special',
            'token_type' => 'required|string',
            'decimals' => 'nullable|integer|min:0|max:18',
            'total_supply' => 'nullable|numeric|min:0',
            'chain_id' => 'nullable|integer',
            'sub_options' => 'nullable|array',
        ]);

        $fee = $this->tokenFactoryService->calculateFee($validated);

        return response()->json([
            'success' => true,
            'data' => $fee,
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

        $stale = collect($disk->files($dir))
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
}
