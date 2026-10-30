<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * event id ของ Stripe ที่ประมวลผลสำเร็จแล้ว (ออดิท 2026-10-29 L2)
 *
 * Stripe รับประกันแค่ "ส่งอย่างน้อยหนึ่งครั้ง" แถวนี้ถูกเขียนหลังตัวจัดการทำเสร็จเท่านั้น
 * event ที่ล้มกลางทางจึงไม่มีแถวและ Stripe ส่งซ้ำมาทำใหม่ได้
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type', 100);
            $table->timestamp('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
