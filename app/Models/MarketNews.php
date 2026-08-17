<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — ข่าวตลาดจริงที่ดึงมาจาก RSS พร้อมคะแนนความตื่นตระหนก.
 *
 * ต่างจาก AiNews (บทความที่ AI เขียนเองเพื่อทำคอนเทนต์) — ตัวนี้คือข่าวจากภายนอก
 * ที่ใช้ตัดสินใจเรื่องเงินจริง จึงเก็บลิงก์ต้นทางไว้ให้ตรวจสอบได้เสมอ
 *
 * Developed by Xman Studio.
 */
class MarketNews extends Model
{
    protected $table = 'market_news';

    protected $fillable = [
        'source', 'title', 'url_hash', 'url', 'published_at',
        'panic_score', 'sentiment', 'symbols', 'matched_terms',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'panic_score' => 'decimal:3',
            'sentiment' => 'decimal:3',
            'symbols' => 'array',
            'matched_terms' => 'array',
        ];
    }

    public function scopeRecent(Builder $query, int $minutes): Builder
    {
        return $query->where('published_at', '>=', now()->subMinutes($minutes));
    }

    public function scopeRisky(Builder $query, float $min = 0.5): Builder
    {
        return $query->where('panic_score', '>=', $min);
    }
}
