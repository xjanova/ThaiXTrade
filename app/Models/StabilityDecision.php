<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หนึ่งรอบการตัดสินใจของ TPIX Stability Reserve — รวมรอบที่ "ไม่ทำอะไร" พร้อมเหตุผล.
 *
 * Developed by Xman Studio
 */
class StabilityDecision extends Model
{
    public const ACTION_NONE = 'none';

    public const ACTION_BUY = 'buy';

    public const ACTION_SELL = 'sell';

    public const ACTION_APPROVE = 'approve';

    public const ACTION_WAIT = 'wait';

    protected $fillable = [
        'decided_at', 'action', 'reason_code',
        'pool_price', 'reference_price', 'band_low', 'band_high',
        'reserve_tpix_wei', 'reserve_usdt_wei', 'details', 'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'details' => 'array',
            'pool_price' => 'decimal:18',
            'reference_price' => 'decimal:18',
            'band_low' => 'decimal:18',
            'band_high' => 'decimal:18',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StabilityTransaction::class, 'transaction_id');
    }
}
