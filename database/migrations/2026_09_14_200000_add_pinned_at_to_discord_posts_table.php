<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — จำว่าข้อความประจำห้องปักหมุดสำเร็จแล้วหรือยัง (2026-09-14).
 *
 * รอบแรกบอทปักหมุดไม่ติดทั้ง 7 ข้อความ (Discord ย้ายการปักหมุดไปสิทธิ์ใหม่ "Pin Messages")
 * ถ้าปักเฉพาะตอนสร้าง ข้อความที่โพสต์ไปแล้วจะไม่ถูกปักอีกเลย → จดไว้ ปักไม่ติด = รอบหน้าลองใหม่
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('discord_posts', 'pinned_at')) {
            return;
        }

        Schema::table('discord_posts', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('posted_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('discord_posts', 'pinned_at')) {
            Schema::table('discord_posts', function (Blueprint $table) {
                $table->dropColumn('pinned_at');
            });
        }
    }
};
