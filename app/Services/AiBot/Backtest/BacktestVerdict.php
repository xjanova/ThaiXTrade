<?php

namespace App\Services\AiBot\Backtest;

/**
 * TPIX TRADE — คำตัดสินของผล backtest: เกณฑ์เดียวสำหรับคำสั่ง aibot:backtest และหน้าตัวสร้างกฎบนเว็บ.
 *
 * เกณฑ์ (ลำดับสำคัญ — ข้อแรกที่เข้าคือคำตอบ):
 *   insufficient          ปิดไม้ไม่ถึง 10 ไม้ — แยกฝีมือจากความบังเอิญไม่ได้
 *   losing                edge ต่อไม้ไม่ชนะต้นทุนเข้า-ออก — แพ้โดยโครงสร้าง จูนเท่าไหร่ก็ไม่รอด
 *   negative_expectancy   ชนะต้นทุนต่อไม้ แต่ไม้แพ้ใหญ่กว่าไม้ชนะจนค่าคาดหวังติดลบ
 *   positive              ชนะต้นทุนและค่าคาดหวังเป็นบวก — ยังไม่ใช่คำรับรอง (อดีตไม่ใช่อนาคต)
 *
 * ⚠️ ห้ามมีระดับที่อ่านว่า "กลยุทธ์นี้ดี": ผลบวกของกลยุทธ์สำเร็จรูปใน backtest 2 ปีส่วนใหญ่มาจากทิศทางตลาด
 *    หน้าจอจึงต้องโชว์ผลของการถือเฉยๆ ในช่วงเดียวกันคู่กันเสมอ (beatsHold)
 *
 * Developed by Xman Studio.
 */
final class BacktestVerdict
{
    public const INSUFFICIENT = 'insufficient';

    public const LOSING = 'losing';

    public const NEGATIVE_EXPECTANCY = 'negative_expectancy';

    public const POSITIVE = 'positive';

    /** ต้องปิดอย่างน้อยกี่ไม้ถึงจะเริ่มสรุปได้ */
    public const MIN_CLOSED = 10;

    /** @param  array  $summary  จาก BacktestEngine::run()['summary'] */
    public static function level(array $summary): string
    {
        if ((int) ($summary['closed'] ?? 0) < self::MIN_CLOSED) {
            return self::INSUFFICIENT;
        }

        if (($summary['edge_bps'] ?? -INF) <= ($summary['cost_bps'] ?? 0)) {
            return self::LOSING;
        }

        if (($summary['expectancy'] ?? 0) <= 0) {
            return self::NEGATIVE_EXPECTANCY;
        }

        return self::POSITIVE;
    }

    /** ผลตอบแทนต่อทุนที่ให้บอทใช้ ชนะการถือเฉยๆ ในช่วงเดียวกันไหม (null = เทียบไม่ได้) */
    public static function beatsHold(array $summary): ?bool
    {
        $capital = $summary['capital_return_pct'] ?? null;
        $hold = $summary['buy_hold_pct'] ?? null;

        return $capital === null || $hold === null ? null : (float) $capital > (float) $hold;
    }
}
