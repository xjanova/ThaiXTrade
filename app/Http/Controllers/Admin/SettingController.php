<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * SettingController.
 *
 * Manages platform-wide configuration stored in the site_settings table.
 * Supports grouped settings, file uploads for images, and cache invalidation.
 */
class SettingController extends Controller
{
    /**
     * แสดงหน้า Settings — แปลง settings เป็น flat format สำหรับ Vue.
     */
    public function index(): InertiaResponse
    {
        // Keys ที่ต้อง mask — ไม่ส่งค่าจริงไป frontend (ป้องกัน secret leak)
        $secretKeys = [
            'turnstile_secret_key',
            'stripe_secret_key',
            'stripe_webhook_secret',
            'groq_api_key',
            'resend_api_key',
            'google_client_secret',
            'facebook_client_secret',
            'line_channel_secret',
            'together_api_key',
            'huggingface_api_key',
            'gemini_api_key',
        ];

        // ดึง settings ทั้งหมดแล้วแปลงเป็น flat key-value (cast ตาม type)
        $allSettings = SiteSetting::all();
        $flat = [];
        foreach ($allSettings as $setting) {
            // Mask secret keys — แสดงแค่ 4 ตัวสุดท้าย
            if (in_array($setting->key, $secretKeys) && $setting->value) {
                $flat[$setting->key] = str_repeat('*', 20).substr($setting->value, -4);
            } else {
                // cast ค่าตาม type เพื่อให้ Vue ได้ค่าที่ถูกต้อง (boolean, int, etc.)
                $flat[$setting->key] = SiteSetting::castValuePublic($setting->value, $setting->type);
            }

            // แปลง image path เป็น URL ที่เข้าถึงได้
            if ($setting->type === 'image' && $setting->value) {
                $flat[$setting->key.'_url'] = Storage::disk('public')->exists($setting->value)
                    ? '/storage/'.$setting->value
                    : null;
            }
        }

        return Inertia::render('Admin/Settings/Index', [
            'settings' => $flat,
        ]);
    }

    /**
     * Update site settings in bulk.
     *
     * Accepts a flat array of settings keyed by "group.key" format,
     * or a nested array keyed by group with key-value pairs.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
        ]);

        $oldValues = [];
        $newValues = [];

        foreach ($validated['settings'] as $groupKey => $value) {
            // Support both "group.key" flat format and nested format
            if (str_contains($groupKey, '.')) {
                [$group, $key] = explode('.', $groupKey, 2);
            } else {
                continue;
            }

            $existing = SiteSetting::where('group', $group)
                ->where('key', $key)
                ->first();

            $oldValues["{$group}.{$key}"] = $existing?->value;
            $newValues["{$group}.{$key}"] = $value;

            // Handle file uploads for image-type settings
            if ($request->hasFile("settings.{$groupKey}")) {
                $file = $request->file("settings.{$groupKey}");
                $path = $file->store('settings', 'public');
                $value = $path;
            }

            SiteSetting::set($group, $key, $value, $existing?->type ?? 'string');
        }

        SiteSetting::clearCache();

        AuditLog::log('settings.update', null, $oldValues, $newValues);

        return back()->with('success', 'Settings updated successfully.');
    }

    /**
     * บันทึก General tab (รวม logo upload).
     */
    public function updateGeneral(Request $request): RedirectResponse
    {
        $request->validate([
            'site_name' => ['nullable', 'string', 'max:100'],
            'site_description' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,jpg,jpeg,webp', 'max:5120'],
        ]);

        // บันทึก text settings
        foreach (['site_name', 'site_description', 'primary_color'] as $key) {
            if ($request->has($key)) {
                SiteSetting::set('general', $key, $request->input($key), 'string');
            }
        }

        // อัปโหลด logo
        if ($request->hasFile('logo')) {
            $oldLogo = SiteSetting::get('general', 'logo');
            if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
                Storage::disk('public')->delete($oldLogo);
            }
            $path = $request->file('logo')->store('logos', 'public');
            SiteSetting::set('general', 'logo', $path, 'image');
        }

        // อัปโหลด favicon
        if ($request->hasFile('favicon')) {
            $oldFav = SiteSetting::get('general', 'favicon');
            if ($oldFav && Storage::disk('public')->exists($oldFav)) {
                Storage::disk('public')->delete($oldFav);
            }
            $path = $request->file('favicon')->store('favicons', 'public');
            SiteSetting::set('general', 'favicon', $path, 'image');
        }

        SiteSetting::clearCache();
        AuditLog::log('settings.general.update');

