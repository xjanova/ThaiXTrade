<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * รายงานจากผู้ใช้ว่าหน้าขายนี้น่าสงสัย — แอดมินอ่านแล้วตัดสินใจเอง (รายงานไม่ซ่อนหน้าขายอัตโนมัติ).
 *
 * @property int $id
 * @property int $launchpad_sale_id
 * @property string $reason
 * @property string|null $details
 * @property string|null $reporter_wallet
 * @property string|null $client_ip
 * @property string $status
 * @property Carbon|null $reviewed_at
 */
class LaunchpadReport extends Model
{
    public const REASONS = ['scam', 'impersonation', 'misleading', 'other'];

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'launchpad_sale_id', 'reason', 'details', 'reporter_wallet', 'client_ip', 'status', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(LaunchpadSale::class, 'launchpad_sale_id');
    }
}
