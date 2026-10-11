<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * เงินที่เจ้าของฝากเข้ากระเป๋า TPIX Stability Reserve — ยืนยันจากเชนตอนบันทึก.
 *
 * ยอด USDT ที่บันทึกตรงนี้คือ "งบ" ที่บอทใช้ซื้อได้เมื่อยังไม่มีหลักฐานทุนสำรองของสะพาน
 * (กัน USDT_TPIX ที่ไม่มีของจริงหนุนหลังถูกเอามาใช้พยุงราคา)
 *
 * Developed by Xman Studio
 */
class StabilityDeposit extends Model
{
    public const TOKEN_USDT = 'usdt';

    public const TOKEN_TPIX = 'tpix';

    protected $fillable = [
        'token', 'amount_wei', 'tx_hash', 'from_address', 'block_number', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['block_number' => 'integer'];
    }
}
