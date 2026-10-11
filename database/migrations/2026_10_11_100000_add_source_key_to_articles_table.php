<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — บทความจำได้ว่าเขียนจากอะไร
 *
 * source_key:
 *   event:dex_live:0x…        = ข่าวจากเหตุการณ์จริง (deploy สัญญา / คู่เทรดใหม่) — ห้ามเขียนซ้ำ
 *   topic:how-tpix-chain-…    = บทความความรู้ทั่วไป — ใช้กันหัวข้อเดิมวนกลับมาเร็ว
 *   null                      = เขียนเองจากหลังบ้าน
 *
 * เดิมไม่มีอะไรผูกบทความกับที่มา บอทเลยเขียน "liquidity pool" ซ้ำ 3 รอบในสัปดาห์เดียว
 * และไม่มีทางรู้ว่าเหตุการณ์ไหนเขียนไปแล้ว
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('source_key', 160)->nullable()->after('author_name');
            $table->index(['source_key', 'language']);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['source_key', 'language']);
            $table->dropColumn('source_key');
        });
    }
};
