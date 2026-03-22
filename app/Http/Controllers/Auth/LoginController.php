<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * LoginController — User authentication (email + password).
 * Supports Cloudflare Turnstile bot protection.
 * Developed by Xman Studio.
 */
class LoginController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLogin(): InertiaResponse
    {
        $turnstileEnabled = filter_var(
            SiteSetting::get('security', 'turnstile_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );
        $turnstileSiteKey = $turnstileEnabled
            ? trim((string) SiteSetting::get('security', 'turnstile_site_key', ''))
            : '';

        return Inertia::render('Auth/Login', [
            'turnstileEnabled' => $turnstileEnabled,
            'turnstileSiteKey' => $turnstileSiteKey,
        ]);
    }

    /**
     * Authenticate a user.
     *
     * Rate limited to 5 attempts per 15 minutes per email + IP.
     *
     * @throws ValidationException
     */
    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'cf-turnstile-response' => ['nullable', 'string'],
        ]);

        $throttleKey = 'user-login:'.$validated['email'].'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        $credentials = [
            'email' => $validated['email'],
            'password' => $validated['password'],
        ];

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 900);

            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        $user = Auth::guard('web')->user();

        // Check if the account is banned
        if ($user->is_banned) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Your account has been suspended. Please contact support.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        // Update activity
        $user->touchActivity($request->ip());

        return redirect()->intended(route('home'));
    }

    /**
     * Log the user out.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
