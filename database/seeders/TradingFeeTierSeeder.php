<?php

namespace Database\Seeders;

use App\Models\TradingFeeTier;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — ขั้นบันไดค่าบริการวางไม้ชุดเริ่มต้น.
 *
 * เป็นแค่จุดตั้งต้นให้แอดมินมีของให้แก้ ไม่ใช่ราคาที่ตัดสินแล้ว —
 * ทุกขั้นแก้ได้ที่ /admin/settings แท็บ Trading รวมทั้งเพิ่ม/ลบขั้น
 *
 * ค่าบริการเป็นจำนวน TPIX คงที่ต่อไม้ ไม่ใช่เปอร์เซ็นต์ ตามที่เจ้าของกำหนด
 * (คิดเป็น % แล้วไม้ใหญ่จ่ายแพงจนไม่มีใครใช้)
 *
 * Developed by Xman Studio.
 */
class TradingFeeTierSeeder extends Seeder
{
    public function run(): void
    {
        // มีขั้นอยู่แล้วแปลว่าแอดมินตั้งเองไปแล้ว — ห้ามทับของเขา
        if (TradingFeeTier::count() > 0) {
            return;
        }

        $tiers = [
            ['label' => 'ไม้เล็ก', 'min_order_usd' => 0, 'max_order_usd' => 100, 'fee_tpix' => 0.5, 'sort_order' => 1],
            ['label' => 'ไม้กลาง', 'min_order_usd' => 100, 'max_order_usd' => 1000, 'fee_tpix' => 2, 'sort_order' => 2],
            ['label' => 'ไม้ใหญ่', 'min_order_usd' => 1000, 'max_order_usd' => 10000, 'fee_tpix' => 5, 'sort_order' => 3],
            ['label' => 'ไม้ใหญ่พิเศษ', 'min_order_usd' => 10000, 'max_order_usd' => null, 'fee_tpix' => 10, 'sort_order' => 4],
        ];

        foreach ($tiers as $tier) {
            TradingFeeTier::create($tier + ['is_active' => true]);
        }
    }
}
