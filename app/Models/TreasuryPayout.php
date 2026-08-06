<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รายการจ่ายเงินจากกระเป๋าร้อน.
 *
 * สถานะเดินทางเดียว: pending -> approved -> broadcasting -> confirmed
 * แตกออกได้ที่ rejected (คนปฏิเสธ) และ failed (เชนไม่รับ / เซ็นไม่ผ่าน)
 *
 * `amount_wei` เป็น string เสมอ ห้ามแปลงเป็น int/float ที่ไหนก็ตาม
 */
class TreasuryPayout extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_BROADCASTING = 'broadcasting';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    /** สถานะที่ถือว่า "ยังกินวงเงินอยู่" ตอนคำนวณยอดใช้ไปต่อวัน */
    public const COUNTS_TOWARD_LIMIT = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_BROADCASTING,
        self::STATUS_CONFIRMED,
    ];

    protected $fillable = [
        'idempotency_key',
        'to_address',
        'amount_wei',
        'purpose',
        'memo',
        'status',
        'tx_hash',
        'block_number',
        'confirmations',
        'failure_reason',
        'hot_balance_at_approval_wei',
        'requested_by',
        'approved_by',
        'approved_at',
        'broadcast_at',
        'confirmed_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'broadcast_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'confirmations' => 'integer',
    ];

    protected $appends = ['amount_tpix'];

    public function setToAddressAttribute(?string $value): void
    {
        $this->attributes['to_address'] = strtolower(trim((string) $value));
    }

    /** ยอดในรูปอ่านง่าย — คำนวณด้วย bcmath ไม่แตะ float */
    public function getAmountTpixAttribute(): string
    {
        return Wei::format((string) ($this->amount_wei ?? '0'));
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** รายการที่ยังเปลี่ยนแปลงได้ — ใช้กันการอนุมัติซ้ำ */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
