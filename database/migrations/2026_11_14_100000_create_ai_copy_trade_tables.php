<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Master / Copy Trade ของ AI TRADE (เจ้าของสั่ง 2026-11-13)
 *
 *   ai_copy_masters  โปรไฟล์มาสเตอร์ — หนึ่งกระเป๋าหนึ่งโปรไฟล์ ผูกกับบอทหนึ่งตัวที่เป็น "ผลงาน"
 *   ai_copy_follows  ผู้ตามหนึ่งคนกับมาสเตอร์หนึ่งคน + อัตราที่ผู้ตาม "ยอมรับไว้" ตอนเริ่มตาม
 *   ai_copy_fees     บัญชีค่าบริการ — หนึ่งแถวต่อไม้ขายเงินจริงของผู้ตามที่ทำกำไรสะสมขึ้นจุดสูงใหม่
 *
 * ⚠️ เวลาทางธุรกิจใช้ dateTime ทั้งหมด — MariaDB บน production เติม ON UPDATE ให้
 *    TIMESTAMP NOT NULL ตัวแรกของตารางเอง (BusinessTimeColumnsTest)
 * ⚠️ ai_copy_fees เป็น "บัญชี" ไม่ใช่การโอน — แถวในนี้ไม่ได้แปลว่ามีเหรียญเปลี่ยนมือ (ดู status)
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ai_copy_masters', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_address', 42)->unique();
            // บอทที่เป็นผลงานของมาสเตอร์ — บอทถูกลบ = โปรไฟล์ต้องถูกปิดก่อน (กันที่ API) จึงเหลือ null ได้เฉพาะแถวที่ปิดแล้ว
            $table->foreignId('ai_bot_config_id')->nullable()->unique()->constrained('ai_bot_configs')->nullOnDelete();
            $table->string('display_name', 60);
            $table->string('bio', 300)->nullable();
            // ค่าบริการ = % ของกำไรที่ปิดแล้วของผู้ตาม · ส่วนแบ่งแพลตฟอร์ม = % ของค่าบริการนั้น
            $table->decimal('fee_rate_pct', 5, 2);
            $table->decimal('platform_share_pct', 5, 2);
            $table->string('status', 16)->default('active');
            $table->json('stats')->nullable();
            $table->dateTime('stats_at')->nullable();
            $table->dateTime('registered_at');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('ai_copy_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_copy_master_id')->constrained('ai_copy_masters')->cascadeOnDelete();
            $table->string('follower_wallet', 42);
            // บอทของผู้ตามที่ก๊อปปี้ไม้ — ลบบอท = เลิกตาม (แถวยังอยู่เป็นประวัติค่าบริการ)
            $table->foreignId('ai_bot_config_id')->nullable()->unique()->constrained('ai_bot_configs')->nullOnDelete();
            // อัตราที่ผู้ตามยอมรับ — มาสเตอร์แก้อัตราทีหลัง แถวนี้ไม่เปลี่ยนจนกว่าผู้ตามจะกดยอมรับเอง
            $table->decimal('fee_rate_pct', 5, 2);
            $table->decimal('platform_share_pct', 5, 2);
            $table->string('status', 16)->default('active');
            // กำไร/ขาดทุนที่ปิดแล้วสะสมของการตามครั้งนี้ และจุดสูงสุดที่เคยคิดค่าบริการไปแล้ว (high-water mark)
            $table->decimal('realized_pnl', 24, 8)->default(0);
            $table->decimal('high_water_mark', 24, 8)->default(0);
            $table->decimal('fees_usd', 24, 8)->default(0);
            // ไม้ขายล่าสุดของบอทผู้ตามที่ถูกนำมาคิดแล้ว (ทั้งไม้กำไรและไม้ขาดทุน) — ไล่ตรวจซ้ำกี่รอบก็ไม่นับซ้ำ
            $table->unsignedBigInteger('last_settled_trade_id')->default(0);
            $table->dateTime('started_at');
            $table->dateTime('stopped_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_copy_master_id', 'follower_wallet']);
            $table->index(['follower_wallet', 'status']);
        });

        Schema::create('ai_copy_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_copy_follow_id')->constrained('ai_copy_follows')->cascadeOnDelete();
            $table->foreignId('ai_copy_master_id')->constrained('ai_copy_masters')->cascadeOnDelete();
            // ไม้ขายที่ทำให้เกิดค่าบริการ — unique: ไม้หนึ่งไม้คิดได้ครั้งเดียว ไม่ว่าจะถูกไล่ตรวจกี่รอบ
            $table->unsignedBigInteger('ai_bot_trade_id')->unique();
            $table->string('follower_wallet', 42);
            $table->string('master_wallet', 42);
            $table->decimal('profit_usd', 24, 8);
            $table->decimal('fee_rate_pct', 5, 2);
            $table->decimal('platform_share_pct', 5, 2);
            $table->decimal('fee_usd', 24, 8);
            $table->decimal('master_usd', 24, 8);
            $table->decimal('platform_usd', 24, 8);
            // ค่าบริการเป็น TPIX — ราคาตอนคิด (null = ตอนนั้นไม่มีราคา TPIX ให้ใช้ ยังแปลงไม่ได้)
            $table->decimal('tpix_usd_price', 24, 10)->nullable();
            $table->decimal('fee_tpix', 30, 8)->nullable();
            $table->decimal('master_tpix', 30, 8)->nullable();
            $table->decimal('platform_tpix', 30, 8)->nullable();
            // recorded = คำนวณไว้เฉยๆ (ยังไม่เปิดเก็บ) · accrued = ค้างเก็บ · settled = เก็บ/จ่ายแล้ว · void = ยกเลิก
            $table->string('status', 16)->default('recorded');
            $table->dateTime('charged_at');
            $table->timestamps();

            $table->index(['ai_copy_master_id', 'status']);
            $table->index(['follower_wallet', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_copy_fees');
        Schema::dropIfExists('ai_copy_follows');
        Schema::dropIfExists('ai_copy_masters');
    }
};
