<?php

namespace App\Console\Commands;

use App\Services\Trading\BscSwapRecorder;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ตรวจไม้ BSC ที่ยังไม่จบย้อนหลัง.
 *
 * แถวที่บันทึกตอนธุรกรรมยังไม่ลงบล็อก (pending) และค่าธรรมเนียมที่ธุรกรรมยังไม่ลงบล็อก
 * ไม่มีใครกลับมาถามให้ ถ้าผู้ใช้ปิดแท็บไปแล้ว — ตัวนี้ถามเชนซ้ำทุกนาทีจนได้ผล
 * (ไม้ไม่โผล่บนเชนเกิน 2 ชม. = หลุดไปแล้ว · ค่าธรรมเนียมไม่โผล่เกิน 2 ชม. = ไม่ได้จ่าย)
 *
 * Developed by Xman Studio.
 */
class TradingReconcileSwaps extends Command
{
    protected $signature = 'trading:reconcile-swaps {--limit=50 : จำนวนแถวสูงสุดต่อรอบ}';

    protected $description = 'ตรวจไม้ BSC ที่รอลงบล็อกและค่าธรรมเนียมที่ยังไม่ได้ผล จากใบเสร็จบนเชน';

    public function handle(BscSwapRecorder $recorder): int
    {
        $counts = $recorder->reconcile((int) $this->option('limit'));

        $this->info(sprintf(
            'ตรวจ %d แถว · ยืนยันแล้ว %d · ไม่ลงเชน %d',
            $counts['checked'],
            $counts['confirmed'],
            $counts['failed'],
        ));

        return self::SUCCESS;
    }
}
