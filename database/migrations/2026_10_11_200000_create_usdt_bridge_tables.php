<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * สะพาน USDT หลายเชน → TPIX Chain
 *
 * bridge_deposits     — Transfer(USDT → vault) บนเชนต้นทางที่ finalized แล้ว หนึ่งแถวต่อหนึ่ง log
 * bridge_withdrawals  — Burned ของ USDT_TPIX บนเชน 4289 หนึ่งแถวต่อหนึ่ง log
 * bridge_reserve_snapshots — ผลกระทบยอด supply vs เงินหนุนทุก 5 นาที (หน้า proof of reserves อ่านจากนี่)
 *
 * จำนวนเงินเก็บเป็น "หน่วยเล็กสุด" แบบจำนวนเต็มในคอลัมน์ข้อความ แล้วคำนวณด้วย bcmath — ห้ามแตะ float
 *   *_units  = 6 ทศนิยมแบบ USDT_TPIX (1 USDT = 1000000)
 *   *_raw    = หน่วยของเหรียญบนเชนนั้นจริง (BSC = 18 ทศนิยม)
 *   ไม่ใช้ decimal เพราะ SQLite (ที่เทสต์ใช้) เก็บเลขเกิน int64 เป็น REAL แล้วหลักท้ายเพี้ยนเงียบ ๆ
 *   — 1 USDT บน BSC = 1e18 ซึ่งเกิน int64 ตั้งแต่ 9.2 USDT
 *
 * ตาราง bridge_transactions ของสะพาน TPIX⇄wTPIX เดิมไม่ถูกแตะ (เก็บประวัติไว้ ไม่ลบข้อมูล)
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bridge_deposits')) {
            Schema::create('bridge_deposits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('source_chain_id');
                $table->string('tx_hash', 66);
                $table->unsignedInteger('log_index');
                $table->unsignedBigInteger('block_number');
                $table->string('block_hash', 66)->nullable();
                $table->string('from_address', 42);
                $table->string('to_address', 42);
                $table->string('token_address', 42);
                $table->unsignedTinyInteger('token_decimals');
                $table->string('amount_raw', 80);
                // เศษที่ต่ำกว่า 6 ทศนิยมจากเชน 18 ทศนิยม — ค้างอยู่ใน vault เป็นส่วนเกิน ไม่ mint
                $table->string('dust_raw', 80)->default('0');
                $table->string('amount_units', 80);
                $table->string('fee_units', 80)->default('0');
                $table->string('mint_units', 80)->default('0');
                // keccak256(abi.encode(chainId, txHash, logIndex)) — ส่งเข้า minter และเป็นคีย์กัน mint ซ้ำบนสัญญา
                $table->string('deposit_id', 66)->unique();
                $table->string('status', 24)->index();
                $table->string('review_reason', 64)->nullable();
                $table->text('review_note')->nullable();
                $table->boolean('limits_overridden')->default(false);
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('mint_tx_hash', 66)->nullable()->index();
                // raw ที่เซ็นแล้วเป็นข้อมูลสาธารณะ (ไม่ใช่ความลับ) — เก็บไว้ส่งซ้ำด้วย nonce เดิมหลังเครื่องดับ
                $table->text('mint_raw_tx')->nullable();
                $table->unsignedBigInteger('mint_nonce')->nullable();
                $table->unsignedInteger('mint_attempts')->default(0);
                $table->timestamp('mint_broadcast_at')->nullable();
                $table->unsignedBigInteger('mint_block_number')->nullable();
                $table->timestamp('minted_at')->nullable();
                $table->string('last_error', 300)->nullable();
                $table->timestamps();

                $table->unique(['source_chain_id', 'tx_hash', 'log_index'], 'bridge_deposits_source_unique');
                $table->index(['from_address', 'created_at']);
            });
        }

        if (! Schema::hasTable('bridge_withdrawals')) {
            Schema::create('bridge_withdrawals', function (Blueprint $table) {
                $table->id();
                $table->string('tx_hash', 66);
                $table->unsignedInteger('log_index');
                $table->unsignedBigInteger('block_number');
                $table->string('from_address', 42);
                $table->string('amount_units', 80);
                // chain id ใน event เป็น uint256 ใครจะใส่เลขอะไรก็ได้ — เก็บดิบไว้ แยกจากเลขที่ใช้ได้จริง
                $table->string('target_chain_raw', 80);
                $table->unsignedBigInteger('target_chain_id')->nullable();
                $table->string('target_address', 200);
                $table->string('fee_units', 80)->default('0');
                $table->string('payout_units', 80)->default('0');
                $table->string('payout_amount_raw', 80)->default('0');
                $table->string('payout_token', 42)->nullable();
                $table->string('payout_from', 42)->nullable();
                $table->string('status', 24)->index();
                $table->string('review_reason', 64)->nullable();
                $table->text('review_note')->nullable();
                $table->boolean('limits_overridden')->default(false);
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('payout_tx_hash', 66)->nullable()->index();
                $table->text('payout_raw_tx')->nullable();
                $table->unsignedBigInteger('payout_nonce')->nullable();
                $table->unsignedInteger('payout_attempts')->default(0);
                $table->timestamp('payout_broadcast_at')->nullable();
                $table->unsignedBigInteger('payout_block_number')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('last_error', 300)->nullable();
                $table->timestamps();

                $table->unique(['tx_hash', 'log_index'], 'bridge_withdrawals_source_unique');
                $table->index(['from_address', 'created_at']);
                $table->index(['target_chain_id', 'status']);
            });
        }

        if (! Schema::hasTable('bridge_reserve_snapshots')) {
            Schema::create('bridge_reserve_snapshots', function (Blueprint $table) {
                $table->id();
                $table->string('status', 24)->index(); // ok · deficit · incomplete
                $table->string('supply_units', 80)->nullable();
                $table->string('vault_units', 80)->default('0');
                $table->string('payout_units', 80)->default('0');
                $table->string('owed_units', 80)->default('0');
                $table->string('backing_units', 80)->default('0');
                // supply − backing (ติดลบ = มีเงินหนุนเกิน ซึ่งเป็นปกติ) — เก็บเป็นข้อความเพราะมีเครื่องหมาย
                $table->string('deficit_units', 40)->default('0');
                $table->string('unexplained_mint_units', 80)->default('0');
                $table->json('chains')->nullable();
                $table->string('action', 64)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_reserve_snapshots');
        Schema::dropIfExists('bridge_withdrawals');
        Schema::dropIfExists('bridge_deposits');
    }
};
