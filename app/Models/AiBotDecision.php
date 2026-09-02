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
        'signal_meta', 'params', 'repeat_count', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:8',
            'budget' => 'decimal:8',
            'has_position' => 'boolean',
            'signal_meta' => 'array',
            'params' => 'array',
            'repeat_count' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * แถวนี้คือ "สภาพเดิม" ของรอบที่กำลังจะบันทึกไหม.
     *
     * บอทที่ถือของอยู่บนแท่ง 1 ชั่วโมงถูกปลุกทุกนาที — ราคาที่ใช้คือราคาปิดของแท่ง
     * ที่ปิดแล้ว จึงเห็นภาพเดิมเป๊ะ 60 รอบต่อแท่ง เดิมบันทึกเป็น 60 แถวที่เหมือนกัน
     * ทุกตัวอักษร (81,105 แถว / 46.7 MB ใน 13 วันบน prod จน aibot:harvest ล้ม)
     * รอบที่เหมือนเดิมจึงนับซ้ำในแถวเดิมแทน — ข้อมูล "ทำไมถึงไม่ทำ" ยังครบ
     * และยังบอกได้ว่าสภาพนั้นคงอยู่นานแค่ไหน (repeat_count × รอบ)
     */
    public function isSameSituation(string $action, string $reason, string $riskLevel, ?float $price, bool $hasPosition): bool
    {
        $samePrice = ($price === null && $this->price === null)
            || ($price !== null && $this->price !== null && abs((float) $this->price - $price) < 1e-8);

        return $this->action === $action
            && $this->reason === $reason
            && $this->risk_level === $riskLevel
            && $this->has_position === $hasPosition
            && $samePrice;
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
