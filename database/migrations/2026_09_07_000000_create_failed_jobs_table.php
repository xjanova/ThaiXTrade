<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สร้างตาราง failed_jobs.
 *
 * 2026-09-07 เว็บกำลังย้าย QUEUE_CONNECTION จาก sync มาเป็น redis ตอนเป็น sync
 * งานรันคาอยู่ในรีเควสต์ ล้มก็โยน exception ออกมาตรง ๆ จึงไม่เคยต้องใช้ตารางนี้
 *
 * พอเข้าคิวจริง Laravel จะบันทึกงานที่ retry จนครบแล้วยังล้มลงตารางนี้ ถ้าไม่มีตาราง
 * ตัวบันทึกจะพังตามไปด้วย = งานที่ล้มหายไปเงียบ ๆ ไม่มีร่องรอยให้ตามเก็บ
 * ซึ่งรับไม่ได้เพราะสองงานที่เข้าคิวคือ ProcessBridgeJob (บริดจ์ข้ามเชน = เงินจริง)
 * กับ DeployTokenJob (ออกโทเคนให้ลูกค้า)
 *
 * ใช้ uuid เป็นคอลัมน์เพราะ config/queue.php ตั้ง failed.driver เป็น database-uuids
 *
 * Developed by Xman Studio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('failed_jobs')) {
            return;
        }

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
