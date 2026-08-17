<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — คลังเหรียญสองฝั่งของบอทอาร์บิทราจ.
 *
 * Developed by Xman Studio.
 */
class AiBotReserve extends Model
{
    protected $fillable = [
        'ai_bot_config_id', 'wallet_address', 'pair', 'mode',
        'base_qty', 'quote_amount', 'funded_quote', 'reference_price', 'realized_pnl',
        'round_trips', 'last_action_at',
    ];

    protected function casts(): array
    {
        return [
            'base_qty' => 'decimal:12',
            'quote_amount' => 'decimal:8',
            'funded_quote' => 'decimal:8',
            'reference_price' => 'decimal:12',
            'realized_pnl' => 'decimal:8',
            'round_trips' => 'integer',
            'last_action_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(AiBotConfig::class, 'ai_bot_config_id');
    }

    /**
     * พร้อมลงมือทั้งสองทิศไหม.
     *
     * มีแค่ฝั่งเดียวก็ทำอาร์บิทราจไม่ได้ — ต้องขายของฝั่งแพงและซื้อฝั่งถูกพร้อมกัน
     */
    public function canActBothWays(float $price): bool
    {
        return (float) $this->base_qty > 0 && (float) $this->quote_amount > 0 && $price > 0;
    }

    /** มูลค่ารวมของคลัง ณ ราคาปัจจุบัน */
    public function totalValue(float $price): float
    {
        return round((float) $this->quote_amount + ((float) $this->base_qty * $price), 8);
    }
}
