<?php

/**
 * Create masternode_allowlist table — auto-allowlist สำหรับ Masternode/Validator IPs
 *
 * Operator masternode/validator พิสูจน์ตัวตนผ่าน wallet signature (ผ่าน delegate key)
 * → server เพิ่ม IP เข้า Cloudflare allowlist TTL 1 ชั่วโมง
 * → operator ส่ง heartbeat ทุก 30 นาที renew TTL ไป
 * → cron cleanup ลบ entry หมดอายุ + ลบ Cloudflare rule
 *
 * Developed by Xman Studio
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('masternode_allowlist', function (Blueprint $t) {
            $t->id();

            // Wallet ของ operator (จะใช้ verify กับ NodeRegistry contract)
            $t->string('wallet_address', 42)->unique();

            // Public address ของ delegate key (ที่ใช้ sign heartbeat — ไม่ใช่ private key หลัก)
            $t->string('delegate_address', 42);

            // หลักฐาน delegation: wallet sign "I authorize delegate_address until <timestamp>"
            $t->text('delegation_signature');
            $t->timestamp('delegation_expires_at');

            // IP ที่ allow (IPv4 หรือ IPv6 — max 45 chars)
            $t->string('ip_address', 45);

            // Tier: Validator / Guardian / Sentinel / Light
            $t->string('tier', 20);

            // Cloudflare access rule ID (ใช้ delete ตอน cleanup)
            $t->string('cf_rule_id', 64)->nullable();

            // TTL — allowlist หมดอายุเมื่อไหร่
            $t->timestamp('allowed_until');

            // Last heartbeat — ใช้สถิติ + detect zombie
            $t->timestamp('last_heartbeat')->useCurrent();

            // Last signed timestamp — ใช้กัน replay (ต้อง monotonic increase ทุก heartbeat)
            // ถ้า attacker capture sig แล้ว replay มาก็ต้องใช้ timestamp ใหม่กว่า → ทำไม่ได้เพราะ sig ผูกกับ timestamp
            $t->unsignedBigInteger('last_signed_timestamp')->default(0);

            // Counter — ใช้ rate-limit (ป้องกันคน abuse re-register บ่อยเกิน)
            $t->unsignedInteger('heartbeat_count')->default(0);

            // Status — active/revoked/expired
            $t->enum('status', ['active', 'revoked', 'expired'])->default('active');

            // Notes — admin override / debug
            $t->string('notes', 255)->nullable();

            $t->timestamps();

            // Indexes
            $t->index('allowed_until');
            $t->index(['status', 'allowed_until']);
            $t->index('ip_address');
            $t->index('delegate_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masternode_allowlist');
    }
};
