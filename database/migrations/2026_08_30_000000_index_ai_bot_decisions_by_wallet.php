<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — ดัชนีสำหรับหน้ามอนิเตอร์ของเจ้าของบอท.
 *
 * เดิมตารางนี้มีดัชนีสำหรับคิวรีของหลังบ้านเท่านั้น (ตามกลยุทธ์ · ตามบอท)
 * พอเปิด GET /api/v1/ai-bot/decisions ให้เจ้าของบอทเลื่อนดูย้อนหลัง คิวรีหลัก
 * กลายเป็น "ของกระเป๋านี้ เรียงใหม่ไปเก่า" ซึ่งดัชนี wallet_address เดี่ยวๆ
 * พาไปได้แค่ครึ่งทาง — ที่เหลือต้อง filesort ทุกครั้ง และตารางนี้โตวันละหลายพันแถว
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_bot_decisions')) {
            return;
        }

        Schema::table('ai_bot_decisions', function (Blueprint $table) {
            $table->index(['wallet_address', 'id'], 'idx_decision_wallet_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_bot_decisions')) {
            return;
        }

        Schema::table('ai_bot_decisions', function (Blueprint $table) {
            $table->dropIndex('idx_decision_wallet_id');
        });
    }
};
