<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * หนึ่งรายการของสะพาน TPIX ⇄ wTPIX — หนึ่ง event บนเชนต้นทาง (Locked หรือ wTPIX Transfer ไป 0x0).
 *
 * ทิศทาง:
 *   to_bsc  — ล็อก TPIX ใน TPIXBridgeVault (4289) → mint wTPIX ผ่าน WTPIXBridgeMinter (BSC)
 *   to_tpix — เผา wTPIX บน BSC → vault.release() ปล่อย TPIX บนเชน 4289
 *
 * วงจรสถานะ (เหมือนกันทั้งสองทิศ):
 *   confirmed → sending → completed
 *   confirmed → awaiting_liquidity (BNB ของ relayer ไม่พอ / TPIX ในคลังไม่พอ — ลองใหม่เองทุกรอบ ไม่ทำบางส่วน)
 *   needs_review → (แอดมินอนุมัติ) confirmed | (ปฏิเสธ) rejected
 *   ignored — ไม่นับ (ต่ำกว่าขั้นต่ำ / ไม่เกินค่าธรรมเนียม / โอนภายใน) เก็บไว้ตรวจย้อนหลัง
 *
 * ⚠️ มี dest_tx_hash แล้ว = ห้ามเซ็นใหม่เด็ดขาด ไปตามผลของ hash เดิมเท่านั้น
 *
 * @property int $id
 * @property string $direction
 * @property int $source_chain_id
 * @property string $source_contract
 * @property string $tx_hash
 * @property int $log_index
 * @property string $from_address
 * @property string $recipient
 * @property string $amount_wei
 * @property string $fee_wei
 * @property string $payout_wei
 * @property string $deposit_id
 * @property string $status
 * @property string|null $review_reason
 * @property int|null $dest_chain_id
 * @property string|null $dest_contract
 * @property string|null $dest_tx_hash
 * @property string|null $dest_raw_tx
 * @property int|null $dest_nonce
 * @property int $attempts
 * @property Carbon|null $broadcast_at
 * @property Carbon|null $completed_at
 */
class WtpixBridgeTransfer extends Model
{
    public const TO_BSC = 'to_bsc';

    public const TO_TPIX = 'to_tpix';

    public const DIRECTIONS = [self::TO_BSC, self::TO_TPIX];

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_AWAITING_LIQUIDITY = 'awaiting_liquidity';

    public const STATUS_SENDING = 'sending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_IGNORED = 'ignored';

    /** สถานะที่รอทำงาน (ยังไม่เคยส่งธุรกรรม) */
    public const QUEUED = [self::STATUS_CONFIRMED, self::STATUS_AWAITING_LIQUIDITY];

    /** นับเข้าวงเงินรายกระเป๋า (ผ่านด่านแล้วหรือกำลังจะได้เหรียญ) */
    public const COUNTS_TOWARD_LIMIT = [self::STATUS_CONFIRMED, self::STATUS_AWAITING_LIQUIDITY, self::STATUS_SENDING, self::STATUS_COMPLETED];

    /** ทิศ to_tpix ที่ยังค้างปล่อย — wTPIX เผาไปแล้วบน BSC แต่ TPIX ยังอยู่ในคลัง */
    public const OWED = [self::STATUS_CONFIRMED, self::STATUS_AWAITING_LIQUIDITY, self::STATUS_SENDING, self::STATUS_NEEDS_REVIEW];

    protected $fillable = [
        'direction', 'source_chain_id', 'source_contract', 'tx_hash', 'log_index', 'block_number', 'block_hash',
        'from_address', 'recipient', 'amount_wei', 'fee_wei', 'payout_wei', 'deposit_id',
        'status', 'review_reason', 'review_note', 'limits_overridden', 'reviewed_by', 'reviewed_at',
        'dest_chain_id', 'dest_contract', 'dest_tx_hash', 'dest_raw_tx', 'dest_nonce', 'dest_from',
        'attempts', 'broadcast_at', 'dest_block_number', 'completed_at', 'last_error',
    ];

    /** raw tx ไม่จำเป็นต่อหน้าจอ และยาว — ไม่ส่งออกไปกับ JSON */
    protected $hidden = ['dest_raw_tx'];

    protected function casts(): array
    {
        return [
            'source_chain_id' => 'integer',
            'log_index' => 'integer',
            'block_number' => 'integer',
            'limits_overridden' => 'boolean',
            'reviewed_at' => 'datetime',
            'dest_chain_id' => 'integer',
            'dest_nonce' => 'integer',
            'attempts' => 'integer',
            'broadcast_at' => 'datetime',
            'dest_block_number' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function scopeForWallet(Builder $query, string $wallet): Builder
    {
        return $query->where('from_address', strtolower($wallet));
    }

    public function scopeDirection(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    /**
     * สถานะแบบที่หน้าเว็บใช้ — แยกคำตาม "ทิศ" (mint บน BSC / ปล่อยบน TPIX) ให้ผู้ใช้อ่านแล้วรู้ว่ารออะไร.
     */
    public function publicState(): string
    {
        $toBsc = $this->direction === self::TO_BSC;

        return match ($this->status) {
            self::STATUS_CONFIRMED => 'queued',
            self::STATUS_AWAITING_LIQUIDITY => 'awaiting_liquidity',
            self::STATUS_SENDING => $toBsc ? 'minting' : 'releasing',
            self::STATUS_COMPLETED => $toBsc ? 'minted' : 'released',
            self::STATUS_NEEDS_REVIEW => 'review',
            self::STATUS_REJECTED => 'rejected',
            default => $this->review_reason === 'below_fee' ? 'below_fee' : 'ignored',
        };
    }

    /**
     * รูปแบบสาธารณะ — ไม่มี raw tx ไม่มีข้อมูลแอดมิน.
     */
    public function toPublicArray(): array
    {
        $delivered = in_array($this->status, [self::STATUS_SENDING, self::STATUS_COMPLETED], true);

        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'source_chain_id' => $this->source_chain_id,
            'tx_hash' => $this->tx_hash,
            'log_index' => $this->log_index,
            'block_number' => $this->block_number,
            'from_address' => $this->from_address,
            'recipient' => $this->recipient,
            'amount' => Wei::format((string) $this->amount_wei, 18),
            'fee' => Wei::format((string) $this->fee_wei, 18),
            'receive_amount' => Wei::format((string) $this->payout_wei, 18),
            'status' => $this->status,
            'state' => $this->publicState(),
            'review_reason' => $this->review_reason,
            'dest_chain_id' => $this->dest_chain_id,
            'dest_tx_hash' => $delivered ? $this->dest_tx_hash : null,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
