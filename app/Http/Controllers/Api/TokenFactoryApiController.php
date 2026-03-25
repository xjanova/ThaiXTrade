<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FactoryToken;
use App\Models\SiteSetting;
use App\Services\TokenFactoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenFactoryApiController extends Controller
{
    public function __construct(
        private TokenFactoryService $tokenFactoryService,
    ) {}

    /**
     * ดึง Token ที่ deployed (public listing).
     */
    public function index(): JsonResponse
    {
        $tokens = $this->tokenFactoryService->getDeployedTokens();

        return response()->json([
            'success' => true,
            'data' => $tokens->items(),
            'meta' => [
                'current_page' => $tokens->currentPage(),
                'per_page' => $tokens->perPage(),
                'total' => $tokens->total(),
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
            ],
        ]);
    }

    /**
     * สร้าง Token ใหม่.
     */
    public function store(Request $request): JsonResponse
    {
        // เช็คว่าระบบเปิดให้สร้างหรือไม่
        if (! SiteSetting::get('factory', 'creation_enabled', true)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FACTORY_DISABLED', 'message' => 'Token creation is currently disabled.'],
            ], 403);
        }

        $maxSupply = (float) SiteSetting::get('factory', 'max_supply_limit', 999999999999999);
        $nftEnabled = SiteSetting::get('factory', 'nft_enabled', true);

        $allowedTypes = ['standard', 'mintable', 'burnable', 'mintable_burnable', 'governance', 'stablecoin', 'utility', 'reward'];
        if ($nftEnabled) {
            $allowedTypes = array_merge($allowedTypes, ['nft', 'nft_collection']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'symbol' => [
                'required', 'string', 'max:20', 'alpha_num',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $exists = FactoryToken::where('symbol', strtoupper($value))
                        ->whereNotIn('status', ['rejected', 'failed'])
                        ->exists();
                    if ($exists) {
                        $fail("Token symbol {$value} already exists.");
                    }
                },
            ],
            'decimals' => 'integer|min:0|max:18',
            'total_supply' => "required|numeric|min:1|max:{$maxSupply}",
            'creator_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'chain_id' => 'integer|exists:chains,chain_id',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url:https|max:255',
            'logo_url' => 'nullable|url:https|max:500',
            'token_type' => 'in:'.implode(',', $allowedTypes),
            'token_category' => 'in:fungible,nft,special',
        ]);

        $token = $this->tokenFactoryService->createToken($validated);

        return response()->json([
            'success' => true,
            'data' => $token,
            'message' => 'Token creation request submitted successfully.',
        ], 201);
    }

    /**
     * ดึงรายละเอียด Token.
     */
    public function show(int $id): JsonResponse
    {
        $token = FactoryToken::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $token,
        ]);
    }
}
