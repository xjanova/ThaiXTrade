<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — มุมมองตลาดที่ AI สรุปไว้เป็นรอบ.
 *
 * เจ้าของสั่ง: ให้ OpenAI เข้ามาร่วมตัดสินใจเข้าไม้/ออกไม้ จากข่าวทั่วโลกและแนวโน้ม
 * ตลาดจริง รวมถึงเลือกเหรียญที่จะเทรด — แต่ **เป็นรอบ ไม่ใช่ทุกไม้**
 *
 * ทำไมต้องเป็นรอบ (เหตุผลเชิงตัวเลข ไม่ใช่แค่ประหยัด):
 *   ยิงต่อไม้ = 7 บอท × 1,440 นาที = 10,080 ครั้ง/วัน ซึ่งนอกจากแพงแล้วยัง
 *   **ให้ผลแย่กว่า** เพราะบริบทรอบเดียวกันถูกถามซ้ำหลายพันครั้งด้วยคำตอบที่
 *   ไม่คงที่ — บอทสองตัวบนเหรียญเดียวกันจะได้คำตอบคนละทางในนาทีเดียวกัน
 *
 *   สรุปเป็นรอบแล้วเก็บลงตารางนี้ = ทุกบอทอ่านมุมมองใบเดียวกัน สอดคล้องกัน
 *   ย้อนตรวจได้ว่า "ตอนนั้น AI คิดอะไร" และเทียบกับผลที่เกิดขึ้นจริงได้ภายหลัง
 *
 * สองจังหวะตามแพลน (เจ้าของกำหนด):
 *   strategic = ทุก 4 ชม.  — ทุกแพลน · จัดอันดับเหรียญ + ภาพรวมตลาด
 *   tactical  = ทุก 15 นาที — แพลนสูง · ปรับท่าทีระยะสั้นตามข่าวที่เพิ่งเข้า
 *
 * ⚠️ เก็บ prompt + คำตอบดิบไว้ทั้งคู่ เพราะการตัดสินใจเรื่องเงินที่อธิบายไม่ได้
 *    คือสิ่งที่เราตั้งใจหลีกเลี่ยงมาตลอดทั้งระบบ ถ้าวันหนึ่งบอทเสียเงินเพราะ
 *    มุมมองใบไหน ต้องเปิดดูได้ว่ามันเห็นอะไรและตอบว่าอะไร
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_market_views')) {
            return;
        }

        Schema::create('ai_market_views', function (Blueprint $table) {
            $table->id();

            $table->string('scope', 16)->comment('strategic (4 ชม.) · tactical (15 นาที)');
            $table->string('provider', 24)->comment('openai · gemini');
            $table->string('model', 64);

            /*
             * ท่าทีรวมของตลาด — บอททุกตัวใช้ค่านี้เป็นตัวคูณขนาดไม้
             * risk_on / neutral / risk_off
             */
            $table->string('regime', 16)->default('neutral');

            /* 0..1 — AI มั่นใจกับมุมมองรอบนี้แค่ไหน ใช้ถ่วงน้ำหนักก่อนเอาไปใช้จริง */
            $table->decimal('confidence', 4, 3)->default(0);

            /*
             * ตัวคูณขนาดไม้ที่แนะนำ (0 = ห้ามเข้าไม้ใหม่)
             * เก็บแยกจาก regime เพราะ regime เป็นป้ายให้คนอ่าน ส่วนตัวนี้เอาไปคูณจริง
             */
            $table->decimal('size_multiplier', 4, 2)->default(1);

            /*
             * คะแนนรายเหรียญ — { "BTC": {"score": 0.62, "stance": "buy", "why": "..."}, ... }
             * score -1..+1 · stance = buy / hold / avoid / exit
             */
            $table->json('coins')->nullable();

            /* คู่เทรดที่ AI คัดว่าน่าสนใจที่สุดรอบนี้ เรียงจากดีไปหาน้อย */
            $table->json('shortlist')->nullable();

            /* สรุปเป็นภาษาไทยให้ผู้ใช้อ่านในหน้าเว็บ */
            $table->text('summary')->nullable();

            /* หัวข้อข่าวที่ AI อ้างถึง — ให้ผู้ใช้กดดูต้นทางได้ */
            $table->json('headlines')->nullable();

            /* ย้อนตรวจได้ว่าเห็นอะไรและตอบว่าอะไร */
            $table->longText('prompt')->nullable();
            $table->longText('raw_response')->nullable();

            $table->unsignedInteger('tokens_used')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);

            /*
             * มุมมองหมดอายุเมื่อไหร่ — บอทต้องไม่ใช้ของเก่าเกินไป
             *
             * ถ้าไม่มีวันหมดอายุ แล้ววันหนึ่ง OpenAI ล่มยาว บอทจะเดินตามมุมมอง
             * เมื่อวานต่อไปเรื่อยๆ โดยไม่มีอะไรฟ้อง — อันตรายกว่าไม่มีมุมมองเลย
             * เพราะไม่มีมุมมอง = ถอยไปใช้กฎล้วน ซึ่งปลอดภัยกว่าตามข่าวที่ตายแล้ว
             */
            $table->timestamp('expires_at')->index();

            $table->timestamps();

            $table->index(['scope', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_market_views');
    }
};
