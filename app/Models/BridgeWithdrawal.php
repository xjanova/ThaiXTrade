<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * การถอนหนึ่งรายการ (Burned ของ USDT_TPIX บนเชน 4289) → จ่าย USDT จริงบนเชนปลายทาง.
 *
 * วงจรสถานะ:
 *   pending → sending → paid
 *   pending → awaiting_liquidity (กระเป๋าจ่ายมีเหรียญหรือแก๊สไม่พอ — ลองใหม่เองทุกรอบ ไม่จ่ายบางส่วน)
 *   needs_review → (แอดมินอนุมัติ) pending | (ปฏิเสธ) rejected
 *
 * ⚠️ มี payout_tx_hash แล้ว = ห้ามเซ็นใหม่เด็ดขาด
 *
 * @property int $id
 * @property string $tx_hash
 * @property int $log_index
 * @property string $from_address
 * @property string $amount_units
 * @property int|null $target_chain_id
 * @property string $target_address
 * @property string $fee_units
 * @property string $payout_units
 * @property string $payout_amount_raw
 * @property string $status
 * @property string|null $payout_tx_hash
 * @property string|null $payout_raw_tx
 * @property Carbon|null $payout_broadcast_at
 */
class BridgeWithdrawal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_LIQUIDITY = 'awaiting_liquidity';

    public const STATUS_SENDING = 'sending';

    public const STATUS_PAID = 'paid';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_REJECTED = 'rejected';

    /** เผาน้อยกว่าหรือเท่ากับค่าธรรมเนียม — ไม่มีอะไรให้จ่าย (ไม่เข้าคิวตรวจ กันคนเผาเศษถมคิวแอดมิน) */
    public const STATUS_IGNORED = 'ignored';

    /** ภาระที่ยังค้างจ่าย — เผาไปแล้วบนเชน 4289 แต่ USDT จริงยังอยู่ในกระเป๋าเรา */
    public const OWED = [self::STATUS_PENDING, self::STATUS_AWAITING_LIQUIDITY, self::STATUS_SENDING, self::STATUS_NEEDS_REVIEW];

    protected $fillable = [
        'tx_hash', 'log_index', 'block_number', 'from_address', 'amount_units',
        'target_chain_raw', 'target_chain_id', 'target_address',
        'fee_units', 'payout_units', 'payout_amount_raw', 'payout_token', 'payout_from',
        'status', 'review_reason', 'review_note', 'limits_overridden', 'reviewed_by', 'reviewed_at',
        'payout_tx_hash', 'payout_raw_tx', 'payout_nonce', 'payout_attempts', 'payout_broadcast_at',
        'payout_block_number', 'paid_at', 'last_error',
    ];

    protected $hidden = ['payout_raw_tx'];

    protected function casts(): array
    {
        return [
            'log_index' => 'integer',
            'block_number' => 'integer',
            'target_chain_id' => 'integer',
            'limits_overridden' => 'boolean',
            'reviewed_at' => 'datetime',
            'payout_nonce' => 'integer',
            'payout_attempts' => 'integer',
            'payout_broadcast_at' => 'datetime',
            'payout_block_number' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function scopeForWallet(Builder $query, string $wallet): Builder
    {
        return $query->where('from_address', strtolower($wallet));
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'tx_hash' => $this->tx_hash,
            'log_index' => $this->log_index,
            'block_number' => $this->block_number,
            'from_address' => $this->from_address,
            'amount' => Wei::format((string) $this->amount_units, 6),
            'fee' => Wei::format((string) $this->fee_units, 6),
            'payout_amount' => Wei::format((string) $this->payout_units, 6),
            'target_chain_id' => $this->target_chain_id,
            'target_address' => $this->target_address,
            'status' => $this->status,
            'review_reason' => $this->review_reason,
            'payout_tx_hash' => in_array($this->status, [self::STATUS_SENDING, self::STATUS_PAID], true)
                ? $this->payout_tx_hash
                : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
