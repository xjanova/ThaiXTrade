<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\UserWalletService;
use App\Services\Web3BalanceService;
use Elliptic\EC;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use kornrunner\Keccak;
use Throwable;

/**
 * WalletController.
 *
 * Handles wallet connection, real balance queries from blockchain RPC,
 * and transaction history for the TPIX TRADE DEX platform.
 */
class WalletController extends Controller
{
    public function __construct(
        private Web3BalanceService $balanceService,
        private UserWalletService $userWalletService,
    )
    {
        //
    }

    /**
     * Record a wallet connection event.
     *
     * POST /api/v1/wallet/connect
     */
    public function connect(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'chain_id' => ['required', 'integer'],
            'wallet_type' => ['nullable', 'string', 'in:metamask,trustwallet,coinbase,walletconnect,okx,tpix_wallet'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid wallet address format.',
                ],
            ], 422);
        }

        $validated = $validator->validated();

        // สมัครสมาชิกอัตโนมัติ (หรือหา user ที่มีอยู่)
        $user = $this->userWalletService->findOrCreateByWallet(
            $validated['wallet_address'],
            $validated['chain_id'],
            $validated['wallet_type'] ?? 'metamask',
            $request->ip()
        );

        // SECURITY FIX: ทุก wallet ต้อง verify ผ่าน requestSignature() → verifySignature()
        // ลบ auto-verify สำหรับ tpix_wallet เพราะ attacker สามารถส่ง wallet_type=tpix_wallet
        // ด้วย address ของเหยื่อ แล้วได้ verified session 24 ชม. ทันที
        // Mobile app ต้องเรียก verifyWithBackend() หลัง connect เสมอ

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $validated['wallet_address'],
                'chain_id' => $validated['chain_id'],
                'connected_at' => now()->toIso8601String(),
                'user_id' => $user->id,
                'is_new' => $user->wasRecentlyCreated,
                'referral_code' => $user->referral_code,
            ],
        ]);
    }

    /**
     * Record a wallet disconnection event.
     *
     * POST /api/v1/wallet/disconnect
     */
    public function disconnect(Request $request): JsonResponse
    {
        // Clear verified wallet cache on disconnect
        $walletAddress = $request->input('wallet_address');
        if ($walletAddress && preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
            Cache::forget('wallet_verified:'.strtolower($walletAddress));
        }

        return response()->json([
            'success' => true,
            'data' => [
                'disconnected_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get real token balances for a wallet address from blockchain RPC.
     *
     * GET /api/v1/wallet/balances?wallet_address=0x...&chain_id=56
     */
    public function balances(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'chain_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid parameters.',
                ],
            ], 422);
        }

        $walletAddress = $request->input('wallet_address');
        $chainId = $request->integer('chain_id', 56);

        $balances = $this->balanceService->getWalletBalances($walletAddress, $chainId);

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $walletAddress,
                'chain_id' => $chainId,
                'balances' => $balances,
                'fetched_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get transaction history for a wallet address.
     *
     * GET /api/v1/wallet/transactions?wallet_address=0x...
     */
    public function transactions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid parameters.',
                ],
            ], 422);
        }

        $limit = $request->input('limit', 20);
        $walletAddress = $request->input('wallet_address');

        $transactions = Transaction::where('wallet_address', strtolower($walletAddress))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->uuid ?? $tx->id,
                    'type' => $tx->type,
                    'from_token' => $tx->from_token,
                    'to_token' => $tx->to_token,
                    'from_amount' => $tx->from_amount,
                    'to_amount' => $tx->to_amount,
                    'fee_amount' => $tx->fee_amount,
                    'tx_hash' => $tx->tx_hash,
                    'status' => $tx->status,
                    'created_at' => $tx->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    /**
     * Get user profile + preferences for cross-device sync.
     *
     * GET /api/v1/wallet/profile?wallet_address=0x...
     * Requires verified wallet (via VerifyWalletOwnership middleware).
     */
    public function getProfile(Request $request): JsonResponse
    {
        $walletAddress = strtolower($request->input('wallet_address'));

        $user = User::where('wallet_address', $walletAddress)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => 'User not found. Please connect wallet first.',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $user->wallet_address,
                'email' => $user->email,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'is_verified' => (bool) $user->is_verified,
                'kyc_status' => $user->kyc_status,
                'referral_code' => $user->referral_code,
                'total_trades' => (int) $user->total_trades,
                'total_volume_usd' => (float) $user->total_volume_usd,
                'preferences' => $user->preferences ?? [],
                'created_at' => $user->created_at?->toIso8601String(),
                'last_active_at' => $user->last_active_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update user profile + preferences (write op — requires signature).
     *
     * PUT /api/v1/wallet/profile
     * Body: { wallet_address, name?, email?, avatar?, preferences? }
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $walletAddress = strtolower($request->input('wallet_address'));

        $user = User::where('wallet_address', $walletAddress)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => 'User not found. Please connect wallet first.',
                ],
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:50'],
            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'avatar' => ['nullable', 'string', 'max:500'],
            'preferences' => ['nullable', 'array'],
            'preferences.language' => ['nullable', 'string', 'in:en,th'],
            'preferences.theme' => ['nullable', 'string', 'in:dark,light,auto'],
            'preferences.default_chain_id' => ['nullable', 'integer'],
            'preferences.notifications' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()->toArray(),
                ],
            ], 422);
        }

        $validated = $validator->validated();

        // Merge preferences (don't overwrite — partial update)
        if (isset($validated['preferences'])) {
            $existing = $user->preferences ?? [];
            $validated['preferences'] = array_merge($existing, $validated['preferences']);
        }

        // Only update fields that were sent (allow clearing with explicit null)
        $updates = array_intersect_key($validated, array_flip(['name', 'email', 'avatar', 'preferences']));

        if (! empty($updates)) {
            $user->update($updates);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $user->wallet_address,
                'email' => $user->email,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'preferences' => $user->preferences ?? [],
                'updated_at' => $user->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Generate a signature request message for wallet verification.
     * Stores nonce in cache for validation against replay attacks.
     *
     * POST /api/v1/wallet/sign
     */
    public function requestSignature(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid wallet address.',
                ],
            ], 422);
        }

        $walletAddress = strtolower($request->input('wallet_address'));
        $nonce = bin2hex(random_bytes(16));
        $timestamp = now()->toIso8601String();
        $message = "TPIX TRADE: Sign this message to verify your wallet.\n\nNonce: {$nonce}\nTimestamp: {$timestamp}";

        // Invalidate any previous nonce for this wallet, then store new one
        $previousNonce = Cache::get("wallet_active_nonce:{$walletAddress}");
        if ($previousNonce) {
            Cache::forget("wallet_nonce:{$walletAddress}:{$previousNonce}");
        }
        Cache::put("wallet_active_nonce:{$walletAddress}", $nonce, 300);
        Cache::put("wallet_nonce:{$walletAddress}:{$nonce}", $timestamp, 300);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => $message,
                'nonce' => $nonce,
            ],
        ]);
    }

    /**
     * Verify a signed message to prove wallet ownership.
     * Uses Ethereum ecrecover to validate the signature.
     *
     * POST /api/v1/wallet/verify-signature
     */
    public function verifySignature(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'signature' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{130}$/'],
            'nonce' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid parameters.',
                ],
            ], 422);
        }

        $walletAddress = strtolower($request->input('wallet_address'));
        $signature = $request->input('signature');
        $nonce = $request->input('nonce');

        // Verify nonce is valid and not expired
        $storedTimestamp = Cache::get("wallet_nonce:{$walletAddress}:{$nonce}");
        if (! $storedTimestamp) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_NONCE',
                    'message' => 'Nonce is invalid or expired. Please request a new one.',
                ],
            ], 422);
        }

        // Reconstruct the message that was signed
        $message = "TPIX TRADE: Sign this message to verify your wallet.\n\nNonce: {$nonce}\nTimestamp: {$storedTimestamp}";

        // Verify the signature using ecrecover
        $recoveredAddress = $this->ecRecover($message, $signature);

        if (! $recoveredAddress || strtolower($recoveredAddress) !== $walletAddress) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_SIGNATURE',
                    'message' => 'Signature verification failed.',
                ],
            ], 403);
        }

        // Invalidate nonce (one-time use)
        Cache::forget("wallet_nonce:{$walletAddress}:{$nonce}");
        Cache::forget("wallet_active_nonce:{$walletAddress}");

        // Cache wallet as cryptographically verified (4 hours)
        // SECURITY: ถ้าแก้เลขนี้ ต้องแก้ expires_in ด้านล่างให้ตรงกัน
        // เพื่อให้ frontend กับ backend รู้เวลาหมดอายุตรงกัน (ไม่งั้น session ขาดช่วง)
        $verificationTtl = 14400;

        Cache::put("wallet_verified:{$walletAddress}", [
            'chain_id' => $request->input('chain_id', 56),
            'ip' => $request->ip(),
            'verified_at' => now()->toIso8601String(),
            'signature_verified' => true,
        ], $verificationTtl);

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_address' => $walletAddress,
                'verified' => true,
                'expires_in' => $verificationTtl,
            ],
        ]);
    }

    /**
     * Recover Ethereum address from signed message using ecrecover.
     * IMPORTANT: Ethereum uses Keccak-256, NOT NIST SHA3-256.
     * Requires: composer require kornrunner/keccak simplito/elliptic-php.
     *
     * Without these packages, ecRecover returns null and signature verification fails.
     * The connect() endpoint no longer grants verified status, so signature verification
     * is mandatory for write operations.
     */
    private function ecRecover(string $message, string $signature): ?string
    {
        try {
            // Check if Keccak library is available
            if (! class_exists(Keccak::class)) {
                Log::warning('ecRecover: kornrunner/keccak not installed. Run: composer require kornrunner/keccak simplito/elliptic-php');

                return null;
            }

            // Ethereum signed message prefix (EIP-191)
            $prefix = "\x19Ethereum Signed Message:\n".strlen($message);
            $msgHash = Keccak::hash($prefix.$message, 256, true);

            // Parse signature: r (32 bytes) + s (32 bytes) + v (1 byte)
            $sigBin = hex2bin(substr($signature, 2));
            if (strlen($sigBin) !== 65) {
                return null;
            }

            $r = substr($sigBin, 0, 32);
            $s = substr($sigBin, 32, 32);
            $v = ord($sigBin[64]);

            // Normalize v value (EIP-155 / pre-155)
            if ($v >= 27) {
                $v -= 27;
            }
            if ($v !== 0 && $v !== 1) {
                return null;
            }

            // Use elliptic-php for ecrecover
            $ec = new EC('secp256k1');
            $rHex = bin2hex($r);
            $sHex = bin2hex($s);
            $pubKey = $ec->recoverPubKey(bin2hex($msgHash), ['r' => $rHex, 's' => $sHex], $v);
            $pubKeyHex = $pubKey->encode('hex');

            // Remove 04 prefix (uncompressed), hash with Keccak-256, take last 20 bytes
            $pubKeyHash = Keccak::hash(hex2bin(substr($pubKeyHex, 2)), 256);

            return '0x'.substr($pubKeyHash, -40);
        } catch (Throwable $e) {
            Log::error('ecRecover failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
