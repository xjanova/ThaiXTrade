<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — เก็บที่อยู่พูล AMM ของคู่เทรดที่อยู่บนเชน TPIX.
 *
 * คู่บนเชน TPIX ไม่ได้มาจากทะเบียน JS หรือ Binance แต่มาจากพูลที่มีอยู่จริงบน
 * TPIXDEXFactory (dex:sync สร้างแถวให้ตามพูล) — ต้องจำที่อยู่พูลไว้เพื่อ
 *   - อ่าน reserve/ราคา/ความลึกได้ตรง ๆ โดยไม่ต้องถาม factory ทุกครั้ง
 *   - หน้าเว็บลิงก์ไป explorer ของพูลได้
 *
 * เป็น NULL สำหรับคู่บนเชนอื่น (BSC ใช้ PancakeSwap ซึ่งไม่ต้องจำที่อยู่พูล)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trading_pairs', 'dex_pair_address')) {
            Schema::table('trading_pairs', function (Blueprint $table) {
                $table->string('dex_pair_address', 42)
                    ->nullable()
                    ->after('execution_mode')
                    ->comment('ที่อยู่พูล AMM บนเชน TPIX (null = คู่บนเชนอื่น)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('trading_pairs', 'dex_pair_address')) {
            Schema::table('trading_pairs', function (Blueprint $table) {
                $table->dropColumn('dex_pair_address');
            });
        }
    }
};
