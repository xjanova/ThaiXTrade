<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * SettingController
 *
 * Manages platform-wide configuration stored in the site_settings table.
 * Supports grouped settings, file uploads for images, and cache invalidation.
 */
class SettingController extends Controller
{
    /**
     * Display all site settings grouped by category.
     */
    public function index(): InertiaResponse
    {
        $settings = SiteSetting::all()->groupBy('group')->map(function ($group) {
            return $group->mapWithKeys(function ($setting) {
                return [$setting->key => [
                    'value' => $setting->value,
                    'type' => $setting->type,
                ]];
            });
        });

        return Inertia::render('Admin/Settings/Index', [
            'settings' => $settings,
        ]);
    }

    /**
     * Update site settings in bulk.
     *
     * Accepts a flat array of settings keyed by "group.key" format,
     * or a nested array keyed by group with key-value pairs.
     */
    public function update(Request $request): \Illuminate\Http\RedirectResponse
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
     * Handle logo file upload.
     */
    public function updateLogo(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
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
}
