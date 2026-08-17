<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — AI Trade bot configuration.
 *
 * บอทหนึ่งตัวที่ผู้ใช้ตั้งไว้ (กลยุทธ์ + พารามิเตอร์ + กรอบความเสี่ยง)
 * engine ที่รันจริงอ่านเฉพาะแถวที่ status = running และเจ้าของยังเช่าอยู่
 * Developed by Xman Studio.
 */
class AiBotConfig extends Model
{
    protected $fillable = [
        'wallet_address',
        'ai_bot_subscription_id',
        'name',
        'pair',
        'strategy',
        'timeframe',
        'params',
        'risk',
        'status',
        'last_run_at',
        'stats',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'risk' => 'array',
            'stats' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AiBotSubscription::class, 'ai_bot_subscription_id');
    }

    public function scopeForWallet(Builder $query, string $wallet): Builder
    {
        return $query->where('wallet_address', strtolower($wallet));
    }

    /** บอทที่กินโควตาของแพลน (draft ไม่นับ เพราะยังไม่ทำงาน) */
    public function scopeCountingTowardQuota(Builder $query): Builder
    {
        return $query->whereIn('status', ['running', 'paused']);
    }

    /** ข้อมูลกลยุทธ์จากแคตตาล็อก (null ถ้า config ถูกถอดออกภายหลัง) */
    public function strategyMeta(): ?array
    {
        foreach (config('aibot.strategies', []) as $strategy) {
            if (($strategy['code'] ?? null) === $this->strategy) {
                return $strategy;
            }
        }

        return null;
    }
}
