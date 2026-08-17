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
    /**
     * ลำดับชั้นของแพลน — ใช้ตัดสินว่ากลยุทธ์ไหนปลดล็อกแล้ว.
     *
     * free = 0 คือชั้นล่างสุด ปลดล็อกเฉพาะกลยุทธ์ที่ตั้ง tier = 'free' ไว้
     */
    public const TIER_RANK = ['free' => 0, 'basic' => 1, 'pro' => 2, 'vip' => 3];

    protected $fillable = [
        'code',
        'name',
        'name_th',
        'description',
        'description_th',
        'tier',
        'execution',
        'credits_per_day',
        'price_tpix_per_day',
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
            'price_tpix_per_day' => 'decimal:8',
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

    /** แพลนนี้ไม่เสียเงินใช่ไหม */
    public function isFree(): bool
    {
        return (float) $this->price_tpix_per_day <= 0;
    }

    /**
     * เซิร์ฟเวอร์รันบอทให้ไหม (ปิดเบราว์เซอร์แล้วยังทำงาน).
     *
     * ตัวนี้คือสิ่งที่ aibot:tick ใช้กรอง — ไม่ใช่แค่ข้อความโฆษณาบนหน้าเว็บ
     */
    public function runsInCloud(): bool
    {
        return $this->execution === 'cloud';
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
