<?php

/**
 * Migration: ผูกตารางรอบขายเข้ากับ "วันที่เปิดขายจริง" แทนวันที่ตายตัวในปฏิทิน.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมต้องมี
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิม `starts_at`/`ends_at` ของแต่ละเฟสถูกตั้งเป็นวันที่ตายตัวตั้งแต่ตอน seed
 * (พ.ค. 2026) แล้วระบบก็ไม่เคยพร้อมขายจริงตามวันนั้น ผลคือเฟสแรกหมดอายุ
 * ไปเงียบ ๆ ทั้งที่ยังไม่เคยขายได้แม้แต่บาทเดียว — และไม่มีอะไรฟ้องเลย
 *
 * เจ้าของกำหนดใหม่: **พร้อมขายวันไหน ให้เฟสแรกเริ่มนับวันนั้น**
 * ตารางจึงต้องเก็บ "ความยาวของเฟส" ไว้แทนวันที่ตายตัว แล้วค่อยคำนวณวันจริง
 * จากหมุดวันเปิดขายทีเดียวตอนกดเปิด
 *
 * เพิ่มสองคอลัมน์:
 *   sale_phases.duration_days  ความยาวเฟส (วัน) — แหล่งความจริงของตาราง
 *   token_sales.launched_at    หมุดวันเปิดขายจริง (null = ยังไม่เคยเปิด)
 *
 * ค่าเดิมถูก backfill จากส่วนต่าง ends_at − starts_at ที่มีอยู่ เพื่อไม่ให้
 * รอบขายที่ตั้งค่าไว้แล้วเปลี่ยนความยาวเฟสโดยไม่มีใครสั่ง
 *
 * Developed by Xman Studio.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** ความยาวเฟสเริ่มต้นเมื่อไม่มีวันเดิมให้คำนวณ (ตรงกับค่า --days เดิมของ sale:reschedule) */
    private const DEFAULT_DURATION_DAYS = 60;

    public function up(): void
    {
        Schema::table('sale_phases', function (Blueprint $table) {
            // ความยาวเฟสเป็นวัน — nullable เพื่อให้แถวเก่าเข้ามาได้ก่อนแล้วค่อย backfill
            $table->unsignedSmallInteger('duration_days')->nullable()->after('phase_order');
        });

        Schema::table('token_sales', function (Blueprint $table) {
            /*
             * หมุดวันเปิดขายจริง
             *
             * ต่างจาก starts_at: starts_at คือ "วันที่ตารางบอกว่าเริ่ม" ซึ่งถูกคำนวณใหม่
             * ได้ทุกครั้งที่ตั้งตาราง ส่วน launched_at คือ "วันที่กดเปิดขายจริง" ซึ่งเป็น
             * ประวัติ ตั้งครั้งเดียวแล้วไม่ควรเปลี่ยน ใช้กันไม่ให้ตัวเปิดอัตโนมัติ
             * เปิดซ้ำรอบสอง
             *
             * nullable + default null โดยตั้งใจ — MySQL จะใส่ auto-init ให้เองถ้าเป็น
             * TIMESTAMP ตัวแรกที่ไม่ nullable ซึ่งจะกลายเป็น "เปิดขายแล้ว" ทุกแถวทันที
             */
            $table->timestamp('launched_at')->nullable()->default(null)->after('ends_at');
        });

        $this->backfillDurations();
    }

    public function down(): void
    {
        Schema::table('sale_phases', function (Blueprint $table) {
            $table->dropColumn('duration_days');
        });

        Schema::table('token_sales', function (Blueprint $table) {
            $table->dropColumn('launched_at');
        });
    }

    /**
     * เติม duration_days จากวันที่ที่ตั้งไว้เดิม.
     *
     * ทำด้วย PHP ไม่ใช่ SQL เพราะ DATEDIFF/julianday เขียนต่างกันระหว่าง MySQL
     * กับ SQLite (เทสต์รันบน SQLite ส่วน production เป็น MySQL) — เขียน SQL ตัวเดียว
     * ให้ผ่านทั้งสองไม่ได้ และถ้าผ่านฝั่งเทสต์แต่ตายฝั่ง production จะรู้ตอน deploy แล้ว
     */
    private function backfillDurations(): void
    {
        DB::table('sale_phases')
            ->select('id', 'starts_at', 'ends_at')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $days = self::DEFAULT_DURATION_DAYS;

                    if ($row->starts_at && $row->ends_at) {
                        $diff = (int) ceil(
                            (strtotime((string) $row->ends_at) - strtotime((string) $row->starts_at)) / 86400
                        );

                        // ช่วงเวลากลับหัวหรือเป็นศูนย์ = ข้อมูลเดิมเพี้ยน ใช้ค่าเริ่มต้นแทน
                        if ($diff > 0) {
                            $days = $diff;
                        }
                    }

                    DB::table('sale_phases')->where('id', $row->id)->update(['duration_days' => $days]);
                }
            });
    }
};
