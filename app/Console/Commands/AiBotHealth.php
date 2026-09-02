<?php

namespace App\Console\Commands;

use App\Models\AiBotConfig;
use App\Services\AiBot\WorkerHealth;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ยามเฝ้าวอร์กเกอร์ AI TRADE: ปลดล็อกค้าง + บันทึกช่วงดับ + ฟื้นเอง.
 *
 * เดินทุก 2 นาทีจาก cron เดียวกับบอท (ถ้า cron ตาย ตัวนี้ตายด้วย — แต่ cron คือ
 * สิ่งที่ระบบปฏิบัติการเปิดคืนให้เองหลังรีบูต จึงเป็นจุดยึดที่เชื่อได้ที่สุด)
 *
 * สิ่งที่ทำต่อกลยุทธ์ที่ "มีบอทคลาวด์รออยู่":
 *  1. วอร์กเกอร์เงียบเกินเกณฑ์ + ล็อกกันซ้อนยังค้าง → ปลดล็อกทันที cron รอบถัดไป
 *     จะเดินได้เลย ไม่ต้องรอล็อกหมดอายุเอง 10 นาที
 *  2. เงียบครั้งแรก → บันทึกเวลาเริ่มดับ + log ระดับ error หนึ่งครั้ง (ไม่สแปมทุกรอบ)
 *  3. กลับมาเต้นแล้วทั้งที่มีบันทึกดับ → log การฟื้นพร้อมจำนวนนาทีที่หายไป
 *
 * ปลดล็อกได้ปลอดภัยเพราะวอร์กเกอร์เต้นหลังบอททุกตัว — รอบที่ยังเดินอยู่จริง
 * จะไม่มีทางเงียบเกินเกณฑ์ ล็อกที่เงียบเกินเกณฑ์จึงเป็นของโปรเซสที่ตายแล้วเท่านั้น
 *
 * Developed by Xman Studio.
 */
class AiBotHealth extends Command
{
    protected $signature = 'aibot:health {--json : พิมพ์สรุปเป็น JSON}';

    protected $description = 'ตรวจสัญญาณชีพวอร์กเกอร์ AI TRADE ปลดล็อกที่ค้าง และบันทึกช่วงดับ/ฟื้น';

    public function handle(WorkerHealth $health, Schedule $schedule): int
    {
        $stale = $health->staleMinutes();

        /*
         * ดูเฉพาะกลยุทธ์ที่มีบอทคลาวด์สถานะ running อยู่จริง — กลยุทธ์ที่ไม่มีใครใช้
         * วอร์กเกอร์ก็ "เงียบ" โดยธรรมชาติ (ยังเต้นอยู่แต่ไม่มีอะไรทำ) การนับว่าดับ
         * จะทำให้แจ้งเตือนเท็จตลอดเวลา
         */
        $waiting = AiBotConfig::runnable()
            ->cloudExecuted()
            ->selectRaw('strategy, count(*) as bots')
            ->groupBy('strategy')
            ->pluck('bots', 'strategy');

        $events = collect($schedule->events())
            ->filter(fn (Event $event) => str_starts_with((string) $event->description, 'aibot:tick:'))
            ->keyBy(fn (Event $event) => substr((string) $event->description, strlen('aibot:tick:')));

        $rows = [];
        $strategies = [];
        $anyDown = false;

        foreach ($waiting as $strategy => $bots) {
            $age = $health->beatAgeMinutes($strategy);
            $alive = $age !== null && $age <= $stale;
            $lockReleased = false;
            $outageMinutes = null;

            if (! $alive) {
                $anyDown = true;
                $event = $events->get($strategy);

                // ล็อกของแต่ละ event อยู่ที่ตัว event เอง (EventMutex ไม่ได้ผูกในคอนเทนเนอร์)
                if ($event && $event->mutex->exists($event)) {
                    $event->mutex->forget($event);
                    $lockReleased = true;
                    Log::warning('AI bot worker: released stale overlap lock', [
                        'strategy' => $strategy,
                        'beat_age_minutes' => $age,
                    ]);
                }

                $since = $health->lastBeat($strategy) ?? now();

                if ($health->markOutage($strategy, $since)) {
                    Log::error('AI bot worker silent — bots are not being ticked', [
                        'strategy' => $strategy,
                        'bots_waiting' => $bots,
                        'last_beat_at' => $health->lastBeat($strategy)?->toIso8601String(),
                    ]);
                }
            } else {
                $outageMinutes = $health->clearOutage($strategy);

                if ($outageMinutes !== null) {
                    Log::info('AI bot worker recovered', [
                        'strategy' => $strategy,
                        'down_minutes' => $outageMinutes,
                        'bots_waiting' => $bots,
                    ]);
                }
            }

            $strategies[$strategy] = [
                'alive' => $alive,
                'bots' => (int) $bots,
                'beat_age_minutes' => $age,
                'last_beat_at' => $health->lastBeat($strategy)?->toIso8601String(),
                'lock_released' => $lockReleased,
                'outage_since' => $health->outageSince($strategy)?->toIso8601String(),
                'recovered_after_minutes' => $outageMinutes,
            ];

            $rows[] = [
                $strategy,
                $bots,
                $alive ? 'เดินอยู่' : 'เงียบ',
                $age === null ? '—' : $age.' นาที',
                $lockReleased ? 'ปลดแล้ว' : '—',
                $outageMinutes !== null ? "ฟื้นหลังดับ {$outageMinutes} นาที" : ($strategies[$strategy]['outage_since'] ? 'ดับตั้งแต่ '.$strategies[$strategy]['outage_since'] : '—'),
            ];
        }

        $summary = [
            'alive' => ! $anyDown,
            'stale_minutes' => $stale,
            'global_last_beat_at' => $health->lastBeat()?->toIso8601String(),
            'strategies' => $strategies,
        ];

        $health->storeSummary($summary);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('ไม่มีบอทคลาวด์ที่กำลังทำงาน — ไม่มีวอร์กเกอร์ให้เฝ้า');

            return self::SUCCESS;
        }

        $this->table(['กลยุทธ์', 'บอทรอ', 'วอร์กเกอร์', 'เงียบมา', 'ล็อกค้าง', 'เหตุการณ์'], $rows);

        return $anyDown ? self::FAILURE : self::SUCCESS;
    }
}
