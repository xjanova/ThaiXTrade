<?php

/**
 * TPIX TRADE — Seed social auth settings ใน site_settings
 * Developed by Xman Studio.
 */

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

return new class() extends Migration
{
    public function up(): void
    {
        $settings = [
            ['group' => 'social_auth', 'key' => 'google_enabled', 'value' => 'false', 'type' => 'boolean'],
            ['group' => 'social_auth', 'key' => 'google_client_id', 'value' => '', 'type' => 'string'],
            ['group' => 'social_auth', 'key' => 'google_client_secret', 'value' => '', 'type' => 'string'],
            ['group' => 'social_auth', 'key' => 'facebook_enabled', 'value' => 'false', 'type' => 'boolean'],
            ['group' => 'social_auth', 'key' => 'facebook_client_id', 'value' => '', 'type' => 'string'],
            ['group' => 'social_auth', 'key' => 'facebook_client_secret', 'value' => '', 'type' => 'string'],
            ['group' => 'social_auth', 'key' => 'line_enabled', 'value' => 'false', 'type' => 'boolean'],
            ['group' => 'social_auth', 'key' => 'line_channel_id', 'value' => '', 'type' => 'string'],
            ['group' => 'social_auth', 'key' => 'line_channel_secret', 'value' => '', 'type' => 'string'],
        ];

        foreach ($settings as $setting) {
            SiteSetting::firstOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                ['value' => $setting['value'], 'type' => $setting['type']]
            );
        }
    }

    public function down(): void
    {
        SiteSetting::where('group', 'social_auth')->delete();
    }
};
