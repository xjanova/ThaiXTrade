<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Alerts — คาดแดงเหตุวิกฤตโครงสร้างพื้นฐาน (เชน/เซิร์ฟเวอร์) ในหลังบ้าน.
 *
 * แหล่งข้อมูล: watchdog บนเซิร์ฟเวอร์เชนยิง heartbeat + alert เข้า POST /api/infra/*
 * (ดู TPIX-Coin: infrastructure/scripts/chain-watchdog.sh) และ scheduler ฝั่งนี้
 * ตรวจ heartbeat ขาดเองอีกชั้น — ครอบเคสทั้งเครื่องเชนดับซึ่ง watchdog ยิงอะไรไม่ได้แล้ว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('node', 64);                            // ชื่อเครื่องต้นเหตุ เช่น chain-1 (รองรับหลายเครื่องในอนาคต)
            $table->string('alert_key', 64);                       // ชนิดเหตุ เช่น chain_stalled, heartbeat_missing
            $table->string('severity', 16)->default('critical');   // critical|warning|info
            $table->text('message');
            $table->json('data')->nullable();
            $table->string('status', 16)->default('active');       // active|resolved
            $table->unsignedInteger('occurrences')->default(1);    // เหตุ key เดิมยิงซ้ำ = นับรวม ไม่สร้างแถวใหม่
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by', 128)->nullable();        // ชื่อแอดมิน หรือ auto:heartbeat
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['node', 'alert_key', 'status']);
        });

        Schema::create('infra_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('node', 64)->unique();
            $table->unsignedBigInteger('last_block')->default(0);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_heartbeats');
        Schema::dropIfExists('system_alerts');
    }
};
