<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX Stability Reserve — บันทึกทุกการตัดสินใจ ทุกธุรกรรม ทุกเงินฝาก และรายงานรายวัน
 *
 * ทำไมต้องเก็บแม้แต่รอบที่ "ไม่ทำอะไร": ความโปร่งใสที่ประกาศไว้คือทุกคนดูย้อนหลังได้ว่า
 * บอทเห็นอะไรและทำไมถึงเลือกแบบนั้น — เก็บเฉพาะรอบที่เทรดจะดูเหมือนซ่อนเหตุผลที่ไม่เทรด
 *
 * จำนวนเงินเก็บเป็นหน่วยเล็กสุด (wei) แบบสตริงเหมือนตารางคลัง — ห้ามรวมด้วย SUM ใน SQL
 * (MySQL แปลงสตริงเป็น double แล้วเศษหาย) ให้รวมใน PHP ด้วย bcmath เท่านั้น
 *
 * ⚠️ กับดัก deploy ของโปรเจกต์นี้ (เคยทำ deploy เขียวทั้งที่ migration พัง):
 *   - คอลัมน์เวลาที่ไม่ nullable ใช้ dateTime ห้าม timestamp — MySQL strict แจก default
 *     0000-00-00 ให้ TIMESTAMP NOT NULL แล้วตาย 1067 (SQLite ของเทสต์ไม่ฟ้อง)
 *   - ทุก create ครอบ hasTable — MySQL ไม่ทำ DDL แบบ transaction พังกลางทางแล้วรอบหน้าเจอ 1050 ซ้ำ
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stability_decisions')) {
            Schema::create('stability_decisions', function (Blueprint $table) {
                $table->id();
                $table->dateTime('decided_at')->index();
                // none | buy | sell | approve | wait
                $table->string('action', 16);
                $table->string('reason_code', 48);
                $table->decimal('pool_price', 36, 18)->nullable();
                $table->decimal('reference_price', 36, 18)->nullable();
                $table->decimal('band_low', 36, 18)->nullable();
                $table->decimal('band_high', 36, 18)->nullable();
                $table->string('reserve_tpix_wei', 80)->nullable();
                $table->string('reserve_usdt_wei', 80)->nullable();
                // ตัวเลขประกอบเหตุผล + พารามิเตอร์ที่ใช้ในรอบนั้น (ตรวจย้อนได้แม้แอดมินเปลี่ยนค่าทีหลัง)
                $table->json('details')->nullable();
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->timestamps();

                $table->index(['action', 'decided_at']);
            });
        }

        if (! Schema::hasTable('stability_transactions')) {
            Schema::create('stability_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('decision_id')->nullable()->index();
                // approve | swap
                $table->string('purpose', 16);
                // buy | sell | null (approve)
                $table->string('side', 8)->nullable();
                $table->string('token_in', 8)->nullable();
                $table->string('amount_in_wei', 80)->default('0');
                $table->string('expected_out_wei', 80)->nullable();
                $table->string('min_out_wei', 80)->nullable();
                $table->string('actual_out_wei', 80)->nullable();
                $table->decimal('price_before', 36, 18)->nullable();
                $table->decimal('price_after_expected', 36, 18)->nullable();
                $table->unsignedBigInteger('nonce');
                // บันทึก hash + raw ก่อนส่งขึ้นเชนเสมอ — ดับกลางคันแล้วรอบหน้ายังรู้ว่าเคยเซ็นอะไรไว้
                $table->string('tx_hash', 66)->unique();
                $table->text('raw_tx');
                $table->unsignedBigInteger('deadline_at')->nullable();
                // signed | broadcast | confirmed | failed | dropped
                $table->string('status', 16)->index();
                $table->unsignedBigInteger('block_number')->nullable();
                $table->string('failure_reason', 500)->nullable();
                $table->dateTime('signed_at')->nullable();
                $table->dateTime('broadcast_at')->nullable();
                $table->dateTime('finalized_at')->nullable();
                $table->timestamps();

                $table->index(['purpose', 'side', 'status']);
            });
        }

        if (! Schema::hasTable('stability_deposits')) {
            Schema::create('stability_deposits', function (Blueprint $table) {
                $table->id();
                // usdt | tpix
                $table->string('token', 8);
                $table->string('amount_wei', 80);
                $table->string('tx_hash', 66)->unique();
                $table->string('from_address', 42)->nullable();
                $table->unsignedBigInteger('block_number')->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stability_reports')) {
            Schema::create('stability_reports', function (Blueprint $table) {
                $table->id();
                $table->date('report_date')->unique();
                $table->json('stats');
                // ข้อความที่ระบบคำนวณเองจากตัวเลข (ไม่ใช้ AI) — มีเสมอ
                $table->text('facts_th');
                $table->text('facts_en');
                // ข้อความที่ AI เรียบเรียง — ว่างได้ถ้าไม่มีคีย์หรือ AI ตอบไม่ผ่านด่านตรวจ
                $table->text('summary_th')->nullable();
                $table->text('summary_en')->nullable();
                $table->string('ai_provider', 32)->nullable();
                $table->string('ai_model', 64)->nullable();
                $table->dateTime('generated_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stability_reports');
        Schema::dropIfExists('stability_deposits');
        Schema::dropIfExists('stability_transactions');
        Schema::dropIfExists('stability_decisions');
    }
};
