<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeaders Middleware.
 *
 * Adds essential security headers to all responses to prevent
 * clickjacking, MIME sniffing, and other common attacks.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');

        // Content-Security-Policy — restrict resource loading
        if (app()->isProduction()) {
            $csp = implode('; ', [
                "default-src 'self'",
                // 'unsafe-eval' kept for Vue runtime compilation; remove when migrated to compiled-only templates
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://pagead2.googlesyndication.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: blob: https://*.tpix.online https://api.binance.com https://*.coingecko.com",
                "connect-src 'self' https://*.tpix.online https://api.binance.com wss://stream.binance.com wss://rpc.tpix.online https://rpc.tpix.online https://bsc-dataseed.binance.org wss://*.tpix.online",
                "frame-src 'self' https://challenges.cloudflare.com",
                "frame-ancestors 'none'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "upgrade-insecure-requests",
                "block-all-mixed-content",
            ]);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        // HSTS — enforce HTTPS + preload eligible (1 year + includeSubDomains + preload)
        if (app()->isProduction()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // Remove server fingerprint headers
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
