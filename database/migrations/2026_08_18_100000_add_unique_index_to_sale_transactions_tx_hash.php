<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * บังคับให้ tx_hash ของรายการซื้อห้ามซ้ำ ที่ระดับฐานข้อมูล.
 *
 * เดิมมีแค่ index ธรรมดา + การเช็คซ้ำในโค้ดที่อยู่ "ก่อน" transaction และคั่นด้วย
 * การเรียก RPC หลายวินาที — ยิงคำขอพร้อมกันหลายอันด้วย tx_hash เดียว จะผ่าน
 * ด่านเช็คได้ทุกอัน แล้วบันทึกซ้ำ = จ่ายเงินครั้งเดียวได้เหรียญหลายเท่า
 *
 * ด่านในโค้ดยังอยู่เพื่อให้ข้อความผิดพลาดอ่านง่าย แต่ตัวที่กันจริงคือ index นี้
 */
return new class extends Migration
{
    public function up(): void
    {
        // ล้างของซ้ำที่อาจค้างอยู่ก่อน ไม่งั้นสร้าง unique index ไม่ผ่าน
        // เก็บใบแรกสุดของแต่ละ tx_hash ไว้ (ใบที่เหลือคือผลของช่องโหว่)
        $duplicateIds = DB::table('sale_transactions as t')
            ->select('t.id')
            ->whereNotNull('t.tx_hash')
            ->where('t.tx_hash', '!=', '')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sale_transactions as older')
                    ->whereColumn('older.tx_hash', 't.tx_hash')
                    ->whereColumn('older.id', '<', 't.id');
            })
            ->pluck('id');

        if ($duplicateIds->isNotEmpty()) {
            // ไม่ลบทิ้ง — ทำหมันด้วยการต่อท้าย id เพื่อให้ยังตรวจสอบย้อนหลังได้
            // (ต่อสตริงใน PHP ไม่ใช่ SQL เพราะ || ใช้ได้เฉพาะ SQLite ส่วน MySQL ต้อง CONCAT)
            foreach ($duplicateIds as $id) {
                $row = DB::table('sale_transactions')->where('id', $id)->first(['tx_hash']);
                if (! $row) {
                    continue;
                }
                DB::table('sale_transactions')
                    ->where('id', $id)
                    ->update([
                        'tx_hash' => $row->tx_hash.'-dup'.$id,
                        // ต้องเป็นค่าที่อยู่ใน enum จริง ['pending','confirmed','claimed','refunded','failed']
                        // MySQL strict mode จะปฏิเสธค่านอก enum แล้ว migration ตายกลางคัน
                        // (SQLite ไม่บังคับ enum จึงผ่านตอนเทสต์แต่พังตอน deploy จริง)
                        'status' => 'failed',
                    ]);
            }
        }

        Schema::table('sale_transactions', function (Blueprint $table) {
            $table->unique('tx_hash', 'sale_transactions_tx_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sale_transactions', function (Blueprint $table) {
            $table->dropUnique('sale_transactions_tx_hash_unique');
        });
    }
};
