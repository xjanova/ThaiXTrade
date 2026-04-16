<?php

/**
 * TPIX TRADE — เพิ่มฟิลด์ preferences สำหรับเก็บการตั้งค่า cross-device
 * (ภาษา, theme, default chain, notification, ฯลฯ) — sync ระหว่าง mobile ↔ web
 * Developed by Xman Studio.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // JSON เก็บ user preferences — sync ข้ามอุปกรณ์
            // โครงสร้าง: { language, theme, default_chain_id, notifications: {...}, ... }
            $table->json('preferences')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
