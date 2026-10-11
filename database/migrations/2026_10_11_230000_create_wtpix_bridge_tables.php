<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * สะพาน TPIX (4289) ⇄ wTPIX (BSC) — ตารางแยกจากสะพาน USDT โดยตั้งใจ
 *
 * ทำไมไม่เติมคอลัมน์ asset ลง bridge_deposits / bridge_withdrawals:
 *   1. หน่วยไม่เหมือนกัน — ตาราง USDT เก็บ *_units เป็น 6 ทศนิยม ส่วน TPIX/wTPIX เป็น 18 ทศนิยม
 *      คอลัมน์ชื่อเดียวกันแต่ความหมายต่างกัน = กับดักคูณ/หารผิดหนึ่งล้านล้านเท่า
 *   2. คิวของสะพาน USDT (relay/payout/reconcile/วงเงินรายกระเป๋า/proof of reserves) ไม่ได้กรอง asset
 *      ถ้าแถว TPIX ไปอยู่ตารางเดียวกัน ตัว relay ของ USDT จะ mint USDT_TPIX ให้คนที่ล็อก TPIX ได้
 *      — ตารางแยกทำให้ความผิดพลาดแบบนั้นเกิดไม่ได้ตั้งแต่โครงสร้าง
 *   3. สองทิศทางของสะพานนี้หน้าตาเหมือนกันทุกอย่าง (event บนเชน A → depositId → สั่งงานสัญญาบนเชน B)
 *      จึงรวมเป็นตารางเดียวแยกด้วย direction
 *
 * จำนวนเงินเป็น wei (18 ทศนิยม) เก็บเป็นข้อความ คำนวณด้วย bcmath — SQLite เก็บเลขเกิน int64 เป็น REAL แล้วเพี้ยน
 * เวลาใช้ dateTime ทั้งหมด (TIMESTAMP ที่ไม่ nullable ชน MySQL strict 1067) และทุก create ห่อ hasTable
 * เพราะ DDL ของ MySQL ไม่อยู่ใน transaction — รันค้างครึ่งทางแล้ว deploy รอบหน้าจะชน 1050
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wtpix_bridge_transfers')) {
            Schema::create('wtpix_bridge_transfers', function (Blueprint $table) {
                $table->id();
                // to_bsc = ล็อก TPIX บนเชน 4289 → mint wTPIX บน BSC · to_tpix = เผา wTPIX บน BSC → ปล่อย TPIX
                $table->string('direction', 16);
                $table->unsignedBigInteger('source_chain_id');
                // สัญญาที่ปล่อย event (vault หรือ wTPIX) — งานทำเฉพาะแถวที่ตรงกับสัญญาที่ตั้งไว้ตอนนี้
                $table->string('source_contract', 42);
                $table->string('tx_hash', 66);
                $table->unsignedInteger('log_index');
                $table->unsignedBigInteger('block_number');
                $table->string('block_hash', 66)->nullable();
                $table->string('from_address', 42);
                // ผู้รับปลายทาง = ที่อยู่เดียวกับผู้ล็อก/ผู้เผา (สัญญาไม่มีช่องให้เลือกผู้รับ)
                $table->string('recipient', 42);
                $table->string('amount_wei', 80);
                $table->string('fee_wei', 80)->default('0');
                $table->string('payout_wei', 80)->default('0');
                // keccak256(abi.encode(sourceChainId, txHash, logIndex)) — คีย์กันจ่ายซ้ำบนสัญญาปลายทาง
                $table->string('deposit_id', 66)->unique();
                $table->string('status', 24)->index();
                $table->string('review_reason', 64)->nullable();
                $table->text('review_note')->nullable();
                $table->boolean('limits_overridden')->default(false);
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->unsignedBigInteger('dest_chain_id')->nullable();
                $table->string('dest_contract', 42)->nullable();
                $table->string('dest_tx_hash', 66)->nullable()->index();
                // raw ที่เซ็นแล้วเป็นข้อมูลสาธารณะ ไม่ใช่ความลับ — เก็บไว้ส่งซ้ำด้วย nonce เดิมหลังเครื่องดับ
                $table->text('dest_raw_tx')->nullable();
                $table->unsignedBigInteger('dest_nonce')->nullable();
                $table->string('dest_from', 42)->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->dateTime('broadcast_at')->nullable();
                $table->unsignedBigInteger('dest_block_number')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->string('last_error', 300)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['source_chain_id', 'tx_hash', 'log_index'], 'wtpix_bridge_source_unique');
                $table->index(['direction', 'status']);
                $table->index(['from_address', 'created_at']);
            });
        }

        if (! Schema::hasTable('wtpix_bridge_snapshots')) {
            Schema::create('wtpix_bridge_snapshots', function (Blueprint $table) {
                $table->id();
                $table->string('status', 24)->index(); // ok · deficit · incomplete
                $table->string('vault_wei', 80)->nullable();
                $table->string('supply_wei', 80)->nullable();
                $table->string('owed_wei', 80)->default('0');
                $table->string('backing_wei', 90)->default('0');
                // supply − backing (ติดลบ = คลังเกิน ซึ่งเป็นปกติเพราะค่าธรรมเนียมค้างในคลัง)
                $table->string('deficit_wei', 90)->default('0');
                $table->string('unexplained_mint_wei', 80)->default('0');
                $table->string('unexplained_release_wei', 80)->default('0');
                $table->string('foreign_supply_wei', 80)->default('0');
                $table->json('details')->nullable();
                $table->string('action', 191)->nullable();
                $table->text('note')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wtpix_bridge_snapshots');
        Schema::dropIfExists('wtpix_bridge_transfers');
    }
};
