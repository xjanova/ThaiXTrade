<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TPIX TRADE — บัญชีของเราผูกกับบัญชี Thaiprompt ไหน (เพื่อใช้ผล KYC ของที่นั่น).
 *
 * ⚠️ token เป็นกุญแจถามสถานะ KYC ของลูกค้าที่ Thaiprompt — เข้ารหัสด้วย APP_KEY
 *    และซ่อนจาก toArray() เผื่อมีใครส่งโมเดลทั้งก้อนออก API โดยไม่ตั้งใจ
 *
 * @property int $id
 * @property int $user_id
 * @property string $thaiprompt_user_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property string $kyc_status
 * @property Carbon|null $verified_at
 * @property string|null $method
 * @property Carbon|null $linked_at
 * @property Carbon|null $last_checked_at
 * @property bool $needs_reconnect
 * @property string|null $last_error
 *
 * Developed by Xman Studio.
 */
class ThaipromptKycLink extends Model
{
    public const STATUS_NONE = 'none';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'thaiprompt_user_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'kyc_status',
        'verified_at',
        'method',
        'linked_at',
        'last_checked_at',
        'needs_reconnect',
        'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'linked_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'needs_reconnect' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isApproved(): bool
    {
        return $this->kyc_status === self::STATUS_APPROVED;
    }

    /**
     * สิ่งที่หน้าเว็บของเจ้าของบัญชีเห็น — ไม่มี token และไม่มีรหัสผู้ใช้ฝั่งโน้น.
     */
    public function toOwnerArray(): array
    {
        return [
            'status' => $this->kyc_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'method' => $this->method,
            'linked_at' => $this->linked_at?->toIso8601String(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'needs_reconnect' => (bool) $this->needs_reconnect,
        ];
    }
}
