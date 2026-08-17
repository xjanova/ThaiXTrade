<?php

namespace App\Services\AiBot;

/**
 * TPIX TRADE — ผลการตัดสินใจหนึ่งครั้งของกลยุทธ์.
 *
 * ทุกกลยุทธ์คืนวัตถุนี้เสมอ ไม่ว่าจะทำอะไรหรือไม่ทำอะไร — การ "ไม่ทำ" ก็ต้องมีเหตุผล
 * ติดมาด้วย เพราะผู้ใช้ต้องเปิดดูได้ว่าบอทเงียบเพราะอะไร (ไม่งั้นดูเหมือนบอทตาย)
 *
 * Developed by Xman Studio.
 */
class Signal
{
    public const BUY = 'buy';

    public const SELL = 'sell';

    public const HOLD = 'hold';

    /**
     * @param  string  $action  buy | sell | hold
     * @param  float  $strength  0..1 — ใช้ปรับขนาดไม้ (1 = เต็มขนาดที่ตั้งไว้)
     * @param  string  $reason  เหตุผลที่มนุษย์อ่านรู้เรื่อง เก็บลง log ของบอท
     * @param  array<string, mixed>  $meta  ตัวเลขที่ใช้ตัดสินใจ (ไว้ตรวจย้อนหลัง)
     */
    public function __construct(
        public readonly string $action,
        public readonly float $strength = 0.0,
        public readonly string $reason = '',
        public readonly array $meta = [],
    ) {}

    public static function buy(float $strength, string $reason, array $meta = []): self
    {
        return new self(self::BUY, max(0.0, min(1.0, $strength)), $reason, $meta);
    }

    public static function sell(float $strength, string $reason, array $meta = []): self
    {
        return new self(self::SELL, max(0.0, min(1.0, $strength)), $reason, $meta);
    }

    public static function hold(string $reason, array $meta = []): self
    {
        return new self(self::HOLD, 0.0, $reason, $meta);
    }

    public function isActionable(): bool
    {
        return $this->action !== self::HOLD;
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'strength' => round($this->strength, 4),
            'reason' => $this->reason,
            'meta' => $this->meta,
        ];
    }
}
