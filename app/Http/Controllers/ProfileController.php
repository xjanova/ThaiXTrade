<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Services\WalletIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * ProfileController — User profile management.
 * Developed by Xman Studio.
 */
class ProfileController extends Controller
{
    /**
     * Show user profile page.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $user->load(['socialAccounts', 'walletConnections']);

        $enabledProviders = collect(['google', 'facebook', 'line'])
            ->filter(fn ($p) => filter_var(
                SiteSetting::get('social_auth', "{$p}_enabled", false),
                FILTER_VALIDATE_BOOLEAN
            ))
            ->values()
            ->all();

        return Inertia::render('Profile/Index', [
            'profileUser' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'wallet_address' => $user->wallet_address,
                'referral_code' => $user->referral_code,
                'kyc_status' => $user->kyc_status,
                'is_verified' => $user->is_verified,
                'total_trades' => $user->total_trades,
                'total_volume_usd' => $user->total_volume_usd,
                'has_password' => $user->password !== null,
                // ถอดกระเป๋าได้ก็ต่อเมื่อยังมีทางเข้าอื่นเหลืออยู่ — คำนวณฝั่งเซิร์ฟเวอร์
                // ให้ตรงกับกฎที่บังคับใช้จริง ไม่ให้หน้าเว็บเดาเอง
                'can_unlink_wallet' => $user->wallet_address !== null
                    && app(WalletIdentityService::class)->hasOtherSignInMethod($user),
                'created_at' => $user->created_at?->format('Y-m-d'),
            ],
            'socialAccounts' => $user->socialAccounts->map(fn ($sa) => [
                'provider' => $sa->provider,
                'provider_email' => $sa->provider_email,
                'provider_name' => $sa->provider_name,
                'connected_at' => $sa->created_at?->format('Y-m-d'),
            ]),
            'walletConnections' => $user->walletConnections->map(fn ($wc) => [
                'wallet_address' => $wc->wallet_address,
                'chain_id' => $wc->chain_id,
                'wallet_type' => $wc->wallet_type,
                'connected_at' => $wc->connected_at?->format('Y-m-d'),
            ]),
            'enabledProviders' => $enabledProviders,
        ]);
    }

    /**
     * Update profile info (name, email).
     *
     * อีเมลไม่บังคับ — ผู้ใช้ที่เข้าระบบด้วยกระเป๋าไม่มีอีเมลตั้งแต่แรก บังคับกรอก
     * เท่ากับเขาแก้แค่ชื่อที่แสดงไม่ได้เลยจนกว่าจะยอมให้อีเมล
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email,'.$request->user()->id],
        ]);

        $user = $request->user();

        // ห้ามลบอีเมลทิ้งถ้ามันเป็นทางเข้าเดียวที่เหลือ — ล้างแล้วล็อกอินกลับไม่ได้อีก
        if (blank($validated['email'] ?? null) && $user->email !== null && $user->wallet_address === null) {
            return back()->withErrors(['email' => 'ลบอีเมลไม่ได้ — เป็นทางเข้าเดียวของบัญชีนี้']);
        }

        $user->update($validated);

        return back()->with('success', 'Profile updated.');
    }

    /**
     * ถอดกระเป๋าออกจากบัญชี.
     *
     * เหตุผลที่ต้องมี: ผู้ใช้เปลี่ยนกระเป๋า ทำ seed หาย หรือเผลอผูกผิดใบ
     * ถ้าถอดเองไม่ได้ ทางเดียวคือทิ้งบัญชีแล้วเริ่มใหม่ ซึ่งประวัติเทรดหายหมด
     */
    public function unlinkWallet(Request $request, WalletIdentityService $identities): RedirectResponse
    {
        $user = $request->user();
        $wallet = $user->wallet_address;

        $result = $identities->unlink($user);

        if (! $result['ok']) {
            return back()->withErrors(['wallet' => match ($result['error']) {
                'ONLY_SIGN_IN_METHOD' => 'ถอดกระเป๋าไม่ได้ — เป็นทางเข้าเดียวของบัญชีนี้ ตั้งอีเมลกับรหัสผ่านก่อน',
                default => 'ยังไม่ได้ผูกกระเป๋ากับบัญชีนี้',
            }]);
        }

        // สิทธิ์ฝั่ง API ของกระเป๋าใบที่ถอดออกต้องหมดทันที ไม่ใช่รออีก 4 ชั่วโมง
        if ($wallet) {
            Cache::forget('wallet_verified:'.strtolower($wallet));
        }

        return back()->with('success', 'ถอดกระเป๋าออกจากบัญชีแล้ว');
    }

    /**
     * Update password (supports social-only users setting first password).
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Social-only users don't have a current password
        if ($user->password !== null) {
            $request->validate([
                'current_password' => ['required', 'string'],
            ]);

            if (! Hash::check($request->current_password, $user->password)) {
                return back()->withErrors(['current_password' => 'Current password is incorrect.']);
            }
        }

        $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user->update(['password' => $request->password]);

        return back()->with('success', 'Password updated.');
    }

    /**
     * Upload avatar.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        // Delete old avatar
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => '/storage/'.$path]);

        return back()->with('success', 'Avatar updated.');
    }

    /**
     * Remove avatar.
     */
    public function deleteAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar) {
            $storagePath = str_replace('/storage/', '', $user->avatar);
            if (Storage::disk('public')->exists($storagePath)) {
                Storage::disk('public')->delete($storagePath);
            }
            $user->update(['avatar' => null]);
        }

        return back()->with('success', 'Avatar removed.');
    }
}
