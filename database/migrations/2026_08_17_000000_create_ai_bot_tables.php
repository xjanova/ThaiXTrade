<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — AI Trade (Cloud Bot) tables.
 *
 * ระบบเช่าบอทเทรดบนคลาวด์ ใช้ร่วมกันระหว่างเว็บและแอพมือถือ:
 *   ai_bot_plans         — แพลนให้เช่า (แอดมินปรับราคา/จำนวนบอทได้)
 *   ai_bot_credits       — ledger เครดิตการทำงาน (แหล่งความจริงเดียวของยอดคงเหลือ)
 *   ai_bot_subscriptions — การเช่าที่ยังไม่หมดอายุของแต่ละ wallet
 *   ai_bot_configs       — บอทที่ผู้ใช้ตั้งไว้ (กลยุทธ์ + พารามิเตอร์ + กรอบความเสี่ยง)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ai_bot_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('name_th')->nullable();
            $table->text('description')->nullable();
            $table->text('description_th')->nullable();
            $table->enum('tier', ['basic', 'pro', 'vip'])->default('basic');
            $table->unsignedInteger('credits_per_day')->default(0)->comment('เครดิตที่ตัดต่อวันเช่า');
            $table->unsignedSmallInteger('max_bots')->default(1);
            $table->decimal('max_capital_usd', 18, 2)->nullable()->comment('null = ไม่จำกัด');
            $table->json('features')->nullable()->comment('จุดเด่น (EN)');
            $table->json('features_th')->nullable();
            $table->string('badge', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('ai_bot_credits', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_address', 42)->index();
            $table->enum('type', ['topup', 'charge', 'refund', 'bonus', 'adjustment']);
            $table->decimal('amount', 20, 4)->comment('บวก = เพิ่ม, ลบ = ตัด');
            $table->decimal('balance_after', 20, 4);
            $table->string('reference', 120)->nullable()->comment('เช่น subscribe:12, pack_1500');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_address', 'created_at']);
            // กันโบนัสต้อนรับซ้ำแบบ race — 1 wallet มีได้แถวเดียวที่ reference = welcome
            $table->unique(['wallet_address', 'reference'], 'idx_credit_unique_reference');
        });

        Schema::create('ai_bot_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_address', 42)->index();
            $table->foreignId('ai_bot_plan_id')->constrained('ai_bot_plans');
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->unsignedSmallInteger('days')->default(1);
            $table->decimal('credits_spent', 20, 4)->default(0);
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_address', 'status', 'expires_at'], 'idx_sub_lookup');
        });

        Schema::create('ai_bot_configs', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_address', 42)->index();
            $table->foreignId('ai_bot_subscription_id')->nullable()
                ->constrained('ai_bot_subscriptions')->nullOnDelete();
            $table->string('name', 60);
            $table->string('pair', 24)->comment('เช่น BTC/USDT');
            $table->string('strategy', 40)->comment('code จาก config/aibot.php');
            $table->string('timeframe', 8)->default('1h');
            $table->json('params')->nullable();
            $table->json('risk')->nullable();
            $table->enum('status', ['draft', 'running', 'paused', 'stopped'])->default('draft')->index();
            $table->timestamp('last_run_at')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();

            $table->index(['wallet_address', 'status'], 'idx_bot_wallet_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_bot_configs');
        Schema::dropIfExists('ai_bot_subscriptions');
        Schema::dropIfExists('ai_bot_credits');
        Schema::dropIfExists('ai_bot_plans');
    }
};
