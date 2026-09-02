<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — กระเป๋าบอท: กระเป๋าแยกต่อผู้ใช้ที่บอทใช้ลงมือในโหมดจริง.
 *
 * ทำไมต้องแยกกระเป๋า: บอทกับผู้ใช้ต้องเทรดพร้อมกันได้โดยไม่แย่งเงินกัน และเราต้อง
 * คิดค่าธรรมเนียม/วัดผลจากยอดที่ "เป็นของบอทเท่านั้น" ผู้ใช้โอนเข้ามาเท่าไหร่
 * คือทุนของบอทเท่านั้น กระเป๋าหลักที่ผู้ใช้เซ็นเองไม่ถูกแตะเลย
 *
 * key_ciphertext เก็บกุญแจที่ห่อสองชั้นแล้ว (ดู BotWalletKeyring) — คอลัมน์นี้ห้าม
 * โผล่ใน API/log ทุกกรณี โมเดลซ่อนไว้ใน $hidden
 *
 * ai_bot_wallet_transfers คือคิวถอน (และบันทึกฝากที่ตรวจพบ) — ถอนได้ทางเดียวคือ
 * กลับไป to_address = เจ้าของ ตัวส่งจริงคือคำสั่ง aibot:wallet-transfers ฝั่ง CLI
 * ตามลำดับ เซ็น → บันทึก tx_hash → ส่ง (ห้ามสลับ ไม่งั้นจ่ายซ้ำได้)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_bot_wallets')) {
            Schema::create('ai_bot_wallets', function (Blueprint $table) {
                $table->id();
                $table->string('owner_address', 42)->unique()->comment('กระเป๋าของผู้ใช้ที่ยืนยันแล้ว (เจ้าของ)');
                $table->unsignedInteger('chain_id');
                $table->string('address', 42)->unique()->comment('ที่อยู่กระเป๋าบอท (ระบบสร้าง)');
                $table->text('key_ciphertext');
                $table->unsignedSmallInteger('key_version')->default(1);
                $table->enum('status', ['active', 'locked'])->default('active');
                $table->json('balances')->nullable()->comment('ยอดล่าสุดที่อ่านจากเชน {asset: amount}');
                $table->timestamp('balances_at')->nullable();
                $table->timestamp('last_deposit_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_bot_wallet_transfers')) {
            Schema::create('ai_bot_wallet_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ai_bot_wallet_id')->constrained('ai_bot_wallets')->cascadeOnDelete();
                $table->string('owner_address', 42)->index();
                $table->enum('direction', ['withdraw', 'deposit']);
                $table->string('asset', 12);
                $table->string('token_address', 42)->nullable()->comment('null = เหรียญหลักของเชน');
                $table->decimal('amount', 36, 18);
                $table->string('amount_wei', 80);
                $table->string('to_address', 42);
                $table->enum('status', ['queued', 'signing', 'broadcasting', 'confirmed', 'failed', 'cancelled'])->default('queued');
                $table->string('tx_hash', 66)->nullable()->unique();
                $table->unsignedBigInteger('nonce')->nullable();
                $table->unsignedBigInteger('block_number')->nullable();
                $table->unsignedInteger('confirmations')->default(0);
                $table->text('failure_reason')->nullable();
                $table->string('requested_ip', 45)->nullable();
                $table->timestamp('broadcast_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->index(['owner_address', 'status'], 'idx_bot_wallet_transfer_owner_status');
                $table->index(['status', 'direction'], 'idx_bot_wallet_transfer_queue');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_bot_wallet_transfers');
        Schema::dropIfExists('ai_bot_wallets');
    }
};
