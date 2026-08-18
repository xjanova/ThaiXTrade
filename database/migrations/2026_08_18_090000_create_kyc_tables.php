<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX TRADE — ยืนยันตัวตน (KYC) แบบทีมงานตรวจเอง.
 *
 * เจ้าของกำหนด: ทุกส่วนของเว็บมีด่านได้ แต่เปิด/ปิดเองเป็นรายฟีเจอร์
 * ที่ต้องใช้แน่ๆ คือ เช่าบอท AI กับ สร้างเหรียญ · ตรวจเอกสารด้วยคนของเรา
 * เก็บไฟล์ไว้ในเซิร์ฟเวอร์เราแบบไม่เปิดสาธารณะ + ต้องขอลบข้อมูลได้ตาม PDPA
 *
 *   kyc_submissions       — ใบคำขอ 1 ใบต่อการยื่น 1 ครั้ง (ยื่นใหม่ได้ เก็บของเก่าไว้)
 *   kyc_documents         — ไฟล์แนบของแต่ละใบ
 *   kyc_document_views    — ใครเปิดดูเอกสารของใครเมื่อไหร่ (PDPA ต้องตอบได้)
 *   kyc_deletion_requests — คำขอลบข้อมูลของเจ้าของข้อมูล
 *
 * ⚠️ ข้อมูลในนี้คือบัตรประชาชนกับใบหน้าคนจริง ไม่ใช่ยอดเหรียญ
 *    - ฟิลด์ที่ระบุตัวบุคคลเข้ารหัสด้วย APP_KEY (cast `encrypted` ใน model)
 *      ฐานข้อมูลรั่วอย่างเดียวจึงยังอ่านไม่ออก ต้องได้ APP_KEY ไปด้วย
 *    - จึงต้องเป็น text ไม่ใช่ string(n) — ciphertext ยาวกว่าต้นฉบับหลายเท่า
 *    - national_id_hash เก็บ HMAC ไว้หาคนยื่นซ้ำโดยไม่ต้องถอดรหัสทีละใบ
 *
 * ⚠️ ใช้ dateTime ไม่ใช่ timestamp สำหรับคอลัมน์ที่ไม่ nullable
 *    MySQL แจก DEFAULT '0000-00-00 00:00:00' ให้ TIMESTAMP NOT NULL ที่ไม่ระบุ default
 *    ซึ่ง sql_mode ที่มี NO_ZERO_DATE ปฏิเสธ → error 1067 · SQLite ตอน dev ไม่มีข้อจำกัดนี้
 *    บั๊กจึงโผล่เฉพาะบนโปรดักชัน (เกิดจริงมาแล้ว 2026-08-17)
 *
 * Developed by Xman Studio.
 */
