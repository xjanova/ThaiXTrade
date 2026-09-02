<?php

namespace App\Services\AiBot\Backtest;

use App\Services\AiBot\Timeframe;
use App\Services\MarketDataService;
use Illuminate\Support\Facades\File;

/**
 * TPIX TRADE — คลังแท่งเทียนย้อนหลังสำหรับ backtest.
 *
 * แท่งที่ปิดแล้วไม่มีวันเปลี่ยน จึงเก็บลงไฟล์ครั้งเดียวแล้วใช้ซ้ำได้ตลอด —
 * backtest ซ้ำสิบรอบเพื่อจูนพารามิเตอร์ต้องไม่ยิงตลาดสิบรอบ (โดนจำกัดอัตราแน่นอน
 * และผลจะต่างกันถ้าดึงคนละเวลา)
 *
 * ไฟล์ละคู่+timeframe: storage/app/aibot/klines/BTCUSDT_1h.json
 * ดึงเฉพาะช่วงที่ยังไม่มี แล้วรวมกับของเดิม (เรียงตามเวลา ไม่ซ้ำ)
 *
 * Developed by Xman Studio.
 */
class KlineArchive
{
    private string $dir;

    public function __construct(
        private readonly MarketDataService $market,
        ?string $dir = null,
    ) {
        $this->dir = $dir ?? storage_path('app/aibot/klines');
    }

    /**
     * แท่งเทียนที่ปิดแล้วในช่วง [fromMs, toMs].
     *
     * @param  bool  $offline  true = ห้ามยิงตลาด ใช้เฉพาะที่มีในคลัง (ไม่ครบก็คืนเท่าที่มี)
     * @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    public function range(string $symbol, string $interval, int $fromMs, int $toMs, bool $offline = false): array
    {
        $stepMs = Timeframe::milliseconds($interval);
        $stored = $this->load($symbol, $interval);

        // แท่งสุดท้ายที่ปิดแล้วจริงๆ ณ ตอนนี้ — แท่งที่ยังวิ่งอยู่ห้ามเข้าคลัง
        $lastClosedOpen = (int) (floor(now()->getTimestamp() * 1000 / $stepMs) * $stepMs) - $stepMs;
        $toMs = min($toMs, $lastClosedOpen);

        if (! $offline) {
            $fetched = false;

            foreach ($this->gaps($stored, $fromMs, $toMs, $stepMs) as [$gapFrom, $gapTo]) {
                foreach ($this->market->getKlinesBetween($symbol, $interval, $gapFrom, $gapTo) as $raw) {
                    $time = (int) $raw['time'];

                    if ($time > $lastClosedOpen) {
                        continue;
                    }

                    $stored[$time] = $this->normalize($raw);
                    $fetched = true;
                }
            }

            if ($fetched) {
                ksort($stored);
                $this->save($symbol, $interval, $stored);
            }
        }

        ksort($stored);

        return array_values(array_filter(
            $stored,
            fn (array $c) => $c['time'] >= $fromMs && $c['time'] <= $toMs,
        ));
    }

    /** ช่วงเวลาที่คลังยังไม่มี — ดูแค่ขอบหน้าและขอบหลัง (คลังไม่มีรูตรงกลางโดยออกแบบ) */
    private function gaps(array $stored, int $fromMs, int $toMs, int $stepMs): array
    {
        if ($stored === []) {
            return [[$fromMs, $toMs]];
        }

        $times = array_keys($stored);
        $first = (int) min($times);
        $last = (int) max($times);
        $gaps = [];

        if ($fromMs < $first - $stepMs) {
            $gaps[] = [$fromMs, $first - $stepMs];
        }

        if ($toMs > $last + $stepMs) {
            $gaps[] = [$last + $stepMs, $toMs];
        }

        return $gaps;
    }

    /** @return array<int, array> คีย์ด้วยเวลาเปิดแท่ง */
    private function load(string $symbol, string $interval): array
    {
        $path = $this->path($symbol, $interval);

        if (! File::exists($path)) {
            return [];
        }

        $rows = json_decode((string) File::get($path), true);

        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            // เก็บแบบย่อ [time, open, high, low, close, volume] ให้ไฟล์เล็ก
            if (is_array($row) && count($row) >= 6) {
                $out[(int) $row[0]] = [
                    'time' => (int) $row[0],
                    'open' => (float) $row[1],
                    'high' => (float) $row[2],
                    'low' => (float) $row[3],
                    'close' => (float) $row[4],
                    'volume' => (float) $row[5],
                ];
            }
        }

        return $out;
    }

    private function save(string $symbol, string $interval, array $stored): void
    {
        File::ensureDirectoryExists($this->dir);

        $compact = array_map(
            fn (array $c) => [$c['time'], $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']],
            array_values($stored),
        );

        File::put($this->path($symbol, $interval), json_encode($compact));
    }

    private function normalize(array $raw): array
    {
        return [
            'time' => (int) $raw['time'],
            'open' => (float) $raw['open'],
            'high' => (float) $raw['high'],
            'low' => (float) $raw['low'],
            'close' => (float) $raw['close'],
            'volume' => (float) $raw['volume'],
        ];
    }

    public function path(string $symbol, string $interval): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $symbol) ?? $symbol);

        return $this->dir.DIRECTORY_SEPARATOR.$clean.'_'.$interval.'.json';
    }
}
