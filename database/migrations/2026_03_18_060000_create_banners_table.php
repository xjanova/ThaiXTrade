<?php

/**
 * TPIX TRADE — สร้างตาราง banners
 * ระบบจัดการป้ายโฆษณา รองรับ 3 ประเภท: รูปภาพ, Google AdSense, Custom HTML
 * Developed by Xman Studio.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();

            // ข้อมูลพื้นฐาน
            $table->string('title');
            $table->enum('type', ['image', 'google_adsense', 'html'])->default('image');

            // สำหรับ type=image — รูปภาพ + ลิงก์
            $table->string('image_url')->nullable();
            $table->string('link_url')->nullable();
            $table->enum('target', ['_blank', '_self'])->default('_blank');

            // สำหรับ type=google_adsense หรือ html — โค้ด ad
            $table->text('ad_code')->nullable();

            // ตำแหน่งที่แสดง
            $table->enum('placement', [
                'trade_top',        // ด้านบนหน้าเทรด
                'trade_sidebar',    // Sidebar หน้าเทรด
                'home_hero',        // Hero section หน้าแรก
                'home_bottom',      // ด้านล่างหน้าแรก
                'explorer_header',  // Header ของ Explorer
                'explorer_sidebar', // Sidebar ของ Explorer
                'all_pages_top',    // ด้านบนทุกหน้า (หลัง ticker)
            ])->default('all_pages_top');

            // สถานะและลำดับ
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // กำหนดเวลาแสดง (ถ้าไม่ใส่ = แสดงตลอด)
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();

            // สถิติ
            $table->unsignedBigInteger('click_count')->default(0);
            $table->unsignedBigInteger('view_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Index สำหรับ query เร็ว
            $table->index(['placement', 'is_active']);
            $table->index(['start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
