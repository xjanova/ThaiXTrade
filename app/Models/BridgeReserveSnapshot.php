<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Model;

/**
 * ผลกระทบยอดหนึ่งรอบ — supply ของ USDT_TPIX เทียบเงินหนุนจริงทุกเชน.
 *
 * หน้า proof of reserves อ่านแถวล่าสุดจากตารางนี้ (ไม่ยิง RPC หกเชนทุกครั้งที่มีคนเปิดหน้า)
 */
class BridgeReserveSnapshot extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_DEFICIT = 'deficit';

    public const STATUS_INCOMPLETE = 'incomplete';

    protected $fillable = [
        'status', 'supply_units', 'vault_units', 'payout_units', 'owed_units', 'backing_units',
        'deficit_units', 'unexplained_mint_units', 'chains', 'action', 'note',
    ];

    protected function casts(): array
    {
        return ['chains' => 'array'];
    }

    public function toPublicArray(): array
    {
        $fmt = fn ($v) => $v === null ? null : Wei::format((string) $v, 6);

        return [
            'status' => $this->status,
            'supply' => $fmt($this->supply_units),
            'vault' => $fmt($this->vault_units),
            'payout_wallets' => $fmt($this->payout_units),
            'owed' => $fmt($this->owed_units),
            'backing' => $fmt($this->backing_units),
            'deficit' => $fmt($this->deficit_units),
            'chains' => $this->chains ?? [],
            'action' => $this->action,
            'checked_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