        return back()->with('success', 'บันทึก General settings สำเร็จ');
    }

    /**
     * บันทึก SEO tab (รวม OG image upload).
     */
    public function updateSeo(Request $request): RedirectResponse
    {
        $request->validate([
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ]);

        foreach (['meta_title', 'meta_description'] as $key) {
            if ($request->has($key)) {
                SiteSetting::set('seo', $key, $request->input($key), 'string');
            }
        }

        if ($request->hasFile('og_image')) {
            $old = SiteSetting::get('seo', 'og_image');
            if ($old && Storage::disk('public')->exists($old)) {
                Storage::disk('public')->delete($old);
            }
            $path = $request->file('og_image')->store('seo', 'public');
            SiteSetting::set('seo', 'og_image', $path, 'image');
        }

        SiteSetting::clearCache();
        AuditLog::log('settings.seo.update');

        return back()->with('success', 'บันทึก SEO settings สำเร็จ');
    }

    /**
     * บันทึก tab ทั่วไป (trading, security, social) — ไม่มี file upload.
     */
    public function updateTab(Request $request): RedirectResponse
    {
        // Whitelist allowed tab names to prevent path manipulation
        $allowedTabs = ['trading', 'security', 'social', 'api', 'notifications', 'advanced', 'ai'];
        $tab = last(explode('/', $request->path()));

        if (! in_array($tab, $allowedTabs, true)) {
            return back()->with('error', 'Invalid settings tab.');
        }

        // Only allow keys that already exist in this group (prevent arbitrary key injection)
        // Exception: AI tab allows new keys (API keys get created on first save)
        $existingKeys = SiteSetting::where('group', $tab)->pluck('key')->toArray();

        // Whitelist of allowed new keys per tab (keys that can be created on first save)
        $allowedNewKeys = [
            'ai' => ['groq_api_key', 'groq_default_model', 'ai_chatbot_enabled', 'ai_content_enabled',
                'cloudflare_image_url', 'cloudflare_image_key',
                'together_api_key', 'huggingface_api_key', 'gemini_api_key'],
        ];

        foreach ($request->except('_method') as $key => $value) {
            $isAllowedNew = in_array($key, $allowedNewKeys[$tab] ?? [], true);
            if (! in_array($key, $existingKeys, true) && ! $isAllowedNew) {
                continue;
            }
            // ดึง type เดิมจาก DB เพื่อไม่ให้ boolean ถูก overwrite เป็น string
            $existing = SiteSetting::where('group', $tab)
                ->where('key', $key)
                ->first();

            $type = $existing?->type ?? 'string';

            // แก้ type ที่ถูก corrupt: key ลงท้ายด้วย _enabled ควรเป็น boolean เสมอ
            if (str_ends_with($key, '_enabled') && ! in_array($type, ['boolean', 'bool'])) {
                $type = 'boolean';
            }

            // แปลง boolean values ให้ถูกต้องก่อนบันทึก
            if ($type === 'boolean' || $type === 'bool') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }

            // trim string values เพื่อป้องกัน whitespace จากการ copy-paste (เช่น API keys)
            if (is_string($value) && $type === 'string') {
                $value = trim($value);
            }

            // ข้าม masked values — ถ้า admin ไม่ได้เปลี่ยน secret key จะส่งค่า masked กลับมา
            if (is_string($value) && str_starts_with($value, '********************')) {
                continue;
            }

            SiteSetting::set($tab, $key, $value, $type);
        }

        SiteSetting::clearCache();
        AuditLog::log("settings.{$tab}.update");

        return back()->with('success', "บันทึก {$tab} settings สำเร็จ");
    }

    /**
     * Handle logo file upload (standalone endpoint).
     */
    public function updateLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ]);

        // Delete old logo if exists
        $oldLogo = SiteSetting::get('general', 'logo');
        if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }

        $path = $request->file('logo')->store('logos', 'public');

        SiteSetting::set('general', 'logo', $path, 'image');
        SiteSetting::clearCache();

        AuditLog::log('settings.logo.update', null, ['logo' => $oldLogo], ['logo' => $path]);

        return back()->with('success', 'Logo updated successfully.');
    }

    /**
     * บันทึกการตั้งค่าอีเมล (Resend API Key, From address/name).
     */
    public function updateEmail(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resend_api_key' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($validated['resend_api_key']) && ! str_starts_with($validated['resend_api_key'], '****')) {
            SiteSetting::set('email', 'resend_api_key', $validated['resend_api_key'], 'string');
            config(['services.resend.key' => $validated['resend_api_key']]);
        }

        if (! empty($validated['mail_from_address'])) {
            SiteSetting::set('email', 'mail_from_address', $validated['mail_from_address'], 'string');
        }

        if (! empty($validated['mail_from_name'])) {
            SiteSetting::set('email', 'mail_from_name', $validated['mail_from_name'], 'string');
        }

        SiteSetting::clearCache();
        AuditLog::log('settings.email.update');

        return back()->with('success', 'บันทึกการตั้งค่าอีเมลเรียบร้อยแล้ว');
    }

    /**
     * ส่งอีเมลทดสอบ.
     */
    public function sendTestEmail(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
        ]);

        // Apply dynamic mail config from DB
        $this->applyMailConfig();

        try {
            Mail::to($validated['test_email'])->send(new TestMail());

            return back()->with('success', "ส่งอีเมลทดสอบไปที่ {$validated['test_email']} เรียบร้อยแล้ว");
        } catch (\Exception $e) {
            Log::error('Test email failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'ส่งอีเมลไม่สำเร็จ: '.$e->getMessage());
        }
    }

    /**
     * Apply mail config from database at runtime.
     */
    private function applyMailConfig(): void
    {
        $apiKey = SiteSetting::get('email', 'resend_api_key');
        if ($apiKey) {
            config(['services.resend.key' => $apiKey]);
            config(['mail.default' => 'resend']);
        }

        $fromAddress = SiteSetting::get('email', 'mail_from_address');
        if ($fromAddress) {
            config(['mail.from.address' => $fromAddress]);
        }

        $fromName = SiteSetting::get('email', 'mail_from_name');
        if ($fromName) {
            config(['mail.from.name' => $fromName]);
        }
    }
}
