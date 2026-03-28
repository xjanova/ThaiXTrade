<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FactoryToken;
use App\Models\SiteSetting;
use App\Services\TokenFactoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TokenFactoryApiController extends Controller
{
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
            'chain_id' => 'nullable|integer',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url:https|max:255',
            'logo_url' => 'nullable|url:https|max:500',
            'token_type' => 'required|in:'.implode(',', $allowedTypes),
            'token_category' => 'nullable|in:fungible,nft,special',
            'fee_tx_hash' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
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
     * Upload logo สำหรับ token (ก่อนหรือหลังสร้าง).
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
        ]);

        $path = $request->file('logo')->store('token-logos', 'public');

        return response()->json([
            'success' => true,
            'data' => [
                'logo_url' => '/storage/'.$path,
                'path' => $path,
            ],
        ]);
    }
}
