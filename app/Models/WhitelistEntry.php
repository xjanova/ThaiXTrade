<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WhitelistEntry Model — รายชื่อ wallet ที่ได้รับอนุญาตให้ซื้อ.
 *
 * ใช้กับ phase ที่ตั้งค่า whitelist_only = true
 *
 * @property int $id
 * @property int $sale_phase_id
 * @property string $wallet_address
 * @property string|null $max_allocation
 * @property bool $is_kyc_verified
 */
class WhitelistEntry extends Model
{
    protected $table = 'whitelist_entries';

    protected $fillable = [
        'sale_phase_id',
        'wallet_address',
        'max_allocation',
        'is_kyc_verified',
    ];

    protected function casts(): array
    {
        return [
            'max_allocation' => 'decimal:18',
            'is_kyc_verified' => 'boolean',
        ];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(SalePhase::class, 'sale_phase_id');
    }
}
