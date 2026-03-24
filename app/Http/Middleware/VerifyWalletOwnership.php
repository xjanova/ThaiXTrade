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
 * GET requests: validate wallet address format only.
 * Write requests (POST/PUT/DELETE): require wallet_signature + wallet_nonce
 * that proves the requester actually controls the private key.
 *
 * Flow:
 * 1. Frontend calls GET /api/v1/wallet/nonce?wallet_address=0x... → receives nonce
 * 2. Frontend signs nonce with wallet private key → gets signature
 * 3. Frontend sends POST with wallet_address + wallet_signature + wallet_nonce
 * 4. Middleware verifies signature matches wallet_address → allows request
 */
class VerifyWalletOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $walletAddress = $request->input('wallet_address')
            ?? $request->query('wallet_address');

        // If no wallet address in request, let the controller handle it
        if (! $walletAddress) {
            return $next($request);
        }

        // Validate wallet address format
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
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

        // GET requests: format validation is sufficient (read-only)
        if ($request->isMethod('GET')) {
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
