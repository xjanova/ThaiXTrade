<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ไม้ BSC ตรวจจากใบเสร็จบนเชน + ใบอนุญาตวางไม้ผูกกับธุรกรรมของไม้ (ออดิท 2026-10-28 C1/C2)
 *
 * transactions.fee_tx_hash — ธุรกรรมโอนค่าธรรมเนียมของไม้นั้น เซิร์ฟเวอร์ตรวจบนเชนก่อนนับเป็นรายได้
 *   unique: ธุรกรรมค่าธรรมเนียมหนึ่งรายการอ้างได้ไม้เดียว — กันเอา hash เดิมมาอ้างซ้ำทุกไม้
 *
 * unique (tx_hash, from_token, to_token) — หนึ่งไม้หนึ่งแถว เป็นด่านสุดท้ายเมื่อคำขอซ้ำชนกันพอดี
 *   คีย์เดียวกับที่ DexSwapIndexer ใช้กันซ้ำ (สวอปหลายฮอปบนเชน TPIX ได้หลายแถว คนละคู่เหรียญ)
 *   MySQL ใช้ index แบบ prefix 66/42/42 ตัวอักษร (= ความยาวเต็มของ hash และที่อยู่) เพราะคอลัมน์เป็น
 *   varchar(255) — สามคอลัมน์ utf8mb4 เต็มความยาวเกินเพดานความยาวคีย์ของ InnoDB บางรูปแบบ
 *
 * trading_order_tickets.chain_id — chain id ของบล็อกเชน (56, 4289) ไม่ใช่ PK ของตาราง chains
 *   ตัวเก็บกวาดต้องรู้ว่าจะไปถามผลของ order_tx_hash ที่เชนไหน ก่อนตัดสินว่าคืนเงินหรือปิดตั๋ว
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'fee_tx_hash')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('fee_tx_hash', 66)->nullable()->after('fee_usd')
                    ->comment('ธุรกรรมโอนค่าธรรมเนียมของไม้นี้ (ตรวจบนเชนแล้วถึงนับเป็นรายได้)');
                $table->unique('fee_tx_hash', 'idx_tx_fee_tx_hash');
            });
        }

        if (! Schema::hasIndex('transactions', 'idx_tx_hash_tokens')) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE transactions ADD UNIQUE INDEX idx_tx_hash_tokens (tx_hash(66), from_token(42), to_token(42))');
            } else {
                Schema::table('transactions', function (Blueprint $table) {
                    $table->unique(['tx_hash', 'from_token', 'to_token'], 'idx_tx_hash_tokens');
                });
            }
        }

        if (! Schema::hasColumn('trading_order_tickets', 'chain_id')) {
            Schema::table('trading_order_tickets', function (Blueprint $table) {
                $table->unsignedInteger('chain_id')->nullable()->after('pair')
                    ->comment('chain id ของบล็อกเชน (56, 4289) — ไม่ใช่ PK ของตาราง chains');
                $table->index('order_tx_hash', 'idx_ticket_order_tx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('transactions', 'idx_tx_hash_tokens')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropUnique('idx_tx_hash_tokens');
            });
        }

        if (Schema::hasColumn('transactions', 'fee_tx_hash')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropUnique('idx_tx_fee_tx_hash');
                $table->dropColumn('fee_tx_hash');
            });
        }

        if (Schema::hasColumn('trading_order_tickets', 'chain_id')) {
            Schema::table('trading_order_tickets', function (Blueprint $table) {
                $table->dropIndex('idx_ticket_order_tx');
                $table->dropColumn('chain_id');
            });
        }
    }
};
