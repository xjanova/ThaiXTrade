<?php

/**
 * TPIX TRADE — ตารางสมาชิกผู้ใช้ (Traders)
 * สมัครอัตโนมัติเมื่อ connect wallet + เพิ่ม email/profile ได้ทีหลัง
 * Developed by Xman Studio.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Primary identifier — wallet address (สมัครอัตโนมัติเมื่อ connect)
            $table->string('wallet_address', 42)->unique();

            // Optional profile — ผูกเพิ่มได้ทีหลัง
            $table->string('email')->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('avatar')->nullable();

            // สถานะ
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_banned')->default(false);
            $table->string('ban_reason')->nullable();
            $table->enum('kyc_status', ['none', 'pending', 'approved', 'rejected'])->default('none');

            // Referral system
            $table->string('referral_code', 10)->unique();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();

            // สถิติ
            $table->unsignedInteger('total_trades')->default(0);
            $table->decimal('total_volume_usd', 20, 2)->default(0);

            // Activity tracking
            $table->timestamp('last_active_at')->nullable();
            $table->string('last_ip', 45)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('is_banned');
            $table->index('kyc_status');
            $table->index('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
