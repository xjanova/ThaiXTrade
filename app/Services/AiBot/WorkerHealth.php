<?php

namespace App\Services\AiBot;

use App\Models\AiBotConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — สัญญาณชีพของวอร์กเกอร์ AI TRADE.
 *
 * เจ้าของสั่งว่า "บอทต้องกลับมาออนไลน์ได้เอง หากเซิร์ฟดับไป" — บอทเดินด้วย cron
 * ทุกนาที (schedule:run) ซึ่งกลับมาเองหลังรีบูตอยู่แล้ว แต่มีสองอย่างที่ทำให้
 * "กลับมาแล้ว" กับ "เดินจริง" ไม่ใช่เรื่องเดียวกัน:
 *
 *  1. ล็อกกันซ้อน (withoutOverlapping) ค้างในแคชเมื่อโปรเซสตายกลางรอบ — cron รอบใหม่
 *     เห็นล็อกแล้วข้ามไป จนกว่าจะหมดอายุ (10 นาที) บอททุกตัวของกลยุทธ์นั้นนิ่ง
 *  2. ไม่มีใครรู้ว่าบอทนิ่ง — สถานะในฐานข้อมูลยังเป็น running อยู่ดี หน้าเว็บก็โชว์
 *     จุดเขียวกะพริบต่อไป ทั้งที่ไม่มีรอบคิดมาครึ่งชั่วโมงแล้ว
 *
 * คลาสนี้เก็บ "จังหวะเต้น" ของวอร์กเกอร์แต่ละกลยุทธ์ไว้ในแคช (เต้นก่อนเริ่มรอบ
 * และหลังบอทแต่ละตัว — รอบที่ยังเดินอยู่จึงเต้นต่อเนื่อง รอบที่ตายจะเงียบ)
 * แล้วให้ `aibot:health` กับ API ใช้ตัดสินว่าอะไรออนไลน์จริง
 *
 * Developed by Xman Studio.
 */
class WorkerHealth
{
    public const KEY_PREFIX = 'aibot:worker:beat:';

    public const KEY_GLOBAL = 'aibot:worker:beat';

    public const KEY_OUTAGE_PREFIX = 'aibot:worker:outage:';

    public const KEY_SUMMARY = 'aibot:worker:health';

    /** เก็บจังหวะเต้นไว้นานพอให้ดูย้อนหลังได้หลังดับข้ามคืน */
    private const BEAT_TTL_HOURS = 72;

    /** วอร์กเกอร์เต้นหนึ่งครั้ง — เรียกตอนเริ่มรอบและหลังบอทแต่ละตัว */
    public function beat(?string $strategy = null): void
    {
        $now = now()->timestamp;

        Cache::put(self::KEY_GLOBAL, $now, now()->addHours(self::BEAT_TTL_HOURS));

        if ($strategy) {
            Cache::put(self::KEY_PREFIX.$strategy, $now, now()->addHours(self::BEAT_TTL_HOURS));
        }
    }

