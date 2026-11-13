<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TPIX TRADE — โปรไฟล์มาสเตอร์ของ Copy Trade.
 *
 * หนึ่งกระเป๋าหนึ่งโปรไฟล์ ผูกกับบอทหนึ่งตัว — ผลงานที่โชว์คือผลเงินจริงของบอทตัวนั้นเท่านั้น
 * (เลือกโชว์เฉพาะบอทที่ผลดีจากหลายตัวไม่ได้ และผลโหมดทดลองไม่ถูกนับ)
 *
 * Developed by Xman Studio.
 */
class AiCopyMaster extends Model
{
    public const STATUS_ACTIVE = 'active';

    /** มาสเตอร์พักรับผู้ตามใหม่ — ผู้ตามเดิมยังก๊อปปี้ต่อ */
    public const STATUS_PAUSED = 'paused';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'wallet_address', 'ai_bot_config_id', 'display_name', 'bio', 'intro',
        'fee_rate_pct', 'platform_share_pct', 'status', 'stats', 'stats_at',
        'registered_at', 'closed_at',
        'rank_tier', 'rank_score', 'rank_below_since', 'rank_evaluated_at', 'rating_avg', 'rating_count',
        'media_revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'fee_rate_pct' => 'float',
            'platform_share_pct' => 'float',
            'stats' => 'array',
            'stats_at' => 'datetime',
            'registered_at' => 'datetime',
            'closed_at' => 'datetime',
            'rank_tier' => 'integer',
            'rank_score' => 'float',
            'rank_below_since' => 'datetime',
            'rank_evaluated_at' => 'datetime',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'media_revoked_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(AiBotConfig::class, 'ai_bot_config_id');
    }

    public function follows(): HasMany
    {
        return $this->hasMany(AiCopyFollow::class);
    }

    /** มาสเตอร์ที่ยังเปิดอยู่ (รับผู้ตามใหม่หรือพักรับ) — ไม่รวมที่ปิดโปรไฟล์แล้ว */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_PAUSED]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PAUSED], true);
    }
}
