<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Copy Trade เฟส C — rank ของมาสเตอร์ + ดาวรีวิวจากผู้ตาม (เจ้าของสั่ง 2026-11-13)
 *
 *   ai_copy_masters.rank_*      ระดับปัจจุบัน คะแนน และ "เริ่มต่ำกว่าเกณฑ์ตั้งแต่เมื่อไหร่" (ช่วงผ่อนก่อนลดระดับ)
 *   ai_copy_rank_history        ทุกครั้งที่ระดับเปลี่ยน พร้อมคะแนนและส่วนประกอบตอนนั้น
 *   ai_copy_reviews             ผู้ตามหนึ่งคนรีวิวมาสเตอร์หนึ่งคนได้หนึ่งรีวิว (แก้ได้)
 *   ai_copy_review_reports      การรายงานรีวิว — กระเป๋าหนึ่งใบรายงานรีวิวหนึ่งอันได้ครั้งเดียว
 *
 * ⚠️ เวลาทางธุรกิจใช้ dateTime ทั้งหมด (BusinessTimeColumnsTest)
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('ai_copy_masters', function (Blueprint $table) {
            // 0 = ยังไม่มีระดับ (ผลงานต่ำกว่าเกณฑ์มาสเตอร์) · 1..5 ตาม config aibot.copy_trade.rank.tiers
            $table->unsignedTinyInteger('rank_tier')->default(0)->after('status');
            $table->decimal('rank_score', 6, 2)->default(0)->after('rank_tier');
            $table->dateTime('rank_below_since')->nullable()->after('rank_score');
            $table->dateTime('rank_evaluated_at')->nullable()->after('rank_below_since');
            $table->decimal('rating_avg', 3, 2)->nullable()->after('rank_evaluated_at');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_avg');
        });

        Schema::create('ai_copy_rank_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_copy_master_id')->constrained('ai_copy_masters')->cascadeOnDelete();
            $table->unsignedTinyInteger('from_tier');
            $table->unsignedTinyInteger('to_tier');
            $table->decimal('score', 6, 2);
            $table->json('components')->nullable();
            $table->string('reason', 32);
            $table->dateTime('changed_at');
            $table->timestamps();

            $table->index(['ai_copy_master_id', 'changed_at']);
        });

        Schema::create('ai_copy_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_copy_master_id')->constrained('ai_copy_masters')->cascadeOnDelete();
            $table->string('reviewer_wallet', 42);
            $table->unsignedTinyInteger('stars');
            $table->string('comment', 600)->nullable();
            // visible = โชว์และนับในค่าเฉลี่ย · hidden = ทีมงานซ่อน (ไม่นับ)
            $table->string('status', 16)->default('visible');
            $table->unsignedInteger('reports')->default(0);
            $table->timestamps();

            $table->unique(['ai_copy_master_id', 'reviewer_wallet']);
            $table->index(['ai_copy_master_id', 'status']);
        });

        Schema::create('ai_copy_review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_copy_review_id')->constrained('ai_copy_reviews')->cascadeOnDelete();
            $table->string('reporter_wallet', 42);
            $table->string('reason', 200)->nullable();
            $table->timestamps();

            $table->unique(['ai_copy_review_id', 'reporter_wallet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_copy_review_reports');
        Schema::dropIfExists('ai_copy_reviews');
        Schema::dropIfExists('ai_copy_rank_history');

        Schema::table('ai_copy_masters', function (Blueprint $table) {
            $table->dropColumn(['rank_tier', 'rank_score', 'rank_below_since', 'rank_evaluated_at', 'rating_avg', 'rating_count']);
        });
    }
};
