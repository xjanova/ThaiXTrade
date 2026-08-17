<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TPIX TRADE — AI Trade rental (การเช่าบอทของ wallet หนึ่ง).
 *
 * "ใช้งานได้จริง" = status active AND expires_at ยังไม่ถึง
 * เราไม่พึ่ง status อย่างเดียว เพราะไม่มี cron มาปิดให้ทันทีตอนหมดอายุ
 * Developed by Xman Studio.
 */
class AiBotSubscription extends Model
{
    protected $fillable = [
        'wallet_address',
        'ai_bot_plan_id',
        'status',
        'days',
        'credits_spent',
        'started_at',
        'expires_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'credits_spent' => 'decimal:4',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AiBotPlan::class, 'ai_bot_plan_id');
    }

    public function bots(): HasMany
    {
        return $this->hasMany(AiBotConfig::class, 'ai_bot_subscription_id');
    }

    /** เช่าที่ยังใช้งานได้จริง ณ ตอนนี้ */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('expires_at', '>', now());
    }

    public function isLive(): bool
    {
        return $this->status === 'active' && $this->expires_at?->isFuture();
    }

    /** เหลืออีกกี่วัน (ปัดขึ้น) — 0 เมื่อหมดอายุแล้ว */
    public function daysRemaining(): int
    {
        if (! $this->isLive()) {
            return 0;
        }

        return max(0, (int) ceil(now()->floatDiffInDays($this->expires_at, false)));
    }
}
