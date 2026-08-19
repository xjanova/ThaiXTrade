<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — บันทึกทุกครั้งที่บอท "คิด" ไม่ใช่เฉพาะตอนที่ลงมือ.
 *
 * เจ้าของสั่งให้เริ่มรันบนคลาวด์แล้วเก็บข้อมูลไว้ เพื่อเอามาปรับปรุงกลยุทธ์ทีหลัง
 *
 * ⚠️ ตาราง ai_bot_trades เก็บเฉพาะไม้ที่ "เข้าจริง" — ซึ่งเป็นส่วนน้อยมาก
 *    ข้อมูลที่มีค่าที่สุดสำหรับการปรับปรุงคือ "ทำไมถึงไม่ทำอะไร" ซึ่งเดิมเขียนทับ
 *    ลงช่อง last_reason ของบอทแล้วหายไปทุกรอบ เหลือให้ดูแค่รอบล่าสุดรอบเดียว
 *
 * ตัวอย่างคำถามที่ตอบได้ก็ต่อเมื่อมีตารางนี้:
 *   - กลยุทธ์ไหนถูกด่านความเสี่ยงห้ามบ่อยที่สุด และห้ามด้วยเหตุผลอะไร
 *   - เกณฑ์ความมั่นใจที่ตั้งไว้ ทำให้พลาดจังหวะไปกี่ครั้ง
 *   - บอทที่ไม่เคยเข้าไม้เลย ติดอยู่ที่เงื่อนไขข้อไหน
 *
 * เก็บราคาไว้ด้วย เพื่อย้อนดูได้ว่า "ตอนนั้นตัดสินใจแบบนี้แล้วราคาไปทางไหนต่อ"
 * โดยไม่ต้องพึ่งข้อมูลตลาดย้อนหลังที่อาจถูกแก้ไขภายหลัง
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_bot_decisions')) {
            return;
        }

        Schema::create('ai_bot_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_bot_config_id')->constrained('ai_bot_configs')->cascadeOnDelete();
            $table->string('wallet_address', 42)->index();
            $table->string('strategy', 40);
            $table->string('pair', 32);
            $table->string('timeframe', 8);
            $table->string('mode', 8)->default('demo');

            $table->string('action', 16)->comment('buy · sell · hold · signal · stopped · error');
            $table->text('reason');
            $table->string('risk_level', 16)->default('calm');

            $table->decimal('price', 24, 8)->nullable()->comment('ราคาตลาดตอนตัดสินใจ');
            $table->decimal('budget', 24, 8)->nullable()->comment('งบที่จะใช้ถ้าลงมือ');
            $table->boolean('has_position')->default(false);

            // สแนปช็อตของสิ่งที่กลยุทธ์เห็นตอนนั้น — ใช้ย้อนสร้างเหตุการณ์ได้
            $table->json('signal_meta')->nullable();
            $table->json('params')->nullable();

            $table->timestamps();

            // คิวรีหลักคือ "กลยุทธ์นี้ในช่วงเวลานี้ตัดสินใจอะไรบ้าง"
            $table->index(['strategy', 'created_at'], 'idx_decision_strategy_time');
            $table->index(['ai_bot_config_id', 'created_at'], 'idx_decision_bot_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_bot_decisions');
    }
};
