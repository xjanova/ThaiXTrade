<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * TurnstileVerify Middleware.
 *
 * Validates Cloudflare Turnstile CAPTCHA tokens when the feature is enabled.
 * Skips verification for already-authenticated admin sessions.
 * Turnstile enablement is controlled via SiteSetting (security.turnstile_enabled).
 */
class TurnstileVerify
{
    /**
     * The Cloudflare Turnstile verification endpoint.
     */
    protected string $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip verification for already authenticated admin sessions
        if (Auth::guard('admin')->check()) {
            return $next($request);
        }

        // Check if Turnstile is enabled in site settings
        // ใช้ filter_var เพื่อรองรับทั้ง boolean และ string ('false', '0', '') จาก DB
        $turnstileEnabled = filter_var(
            SiteSetting::get('security', 'turnstile_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (! $turnstileEnabled) {
            return $next($request);
        }

        // Skip if keys are not configured (Turnstile enabled but not set up yet)
        // trim เพื่อป้องกัน whitespace ที่ copy มาแล้ว Cloudflare reject
        $secretKey = trim((string) SiteSetting::get('security', 'turnstile_secret_key', ''));
        $siteKey = trim((string) SiteSetting::get('security', 'turnstile_site_key', ''));

        if (empty($secretKey) || empty($siteKey)) {
            Log::warning('Turnstile enabled but keys not configured. Skipping verification.');

            return $next($request);
        }

        $token = $request->input('cf-turnstile-response');

        if (empty($token)) {
            return back()->withErrors([
                'turnstile' => 'Please complete the security verification.',
            ]);
        }

        try {
            $response = Http::asForm()->post($this->verifyUrl, [
                'secret' => $secretKey,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);

            $result = $response->json();

            if (! ($result['success'] ?? false)) {
                Log::warning('Turnstile verification failed.', [
                    'ip' => $request->ip(),
                    'error_codes' => $result['error-codes'] ?? [],
                ]);

                return back()->withErrors([
                    'turnstile' => 'Security verification failed. Please try again.',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Turnstile verification request failed.', [
                'message' => $e->getMessage(),
            ]);

            // Fail open: allow request if Turnstile service is unreachable
            return $next($request);
        }

        return $next($request);
    }
}
