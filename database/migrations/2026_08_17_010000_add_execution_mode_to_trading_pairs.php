<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — แยกคู่เทรดที่ "ส่งคำสั่งได้จริง" ออกจากคู่ที่ "ดูราคาได้อย่างเดียว".
 *
 * ก่อนหน้านี้ไม่มีอะไรบอกความต่างนี้ในฐานข้อมูลเลย ฝั่งหน้าเว็บต้องเดาเอาจาก
 * ทะเบียนใน JS ว่าคู่ไหนเทรดได้ ทำให้แอดมินเปิดหลังบ้านแล้วแยกไม่ออก
 *
 *   onchain = มี token address จริงบนเชน ส่ง swap ได้
 *   index   = ดูราคา/กราฟได้ (ราคาจาก Binance) แต่ยังไม่มีสภาพคล่องให้ส่งคำสั่ง
 *
 * หมายเหตุความปลอดภัย: คอลัมน์นี้ใช้ "แสดงผล" เท่านั้น
 * ด่านจริงที่กันการส่งธุรกรรมยังเป็นการตรวจ address บนเชนฝั่ง client เหมือนเดิม
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('trading_pairs', function (Blueprint $table) {
            $table->enum('execution_mode', ['onchain', 'index'])
                ->default('index')
                ->after('is_active')
                ->comment('onchain = ส่งคำสั่งได้จริง, index = ดูราคาอย่างเดียว');
        });

        // คู่ที่มีอยู่แล้วบนเชนที่เทรดจริง (BSC) = onchain ที่เหลือเป็น index
        $bscId = DB::table('chains')->where('chain_id_hex', '0x38')->value('id');

        if ($bscId) {
            DB::table('trading_pairs')->where('chain_id', $bscId)->update(['execution_mode' => 'onchain']);
        }
    }

    public function down(): void
    {
        Schema::table('trading_pairs', function (Blueprint $table) {
            $table->dropColumn('execution_mode');
        });
    }
};
