<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — นับรอบที่ "สภาพเดิม" ซ้ำในแถวเดิม แทนการแทรกแถวใหม่ทุกนาที.
 *
 * บอทคลาวด์ระดับ VIP ถูกปลุกทุกนาที แต่ 6 ใน 7 ตัวตัดสินใจจากแท่ง 1 ชั่วโมง
 * ที่ปิดแล้ว → เห็นภาพเดิมเป๊ะ 60 รอบต่อแท่ง แล้วบันทึกเป็น 60 แถวที่เหมือนกัน
 * ทุกตัวอักษร วัดบน prod 2 ก.ย. 2026: 81,105 แถว / 46.7 MB ใน 13 วัน
 * จน `aibot:harvest` ตายด้วย memory limit 128M
 *
 * repeat_count = รอบที่สภาพนี้คงอยู่ · last_seen_at = รอบล่าสุดที่ยังเป็นแบบนี้
 * (created_at ยังเป็นรอบแรกที่เจอ จึงยังย้อนดูได้ว่าสภาพนั้นเริ่มเมื่อไหร่)
 *
 * แถวเก่าไม่มี last_seen_at — ให้ถือว่าเท่ากับ created_at (สภาพที่เห็นรอบเดียว)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_bot_decisions') || Schema::hasColumn('ai_bot_decisions', 'repeat_count')) {
            return;
        }

        Schema::table('ai_bot_decisions', function (Blueprint $table) {
            $table->unsignedInteger('repeat_count')->default(1)->after('params')
                ->comment('กี่รอบติดกันที่บอทเห็นสภาพนี้เหมือนเดิม');
            $table->timestamp('last_seen_at')->nullable()->after('repeat_count')
                ->comment('รอบล่าสุดที่สภาพนี้ยังคงอยู่ (null = เห็นรอบเดียว)');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_bot_decisions') || ! Schema::hasColumn('ai_bot_decisions', 'repeat_count')) {
            return;
        }

        Schema::table('ai_bot_decisions', function (Blueprint $table) {
            $table->dropColumn(['repeat_count', 'last_seen_at']);
        });
    }
};
