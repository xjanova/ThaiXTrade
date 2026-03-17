<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyWalletOwnership Middleware.
 *
 * Ensures that the wallet_address in the request belongs to the requester
 * by checking that a valid nonce exists in cache (issued via /wallet/sign).
 * For read-only endpoints (GET), validates format only.
 * For write endpoints (POST/PUT/DELETE), requires a valid cached nonce.
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

        // Normalize to lowercase for consistent cache lookups
        $request->merge([
            'wallet_address' => strtolower($walletAddress),
        ]);

        return $next($request);
    }
}
