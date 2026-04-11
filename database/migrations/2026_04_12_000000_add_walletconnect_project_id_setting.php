<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // WalletConnect Project ID — ใช้เชื่อมต่อ wallet ผ่าน QR code (WalletConnect v2)
        DB::table('site_settings')->updateOrInsert(
            ['group' => 'trading', 'key' => 'walletconnect_project_id'],
            [
                'value' => '52dc35105b74ddd9ade472de308b02d5',
                'type' => 'string',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('site_settings')
            ->where('group', 'trading')
            ->where('key', 'walletconnect_project_id')
            ->delete();
    }
};
