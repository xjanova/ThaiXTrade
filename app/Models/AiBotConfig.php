<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * TPIX TRADE — AI Trade bot configuration.
 *
 * บอทหนึ่งตัวที่ผู้ใช้ตั้งไว้ (กลยุทธ์ + พารามิเตอร์ + กรอบความเสี่ยง)
 * engine ที่รันจริงอ่านเฉพาะแถวที่ status = running และเจ้าของยังเช่าอยู่
 * Developed by Xman Studio.
 */
class AiBotConfig extends Model
{
    /*
     * รหัสเหตุผลที่ระบบพักบอทเอง — เก็บใน stats.pause_reason คู่กับ last_reason (ข้อความ)
     *
     * last_reason เป็นข้อความไทยที่ถูกเขียนทับทุกรอบคิด หน้าเว็บ/แอพแปลภาษาจากมันไม่ได้
     * และแยกไม่ออกว่า "พักเพราะแพลนหมด" กับ "ผู้ใช้กดพักเอง" ต่างกันตรงไหน
     * รหัสนี้ทำให้จอบอกได้ตรงตัวว่าต้องทำอะไรต่อ (ต่ออายุแพลน · ลบบอทให้เหลือตามโควตา)
     */
    public const PAUSE_PLAN_EXPIRED = 'plan_expired';

    public const PAUSE_PLAN_LOCKED = 'plan_locked';

    public const PAUSE_PLAN_QUOTA = 'plan_quota';

    public const PAUSE_STRATEGY_RETIRED = 'strategy_retired';

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
        'banned_at',
        'banned_reason',
        'banned_by',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'risk' => 'array',
            'stats' => 'array',
            'last_run_at' => 'datetime',
            'last_signal_at' => 'datetime',
            'banned_at' => 'datetime',
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
        return $query->where('status', 'running')->whereNull('banned_at');
    }

    /** ถูกทีมงานปิดกั้นไว้ — เจ้าของปลดเองไม่ได้ */
    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /**
     * ระบบพักบอทเองพร้อมเหตุผล (ทั้งข้อความให้คนอ่าน และรหัสให้หน้าจอแปลภาษา).
     *
     * เขียน stats ผ่านตัวแปรในหน่วยความจำ — BotRunner::record() รวม stats จากตัวแปรเดียวกัน
     * ต่อท้ายอีกรอบ ถ้าเขียนลงฐานข้อมูลตรงๆ รหัสนี้จะถูกทับหายในบรรทัดถัดไป
     */
    public function pauseBecause(string $code, string $reason): void
    {
        $this->update([
            'status' => 'paused',
            'last_reason' => $reason,
            'stats' => array_merge($this->stats ?? [], ['pause_reason' => $code]),
        ]);
    }

    /** รหัสเหตุผลที่ระบบพักบอทไว้ — null เมื่อไม่ได้พัก หรือผู้ใช้กดพักเอง */
    public function pauseReason(): ?string
    {
        if ($this->status !== 'paused') {
            return null;
        }

        $code = ($this->stats ?? [])['pause_reason'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** ล้างรหัสเหตุผลการพัก — เรียกตอนเจ้าของกดเริ่มใหม่ (เหตุผลเดิมไม่จริงแล้ว) */
    public function clearPauseReason(): void
    {
        $stats = $this->stats ?? [];

        if (array_key_exists('pause_reason', $stats)) {
            unset($stats['pause_reason']);
            $this->stats = $stats;
        }
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

    /**
     * บอทของกระเป๋าที่ไม่มีการเช่าที่ยังไม่หมดอายุเหลืออยู่เลย (ทั้งแพลนเสียเงินและแพลนฟรี).
     *
     * ใช้หาบอทที่ค้างสถานะ running หลังแพลนหมดอายุ — cloudExecuted() มองไม่เห็นพวกนี้
     * (เลือกเฉพาะกระเป๋าที่มีแพลนคลาวด์ที่ยังไม่หมดอายุ) ด่านพักบอทใน BotRunner จึงไม่เคยได้ทำงาน
     */
    public function scopeWithoutLiveSubscription(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('ai_bot_subscriptions')
                ->whereColumn('ai_bot_subscriptions.wallet_address', 'ai_bot_configs.wallet_address')
                ->where('ai_bot_subscriptions.status', 'active')
                ->where('ai_bot_subscriptions.expires_at', '>', now());
        });
    }

    /**
     * ระดับแพลนของเจ้าของบอท (0 = ฟรี … 3 = VIP) — ใช้จัดคิวประมวลผล.
     *
     * ทำเป็น subquery ไม่ใช่ join เพราะกระเป๋าหนึ่งใบอาจมีแถว subscription เก่า
     * ค้างอยู่ได้ join แล้วบอทจะโผล่ซ้ำหลายแถวจนเดินซ้ำในรอบเดียว
     */
    public function scopeWithPlanRank(Builder $query): Builder
    {
        $cases = collect(AiBotPlan::TIER_RANK)
            ->map(fn (int $rank, string $tier) => sprintf("WHEN '%s' THEN %d", $tier, $rank))
            ->implode(' ');

        return $query->select('ai_bot_configs.*')->selectSub(
            DB::table('ai_bot_subscriptions')
                ->join('ai_bot_plans', 'ai_bot_plans.id', '=', 'ai_bot_subscriptions.ai_bot_plan_id')
                ->whereColumn('ai_bot_subscriptions.wallet_address', 'ai_bot_configs.wallet_address')
                ->where('ai_bot_subscriptions.status', 'active')
                ->where('ai_bot_subscriptions.expires_at', '>', now())
                ->selectRaw("COALESCE(MAX(CASE ai_bot_plans.tier {$cases} ELSE 0 END), 0)"),
            'plan_rank'
        );
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
