<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — กระเป๋าทดลองของผู้ใช้หนึ่งคน (เครดิตเดโม).
 *
 * แยกจากเครดิตการทำงานจริงเด็ดขาด — เงินในนี้ใช้เช่าบอทไม่ได้ และเครดิตจริง
 * ก็ไหลเข้ามาที่นี่ไม่ได้ เพื่อไม่ให้มีทางสับสนว่ากำไรที่เห็นเป็นเงินจริงหรือไม่
 *
 * Developed by Xman Studio.
 */
class AiBotDemoAccount extends Model
{
    protected $fillable = [
        'wallet_address', 'bucket', 'balance', 'starting_balance', 'reset_count', 'last_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:8',
            'starting_balance' => 'decimal:8',
            'reset_count' => 'integer',
            'last_reset_at' => 'datetime',
        ];
    }
}
