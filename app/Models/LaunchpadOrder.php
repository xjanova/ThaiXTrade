<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * คำสั่งซื้อหนึ่งรายการ — ผู้ซื้อเซ็นในกระเป๋าว่า "ยอดฝาก USDT ครั้งถัดไปของฉัน ยอดเป๊ะเท่านี้ ใช้ซื้อหน้าขายนี้".
 *
 * วงจรสถานะ:
 *   awaiting_payment → paid (จับคู่กับยอดฝากแล้ว สะพานกำลัง mint ให้ตัวแทน)
 *                    → buying (ส่ง buyFor แล้ว) → delivered
 *                    → refunding (ส่งคืน USDT_TPIX แล้ว) → refunded
 *   awaiting_payment → expired (ไม่มีเงินเข้าในเวลา) | cancelled (ผู้ซื้อยกเลิกเอง)
 *   ทุกสถานะที่มีธุรกรรมค้าง/ผลไม่ชัด → needs_review (คนดู ห้ามระบบเดาเอง)
 *
 * ⚠️ มีธุรกรรมของตัวแทนที่ยังไม่รู้ผล (launchpad_agent_txs.status = pending/stuck) = ห้ามเซ็นใหม่ให้คำสั่งนี้
 *
 * @property int $id
 * @property string $public_id
 * @property int $launchpad_sale_id
 * @property string $buyer_address
 * @property int $source_chain_id
 * @property string $amount_units
 * @property string $pay_units
 * @property string $expected_tokens
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon $risk_accepted_at
 * @property int|null $bridge_deposit_id
 * @property string|null $tokens_out
 * @property int $attempts
 */
class LaunchpadOrder extends Model
{
    public const STATUS_AWAITING = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_BUYING = 'buying';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_REFUNDING = 'refunding';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /** ยังไม่จบ — นับเป็น "คำสั่งที่เปิดอยู่" ของผู้ซื้อ */
    public const OPEN = [self::STATUS_AWAITING, self::STATUS_PAID, self::STATUS_BUYING, self::STATUS_REFUNDING, self::STATUS_NEEDS_REVIEW];

    protected $fillable = [
        'public_id', 'launchpad_sale_id', 'buyer_address', 'source_chain_id',
        'amount_units', 'pay_units', 'expected_tokens', 'status', 'expires_at', 'risk_accepted_at',
        'message_hash', 'signature', 'client_ip', 'bridge_deposit_id', 'paid_at',
        'tokens_out', 'delivery_tx_hash', 'refund_tx_hash', 'settled_at', 'attempts', 'last_error',
    ];

    protected $hidden = ['signature', 'client_ip', 'message_hash'];

    protected function casts(): array
    {
        return [
            'source_chain_id' => 'integer',
            'bridge_deposit_id' => 'integer',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'risk_accepted_at' => 'datetime',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(LaunchpadSale::class, 'launchpad_sale_id');
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(BridgeDeposit::class, 'bridge_deposit_id');
    }

    /**
     * รูปแบบสาธารณะ — ข้อมูลเดียวกับที่อยู่บนเชนอยู่แล้ว ไม่มีลายเซ็น/IP.
     */
    public function toPublicArray(): array
    {
        $sale = $this->relationLoaded('sale') ? $this->sale : null;
        $decimals = $sale?->token_decimals ?? 18;

        return [
            'id' => $this->public_id,
            'sale_id' => $this->launchpad_sale_id,
            'token_symbol' => $sale?->token_symbol,
            'buyer_address' => $this->buyer_address,
            'source_chain_id' => $this->source_chain_id,
            'amount' => Wei::format((string) $this->amount_units, 6),
            'amount_units' => (string) $this->amount_units,
            'expected_tokens' => Wei::format((string) $this->expected_tokens, $decimals),
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'tokens_out' => $this->tokens_out !== null ? Wei::format((string) $this->tokens_out, $decimals) : null,
            'delivery_tx_hash' => $this->status === self::STATUS_DELIVERED ? $this->delivery_tx_hash : null,
            'refund_tx_hash' => in_array($this->status, [self::STATUS_REFUNDING, self::STATUS_REFUNDED], true) ? $this->refund_tx_hash : null,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
