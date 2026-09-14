<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — ข้อความที่บอท Discord โพสต์ไปแล้ว (2026-09-14).
 *
 * เจ้าของสั่ง: บอทต้องอัปเดตข่าว วิดีโอ กฎ และการขายเหรียญในเซิร์ฟเวอร์ Discord เอง "ตามจริง"
 *
 * ตารางนี้ทำให้การโพสต์ "รันซ้ำได้":
 *   - ข้อความประจำห้อง (สถานะการขาย / กฎ / whitepaper) ต้อง "แก้ข้อความเดิม" เมื่อข้อมูลเปลี่ยน
 *     ไม่ใช่โพสต์ใหม่ทุก 10 นาทีจนห้องรก → เก็บ message_id + hash ของเนื้อหาไว้
 *   - ข่าว/วิดีโอโพสต์ครั้งเดียวต่อชิ้น → unique (kind, ref_key) กันโพสต์ซ้ำแม้ cron ซ้อนกัน
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('discord_posts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32)->comment('sale_status | rules | whitepaper | ask_hint | article | video');
            $table->string('ref_key', 191)->comment('main สำหรับข้อความประจำห้อง / id บทความ / รหัสตอนวิดีโอ');
            $table->string('channel_id', 32);
            $table->string('message_id', 32)->nullable();
            $table->string('content_hash', 64)->nullable()->comment('sha1 ของเนื้อหาล่าสุดที่โพสต์ — ไม่เปลี่ยน = ไม่ต้องแก้');
            $table->text('last_error')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'ref_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_posts');
    }
};
