<?php

namespace App\Services\AiBot\Backtest;

use App\Services\AiBot\MacroTrend;
use App\Services\AiBot\Timeframe;

/**
 * TPIX TRADE — เตรียมแท่งเทียนให้ BacktestEngine: ช่วงที่ทดสอบ + ช่วงอุ่นเครื่อง + แท่งรายวันของตัวกรองตลาดใหญ่.
 *
 * ยกออกมาจากคำสั่ง aibot:backtest เพื่อให้การทดสอบย้อนหลังจากหน้าเว็บ (ตัวสร้างกฎ) ใช้ชุดข้อมูลแบบเดียวกัน
 * "เป๊ะ" — ถ้าสองที่เตรียมข้อมูลคนละแบบ (อุ่นเครื่องไม่เท่ากัน · ไม่มีแท่งรายวัน) ผลที่ผู้ใช้เห็นบนเว็บ
 * จะไม่ตรงกับที่ทีมงานวัดจากคำสั่ง ทั้งที่กฎและช่วงเวลาเดียวกัน
 *
 * Developed by Xman Studio.
 */
class BacktestData
{
    /** หน้าต่างสูงสุดที่ engine ใช้ (เท่ากับ BotRunner::candles) — ดึงเผื่อเท่านี้ให้ช่วงที่ขอถูกทดสอบเต็ม */
    private const WARMUP_BARS = 500;

    public function __construct(private readonly KlineArchive $archive) {}

    /**
     * @param  bool  $offline  true = ห้ามยิงตลาด ใช้เฉพาะแท่งที่มีในคลัง
     * @return array{candles: list<array>, start_index: int, macro_daily: list<array>, warnings: list<string>, from_ms: int, to_ms: int}
     *
     * @throws \Throwable เมื่อดึงแท่งเทียนของคู่ที่ทดสอบไม่ได้ (แท่งรายวันของตัวกรองดึงไม่ได้ = คำเตือน ไม่ใช่ข้อผิดพลาด)
     */
    public function load(string $pair, string $timeframe, int $days, bool $offline = false): array
    {
        $stepMs = Timeframe::milliseconds($timeframe);
        $toMs = now()->getTimestamp() * 1000;
        $fromMs = $toMs - max(1, $days) * 86_400_000;
        $warmupMs = self::WARMUP_BARS * $stepMs;
        $warnings = [];

        $candles = $this->archive->range($pair, $timeframe, $fromMs - $warmupMs, $toMs, $offline);

        /*
         * แท่งรายวันของ BTC สำหรับตัวกรองแนวโน้มใหญ่ (กติกาเดียวกับ BotRunner ขั้น 4.2)
         * เผื่อย้อนหลัง MacroTrend::WINDOW วันให้ EMA อุ่นเครื่องเต็มตั้งแต่แท่งแรกของช่วงที่ทดสอบ
         * ดึงไม่ได้ = รันต่อแบบไม่กรอง พร้อมเตือน (บอทจริงก็ถอยแบบเดียวกัน)
         */
        try {
            $macroDaily = $this->archive->range(
                (string) config('aibot.macro.symbol', 'BTC/USDT'),
                '1d',
                $fromMs - $warmupMs - MacroTrend::WINDOW * 86_400_000,
                $toMs,
                $offline,
            );
        } catch (\Throwable $e) {
            $macroDaily = [];
            $warnings[] = 'ดึงแท่งรายวันของตัวกรองแนวโน้มใหญ่ไม่ได้: '.$e->getMessage();
        }

        $needDailyFrom = $fromMs - MacroTrend::WINDOW * 86_400_000;
        $firstDaily = $macroDaily[0]['time'] ?? null;

        if ($macroDaily === []) {
            // --offline กับคลังที่ไม่มีแท่งรายวัน BTC ได้ [] เงียบๆ — ต้องบอกว่าผลนี้ "ไม่ได้กรอง"
            $warnings[] = 'ไม่มีแท่งรายวันของ BTC — ผลนี้ไม่ได้ใช้ตัวกรองแนวโน้มใหญ่ (ต่างจากบอทจริง)';
        } elseif ((int) $firstDaily > $needDailyFrom + 86_400_000) {
            /*
             * มีข้อมูลแต่สั้นกว่าหน้าต่าง — ช่วงแรกของผลใช้ EMA จากหน้าต่างสั้นกว่าบอทจริง
             * (รีวิว 2026-09-23: หน้าต่าง 499 วัน + --offline + --days ยาว = เพี้ยนเงียบๆ)
             */
            $warnings[] = sprintf(
                'แท่งรายวันของ BTC ในคลังเริ่ม %s (ต้องมีตั้งแต่ %s) — ช่วงแรกของผลนี้ใช้หน้าต่าง EMA สั้นกว่าบอทจริง',
                gmdate('Y-m-d', intdiv((int) $firstDaily, 1000)),
                gmdate('Y-m-d', intdiv($needDailyFrom, 1000)),
            );
        }

        return [
            'candles' => $candles,
            'start_index' => self::firstIndexAtOrAfter($candles, $fromMs),
            'macro_daily' => $macroDaily,
            'warnings' => $warnings,
            'from_ms' => $fromMs,
            'to_ms' => $toMs,
        ];
    }

    private static function firstIndexAtOrAfter(array $candles, int $ms): int
    {
        foreach ($candles as $i => $candle) {
            if ((int) $candle['time'] >= $ms) {
                return $i;
            }
        }

        return count($candles);
    }
}
