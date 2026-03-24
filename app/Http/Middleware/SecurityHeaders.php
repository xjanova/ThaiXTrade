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

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Content-Security-Policy — restrict resource loading
        if (app()->isProduction()) {
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://pagead2.googlesyndication.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: blob: https://*.tpix.online https://api.binance.com",
                "connect-src 'self' https://*.tpix.online https://api.binance.com wss://stream.binance.com https://rpc.tpix.online https://bsc-dataseed.binance.org",
                "frame-src 'self' https://challenges.cloudflare.com https://js.stripe.com",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        // HSTS — enforce HTTPS (only in production)
        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
