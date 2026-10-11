<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ธุรกรรมบนเชนของ TPIX Stability Reserve (approve หรือ swap).
 *
 * raw_tx ไม่ใช่ความลับ — เป็นธุรกรรมที่เซ็นแล้วซึ่งจะเปิดเผยบนเชนอยู่ดี
 * แต่ซ่อนจาก toArray() เพราะไม่มีประโยชน์กับผู้อ่าน และเก็บไว้ใช้ส่งซ้ำตัวเดิมเท่านั้น
 *
 * Developed by Xman Studio
 */
class StabilityTransaction extends Model
{
    public const PURPOSE_APPROVE = 'approve';

    public const PURPOSE_SWAP = 'swap';

    public const SIDE_BUY = 'buy';

    public const SIDE_SELL = 'sell';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_BROADCAST = 'broadcast';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DROPPED = 'dropped';

    /** สถานะที่ "ยังไม่จบ" — ห้ามเซ็นธุรกรรมใหม่ตราบที่ยังมีตัวไหนค้างอยู่ */
    public const OPEN_STATUSES = [self::STATUS_SIGNED, self::STATUS_BROADCAST];

    /** สถานะที่นับว่าใช้งบไปแล้ว (รวมตัวที่ยังไม่รู้ผล — นับไว้ก่อนดีกว่าใช้เกินงบ) */
    public const SPENDING_STATUSES = [self::STATUS_SIGNED, self::STATUS_BROADCAST, self::STATUS_CONFIRMED];

    protected $fillable = [
        'decision_id', 'purpose', 'side', 'token_in',
        'amount_in_wei', 'expected_out_wei', 'min_out_wei', 'actual_out_wei',
        'price_before', 'price_after_expected',
        'nonce', 'tx_hash', 'raw_tx', 'deadline_at', 'status', 'block_number',
        'failure_reason', 'signed_at', 'broadcast_at', 'finalized_at',
    ];

    protected $hidden = ['raw_tx'];

    protected function casts(): array
    {
        return [
            'nonce' => 'integer',
            'deadline_at' => 'integer',
            'block_number' => 'integer',
            'price_before' => 'decimal:18',
            'price_after_expected' => 'decimal:18',
            'signed_at' => 'datetime',
            'broadcast_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeSwaps(Builder $query): Builder
    {
        return $query->where('purpose', self::PURPOSE_SWAP);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