return new class() extends Migration
{
    public function up(): void
    {
        // ── ใบคำขอยืนยันตัวตน ───────────────────────────────────────────────
        //
        // เก็บเป็นประวัติ ไม่ทับของเดิม เพราะถูกปฏิเสธแล้วยื่นใหม่ได้
        // และเวลามีข้อโต้แย้งภายหลังต้องย้อนดูได้ว่าตอนนั้นส่งอะไรมา ใครตรวจ
        // สถานะล่าสุดสะท้อนกลับไปที่ users.kyc_status ที่หน้าอื่นใช้อยู่แล้ว
        if (! Schema::hasTable('kyc_submissions')) {
            Schema::create('kyc_submissions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                // basic = บัตรประชาชน + เซลฟี่ · enhanced = เพิ่มหลักฐานที่อยู่
                $table->enum('level', ['basic', 'enhanced'])->default('basic');
                $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled', 'expired'])
                    ->default('pending');

                // ── ข้อมูลส่วนบุคคล (เข้ารหัสทั้งหมด) ────────────────────────
                $table->text('full_name')->nullable();
                $table->text('full_name_en')->nullable();
                $table->text('national_id')->nullable()->comment('เลขบัตร/พาสปอร์ต — เข้ารหัส');
                $table->enum('id_type', ['national_id', 'passport'])->default('national_id');
                $table->text('date_of_birth')->nullable();
                $table->string('nationality', 2)->nullable()->comment('ISO 3166-1 alpha-2 ระบุตัวบุคคลเดี่ยวๆ ไม่ได้');
                $table->text('address')->nullable();
                $table->text('occupation')->nullable();
                $table->text('phone')->nullable();

                // หาคนยื่นเลขบัตรเดียวกันหลายบัญชีโดยไม่ต้องถอดรหัสทั้งตาราง
                // ไม่ unique เพราะถูกปฏิเสธแล้วยื่นใหม่ด้วยเลขเดิมเป็นเรื่องปกติ
                $table->string('national_id_hash', 64)->nullable()->index();

                // ── ความยินยอมตาม PDPA ──────────────────────────────────────
                //
                // ต้องพิสูจน์ได้ว่าตอนกดยินยอม เขาเห็นข้อความเวอร์ชันไหน
                // แก้ข้อความทีหลังแล้วอ้างว่าเขายินยอมข้อความใหม่ไม่ได้
                $table->string('consent_version', 20);
                $table->dateTime('consented_at');
                $table->string('consent_ip', 45)->nullable();

                // ── การตรวจ ─────────────────────────────────────────────────
                $table->dateTime('submitted_at');
                $table->dateTime('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->text('review_note')->nullable()->comment('บันทึกภายใน ผู้ใช้ไม่เห็น');
                $table->text('reject_reason')->nullable()->comment('เหตุผลที่แจ้งผู้ใช้');

                // ── ระยะเก็บข้อมูล ──────────────────────────────────────────
                //
                // PDPA: เก็บได้เท่าที่จำเป็นตามวัตถุประสงค์ ไม่ใช่เก็บตลอดกาล
                // ครบกำหนดแล้วคำสั่ง kyc:purge ลบไฟล์ทิ้งและล้างฟิลด์ที่ระบุตัวบุคคล
                $table->dateTime('purge_after')->nullable()->index();
                $table->dateTime('purged_at')->nullable();
                $table->string('purge_reason', 40)->nullable()->comment('retention | user_request | admin');

                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['status', 'submitted_at']);
            });
        }

        // ── ไฟล์แนบ ─────────────────────────────────────────────────────────
        //
        // เก็บแค่ "ที่อยู่ไฟล์" ตัวไฟล์อยู่บน disk ส่วนตัวนอก document root
        // ห้ามใส่ใน storage/app/public เด็ดขาด — ตรงนั้นมี symlink ออกเว็บ
        // ใครเดา URL ถูกก็เปิดบัตรประชาชนคนอื่นได้ทันทีโดยไม่ต้องล็อกอิน
        if (! Schema::hasTable('kyc_documents')) {
            Schema::create('kyc_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('kyc_submission_id')->constrained()->cascadeOnDelete();
                $table->enum('type', [
                    'id_card_front',
                    'id_card_back',
                    'selfie_with_id',
                    'address_proof',
                    'bank_book',
                ]);
                $table->string('disk', 40)->default('kyc');
                $table->string('path', 255);
                $table->string('original_name', 255)->nullable();
                $table->string('mime', 100)->nullable();
                $table->unsignedInteger('size')->default(0);
                // จับไฟล์ซ้ำ (คนละบัญชีส่งรูปเดียวกัน) และพิสูจน์ว่าไฟล์ไม่ถูกแก้หลังยื่น
                $table->string('sha256', 64)->nullable()->index();
                $table->dateTime('purged_at')->nullable();
                $table->timestamps();

                $table->index(['kyc_submission_id', 'type']);
            });
        }

        // ── ประวัติการเปิดดูเอกสาร ──────────────────────────────────────────
        //
        // PDPA ให้เจ้าของข้อมูลถามได้ว่าใครเข้าถึงข้อมูลเขาบ้าง
        // ถ้าไม่บันทึกตอนเปิดดู ก็ตอบไม่ได้ และไม่รู้ด้วยว่าแอดมินคนไหนแอบส่อง
        if (! Schema::hasTable('kyc_document_views')) {
            Schema::create('kyc_document_views', function (Blueprint $table) {
                $table->id();
                $table->foreignId('kyc_document_id')->constrained()->cascadeOnDelete();
                $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->dateTime('viewed_at');

                $table->index(['kyc_document_id', 'viewed_at']);
                $table->index('admin_user_id');
            });
        }

        // ── คำขอลบข้อมูล (สิทธิของเจ้าของข้อมูลตาม PDPA) ────────────────────
        //
        // ไม่ลบทันทีที่กด เพราะบางใบยังต้องเก็บตามกฎหมายอื่น (เช่นบัญชีการเงิน)
        // ทีมงานต้องดูก่อนแล้วบันทึกเหตุผลไว้ ถ้าปฏิเสธต้องบอกได้ว่าเพราะอะไร
        if (! Schema::hasTable('kyc_deletion_requests')) {
            Schema::create('kyc_deletion_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('kyc_submission_id')->nullable()->constrained()->nullOnDelete();
                $table->text('reason')->nullable()->comment('เหตุผลของผู้ขอ');
                $table->enum('status', ['pending', 'completed', 'rejected'])->default('pending');
                $table->dateTime('requested_at');
                $table->string('requested_ip', 45)->nullable();
                $table->foreignId('handled_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->dateTime('handled_at')->nullable();
                $table->text('handler_note')->nullable();
                $table->unsignedInteger('files_deleted')->default(0);
                $table->timestamps();

                $table->index(['status', 'requested_at']);
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_deletion_requests');
        Schema::dropIfExists('kyc_document_views');
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('kyc_submissions');
    }
};
