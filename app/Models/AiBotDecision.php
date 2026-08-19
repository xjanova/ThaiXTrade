<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — บันทึกการตัดสินใจของบอทหนึ่งรอบ.
 *
 * เก็บ "ทุกครั้งที่คิด" ไม่ใช่เฉพาะตอนลงมือ — ข้อมูลที่ใช้ปรับปรุงกลยุทธ์ได้จริง
 * คือเหตุผลที่บอท "ไม่ทำอะไร" ซึ่งเกิดบ่อยกว่าการเข้าไม้หลายสิบเท่า
 *
 * Developed by Xman Studio.
 */
class AiBotDecision extends Model
{
    protected $fillable = [
        'ai_bot_config_id', 'wallet_address', 'strategy', 'pair', 'timeframe', 'mode',
        'action', 'reason', 'risk_level', 'price', 'budget', 'has_position',
        'signal_meta', 'params',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:8',
            'budget' => 'decimal:8',
            'has_position' => 'boolean',
            'signal_meta' => 'array',
            'params' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(AiBotConfig::class, 'ai_bot_config_id');
    }

    /** รอบที่บอทลงมือจริง (ไม่นับ hold/stopped/error) */
    public function scopeActed(Builder $query): Builder
    {
        return $query->whereIn('action', ['buy', 'sell', 'signal']);
    }

    public function scopeForWallet(Builder $query, string $wallet): Builder
    {
        return $query->where('wallet_address', strtolower($wallet));
    }
}
