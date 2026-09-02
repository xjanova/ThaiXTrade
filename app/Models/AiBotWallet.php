<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TPIX TRADE — กระเป๋าบอทของผู้ใช้หนึ่งคน (หนึ่งใบต่อกระเป๋าเจ้าของ).
 *
 * ⚠️ key_ciphertext อยู่ใน $hidden — ห้ามเอาออก และห้าม toArray()/json ในที่ที่
 *    อาจไหลไป log (ต่อให้ห่อแล้วก็ไม่ควรให้ใครเห็นรูปร่างของมัน)
 *
 * Developed by Xman Studio.
 */
class AiBotWallet extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    protected $fillable = [
        'owner_address', 'chain_id', 'address', 'key_ciphertext', 'key_version',
        'status', 'balances', 'balances_at', 'last_deposit_at',
    ];

    protected $hidden = ['key_ciphertext'];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'key_version' => 'integer',
            'balances' => 'array',
            'balances_at' => 'datetime',
            'last_deposit_at' => 'datetime',
        ];
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AiBotWalletTransfer::class, 'ai_bot_wallet_id');
    }

    public function scopeForOwner(Builder $query, string $owner): Builder
    {
        return $query->where('owner_address', strtolower($owner));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** ยอดล่าสุดของสินทรัพย์หนึ่งตัว (0 ถ้ายังไม่เคยอ่าน) */
    public function balanceOf(string $asset): float
    {
        return (float) (($this->balances ?? [])[strtoupper($asset)] ?? 0);
    }
}
