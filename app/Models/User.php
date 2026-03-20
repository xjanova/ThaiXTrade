<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — User Model (Traders/สมาชิก)
 * สมัครอัตโนมัติเมื่อ connect wallet + เพิ่ม email/profile ได้ทีหลัง
 * Developed by Xman Studio.
 */
class User extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'wallet_address',
        'email',
        'name',
        'avatar',
        'is_verified',
        'is_banned',
        'ban_reason',
        'kyc_status',
        'referral_code',
        'referred_by',
        'total_trades',
        'total_volume_usd',
        'last_active_at',
        'last_ip',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_banned' => 'boolean',
            'total_trades' => 'integer',
            'total_volume_usd' => 'decimal:2',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * Auto-generate referral code เมื่อสร้าง user.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user) {
            if (empty($user->referral_code)) {
                $user->referral_code = strtoupper(Str::random(8));
            }
        });
    }

    // === Relations ===

    public function walletConnections(): HasMany
    {
        return $this->hasMany(WalletConnection::class);
    }

    public function referrer()
    {
        return $this->belongsTo(self::class, 'referred_by');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by');
    }

    // === Scopes ===

    public function scopeActive($query)
    {
        return $query->where('is_banned', false);
    }

    public function scopeBanned($query)
    {
        return $query->where('is_banned', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeSearch($query, ?string $search)
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('wallet_address', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%");
        });
    }

    // === Methods ===

    /**
     * แบนผู้ใช้.
     */
    public function ban(string $reason = ''): void
    {
        $this->update([
            'is_banned' => true,
            'ban_reason' => $reason,
        ]);
    }

    /**
     * ปลดแบน.
     */
    public function unban(): void
    {
        $this->update([
            'is_banned' => false,
            'ban_reason' => null,
        ]);
    }

    /**
     * อัปเดต last active.
     */
    public function touchActivity(?string $ip = null): void
    {
        $this->update([
            'last_active_at' => now(),
            'last_ip' => $ip,
        ]);
    }
}
