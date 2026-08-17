<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — ใบอนุญาตวางไม้ 1 ใบต่อ 1 ไม้.
 *
 * เหตุที่ต้องมี: เส้นทางเทรดจริงบน BSC ผู้ใช้เซ็นกับ PancakeSwap ตรงๆ
 * เว็บเราไม่ได้อยู่ในเส้นทางของเหรียญ จึงบังคับเก็บเงินตอนส่งคำสั่งไม่ได้
 * ต้องเก็บตอน "ขออนุญาตวางไม้" แล้วค่อยปล่อยให้ swap
 *
 * Developed by Xman Studio.
 */
class TradingOrderTicket extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_EXPIRED = 'expired';

    public const METHOD_CREDIT = 'tpix_credit';

    public const METHOD_ONCHAIN = 'onchain';

    protected $fillable = [
        'uuid',
        'wallet_address',
        'pair',
        'side',
        'order_value_usd',
        'fee_method',
        'fee_amount',
        'fee_currency',
        'trading_fee_tier_id',
        'fee_tx_hash',
        'status',
        'order_tx_hash',
        'refund_amount',
        'gas_deducted',
        'refund_tx_hash',
        'note',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'order_value_usd' => 'decimal:8',
            'fee_amount' => 'decimal:8',
            'refund_amount' => 'decimal:8',
            'gas_deducted' => 'decimal:8',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ticket) {
            if (empty($ticket->uuid)) {
                $ticket->uuid = (string) Str::uuid();
            }
        });
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(TradingFeeTier::class, 'trading_fee_tier_id');
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    /** ยังใช้วางไม้ได้อยู่ไหม */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ISSUED && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** จ่ายด้วยคลัง TPIX = คืนเต็ม · จ่ายเป็นเหรียญบนเชน = คืนโดยหักค่าแก๊ส */
    public function refundsInFull(): bool
    {
        return $this->fee_method === self::METHOD_CREDIT;
    }
}
