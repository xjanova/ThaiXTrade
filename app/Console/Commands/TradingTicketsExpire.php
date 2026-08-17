<?php

namespace App\Console\Commands;

use App\Services\Trading\OrderTicketService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — เก็บกวาดใบอนุญาตวางไม้ที่หมดอายุแล้วคืนเงิน.
 *
 * ⚠️ ตั๋วที่ค้างสถานะ issued คือเงินที่ถูกหักไปแล้วแต่ไม่มีใครได้อะไรเลย
 *    ผู้ใช้ที่ขอตั๋วแล้วปิดแท็บทิ้งจะเสีย TPIX ฟรีถ้าไม่มีตัวนี้เดิน
 *    และเขาจะไม่รู้ตัวด้วยซ้ำ เพราะยอดหายไปเงียบๆ ทีละนิด
 *
 * Developed by Xman Studio.
 */
class TradingTicketsExpire extends Command
{
    protected $signature = 'trading:expire-tickets {--limit=200 : จำนวนสูงสุดต่อรอบ}';

    protected $description = 'ปิดใบอนุญาตวางไม้ที่หมดอายุ แล้วคืนค่าบริการให้ผู้ใช้';

    public function handle(OrderTicketService $tickets): int
    {
        $closed = $tickets->expireStale((int) $this->option('limit'));

        $this->info($closed > 0
            ? "ปิดใบอนุญาตหมดอายุ {$closed} ใบ พร้อมคืนค่าบริการแล้ว"
            : 'ไม่มีใบอนุญาตหมดอายุค้างอยู่');

        return self::SUCCESS;
    }
}
