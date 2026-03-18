<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FactoryToken;
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
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'symbol' => 'required|string|max:20|alpha_num',
            'decimals' => 'integer|min:0|max:18',
            'total_supply' => 'required|numeric|min:1',
            'creator_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'chain_id' => 'integer|exists:chains,chain_id',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:255',
            'token_type' => 'in:standard,mintable,burnable,mintable_burnable',
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
