<?php

namespace App\Console\Commands;

use App\Services\Trading\TicketSettlementService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — เก็บกวาดใบอนุญาตวางไม้ที่หมดอายุ.
 *
 * ⚠️ ตั๋วที่ค้างสถานะ issued คือเงินที่ถูกหักไปแล้วแต่ไม่มีใครได้อะไรเลย
 *    ผู้ใช้ที่ขอตั๋วแล้วปิดแท็บทิ้งจะเสีย TPIX ฟรีถ้าไม่มีตัวนี้เดิน
 *    และเขาจะไม่รู้ตัวด้วยซ้ำ เพราะยอดหายไปเงียบๆ ทีละนิด
 *
 * ⚠️ แต่ตั๋วที่ผูกธุรกรรมของไม้แล้ว ห้ามคืนเพราะ "หมดอายุ" อย่างเดียว (ออดิท 2026-10-28 C1)
 *    เดิมคืนทุกใบที่หมดอายุ — consume ล้ม (429 / ปิดแท็บ) = ไม้ลงเชนแล้วแต่ได้เงินคืน
 *    ตอนนี้ถามผลบนเชนก่อน: ไม้ลง = ปิดตั๋ว · ไม้ไม่ลง = คืน · ยังไม่รู้ = รอรอบหน้า
 *
 * Developed by Xman Studio.
 */
class TradingTicketsExpire extends Command
{
    protected $signature = 'trading:expire-tickets {--limit=200 : จำนวนสูงสุดต่อรอบ}';

    protected $description = 'ปิดใบอนุญาตวางไม้ที่หมดอายุ — คืนค่าบริการเมื่อไม้ไม่ได้ลงเชน';

    public function handle(TicketSettlementService $settlement): int
    {
        $counts = $settlement->sweep((int) $this->option('limit'));

        $this->info(sprintf(
            'คืนค่าบริการ %d ใบ · ปิดเพราะไม้ลงเชนแล้ว %d ใบ · รอผลบนเชน %d ใบ',
            $counts['refunded'],
            $counts['consumed'],
            $counts['waiting'],
        ));

        return self::SUCCESS;
    }
}
