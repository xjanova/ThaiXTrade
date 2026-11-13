<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — คำสั่งตั้งราคาของคู่ที่เทรดบนเชนจริง.
 *
 * วงจรสถานะ:
 *   open       รอราคา (ยกเลิกได้ · หมดอายุได้)
 *   submitted  เจ้าของยืนยันในกระเป๋าแล้ว มี tx_hash รอผลบนเชน
 *   filled     ไม้ลงเชนแล้ว (เซิร์ฟเวอร์เห็นแถว swap ที่ยืนยันแล้วของ hash นี้)
 *   cancelled  เจ้าของยกเลิก
 *   expired    เลยอายุคำสั่ง
 *
 * Developed by Xman Studio.
 */
class LimitOrder extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_FILLED = 'filled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** สถานะที่ยังนับเป็น "คำสั่งเปิดอยู่" ของกระเป๋า */
    public const ACTIVE = [self::STATUS_OPEN, self::STATUS_SUBMITTED];

    protected $fillable = [
        'uuid',
        'wallet_address',
        'trading_pair_id',
        'pair',
        'network_chain_id',
        'side',
        'limit_price',
        'base_amount',
        'quote_amount',
        'min_receive',
        'slippage',
        'fee_method',
        'status',
        'tx_hash',
        'filled_base',
        'filled_quote',
        'close_reason',
        'expires_at',
        'submitted_at',
        'filled_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'network_chain_id' => 'integer',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'filled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tradingPair(): BelongsTo
    {
        return $this->belongsTo(TradingPair::class);
    }

    public function scopeForWallet(Builder $query, string $wallet): Builder
    {
        return $query->where('wallet_address', strtolower($wallet));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
