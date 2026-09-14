<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — บันทึกความผิดและการลงโทษของบอทดูแลห้อง Discord (2026-09-14).
 *
 * เจ้าของสั่ง: "บอทควบคุมห้อง จัดการ แบน เตะ คนได้หากมีแนวโน้มไม่ดี"
 *
 * AutoMod ของ Discord เป็นคนจับ (บล็อกข้อความ + ปิดเสียงชั่วคราว) แล้วเขียนลง audit log ของเซิร์ฟเวอร์
 * เราอ่าน audit log ทุก 5 นาที → นับความผิดต่อคน → ไล่ระดับ ปิดเสียง → เตะ → แบน
 *
 * - discord_mod_strikes: หนึ่งแถว = AutoMod บล็อกหนึ่งครั้ง · unique audit_id กันนับซ้ำเมื่ออ่าน log ซ้อน
 * - discord_mod_actions: ทุกการลงโทษ (หรือที่ "จะ" ลงโทษในโหมดแจ้งเตือน) พร้อมผล — ใช้ตรวจย้อนหลังและนับเพดานต่อวัน
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('discord_mod_strikes', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id', 32)->unique()->comment('id ของรายการใน audit log — กันนับซ้ำ');
            $table->string('user_id', 32)->index();
            $table->string('rule_name', 120)->nullable();
            $table->unsignedSmallInteger('weight')->default(1);
            $table->string('channel_id', 32)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('discord_mod_actions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 32)->index();
            $table->string('action', 16)->comment('timeout | kick | ban | unban');
            $table->string('mode', 16)->comment('enforce = ลงโทษจริง · observe = แจ้งเตือนอย่างเดียว · manual = แอดมินกดเอง');
            $table->unsignedSmallInteger('score')->default(0)->comment('คะแนนความผิดตอนตัดสิน');
            $table->string('status', 16)->comment('done | failed | skipped | capped (เกินเพดานต่อวัน รอแอดมิน)');
            $table->string('reason', 255)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_mod_actions');
        Schema::dropIfExists('discord_mod_strikes');
    }
};
