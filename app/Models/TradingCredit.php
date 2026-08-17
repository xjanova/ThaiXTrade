<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — รายการเดินบัญชีคลัง TPIX ของผู้ใช้.
 *
 * แหล่งความจริงเดียวของยอดคงเหลือ — ไม่มีคอลัมน์ balance เก็บซ้ำที่อื่น
 * ยอด = balance_after ของแถวล่าสุดของกระเป๋านั้น (เขียนใต้ transaction + lock)
 *
 * Developed by Xman Studio.
 */
class TradingCredit extends Model
{
    public const TYPE_TOPUP = 'topup';

    public const TYPE_CHARGE = 'charge';

    public const TYPE_REFUND = 'refund';

    public const TYPE_BONUS = 'bonus';

    public const TYPE_ADJUSTMENT = 'adjustment';

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
            'amount' => 'decimal:8',
            'balance_after' => 'decimal:8',
            'meta' => 'array',
        ];
    }
}
