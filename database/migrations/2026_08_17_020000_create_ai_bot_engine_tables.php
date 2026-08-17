<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — ตารางของ engine ที่รันบอทจริง.
 *
 *   market_news          — ข่าวตลาดที่ดึงมาจริง + คะแนนความตื่นตระหนก
 *   ai_bot_demo_accounts — กระเป๋าทดลอง (เครดิตเดโม) แยกจากเครดิตจริงเด็ดขาด
 *   ai_bot_positions     — ของที่บอทถืออยู่
 *   ai_bot_trades        — ทุกไม้ที่บอทลงมือ พร้อมเหตุผล
 *
 * เพิ่ม `mode` ให้ ai_bot_configs: demo = เทรดกระดาษด้วยราคาจริง · live = เทรดจริง
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('market_news', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40)->index();
            $table->string('title', 500);
            // ชี้ซ้ำจากฟีดได้บ่อย — กันบันทึกข่าวเดิมซ้ำด้วย hash ของลิงก์
            $table->string('url_hash', 64)->unique();
            $table->text('url');
            $table->timestamp('published_at')->index();
            $table->decimal('panic_score', 6, 3)->default(0)->comment('0 = ปกติ, 1 = ตื่นตระหนกสุด');
            $table->decimal('sentiment', 6, 3)->default(0)->comment('-1 ลบ .. +1 บวก');
            $table->json('symbols')->nullable()->comment('เหรียญที่ข่าวพาดพิงถึง');
            $table->json('matched_terms')->nullable();
            $table->timestamps();

            $table->index(['published_at', 'panic_score'], 'idx_news_risk');
        });

        Schema::create('ai_bot_demo_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_address', 42)->unique();
            $table->decimal('balance', 24, 8)->comment('USDT จำลอง');
            $table->decimal('starting_balance', 24, 8);
            $table->unsignedInteger('reset_count')->default(0);
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_bot_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_bot_config_id')->constrained('ai_bot_configs')->cascadeOnDelete();
            $table->string('pair', 24);
            $table->enum('mode', ['demo', 'live'])->default('demo');
            $table->decimal('quantity', 32, 12);
            $table->decimal('entry_price', 32, 12)->comment('ต้นทุนเฉลี่ย (DCA เติมไม้แล้วเฉลี่ยใหม่)');
            $table->decimal('cost_basis', 24, 8)->comment('เงินที่จ่ายไปทั้งหมดรวมค่าธรรมเนียม');
            $table->unsignedInteger('entry_count')->default(1);
            $table->timestamp('opened_at');
            $table->timestamps();

            // บอทหนึ่งตัวถือได้คู่เดียวต่อโหมด — กันสถานะซ้อนจนคำนวณกำไรเพี้ยน
            $table->unique(['ai_bot_config_id', 'mode'], 'idx_position_unique_bot');
        });

        Schema::create('ai_bot_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_bot_config_id')->constrained('ai_bot_configs')->cascadeOnDelete();
            $table->string('wallet_address', 42)->index();
            $table->string('pair', 24);
            $table->enum('mode', ['demo', 'live'])->default('demo');
            $table->enum('side', ['buy', 'sell']);
            $table->decimal('price', 32, 12);
            $table->decimal('quantity', 32, 12);
            $table->decimal('gross_value', 24, 8);
            $table->decimal('fee', 24, 8)->default(0);
            $table->decimal('slippage_cost', 24, 8)->default(0);
            $table->decimal('realized_pnl', 24, 8)->nullable()->comment('เฉพาะไม้ขาย');
            $table->string('strategy', 40);
            $table->text('reason');
            $table->json('signal_meta')->nullable();
            $table->string('risk_level', 20)->default('calm');
            $table->timestamps();

            $table->index(['ai_bot_config_id', 'created_at'], 'idx_trade_bot_time');
        });

        Schema::table('ai_bot_configs', function (Blueprint $table) {
            $table->enum('mode', ['demo', 'live'])->default('demo')->after('status')
                ->comment('demo = เทรดกระดาษด้วยราคาจริง, live = เทรดจริง');
            $table->timestamp('last_signal_at')->nullable()->after('last_run_at');
            $table->text('last_reason')->nullable()->after('last_signal_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_bot_configs', function (Blueprint $table) {
            $table->dropColumn(['mode', 'last_signal_at', 'last_reason']);
        });

        Schema::dropIfExists('ai_bot_trades');
        Schema::dropIfExists('ai_bot_positions');
        Schema::dropIfExists('ai_bot_demo_accounts');
        Schema::dropIfExists('market_news');
    }
};
