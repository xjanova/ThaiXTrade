<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — ขั้นบันไดค่าบริการวางไม้.
 *
 * ค่าบริการเป็น "จำนวน TPIX คงที่ต่อไม้" ตามช่วงมูลค่าไม้ ไม่ใช่เปอร์เซ็นต์
 * เจ้าของเลือกแบบนี้เพราะคิดเป็น % แล้วไม้ใหญ่จ่ายแพงจนไม่มีใครใช้
 *
 * Developed by Xman Studio.
 */
class TradingFeeTier extends Model
{
    protected $fillable = [
        'label',
        'min_order_usd',
        'max_order_usd',
        'fee_tpix',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_order_usd' => 'decimal:2',
            'max_order_usd' => 'decimal:2',
            'fee_tpix' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** ขั้นนี้ครอบคลุมมูลค่าไม้นี้ไหม — ปลายบนไม่รวม (ต่อกันได้พอดีไม่ทับซ้อน) */
    public function covers(float $orderValueUsd): bool
    {
        if ($orderValueUsd < (float) $this->min_order_usd) {
            return false;
        }

        return $this->max_order_usd === null || $orderValueUsd < (float) $this->max_order_usd;
    }

    /** ข้อความช่วงราคาสำหรับแสดงผล */
    public function rangeLabel(): string
    {
        $min = number_format((float) $this->min_order_usd, 0);

        if ($this->max_order_usd === null) {
            return "\${$min} ขึ้นไป";
        }

        return "\${$min} – \$".number_format((float) $this->max_order_usd, 0);
    }
}
