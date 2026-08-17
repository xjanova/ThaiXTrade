<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — AI Trade work-credit ledger entry.
 *
 * เป็นแหล่งความจริงเดียวของยอดเครดิต — ไม่มีคอลัมน์ balance เก็บซ้ำที่อื่น
 * ยอดคงเหลือ = balance_after ของแถวล่าสุดของ wallet นั้น (เขียนภายใต้ transaction + lock)
 * Developed by Xman Studio.
 */
class AiBotCredit extends Model
{
    protected $fillable = [
        'wallet_address',
        'type',
        'amount',
        'balance_after',
        'reference',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'meta' => 'array',
        ];
    }
}
