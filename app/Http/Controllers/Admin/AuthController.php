<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * AuthController
 *
 * Handles admin authentication: login form display, credential
 * validation with rate limiting, and session logout.
 */
class AuthController extends Controller
{
    /**
     * Display the admin login page.
     */
    public function showLogin(): InertiaResponse
    {
        return Inertia::render('Admin/Auth/Login');
    }

    /**
     * Authenticate an admin user.
     *
     * Rate limited to 5 attempts per 15 minutes per email + IP combination.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function login(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'cf-turnstile-response' => ['nullable', 'string'],
        ]);

        $throttleKey = 'admin-login:' . $validated['email'] . '|' . $request->ip();

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

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 900); // 15 minutes

            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        $admin = Auth::guard('admin')->user();

        // Check if the account is active
        if (! $admin->is_active) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Your account has been deactivated. Please contact a super administrator.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        // Update login metadata
        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Log the admin user out of the application.
     */
    public function logout(Request $request): \Illuminate\Http\RedirectResponse
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
