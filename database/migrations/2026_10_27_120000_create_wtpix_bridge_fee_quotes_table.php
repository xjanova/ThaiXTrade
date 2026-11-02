<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ค่าธรรมเนียมขาออก (TPIX → BSC) แบบลอยตัว = ค่าแก๊ส BNB ที่ relayer จ่ายจริง + ส่วนเพิ่ม แปลงเป็น TPIX
 *
 * wtpix_bridge_fee_quotes — ประวัติราคาที่ประกาศ (ทุก 5 นาที) · ตัวสแกนคิดค่าธรรมเนียมจาก
 *   "ราคาที่ประกาศอยู่ตอนบล็อกที่ผู้ใช้ล็อก" ไม่ใช่ราคาตอนสแกน จึงต้องเก็บย้อนหลัง
 * wtpix_bridge_transfers.dest_gas_* — ค่าแก๊สที่จ่ายจริงของแต่ละ mint (จากใบเสร็จ)
 *   ใช้หาจำนวนแก๊สกลางของ mint และให้หลังบ้านเทียบ "ค่าธรรมเนียมที่เก็บ vs BNB ที่จ่าย"
 *
 * ตัวเลขเป็นข้อความ (wei / ราคาทศนิยม) คำนวณด้วย bcmath · เวลาเป็น dateTime · ห่อ hasTable/hasColumn
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wtpix_bridge_fee_quotes')) {
            Schema::create('wtpix_bridge_fee_quotes', function (Blueprint $table) {
                $table->id();
                // ค่าธรรมเนียมที่ประกาศ (หลังหนีบขั้นต่ำ/เพดานแล้ว) — ตัวที่ผู้ใช้เห็นและถูกเก็บ
                $table->string('fee_wei', 80);
                // ก่อนหนีบ — null เมื่อคำนวณจากตลาดไม่ได้ (ใช้เพดานแทน)
                $table->string('raw_fee_wei', 80)->nullable();
                $table->string('gas_price_wei', 80)->nullable();
                $table->unsignedInteger('gas_units')->default(0);
                $table->string('bnb_usd', 40)->nullable();
                $table->string('tpix_usd', 40)->nullable();
                $table->unsignedInteger('markup_bps')->default(0);
                // market = จากตลาด · capped = ค่าแก๊สจริงเกินเพดาน · fallback = ไม่มีราคาที่เชื่อได้
                $table->string('source', 16);
                $table->string('note', 300)->nullable();
                $table->dateTime('created_at')->nullable()->index();
                $table->dateTime('updated_at')->nullable();
            });
        }

        Schema::table('wtpix_bridge_transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('wtpix_bridge_transfers', 'dest_gas_used')) {
                $table->unsignedBigInteger('dest_gas_used')->nullable();
            }
            if (! Schema::hasColumn('wtpix_bridge_transfers', 'dest_gas_price_wei')) {
                $table->string('dest_gas_price_wei', 80)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wtpix_bridge_fee_quotes');

        Schema::table('wtpix_bridge_transfers', function (Blueprint $table) {
            foreach (['dest_gas_used', 'dest_gas_price_wei'] as $column) {
                if (Schema::hasColumn('wtpix_bridge_transfers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
