<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'mode',
        'last_run_at',
        'last_signal_at',
        'last_reason',
        'stats',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'risk' => 'array',
            'stats' => 'array',
            'last_run_at' => 'datetime',
            'last_signal_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AiBotSubscription::class, 'ai_bot_subscription_id');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(AiBotTrade::class, 'ai_bot_config_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(AiBotPosition::class, 'ai_bot_config_id');
    }

    /** บอทที่ engine ต้องรันในรอบนี้ */
    public function scopeRunnable(Builder $query): Builder
    {
        return $query->where('status', 'running');
    }

    public function scopeForWallet(Builder $query, string $wallet): Builder
    {
        return $query->where('wallet_address', strtolower($wallet));
    }

    /** บอทที่กินโควตาของแพลน (draft ไม่นับ เพราะยังไม่ทำงาน) */
    /**
     * เฉพาะบอทที่เจ้าของซื้อ "การรันบนคลาวด์" ไว้.
     *
     * ต้องมีการเช่าที่ยังไม่หมดอายุ และแพลนนั้นต้องเป็น execution = cloud
     * ตัวจับเวลาของเซิร์ฟเวอร์ (aibot:tick) ใช้ scope นี้เป็นด่านเดียวในการตัดสิน
     * ว่าจะเดินบอทตัวไหน — บอทของแพลนฟรีจะไม่ถูกแตะเลย
     */
    public function scopeCloudExecuted(Builder $query): Builder
    {
        return $query->whereExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('ai_bot_subscriptions')
                ->join('ai_bot_plans', 'ai_bot_plans.id', '=', 'ai_bot_subscriptions.ai_bot_plan_id')
                ->whereColumn('ai_bot_subscriptions.wallet_address', 'ai_bot_configs.wallet_address')
                ->where('ai_bot_subscriptions.status', 'active')
                ->where('ai_bot_subscriptions.expires_at', '>', now())
                ->where('ai_bot_plans.execution', 'cloud');
        });
    }

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
