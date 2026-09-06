<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ลบช่องลิงก์ GitHub ออกจากค่าโซเชียลของเว็บ.
 *
 * 2026-09-06 เก็บกวาดที่อยู่ repo ออกจากทุกที่ที่คนนอกเห็น — ไอคอน GitHub ที่ footer
 * กับช่องกรอกในหลังบ้านถูกถอดออกไปแล้ว แถวนี้จึงไม่มีอะไรมาอ่าน แต่ถ้าปล่อยค้างไว้
 * มันคือลิงก์ที่รอวันโผล่กลับตอนใครสักคนเอาไอคอนกลับมา
 *
 * Developed by Xman Studio.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('site_settings')
            ->where('group', 'social')
            ->where('key', 'github')
            ->delete();
    }

    public function down(): void
    {
        // ใส่กลับเป็นค่าว่าง ไม่ใช่ค่าที่เคยมี — ที่อยู่ repo ไม่ควรกลับมาเองจากการ rollback
        $exists = DB::table('site_settings')
            ->where('group', 'social')
            ->where('key', 'github')
            ->exists();

        if (! $exists) {
            DB::table('site_settings')->insert([
                'group' => 'social',
                'key' => 'github',
                'value' => '',
                'type' => 'string',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
