<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * RegisterController — User registration (email + password).
 * Supports Cloudflare Turnstile bot protection.
 * Developed by Xman Studio.
 */
class RegisterController extends Controller
{
    /**
     * Show the registration form.
     */
    public function showRegister(): InertiaResponse
    {
        $turnstileEnabled = filter_var(
            SiteSetting::get('security', 'turnstile_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );
        $turnstileSiteKey = $turnstileEnabled
            ? trim((string) SiteSetting::get('security', 'turnstile_site_key', ''))
            : '';

        $enabledProviders = collect(['google', 'facebook', 'line'])
            ->filter(fn ($p) => filter_var(
                SiteSetting::get('social_auth', "{$p}_enabled", false),
                FILTER_VALIDATE_BOOLEAN
            ))
            ->values()
            ->all();

        return Inertia::render('Auth/Register', [
            'turnstileEnabled' => $turnstileEnabled,
            'turnstileSiteKey' => $turnstileSiteKey,
            'enabledProviders' => $enabledProviders,
        ]);
    }

    /**
     * Register a new user.
     */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'cf-turnstile-response' => ['nullable', 'string'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->touchActivity($request->ip());

        return redirect()->route('home');
    }
}
