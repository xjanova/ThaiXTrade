<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * AuthController.
 *
 * Handles admin authentication: login form display, credential
 * validation with rate limiting, and session logout.
 */
class AuthController extends Controller
{
    /**
     * Display the admin login page.
     * If no admin users exist, show the initial setup page.
     */
    public function showLogin(): InertiaResponse|RedirectResponse
    {
        // First-time setup: no admin users exist yet — redirect to wizard
        if (AdminUser::count() === 0) {
            return redirect()->route('admin.setup.show');
        }

        $turnstileEnabled = filter_var(SiteSetting::get('security', 'turnstile_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $turnstileSiteKey = $turnstileEnabled
            ? trim((string) SiteSetting::get('security', 'turnstile_site_key', ''))
            : '';

        return Inertia::render('Admin/Auth/Login', [
            'turnstileEnabled' => $turnstileEnabled,
            'turnstileSiteKey' => $turnstileSiteKey,
        ]);
    }

    /**
     * Create the first admin user (first-time setup only).
     */
    public function setup(Request $request): RedirectResponse
    {
        // Only allow if no admin users exist — use DB lock to prevent race condition
        $adminCount = AdminUser::lockForUpdate()->count();
        if ($adminCount > 0) {
            return redirect()->route('admin.login')
                ->with('error', 'Setup already completed.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $admin = AdminUser::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // Auto-login the new admin
        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        return redirect()->route('admin.dashboard');
    }

    /**
     * Authenticate an admin user.
     *
     * ด่านกันเดารหัส 3 ชั้น (ดูเหตุผลของแต่ละชั้นในตัวฟังก์ชัน).
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

        /*
         * เดิมมีคีย์เดียวคือ email|IP ซึ่งกันได้แค่ "คนเดิม เครื่องเดิม"
         * แต่กันสองท่าที่ใช้เจาะจริงไม่ได้เลย:
         *   1. บอทเน็ตสลับ IP ยิงอีเมลแอดมินเดิม → ทุก IP ได้โควตาใหม่ 5 ครั้ง
         *   2. เครื่องเดียวไล่ยิงทีละอีเมล → ทุกอีเมลได้บัคเก็ตใหม่ ไม่มีเพดานรวม
         * จึงเติมคีย์คุมที่ "บัญชี" และ "ต้นทาง" ตรง ๆ อีกสองชั้น
         * (Turnstile กัน bot ธรรมดาได้ แต่มีบริการรับ solve ราคาหลักสตางค์ต่อครั้ง
         *  จึงนับเป็นด่านชะลอ ไม่ใช่ด่านกันเดารหัส)
         */
        $ip = (string) $request->ip();
        $email = strtolower($validated['email']);

        $gates = [
            ['admin-login:'.$email.'|'.$ip, 5],   // คนเดิม เครื่องเดิม
            ['admin-login-acct:'.$email, 20],     // บัญชีเดียว ทุกเครื่องรวมกัน
            ['admin-login-ip:'.$ip, 20],          // เครื่องเดียว ทุกบัญชีรวมกัน
        ];

        foreach ($gates as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = RateLimiter::availableIn($key);

                throw ValidationException::withMessages([
                    'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
                ]);
            }
        }

        $credentials = [
            'email' => $validated['email'],
            'password' => $validated['password'],
        ];

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            foreach ($gates as [$key]) {
                RateLimiter::hit($key, 900); // 15 นาที
            }

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

        // ล้างทุกชั้นตอนล็อกอินสำเร็จ — ไม่งั้นแอดมินตัวจริงที่พิมพ์ผิดหลายรอบ
        // จะโดนด่าน "ต้นทาง" ล็อกตัวเองออกจากระบบ
        foreach ($gates as [$key]) {
            RateLimiter::clear($key);
        }

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
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
