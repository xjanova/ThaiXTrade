<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — คลังเหรียญสองฝั่งสำหรับกลยุทธ์อาร์บิทราจ.
 *
 * อาร์บิทราจต่างจากกลยุทธ์อื่นตรงที่ "ต้องลงมือได้ทันที" ทั้งสองทิศ:
 * ถ้าฝั่งหนึ่งแพงกว่า ต้องขายฝั่งนั้นและซื้ออีกฝั่งพร้อมกันในจังหวะเดียว
 *
 * กลยุทธ์อื่นถือแค่เงินสด (quote) แล้วค่อยซื้อของเมื่อเจอสัญญาณ — ทำแบบนั้นกับ
 * อาร์บิทราจไม่ได้ เพราะกว่าจะซื้อของมาได้ ส่วนต่างก็ปิดไปแล้ว
 * ต้องมีของ (base) กับเงิน (quote) ค้างไว้ทั้งคู่ตลอดเวลา
 *
 * ⚠️ ยอดในตารางนี้เป็น "ของที่กันไว้แล้ว" — แยกจากยอดเงินอิสระใน
 *    ai_bot_demo_accounts เพื่อไม่ให้บอทตัวอื่นเอาไปใช้จนอาร์บิทราจลงมือไม่ได้
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_bot_reserves')) {
            return;
        }

        Schema::create('ai_bot_reserves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_bot_config_id')->constrained('ai_bot_configs')->cascadeOnDelete();
            $table->string('wallet_address', 42)->index();
            $table->string('pair', 24);
            $table->enum('mode', ['demo', 'live'])->default('demo');

            // สองฝั่งของคู่เทรด — ต้องมีทั้งคู่ถึงจะลงมือทันทีได้
            $table->decimal('base_qty', 32, 12)->default(0)->comment('จำนวนเหรียญฝั่งซ้าย เช่น BTC');
            $table->decimal('quote_amount', 24, 8)->default(0)->comment('เงินฝั่งขวา เช่น USDT');

            // ทุนที่ใส่เข้ามาทั้งหมด ใช้วัดว่าคลังนี้ทำกำไรได้เท่าไหร่
            $table->decimal('funded_quote', 24, 8)->default(0);
            // ราคาตอนใส่ทุน — ใช้ตีมูลค่าคลังเพื่อวัด "ฝีมือจับส่วนต่าง" แยกจากทิศทางราคา
            $table->decimal('reference_price', 32, 12)->default(0);
            $table->decimal('realized_pnl', 24, 8)->default(0);
            $table->unsignedInteger('round_trips')->default(0)->comment('จำนวนครั้งที่จับส่วนต่างสำเร็จ');

            $table->timestamp('last_action_at')->nullable();
            $table->timestamps();

            // บอทหนึ่งตัวมีคลังเดียวต่อโหมด
            $table->unique(['ai_bot_config_id', 'mode'], 'idx_reserve_unique_bot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_bot_reserves');
    }
};
