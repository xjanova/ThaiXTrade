<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TPIX TRADE — ผู้ตามหนึ่งคนที่ก๊อปปี้มาสเตอร์หนึ่งคน.
 *
 * `fee_rate_pct` / `platform_share_pct` คืออัตราที่ผู้ตาม "ยอมรับไว้" — มาสเตอร์แก้อัตราทีหลัง
 * แถวนี้ไม่เปลี่ยนจนกว่าผู้ตามจะกดยอมรับเอง (ห้ามขึ้นราคากับคนที่ตกลงไว้อีกราคา)
 *
 * Developed by Xman Studio.
 */
class AiCopyFollow extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_STOPPED = 'stopped';

    protected $fillable = [
        'ai_copy_master_id', 'follower_wallet', 'ai_bot_config_id',
        'fee_rate_pct', 'platform_share_pct', 'status',
        'realized_pnl', 'high_water_mark', 'fees_usd', 'last_settled_trade_id', 'started_at', 'stopped_at',
    ];

    protected function casts(): array
    {
        return [
            'fee_rate_pct' => 'float',
            'platform_share_pct' => 'float',
            'realized_pnl' => 'float',
            'high_water_mark' => 'float',
            'fees_usd' => 'float',
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(AiCopyMaster::class, 'ai_copy_master_id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(AiBotConfig::class, 'ai_bot_config_id');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(AiCopyFee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