    public function lastBeat(?string $strategy = null): ?Carbon
    {
        $ts = Cache::get($strategy ? self::KEY_PREFIX.$strategy : self::KEY_GLOBAL);

        return is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    /** อายุของจังหวะล่าสุด (นาที) — null = ไม่เคยเต้นเลย */
    public function beatAgeMinutes(?string $strategy = null): ?int
    {
        $last = $this->lastBeat($strategy);

        return $last ? (int) $last->diffInMinutes(now()) : null;
    }

    /** เกณฑ์ "เงียบเกินไป" (นาที) — อ่านจาก config เพื่อปรับได้โดยไม่แก้โค้ด */
    public function staleMinutes(): int
    {
        return max(2, (int) config('aibot.health.stale_minutes', 5));
    }

    /**
     * วอร์กเกอร์ของกลยุทธ์นี้ยังเดินอยู่ไหม.
     *
     * "ไม่เคยเต้น" ถือว่าไม่มีชีวิต — ยกเว้นเพิ่งติดตั้ง (ยังไม่มีใครเรียกเลย)
     * ซึ่ง aibot:health จะเป็นคนแยกแยะเองว่ามีบอทรออยู่หรือเปล่า
     */
    public function isAlive(?string $strategy = null, ?int $maxAgeMinutes = null): bool
    {
        $age = $this->beatAgeMinutes($strategy);

        return $age !== null && $age <= ($maxAgeMinutes ?? $this->staleMinutes());
    }

    // ── บันทึกช่วงที่ดับ ──────────────────────────────────────────────────

    public function outageSince(string $strategy): ?Carbon
    {
        $ts = Cache::get(self::KEY_OUTAGE_PREFIX.$strategy);

        return is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    /** เริ่มนับช่วงดับ — คืน true เฉพาะครั้งแรก (ให้แจ้งเตือนครั้งเดียว ไม่สแปม) */
    public function markOutage(string $strategy, ?Carbon $since = null): bool
    {
        if ($this->outageSince($strategy)) {
            return false;
        }

        Cache::put(
            self::KEY_OUTAGE_PREFIX.$strategy,
            ($since ?? now())->timestamp,
            now()->addHours(self::BEAT_TTL_HOURS),
        );

        return true;
    }

    /** กลับมาแล้ว — คืนจำนวนนาทีที่ดับไป (null = ไม่ได้ดับอยู่) */
    public function clearOutage(string $strategy): ?int
    {
        $since = $this->outageSince($strategy);

        if (! $since) {
            return null;
        }

        Cache::forget(self::KEY_OUTAGE_PREFIX.$strategy);

        return (int) $since->diffInMinutes(now());
    }

    // ── สรุปให้ API / หลังบ้าน ─────────────────────────────────────────────

    public function storeSummary(array $summary): void
    {
        Cache::put(self::KEY_SUMMARY, $summary + ['checked_at' => now()->toIso8601String()], now()->addDay());
    }

    public function summary(): ?array
    {
        $summary = Cache::get(self::KEY_SUMMARY);

        return is_array($summary) ? $summary : null;
    }

    /**
     * บอทตัวนี้ "ออนไลน์" จริงไหม — มองจากสามชั้นพร้อมกัน.
     *
     *  - สถานะต้องเป็น running และไม่ถูกแบน
     *  - วอร์กเกอร์ของกลยุทธ์นั้นต้องยังเต้นอยู่
     *  - บอทต้องได้รอบคิดไม่นานเกิน 3 เท่าของรอบที่แพลนสัญญาไว้
     *    (บอทใหม่ที่ยังไม่เคยเดินให้เวลาเท่ากับหนึ่งรอบ + เกณฑ์เงียบ)
     *
     * @return array{online: bool, reason: string|null, worker_last_beat_at: string|null}
     */
    public function botStatus(AiBotConfig $bot, int $intervalMinutes = 5): array
    {
        $beat = $this->lastBeat($bot->strategy);
        $beatIso = $beat?->toIso8601String();

        if ($bot->status !== 'running') {
            return ['online' => false, 'reason' => 'not_running', 'worker_last_beat_at' => $beatIso];
        }

        if ($bot->isBanned()) {
            return ['online' => false, 'reason' => 'banned', 'worker_last_beat_at' => $beatIso];
        }

        if (! $this->isAlive($bot->strategy)) {
            return ['online' => false, 'reason' => 'worker_silent', 'worker_last_beat_at' => $beatIso];
        }

        $allowance = max(1, $intervalMinutes) * 3 + $this->staleMinutes();
        $reference = $bot->last_run_at ?? $bot->created_at;

        if ($reference && CarbonImmutable::instance($reference)->addMinutes($allowance)->isPast()) {
            return ['online' => false, 'reason' => 'bot_stale', 'worker_last_beat_at' => $beatIso];
        }

        return ['online' => true, 'reason' => null, 'worker_last_beat_at' => $beatIso];
    }
}
