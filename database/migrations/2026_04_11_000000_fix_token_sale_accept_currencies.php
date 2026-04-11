<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * แก้ไข accept_currencies ของ token_sales
 * เปลี่ยนจาก ['USDT', 'STRIPE'] เป็น ['BNB', 'USDT']
 * STRIPE ไม่ใช่สกุลเงินคริปโต — จัดการผ่าน Stripe payment button แยกต่างหาก
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('token_sales')
            ->where('accept_currencies', json_encode(['USDT', 'STRIPE']))
            ->update(['accept_currencies' => json_encode(['BNB', 'USDT'])]);
    }

    public function down(): void
    {
        DB::table('token_sales')
            ->where('accept_currencies', json_encode(['BNB', 'USDT']))
            ->update(['accept_currencies' => json_encode(['USDT', 'STRIPE'])]);
    }
};
