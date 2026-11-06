<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * เอกสารประกอบใบลงทะเบียนผู้สร้างเหรียญ (หนังสือรับรองบริษัท, ภ.พ.20, บอจ.5, รูปโครงการ, บัตรผู้มีอำนาจลงนาม).
 *
 * ไฟล์อยู่ใน disk creator_documents (storage/app/creator-documents — ไม่อยู่ใต้ public)
 * ผู้ซื้อไม่เคยได้ไฟล์ต้นฉบับ — หน้าโครงการสาธารณะแสดงภาพที่เซิร์ฟเวอร์วาดลายน้ำ PDPA ใหม่ทุกครั้ง
 * และเฉพาะเอกสารประเภทที่เปิดได้ (ไม่รวมบัตร) ที่ผู้สร้างติ๊กยินยอมเผยแพร่ทีละรายการ
 *
 * documents_hash บนใบลงทะเบียน = แฮชของรายการเอกสาร (ประเภท + SHA-256 ของไฟล์ + ยินยอมเผยแพร่)
 * ผูกอยู่ในข้อความที่ผู้สร้างเซ็น — เปลี่ยนเอกสารหลังเซ็นแล้วหลักฐานตรวจไม่ผ่าน
 *
 * ไม่มีคอลัมน์เวลาทางธุรกิจ — มีแค่ timestamps() (nullable จึงไม่โดน MariaDB ON UPDATE)
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('creator_registration_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('creator_registration_id')->constrained('creator_registrations')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('path', 255);
            $table->string('mime', 50);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            // ชื่อไฟล์เดิมอาจมีชื่อคน — แสดงเฉพาะเจ้าของใบและแอดมิน
            $table->string('original_name', 150)->nullable();
            $table->boolean('publish')->default(false);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamps();

            // ตั้งชื่อเอง — ชื่ออัตโนมัติยาว 65 ตัว เกินเพดาน 64 ของ MariaDB/MySQL
            $table->index(['creator_registration_id', 'type'], 'creator_reg_docs_reg_type_index');
        });

        Schema::table('creator_registrations', function (Blueprint $table) {
            $table->string('documents_hash', 64)->nullable()->after('form_hash');
        });
    }

    public function down(): void
    {
        Schema::table('creator_registrations', function (Blueprint $table) {
            $table->dropColumn('documents_hash');
        });

        Schema::dropIfExists('creator_registration_documents');
    }
};
