<?php

namespace App\Services\AiBot\Strategies;

use App\Services\AiBot\Signal;

/**
 * TPIX TRADE — อาร์บิทราจส่วนต่างราคา DEX ↔ ตลาดอ้างอิง.
 *
 * ⚠️ ยังทำงานไม่ได้จริงในตอนนี้ และตั้งใจให้เป็นแบบนั้น
 *
 * กลยุทธ์นี้ต้องอ่านราคาจากพูล DEX บน TPIX Chain เทียบกับราคาตลาดกลาง
 * แต่ DEX บนเชนยังไม่ deploy (ดู bonding curve / AMM ที่รอเชนพร้อม) จึงยังไม่มี
 * ราคาฝั่งพูลให้เทียบ
 *
 * ทางเลือกคือ "แกล้งเทรดด้วยข้อมูลสมมติ" ซึ่งจะให้ผลกำไรปลอมในโหมดทดลอง
 * แล้วผู้ใช้ตัดสินใจเช่าจากตัวเลขที่ไม่จริง — จึงเลือกคืน hold พร้อมบอกเหตุผลตรงๆ
 * แทน engine จะบันทึกเหตุผลนี้ให้ผู้ใช้เห็นทุกครั้งที่บอทตื่น
 *
 * Developed by Xman Studio.
 */
class ArbitrageStrategy implements Strategy
{
    public function code(): string
    {
        return 'arbitrage';
    }

    public function minCandles(array $params): int
    {
        return 2;
    }

    public function allowsPyramiding(): bool
    {
        return false;
    }

    public function decide(array $candles, array $params, ?array $position): Signal
    {
        if ($position) {
            return Signal::sell(1.0, 'ปิดไม้ค้าง — กลยุทธ์อาร์บิทราจยังไม่เปิดใช้งาน');
        }

        return Signal::hold(
            'รอพูล DEX บน TPIX Chain เปิดใช้งานก่อน จึงจะมีราคาสองฝั่งให้เทียบส่วนต่าง',
            ['min_edge_bps' => (float) ($params['min_edge_bps'] ?? 25), 'available' => false],
        );
    }
}
