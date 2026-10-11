<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * เงินฝาก USDT หนึ่งรายการ (หนึ่ง Transfer log เข้า vault บนเชนต้นทาง) → mint USDT_TPIX บนเชน 4289.
 *
 * วงจรสถานะ:
 *   confirmed → minting → minted
 *   needs_review → (แอดมินอนุมัติ) confirmed | (ปฏิเสธ) rejected
 *   ignored — ไม่ใช่การฝาก (ยอดศูนย์/ฝุ่น/โอนภายใน) บันทึกไว้ตรวจย้อนหลังเท่านั้น
 *
 * ⚠️ มี mint_tx_hash แล้ว = ห้ามเซ็นใหม่เด็ดขาด ไปตามผลของ hash เดิมอย่างเดียว
 *
 * @property int $id
 * @property int $source_chain_id
 * @property string $tx_hash
 * @property int $log_index
 * @property int $block_number
 * @property string $from_address
 * @property string $amount_raw
 * @property string $amount_units
 * @property string $mint_units
 * @property string $deposit_id
 * @property string $status
 * @property string|null $mint_tx_hash
 * @property string|null $mint_raw_tx
 * @property Carbon|null $mint_broadcast_at
 */
class BridgeDeposit extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_MINTING = 'minting';

    public const STATUS_MINTED = 'minted';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_IGNORED = 'ignored';

    /** สถานะที่นับเข้าวงเงินรายกระเป๋า (ผ่านด่านแล้วหรือกำลังจะได้เหรียญ) */
    public const COUNTS_TOWARD_LIMIT = [self::STATUS_CONFIRMED, self::STATUS_MINTING, self::STATUS_MINTED];

    protected $fillable = [
        'source_chain_id', 'tx_hash', 'log_index', 'block_number', 'block_hash',
        'from_address', 'to_address', 'token_address', 'token_decimals',
        'amount_raw', 'dust_raw', 'amount_units', 'fee_units', 'mint_units', 'deposit_id',
        'status', 'review_reason', 'review_note', 'limits_overridden', 'reviewed_by', 'reviewed_at',
        'mint_tx_hash', 'mint_raw_tx', 'mint_nonce', 'mint_attempts', 'mint_broadcast_at',
        'mint_block_number', 'minted_at', 'last_error',
    ];

    /** raw tx ไม่จำเป็นต่อหน้าจอ และยาว — ไม่ส่งออกไปกับ JSON */
    protected $hidden = ['mint_raw_tx'];

    protected function casts(): array
    {
        return [
            'source_chain_id' => 'integer',
            'log_index' => 'integer',
            'block_number' => 'integer',
            'token_decimals' => 'integer',
            'limits_overridden' => 'boolean',
            'reviewed_at' => 'datetime',
            'mint_nonce' => 'integer',
            'mint_attempts' => 'integer',
            'mint_broadcast_at' => 'datetime',
            'mint_block_number' => 'integer',
            'minted_at' => 'datetime',
        ];
    }

    public function scopeForWallet(Builder $query, string $wallet): Builder
    {
        return $query->where('from_address', strtolower($wallet));
    }

    /** จำนวนแบบอ่านง่าย (USDT) */
    public function amountUsdt(): string
    {
        return Wei::format((string) $this->amount_units, 6);
    }

    public function mintUsdt(): string
    {
        return Wei::format((string) $this->mint_units, 6);
    }

    /**
     * รูปแบบสาธารณะ — หน้าเว็บ/API ใช้ ไม่มี raw tx ไม่มีข้อมูลแอดมิน.
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'source_chain_id' => $this->source_chain_id,
            'tx_hash' => $this->tx_hash,
            'log_index' => $this->log_index,
            'block_number' => $this->block_number,
            'from_address' => $this->from_address,
            'amount' => $this->amountUsdt(),
            'fee' => Wei::format((string) $this->fee_units, 6),
            'mint_amount' => $this->mintUsdt(),
            'status' => $this->status,
            'review_reason' => $this->review_reason,
            'mint_tx_hash' => $this->status === self::STATUS_MINTED || $this->status === self::STATUS_MINTING
                ? $this->mint_tx_hash
                : null,
            'minted_at' => $this->minted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
