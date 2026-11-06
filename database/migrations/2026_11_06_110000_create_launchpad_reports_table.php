<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * launchpad_reports — ปุ่ม "แจ้งเหรียญนี้" บนหน้าขาย (หลอกลวง / เลียนแบบ / ข้อมูลเท็จ / อื่น ๆ)
 * แอดมินเห็นที่ /admin/launchpad แล้วตัดสินใจเองว่าจะซ่อนหน้าขายไหม — รายงานไม่ซ่อนอะไรอัตโนมัติ
 * (ไม่งั้นคู่แข่งกดรัว ๆ ปิดหน้าขายของคนอื่นได้)
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('launchpad_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('launchpad_sale_id')->constrained('launchpad_sales');
            $table->string('reason', 24);                 // scam | impersonation | misleading | other
            $table->string('details', 1000)->nullable();
            $table->string('reporter_wallet', 42)->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->string('status', 16)->default('open')->index(); // open | reviewed | dismissed
            $table->string('reviewed_by', 80)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['launchpad_sale_id', 'client_ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launchpad_reports');
    }
};
