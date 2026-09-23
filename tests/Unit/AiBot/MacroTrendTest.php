<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\MacroTrend;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TPIX TRADE — แนวโน้มใหญ่ (BTC รายวันเทียบ EMA) ที่บอทจริงและ backtester ใช้ร่วมกัน.
 *
 * สิ่งที่ต้องยืน:
 *   1. ข้อมูลไม่พอ = "ไม่รู้" (null) ไม่ใช่ "ขาลง" — ไม่งั้นบอทหยุดเปิดไม้ทั้งระบบเพราะตลาดตอบช้า
 *   2. ถามได้เฉพาะแท่งที่ "ปิดแล้ว" ก่อนเวลาที่ถาม — ห้ามรู้ราคาปิดของวันที่ยังไม่จบ
 *   3. บอทจริงกับ backtest ต้องได้คำตอบเดียวกัน (หน้าต่าง MacroTrend::WINDOW แท่งเท่ากัน)
 *
 * Developed by Xman Studio.
 */
class MacroTrendTest extends TestCase
{
    private const DAY = 86_400_000;

    /** @param list<float> $closes */
    private function daily(array $closes, int $startMs = 1_700_006_400_000): array
    {
        return array_map(fn ($i) => ['time' => $startMs + $i * self::DAY, 'close' => $closes[$i]], array_keys($closes));
    }

    #[Test]
    public function ราคาเหนือ_ema_คือขาขึ้น_ใต้_ema_คือขาลง(): void
    {
        $rising = $this->daily(array_map(fn ($i) => 100 + $i, range(0, 80)));
        $falling = $this->daily(array_map(fn ($i) => 200 - $i, range(0, 80)));

        $this->assertTrue(MacroTrend::assess($rising, 50)['up']);
        $this->assertFalse(MacroTrend::assess($falling, 50)['up']);
        $this->assertGreaterThan(0, MacroTrend::assess($rising, 50)['above_pct']);
    }

    #[Test]
    public function ข้อมูลไม่พอต้องตอบไม่รู้_ไม่ใช่ขาลง(): void
    {
        $this->assertNull(MacroTrend::assess($this->daily(array_fill(0, 50, 100.0)), 50)['up']);
        $this->assertNull(MacroTrend::assess([], 50)['up']);
    }

    #[Test]
    public function ใช้แค่หน้าต่างล่าสุด_บอทจริงกับ_backtest_จึงได้คำตอบเดียวกัน(): void
    {
        // ประวัติยาวมากที่ต้นทางต่างกัน แต่แท่งท้ายเท่าหน้าต่างเหมือนกัน → ผลต้องเท่ากันเป๊ะ ทุกคาบ
        $tail = array_map(fn ($i) => 100 + sin($i / 7) * 10, range(0, MacroTrend::WINDOW - 1));
        $a = $this->daily(array_merge(array_fill(0, 400, 50.0), $tail));
        $b = $this->daily(array_merge(array_fill(0, 100, 500.0), $tail));

        foreach ([50, 100, 200] as $period) {
            $this->assertSame(MacroTrend::assess($a, $period), MacroTrend::assess($b, $period), "EMA {$period}");
        }
    }

    /**
     * หน้าต่างต้องยาวพอให้ "EMA 200" เป็น EMA จริง ไม่ใช่ SMA ของเมื่อหลายเดือนก่อน.
     *
     * รีวิว 2026-09-23: หน้าต่าง 300 แท่ง → ค่าเริ่ม (SMA 200 แท่งแรก) ยังหนัก ~37%
     * และต้องไม่เกินที่ตัวดึงราคาให้ได้ (MarketDataService::getKlines ตัดที่ 500 แท่ง
     * ตัดแท่งของวันที่ยังวิ่งทิ้ง 1) — เกินแล้วบอทจริงได้หน้าต่างสั้นกว่า backtest เงียบๆ
     */
    #[Test]
    public function หน้าต่างยาวพอให้_ema_200_เป็น_ema_จริง_และไม่เกินที่ดึงได้(): void
    {
        $seedWeight = (1 - 2 / 201) ** (MacroTrend::WINDOW - 200);

        $this->assertLessThan(0.06, $seedWeight, 'ค่าเริ่มของ EMA 200 ยังหนักเกิน');
        $this->assertLessThanOrEqual(499, MacroTrend::WINDOW, 'บอทจริงดึงได้มากสุด 500 แท่ง รวมแท่งที่ยังวิ่ง');
    }

    #[Test]
    public function backtest_เห็นแท่งรายวันเฉพาะหลังมันปิดแล้ว(): void
    {
        // ขึ้น 80 วันแล้วดิ่งหนักวันสุดท้าย: แนวโน้มพลิกเป็นขาลงหลังแท่งสุดท้าย "ปิด" เท่านั้น
        $closes = array_map(fn ($i) => 100 + $i, range(0, 79));
        $closes[] = 50.0;
        $daily = $this->daily($closes);
        $series = MacroTrend::series($daily, 50);

        $lastOpen = $daily[80]['time'];
        $cursor = 0;

        $this->assertTrue(MacroTrend::upAt($series, $lastOpen + self::DAY - 1, $cursor), 'ก่อนแท่งวันดิ่งปิด ยังต้องเห็นขาขึ้น');
        $this->assertFalse(MacroTrend::upAt($series, $lastOpen + self::DAY, $cursor), 'แท่งปิดแล้ว (00:00 UTC) ต้องเห็นขาลงทันที');

        $fresh = 0;
        $this->assertNull(MacroTrend::upAt($series, $daily[0]['time'], $fresh), 'ก่อนแท่งแรกปิด = ไม่รู้');
    }
}
