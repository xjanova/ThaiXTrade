<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * เงินเข้ากระเป๋ารับเงินของรอบขายหนึ่งรายการ (หนึ่ง Transfer log ที่บล็อกนิ่งแล้ว).
 *
 * วงจรสถานะ:
 *   detected     → credited       (จับคู่คำสั่งซื้อได้เป๊ะ ทันเวลา ออก SaleTransaction แล้ว)
 *   detected     → needs_review   (ยอดไม่ตรง / มาช้า / จ่ายซ้ำ / ผิดเหรียญ / หาคำสั่งซื้อไม่เจอ / โควตาหมด)
 *   needs_review → resolved       (ทีมงานออกเหรียญให้ตามยอดที่ได้รับ หรือบันทึกว่าคืนเงินแล้ว)
 *
 * detected ค้างได้เฉพาะตอนเครดิตล้มกลางคัน — รอบสแกนถัดไปทำต่อเอง (unique (เชน, tx, log) กันซ้ำ)
 *
 * @property int $id
 * @property int $chain_id
 * @property string $tx_hash
 * @property int $log_index
 * @property int $block_number
 * @property int $block_timestamp
 * @property string $from_address
 * @property string $to_address
 * @property string $token_address
 * @property string $token_symbol
 * @property int $token_decimals
 * @property string $amount_raw
 * @property int|null $order_id
 * @property int|null $sale_transaction_id
 * @property string $status
 * @property string|null $review_reason
 * @property string|null $review_note
 * @property Carbon|null $resolved_at
 */
class SaleCryptoPayment extends Model
{
    public const STATUS_DETECTED = 'detected';

    public const STATUS_CREDITED = 'credited';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_RESOLVED = 'resolved';

    // ── เหตุผลที่ต้องให้คนดู ─────────────────────────────────────────────
    public const REASON_UNDERPAID = 'underpaid';

    public const REASON_OVERPAID = 'overpaid';

    public const REASON_LATE = 'late';

    public const REASON_DUPLICATE = 'duplicate_payment';

    public const REASON_WRONG_TOKEN = 'wrong_token';

    public const REASON_UNMATCHED = 'unmatched';

    public const REASON_ALLOCATION = 'insufficient_allocation';

    public const REASON_WALLET_CAP = 'exceeds_wallet_allocation';

    protected $table = 'sale_crypto_payments';

    protected $fillable = [
        'chain_id', 'tx_hash', 'log_index', 'block_number', 'block_timestamp',
        'from_address', 'to_address', 'token_address', 'token_symbol', 'token_decimals', 'amount_raw',
        'order_id', 'sale_transaction_id', 'status', 'review_reason', 'review_note',
        'resolved_by', 'resolved_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'log_index' => 'integer',
            'block_number' => 'integer',
            'block_timestamp' => 'integer',
            'token_decimals' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SaleCryptoOrder::class, 'order_id');
    }

    public function amountDisplay(): string
    {
        return SaleCryptoOrder::formatRaw($this->amount_raw, $this->token_decimals);
    }

    /** คีย์กันบันทึกซ้ำใน sale_transactions.tx_hash (unique) — หนึ่ง log ออกเหรียญได้ครั้งเดียวตลอดกาล */
    public function saleTxHash(): string
    {
        return 'crypto_'.$this->chain_id.'_'.$this->tx_hash.'_'.$this->log_index;
    }
}
