<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ใบสมัคร validator ไม่เก็บพิกัดแล้ว.
 *
 * เดิมหน้าสมัครเติมจุดกึ่งกลางประเทศให้เอง แล้วพิกัดนั้นถูกเอาไปวาดเป็นตำแหน่งโหนดบนแผนที่สาธารณะ
 * ตำแหน่งจริงต้องมาจากตัวโหนดเอง ไม่ใช่จากแบบฟอร์ม — คอลัมน์ยังอยู่ให้ข้อมูลเก่าอ่านได้
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('validator_applications', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->change();
            $table->decimal('longitude', 10, 7)->nullable()->change();
        });
    }

    public function down(): void
    {
        // แถวใหม่ไม่มีพิกัด — เติม 0 ก่อนบังคับ NOT NULL กลับ ไม่งั้น rollback ล้ม
        DB::table('validator_applications')->whereNull('latitude')->update(['latitude' => 0]);
        DB::table('validator_applications')->whereNull('longitude')->update(['longitude' => 0]);

        Schema::table('validator_applications', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable(false)->change();
            $table->decimal('longitude', 10, 7)->nullable(false)->change();
        });
    }
};
