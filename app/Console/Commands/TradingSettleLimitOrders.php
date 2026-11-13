<?php

namespace App\Console\Commands;

use App\Services\Trading\LimitOrderService;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — ปิดบัญชีคำสั่งตั้งราคา.
 *
 *   php artisan trading:settle-limit-orders
 *
 * 1) คำสั่งที่เจ้าของยืนยันในกระเป๋าแล้ว → ดูแถว swap ที่เซิร์ฟเวอร์ยืนยันจากเชน
 *    ลง = ปิดเป็นสำเร็จ · ไม่ลง = เปิดคืนให้รอราคารอบใหม่
 * 2) คำสั่งที่เลยอายุ → หมดอายุ
 *
 * ผู้ใช้ปิดแท็บหลังกดยืนยัน = ไม่มีใครกลับมาบอกผล ตัวนี้คือสิ่งเดียวที่ทำให้
 * รายการ "คำสั่งที่เปิดอยู่" ตรงกับความจริงบนเชน
 *
 * Developed by Xman Studio.
 */
class TradingSettleLimitOrders extends Command
{
    protected $signature = 'trading:settle-limit-orders';

    protected $description = 'ตัดสินคำสั่งตั้งราคาที่รอผลบนเชน และปิดคำสั่งที่หมดอายุ';

    public function handle(LimitOrderService $orders): int
    {
        $result = $orders->sweep();

        $this->info("สำเร็จ {$result['settled']} · เปิดคืน {$result['reopened']} · หมดอายุ {$result['expired']}");

        return self::SUCCESS;
    }
}
