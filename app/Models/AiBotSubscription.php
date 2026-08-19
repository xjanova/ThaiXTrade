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

    /**
     * เหลืออีกกี่วัน (ปัดขึ้น) — 0 เมื่อหมดอายุแล้ว.
     *
     * ⚠️ ใช้ "แสดงผล" เท่านั้น ห้ามเอาไปคูณราคาเพื่อคืนเงิน — ดู refundableDays()
     */
    public function daysRemaining(): int
    {
        if (! $this->isLive()) {
            return 0;
        }

        return max(0, (int) ceil(now()->floatDiffInDays($this->expires_at, false)));
    }

    /**
     * วันที่คืนเงินได้ — เศษวันคิดตามจริง ไม่ปัด.
     *
     * ทำไมต้องแยกจาก daysRemaining(): ตัวนั้นปัดขึ้น เหลืออีก 1 นาทีก็นับเป็น 1 วัน
     * เอามาคูณราคาคืนเงินแล้วได้ช่องโหว่ที่ใช้ของฟรีตลอดกาล — เช่า VIP 90 วัน
     * (21,600 เครดิต) ใช้ 23 ชม. 59 นาที แล้วกดยกเลิก ได้คืนครบ 21,600 เพราะ
     * ceil(90 − 0.999) = 90 แล้วเช่าใหม่ทันที วนแบบนี้ได้ทุกวันโดยไม่เสียอะไรเลย
     *
     * ที่ไม่ใช้ floor แทน เพราะจะเหวี่ยงไปอีกทาง — กดเช่าผิดแล้วยกเลิกทันที
     * ต้องเสียค่าเช่าเต็มวัน (แพลน 1 วันคือเสียทั้งก้อน) คิดตามจริงยุติธรรมทั้งสองฝั่ง
     * และปิดช่องโหว่ได้เท่ากัน เพราะใช้ VIP ไป 1 วันก็จ่ายค่า 1 วันพอดี
     */
    public function refundableDays(): float
    {
        if (! $this->isLive()) {
            return 0.0;
        }

        return max(0.0, (float) now()->floatDiffInDays($this->expires_at, false));
    }
}
