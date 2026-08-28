<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — มุมมองตลาดที่ AI สรุปไว้เป็นรอบ.
 *
 * ดู migration `create_ai_market_views_table` สำหรับเหตุผลของแต่ละคอลัมน์
 *
 * Developed by Xman Studio.
 */
class AiMarketView extends Model
{
    public const SCOPE_STRATEGIC = 'strategic';

    public const SCOPE_TACTICAL = 'tactical';

    /** ท่าทีรายเหรียญที่ AI ตอบกลับมาได้ */
    public const STANCE_BUY = 'buy';

    public const STANCE_HOLD = 'hold';

    public const STANCE_AVOID = 'avoid';

    public const STANCE_EXIT = 'exit';

    protected $fillable = [
        'scope', 'provider', 'model', 'regime', 'confidence', 'size_multiplier',
        'coins', 'shortlist', 'summary', 'headlines', 'prompt', 'raw_response',
        'tokens_used', 'latency_ms', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'size_multiplier' => 'float',
            'coins' => 'array',
            'shortlist' => 'array',
            'headlines' => 'array',
            'tokens_used' => 'integer',
            'latency_ms' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeFresh(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * มุมมองล่าสุดที่ยังไม่หมดอายุของขอบเขตนี้.
     *
     * คืน null ได้เป็นปกติ — ไม่ใช่ error แต่แปลว่า "ยังไม่มีมุมมอง" ซึ่งบอท
     * ต้องถอยไปใช้กฎล้วน (ดู BotRunner) ไม่ใช่หยุดเทรด
     */
    public static function latestFor(string $scope): ?self
    {
        return static::query()
            ->where('scope', $scope)
            ->fresh()
            ->latest('created_at')
            ->first();
    }

    /**
     * ท่าทีที่ AI ให้กับเหรียญนี้.
     *
     * @return array{score: float, stance: string, why: string}|null
     */
    public function forCoin(string $symbol): ?array
    {
        $entry = $this->coins[strtoupper($symbol)] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        return [
            'score' => (float) ($entry['score'] ?? 0),
            'stance' => (string) ($entry['stance'] ?? self::STANCE_HOLD),
            'why' => (string) ($entry['why'] ?? ''),
        ];
    }

    /** ท่าทีสำหรับคู่เทรด — ตัดเอาเฉพาะเหรียญฐาน (BTC/USDT → BTC) */
    public function forPair(string $pair): ?array
    {
        return $this->forCoin(static::baseOf($pair));
    }

    /** @return list<string> */
    public function shortlistPairs(): array
    {
        return array_values(array_filter((array) $this->shortlist, 'is_string'));
    }

    /** เหรียญฐานของคู่เทรด — รองรับทั้ง BTC/USDT และ BTC-USDT */
    public static function baseOf(string $pair): string
    {
        return strtoupper(explode('/', str_replace('-', '/', $pair))[0]);
    }
}
