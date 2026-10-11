<?php

namespace App\Services\Stabilizer;

/**
 * แหล่งข้อมูลหลักประกันของ USDT_TPIX (proof-of-reserves ของสะพาน).
 *
 * ระบบสะพานที่มีหลักฐานทุนสำรองกำลังสร้างแยกต่างหาก — เมื่อพร้อมให้ bind คลาสจริง
 * ทับ NullBridgeReserveSource ใน service provider แล้วเปิด STABILIZER_BRIDGE_RESERVES=true
 *
 * สัญญาของ status():
 *   available = อ่านข้อมูลได้และเชื่อถือได้ตอนนี้ (false = ห้ามใช้ตัดสินใจ)
 *   backed    = USDT_TPIX ที่หมุนเวียนอยู่มีของจริงหนุนครบ (reserve >= supply)
 *
 * Developed by Xman Studio
 */
interface BridgeReserveSource
{
    /**
     * @return array{available: bool, backed: ?bool, reserve_usdt: ?string, supply_usdt: ?string, checked_at: ?string, source: string}
     */
    public function status(): array;
}
