<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — ไม้ที่บอทลงมือไปแล้วหนึ่งไม้ พร้อมเหตุผลที่ตัดสินใจ.
 *
 * เก็บ reason + signal_meta ไว้ทุกไม้โดยตั้งใจ — ผู้ใช้ต้องเปิดดูย้อนหลังได้ว่า
 * บอทเห็นอะไรถึงซื้อ/ขาย ไม่ใช่แค่เห็นตัวเลขกำไรลอยๆ
 *
 * Developed by Xman Studio.
 */
class AiBotTrade extends Model
{
    protected $fillable = [
        'ai_bot_config_id', 'wallet_address', 'pair', 'mode', 'side',
        'price', 'quantity', 'gross_value', 'fee', 'slippage_cost',
        'realized_pnl', 'strategy', 'reason', 'signal_meta', 'risk_level',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:12',
            'quantity' => 'decimal:12',
            'gross_value' => 'decimal:8',
            'fee' => 'decimal:8',
            'slippage_cost' => 'decimal:8',
            'realized_pnl' => 'decimal:8',
            'signal_meta' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(AiBotConfig::class, 'ai_bot_config_id');
    }

    public function scopeDemo(Builder $query): Builder
    {
        return $query->where('mode', 'demo');
    }
}
