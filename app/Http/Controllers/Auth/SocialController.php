<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

/**
 * SocialController — OAuth login/link/unlink (Google, Facebook, Line).
 * Developed by Xman Studio.
 */
class SocialController extends Controller
{
    private const PROVIDERS = ['google', 'facebook', 'line'];

    /**
     * Redirect to OAuth provider.
     */
    public function redirect(Request $request, string $provider): RedirectResponse|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS), 404);

        $enabled = filter_var(
            SiteSetting::get('social_auth', "{$provider}_enabled", false),
            FILTER_VALIDATE_BOOLEAN
        );
        abort_unless($enabled, 404);

        // Apply SiteSetting credentials to config at runtime
        $this->applySocialConfig($provider);

        // If user is authenticated, this is a linking operation
        if (Auth::guard('web')->check()) {
            $request->session()->put('social_link_user_id', Auth::id());
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle OAuth callback.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS), 404);

        $this->applySocialConfig($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect()->route('login')
                ->with('error', 'Social login failed. Please try again.');
        }

        // Linking flow: authenticated user connecting a social account
        $linkUserId = $request->session()->pull('social_link_user_id');
        if ($linkUserId) {
            $user = User::find($linkUserId);
            if ($user) {
                $this->createSocialAccount($user, $provider, $socialUser);

                return redirect()->route('profile')
                    ->with('success', ucfirst($provider).' account connected.');
            }
        }

        // Check if social account already exists → login
        $existing = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($existing) {
            $user = $existing->user;

            if ($user->is_banned) {
                return redirect()->route('login')
                    ->with('error', 'Your account has been suspended.');
            }

            // Update token
            $existing->update([
                'token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'provider_avatar' => $socialUser->getAvatar(),
            ]);

            Auth::guard('web')->login($user, true);
            $request->session()->regenerate();
            $user->touchActivity($request->ip());

            return redirect()->intended(route('home'));
        }

        // Check if user with same email exists → link and login
        $email = $socialUser->getEmail();
        if ($email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                if ($user->is_banned) {
                    return redirect()->route('login')
                        ->with('error', 'Your account has been suspended.');
                }

                $this->createSocialAccount($user, $provider, $socialUser);

                Auth::guard('web')->login($user, true);
                $request->session()->regenerate();
                $user->touchActivity($request->ip());

                return redirect()->intended(route('home'));
            }
        }

        // Create new user + social account
        $user = User::create([
            'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
            'email' => $email,
            'avatar' => $socialUser->getAvatar(),
        ]);

        $this->createSocialAccount($user, $provider, $socialUser);

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        $user->touchActivity($request->ip());

        return redirect()->route('home');
    }

    /**
     * Unlink a social account from current user.
     */
    public function unlink(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS), 404);

        $user = $request->user();
        $account = $user->socialAccounts()->where('provider', $provider)->first();

        if (! $account) {
            return back()->with('error', 'Account not found.');
        }

        // Safety: user must have password OR another social account
        $hasPassword = $user->password !== null;
        $otherSocials = $user->socialAccounts()->where('provider', '!=', $provider)->exists();

        if (! $hasPassword && ! $otherSocials) {
            return back()->with('error', 'Cannot unlink — this is your only login method. Set a password first.');
        }

        $account->delete();

        return back()->with('success', ucfirst($provider).' account disconnected.');
    }

    /**
     * Create a SocialAccount record for a user.
     */
    private function createSocialAccount(User $user, string $provider, $socialUser): SocialAccount
    {
        return SocialAccount::updateOrCreate(
            ['user_id' => $user->id, 'provider' => $provider],
            [
                'provider_id' => $socialUser->getId(),
                'provider_email' => $socialUser->getEmail(),
                'provider_name' => $socialUser->getName(),
                'provider_avatar' => $socialUser->getAvatar(),
                'token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
            ]
        );
    }

    /**
     * Apply SiteSetting OAuth credentials to runtime config.
     */
    private function applySocialConfig(string $provider): void
    {
        $keyMap = [
            'google' => ['client_id' => 'google_client_id', 'client_secret' => 'google_client_secret'],
            'facebook' => ['client_id' => 'facebook_client_id', 'client_secret' => 'facebook_client_secret'],
            'line' => ['client_id' => 'line_channel_id', 'client_secret' => 'line_channel_secret'],
        ];

        $keys = $keyMap[$provider] ?? [];
        foreach ($keys as $configKey => $settingKey) {
            $value = trim((string) SiteSetting::get('social_auth', $settingKey, ''));
            if ($value) {
                config(["services.{$provider}.{$configKey}" => $value]);
            }
        }
    }
}
