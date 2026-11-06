<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ใบลงทะเบียนผู้สร้างที่ผูกกับเหรียญที่ขึ้นเชนไปแล้ว (เช่น IDTC สร้างก่อนมีระบบลงทะเบียน).
 *
 * ใบปกติผูกกับเหรียญผ่านกระเป๋า (wallet_address = creator_address ของเหรียญ)
 * ใบแบบนี้ wallet_address = กระเป๋ารับเหรียญของโครงการ ซึ่งต่างจากกระเป๋าเจ้าของเหรียญ
 * จึงต้องเก็บ id ของเหรียญไว้ตรงๆ — และ id นี้ผูกอยู่ในข้อความข้อตกลงที่ผู้แทนเซ็น
 *
 * ไม่ใส่ unique: ใบที่ถูกปฏิเสธยังอยู่ ใบใหม่ผูกเหรียญเดิมได้ — โค้ดกันไม่ให้มีใบส่งแล้ว/อนุมัติแล้วสองใบต่อเหรียญ
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('creator_registrations', function (Blueprint $table) {
            $table->foreignId('factory_token_id')->nullable()->after('token_symbol')
                ->constrained('factory_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creator_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factory_token_id');
        });
    }
};
