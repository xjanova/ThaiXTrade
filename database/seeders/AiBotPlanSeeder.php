<?php

namespace Database\Seeders;

use App\Models\AiBotPlan;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — แพลนเช่าบอท AI TRADE (คลาวด์).
 *
 * ราคาเป็น "เครดิตการทำงาน" ต่อวัน — ผู้ใช้เติมเครดิตแล้วเลือกจำนวนวันที่เช่า
 * tier เป็นตัวปลดล็อกกลยุทธ์ตามแคตตาล็อกใน config/aibot.php
 *
 * Idempotent — รันซ้ำได้ (updateOrCreate ตาม code) และไม่ทับค่าที่แอดมินปรับเอง
 * เฉพาะ is_active/sort_order ที่คงไว้ตามของเดิมถ้าเคยมีแถวอยู่แล้ว
 *
 * Usage:
 *   php artisan db:seed --class=AiBotPlanSeeder
 *
 * Developed by Xman Studio.
 */
class AiBotPlanSeeder extends Seeder
{
    private const PLANS = [
        [
            'code' => 'starter',
            'name' => 'Starter Bot',
            'name_th' => 'สตาร์ทเตอร์',
            'description' => 'One cloud bot with the core strategies. Best for testing the waters.',
            'description_th' => 'บอทคลาวด์ 1 ตัว พร้อมกลยุทธ์พื้นฐาน เหมาะกับคนเริ่มต้นลองระบบ',
            'tier' => 'basic',
            'credits_per_day' => 30,
            'max_bots' => 1,
            'max_capital_usd' => 500,
            'badge' => null,
            'sort_order' => 10,
            'features' => [
                'Runs on the cloud 24/7 — nothing to leave open on your machine',
                'Grid / DCA / Momentum strategies',
                'Set your own Stop Loss and Take Profit',
                'Shared between the TPIX web app and mobile app',
            ],
            'features_th' => [
                'บอททำงานบนคลาวด์ 24 ชม. ไม่ต้องเปิดเครื่องทิ้งไว้',
                'กลยุทธ์ Grid / DCA / Momentum',
                'ตั้ง Stop Loss + Take Profit ได้',
                'ใช้ร่วมกันระหว่างเว็บและแอพ TPIX',
            ],
        ],
        [
            'code' => 'pro',
            'name' => 'Pro Trader',
            'name_th' => 'โปรเทรดเดอร์',
            'description' => 'Three bots, advanced strategies and faster execution cadence.',
            'description_th' => 'บอท 3 ตัว พร้อมกลยุทธ์ขั้นสูงและรอบประมวลผลถี่ขึ้น',
            'tier' => 'pro',
            'credits_per_day' => 90,
            'max_bots' => 3,
            'max_capital_usd' => 10000,
            'badge' => 'POPULAR',
            'sort_order' => 20,
            'features' => [
                'Everything in Starter',
                'Adds RSI Mean Reversion / Breakout / Scalper',
                'Three bots at once, each on its own pair',
                'Capital per trade up to $10,000',
            ],
            'features_th' => [
                'ทุกอย่างในแพลนสตาร์ทเตอร์',
                'เพิ่ม RSI Mean Reversion / Breakout / Scalper',
                'รันพร้อมกันได้ 3 บอท คนละคู่เทรด',
                'เพดานทุนต่อไม้สูงถึง $10,000',
            ],
        ],
        [
            'code' => 'vip',
            'name' => 'VIP Cloud',
            'name_th' => 'วีไอพี คลาวด์',
            'description' => 'Every strategy including TPIX AI Signal, priority queue, unlimited capital.',
            'description_th' => 'ปลดล็อกทุกกลยุทธ์รวมถึงสัญญาณ AI ของ TPIX คิวประมวลผลลำดับแรก ไม่จำกัดเพดานทุน',
            'tier' => 'vip',
            'credits_per_day' => 240,
            'max_bots' => 10,
            'max_capital_usd' => null,
            'badge' => 'VIP',
            'sort_order' => 30,
            'features' => [
                'Everything in Pro Trader',
                'TPIX AI Signal plus spread arbitrage',
                'Ten bots at once, first in the execution queue',
                'No capital cap per trade',
            ],
            'features_th' => [
                'ทุกอย่างในแพลนโปรเทรดเดอร์',
                'สัญญาณ AI ของ TPIX + อาร์บิทราจส่วนต่างราคา',
                'รันพร้อมกันได้ 10 บอท · คิวประมวลผลลำดับแรก',
                'ไม่จำกัดเพดานทุนต่อไม้',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $plan) {
            AiBotPlan::updateOrCreate(
                ['code' => $plan['code']],
                $plan + ['is_active' => true],
            );
        }

        $this->command?->info('✅ AI Trade plans seeded: '.count(self::PLANS));
    }
}
