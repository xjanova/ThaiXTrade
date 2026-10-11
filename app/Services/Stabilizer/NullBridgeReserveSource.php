<?php

namespace App\Services\Stabilizer;

/**
 * ค่าปริยายตอนที่ยังไม่มีหลักฐานทุนสำรองของสะพาน — ตอบ "ไม่มีข้อมูล" เสมอ.
 *
 * ผลคือบอทซื้อได้เฉพาะ USDT ที่เจ้าของฝากเข้ากระเป๋ากองทุนและบันทึกเป็นงบไว้
 * (fail-closed: ไม่รู้ว่ามีของจริงหนุนไหม = ไม่ใช้ USDT ที่ไม่รู้ที่มา)
 *
 * Developed by Xman Studio
 */
class NullBridgeReserveSource implements BridgeReserveSource
{
    public function status(): array
    {
        return [
            'available' => false,
            'backed' => null,
            'reserve_usdt' => null,
            'supply_usdt' => null,
            'checked_at' => null,
            'source' => 'none',
        ];
    }
}
