<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ทางจาก "stake ชั้น Validator" ไปถึง "ปิดบล็อกได้จริง" (2026-10-22)
 *
 * validator_key_bindings — กระเป๋าที่ stake ↔ กุญแจที่ปิดบล็อก (ที่อยู่ IBFT + BLS + node ID)
 *   สัญญา NodeRegistryV2 ไม่มีช่องเก็บกุญแจพวกนี้ จึงผูกนอกเชนด้วยลายเซ็น 2 ใบ
 *   (กระเป๋าที่ stake + กุญแจ validator เอง) ไม่ต้องแก้สัญญา
 *
 * validator_seal_stats — ผลการเฝ้าดูรายตัว (validators:seal-monitor ทุกนาที)
 *   ใครเสนอบล็อกล่าสุดเมื่อไหร่ ใครเซ็นรับรองล่าสุดเมื่อไหร่ กี่ % ในหน้าต่างล่าสุด
 *
 * validator_kyc_actions — บันทึกการกด submit/approve/reject KYC ที่เซิร์ฟเวอร์ตรวจกับเชนแล้ว
 *   โยง hash บนเชนกลับไปหาใบ KYC ของเว็บ (ไม่มีข้อมูลส่วนตัวในตารางนี้)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('validator_key_bindings')) {
            Schema::create('validator_key_bindings', function (Blueprint $table) {
                $table->id();
                $table->string('staking_wallet', 42)->index()->comment('กระเป๋าที่ stake ชั้น Validator (ตัวพิมพ์เล็ก)');
                $table->string('validator_address', 42)->index()->comment('ที่อยู่ ECDSA ที่ IBFT ใช้ปิดบล็อก');
                $table->string('bls_public_key', 98)->comment('0x + 96 hex');
                $table->string('node_id', 64)->comment('libp2p peer ID');
                $table->text('message')->comment('ข้อความที่ทั้งสองกุญแจเซ็น — เก็บไว้ให้ตรวจซ้ำได้');
                $table->string('staking_signature', 132);
                $table->string('validator_signature', 132);
                $table->unsignedBigInteger('signed_at')->comment('unix time ในข้อความ');
                $table->string('status', 16)->default('active')->index()->comment('active | superseded');
                $table->timestamp('voted_in_at')->nullable()->comment('เห็นในชุด validator ครั้งแรก');
                $table->timestamp('left_set_at')->nullable()->comment('หายจากชุด validator');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('validator_seal_stats')) {
            Schema::create('validator_seal_stats', function (Blueprint $table) {
                $table->id();
                $table->string('address', 42)->unique();
                $table->string('bls_public_key', 98)->nullable();
                $table->boolean('in_set')->default(false)->index();
                $table->unsignedSmallInteger('set_index')->nullable();
                $table->unsignedBigInteger('first_seen_block')->nullable();
                $table->unsignedBigInteger('last_proposed_block')->nullable();
                $table->unsignedBigInteger('last_signed_block')->nullable();
                $table->unsignedInteger('window_blocks')->default(0)->comment('บล็อกในหน้าต่างที่ตัวนี้อยู่ในชุด');
                $table->unsignedInteger('window_signed')->default(0);
                $table->unsignedInteger('window_proposed')->default(0);
                $table->unsignedInteger('window_missed_turns')->default(0)->comment('ถึงคิวเสนอแต่ต้องเปลี่ยน round');
                $table->unsignedBigInteger('scanned_to_block')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('validator_kyc_actions')) {
            Schema::create('validator_kyc_actions', function (Blueprint $table) {
                $table->id();
                $table->string('applicant', 42)->index();
                $table->string('action', 16)->comment('submit | approve | reject');
                $table->string('tx_hash', 66)->unique();
                $table->string('signer', 42)->comment('กระเป๋า owner ที่เซ็น (จาก tx จริง)');
                $table->string('kyc_hash', 66)->nullable();
                $table->foreignId('kyc_submission_id')->nullable()->constrained('kyc_submissions')->nullOnDelete();
                $table->string('note', 500)->nullable()->comment('เหตุผลเมื่อใส่ hash เอง (ไม่ได้มาจากใบ KYC เว็บ)');
                $table->string('chain_status', 16)->comment('สถานะบนสัญญาหลัง tx');
                $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->unsignedBigInteger('block_number')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('validator_kyc_actions');
        Schema::dropIfExists('validator_seal_stats');
        Schema::dropIfExists('validator_key_bindings');
    }
};
