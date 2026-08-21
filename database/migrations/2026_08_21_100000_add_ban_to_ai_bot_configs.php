<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — ให้ทีมงานแบนบอทที่มีปัญหาได้.
 *
 * ทำไมไม่ใช้ status = 'stopped' ที่มีอยู่แล้ว: เจ้าของบอทกดเริ่มใหม่เองได้ทันที
 * การแบนจึงไม่มีผลอะไรเลยนอกจากทำให้บอทหยุดไปหนึ่งนาที — ต้องเป็นคนละแกน
 * กับสถานะที่เจ้าของคุมเอง ไม่งั้นเป็นแค่ปุ่มที่ดูเหมือนทำงาน
 *
 * เก็บเหตุผลกับคนสั่งไว้ด้วย เพราะการปิดกั้นของผู้ใช้ต้องอธิบายได้ว่าใครสั่งและเพราะอะไร
 * โดยเฉพาะเมื่อผู้ใช้จ่ายเงินค่าเช่าไว้แล้ว
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('ai_bot_configs', function (Blueprint $table) {
            // index เพราะทุกรอบของ engine ต้องกรองบอทที่ถูกแบนออก
            $table->timestamp('banned_at')->nullable()->index()->after('status');
            $table->string('banned_reason', 255)->nullable()->after('banned_at');
            $table->string('banned_by', 191)->nullable()->after('banned_reason');
        });
    }

    public function down(): void
    {
        Schema::table('ai_bot_configs', function (Blueprint $table) {
            $table->dropIndex(['banned_at']);
            $table->dropColumn(['banned_at', 'banned_reason', 'banned_by']);
        });
    }
};
