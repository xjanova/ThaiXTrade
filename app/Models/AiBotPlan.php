<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TPIX TRADE — AI Trade rental plan.
 *
 * แพลนเช่าบอทเทรดบนคลาวด์ (ราคาเป็น "เครดิตการทำงาน" ต่อวัน)
 * tier เป็นตัวกำหนดว่าปลดล็อกกลยุทธ์ระดับไหนได้บ้าง — ดู config/aibot.php
 * Developed by Xman Studio.
 */
class AiBotPlan extends Model
{
    /** ลำดับความสูงของ tier — ใช้เทียบว่าแพลนปลดล็อกกลยุทธ์ใดได้ */
    public const TIER_RANK = ['basic' => 1, 'pro' => 2, 'vip' => 3];

    protected $fillable = [
        'code',
        'name',
        'name_th',
        'description',
        'description_th',
        'tier',
        'credits_per_day',
        'max_bots',
        'max_capital_usd',
        'features',
        'features_th',
        'badge',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'features_th' => 'array',
            'credits_per_day' => 'integer',
            'max_bots' => 'integer',
            'max_capital_usd' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(AiBotSubscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function tierRank(): int
    {
        return self::TIER_RANK[$this->tier] ?? 0;
    }

    /**
     * กลยุทธ์ที่แพลนนี้ปลดล็อก (จากแคตตาล็อกใน config/aibot.php).
     *
     * @return list<string>
     */
    public function unlockedStrategies(): array
    {
        $rank = $this->tierRank();

        return collect(config('aibot.strategies', []))
            ->filter(fn (array $s) => (self::TIER_RANK[$s['tier'] ?? 'basic'] ?? 1) <= $rank)
            ->pluck('code')
            ->values()
            ->all();
    }
}
