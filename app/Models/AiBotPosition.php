<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — ของที่บอทถืออยู่ตอนนี้.
 *
 * entry_price เป็น "ต้นทุนเฉลี่ย" — DCA เติมไม้แล้วต้องเฉลี่ยใหม่ ไม่ใช่ทับค่าเดิม
 * ไม่งั้นกำไรที่คำนวณได้จะผิดทุกครั้งที่มีการเติมไม้
 *
 * Developed by Xman Studio.
 */
class AiBotPosition extends Model
{
    protected $fillable = [
        'ai_bot_config_id', 'pair', 'mode', 'quantity',
        'entry_price', 'cost_basis', 'entry_count', 'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:12',
            'entry_price' => 'decimal:12',
            'cost_basis' => 'decimal:8',
            'entry_count' => 'integer',
            'opened_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(AiBotConfig::class, 'ai_bot_config_id');
    }

    /** กำไร/ขาดทุนที่ยังไม่ได้ปิด ณ ราคาที่ให้มา */
    public function unrealizedPnl(float $price): float
    {
        return round((float) $this->quantity * $price - (float) $this->cost_basis, 8);
    }
}
