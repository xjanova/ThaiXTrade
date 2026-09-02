<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — รายการโอนของกระเป๋าบอท (คิวถอนกลับหาเจ้าของ + ฝากที่ตรวจพบ).
 *
 * สถานะเดินทางเดียว: queued → signing → broadcasting → confirmed | failed
 * ยกเลิกได้เฉพาะตอนยัง queued — พ้นจากนั้นกุญแจถูกใช้แล้ว ต้องรอผลจากเชนเท่านั้น
 *
 * Developed by Xman Studio.
 */
class AiBotWalletTransfer extends Model
{
    public const DIRECTION_WITHDRAW = 'withdraw';

    public const DIRECTION_DEPOSIT = 'deposit';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SIGNING = 'signing';

    public const STATUS_BROADCASTING = 'broadcasting';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** สถานะที่ยัง "กินโควตา/ล็อกกระเป๋า" อยู่ — ห้ามมีสองรายการพร้อมกัน */
    public const IN_FLIGHT = [self::STATUS_QUEUED, self::STATUS_SIGNING, self::STATUS_BROADCASTING];

    protected $fillable = [
        'ai_bot_wallet_id', 'owner_address', 'direction', 'asset', 'token_address',
        'amount', 'amount_wei', 'to_address', 'status', 'tx_hash', 'nonce',
        'block_number', 'confirmations', 'failure_reason', 'requested_ip',
        'broadcast_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:18',
            'nonce' => 'integer',
            'block_number' => 'integer',
            'confirmations' => 'integer',
            'broadcast_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AiBotWallet::class, 'ai_bot_wallet_id');
    }

    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', self::IN_FLIGHT);
    }

    public function scopeWithdrawals(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_WITHDRAW);
    }

    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }
}
