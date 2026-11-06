<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ธุรกรรมหนึ่งรายการที่กระเป๋าตัวแทนขายเซ็น — บันทึก hash + raw ก่อน broadcast เสมอ.
 *
 * pending → confirmed (ลงบล็อก status 1) | failed (ลงบล็อกแต่ revert) | stuck (ค้างนานเกิน — คนดู)
 * ⚠️ มีแถว pending/stuck อยู่ = ตัวแทนไม่เซ็นอะไรใหม่เลย (nonce ถูกถามแบบ pending — เซ็นซ้อนจะทับกัน)
 *
 * @property int $id
 * @property string $kind
 * @property int|null $launchpad_sale_id
 * @property int|null $launchpad_order_id
 * @property string $to_address
 * @property string $calldata
 * @property string $tx_hash
 * @property string $raw_tx
 * @property int $nonce
 * @property string $status
 * @property Carbon $broadcast_at
 */
class LaunchpadAgentTx extends Model
{
    public const KIND_APPROVE = 'approve';

    public const KIND_BUY = 'buy';

    public const KIND_REFUND = 'refund';

    public const KIND_FEED = 'feed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STUCK = 'stuck';

    public const UNRESOLVED = [self::STATUS_PENDING, self::STATUS_STUCK];

    protected $table = 'launchpad_agent_txs';

    protected $fillable = [
        'kind', 'launchpad_sale_id', 'launchpad_order_id', 'to_address', 'calldata',
        'tx_hash', 'raw_tx', 'nonce', 'status', 'broadcast_at', 'mined_block', 'last_error',
    ];

    protected $hidden = ['raw_tx'];

    protected function casts(): array
    {
        return [
            'nonce' => 'integer',
            'mined_block' => 'integer',
            'broadcast_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaunchpadOrder::class, 'launchpad_order_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(LaunchpadSale::class, 'launchpad_sale_id');
    }
}
