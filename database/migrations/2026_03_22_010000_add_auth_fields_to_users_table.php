<?php

/**
 * TPIX TRADE — เพิ่มฟิลด์ authentication สำหรับ users
 * รองรับ login ด้วย email+password
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
            // เพิ่ม password สำหรับ login ผ่าน email (nullable เพราะ wallet-only users ไม่ต้องใช้)
            $table->string('password')->nullable()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('avatar');
            $table->rememberToken()->after('last_ip');

            // wallet_address เปลี่ยนเป็น nullable — user ที่สมัครผ่าน email ไม่ต้องมี wallet
            $table->string('wallet_address', 42)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password', 'email_verified_at', 'remember_token']);
            $table->string('wallet_address', 42)->nullable(false)->change();
        });
    }
};
