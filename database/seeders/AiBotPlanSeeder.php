<?php

namespace Database\Seeders;

use App\Models\AiBotPlan;
use Illuminate\Database\Seeder;

/**
 * TPIX TRADE — แพลนเช่าบอท AI TRADE (คลาวด์).
 *
 * ความต่างที่สำคัญที่สุดของแพลนคือ `execution` ไม่ใช่จำนวนบอท:
 *   browser (ฟรี) → บอทเดินเฉพาะตอนเปิดหน้าเว็บทิ้งไว้ ปิดแท็บเมื่อไหร่ก็หยุด
 *   cloud (เสียเงิน) → เซิร์ฟเวอร์เดินให้ ปิดเครื่องไปก็ยังทำงาน
 * ค่านี้ถูกใช้จริงโดย aibot:tick ในการกรองบอท ไม่ใช่แค่ข้อความโฆษณา
 *
 * ราคาเป็น TPIX ต่อวัน (เจ้าของกำหนด: ชำระด้วย TPIX เท่านั้น)
 * ผู้ใช้แลก TPIX เป็น "เครดิตการทำงาน" แล้วเครดิตถูกตัดตามจำนวนวันที่เช่า
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
            'code' => 'free',
            'name' => 'Free (browser)',
            'name_th' => 'ฟรี (รันในเบราว์เซอร์)',
            'description' => 'Auto-trading that runs in your browser tab. Close the tab and the bot stops.',
            'description_th' => 'เทรดอัตโนมัติที่เดินอยู่ในแท็บเบราว์เซอร์ของคุณ ปิดแท็บเมื่อไหร่บอทก็หยุดทำงาน',
            'tier' => 'free',
            'execution' => 'browser',
            'credits_per_day' => 0,
            'price_tpix_per_day' => 0,
            'max_bots' => 1,
            'max_capital_usd' => 0,
            'badge' => 'FREE',
            'sort_order' => 5,
            'features' => [
                'Auto-trading with the Grid and DCA strategies',
                'Demo credits — practise at real market prices',
                'One bot at a time',
                '⚠️ Runs only while this page stays open — closing the tab stops the bot',
                '⚠️ No news risk gate and no cloud execution',
            ],
            'features_th' => [
                'เทรดอัตโนมัติด้วยกลยุทธ์ Grid และ DCA',
                'ใช้เครดิตทดลอง ฝึกด้วยราคาจริงจากตลาด',
                'ใช้ได้ครั้งละ 1 บอท',
                '⚠️ ทำงานเฉพาะตอนเปิดหน้านี้ทิ้งไว้ — ปิดแท็บแล้วบอทหยุดทันที',
                '⚠️ ไม่มีด่านความเสี่ยงจากข่าว และไม่รันบนคลาวด์',
            ],
        ],
        [
            'code' => 'starter',
            'name' => 'Starter Bot',
            'name_th' => 'สตาร์ทเตอร์',
            'description' => 'One cloud bot with the core strategies. Best for testing the waters.',
            'description_th' => 'บอทคลาวด์ 1 ตัว พร้อมกลยุทธ์พื้นฐาน เหมาะกับคนเริ่มต้นลองระบบ',
            'tier' => 'basic',
            'execution' => 'cloud',
            'credits_per_day' => 30,
            'price_tpix_per_day' => 300,
            'max_bots' => 1,
            'max_capital_usd' => 500,
            'badge' => null,
            'sort_order' => 10,
            'features' => [
                '☁️ Runs on our cloud 24/7 — keeps trading with your browser closed',
                'Grid / DCA / Momentum strategies',
                'Set your own Stop Loss and Take Profit',
                'Shared between the TPIX web app and mobile app',
            ],
            'features_th' => [
                '☁️ รันบนคลาวด์ของเรา 24 ชม. — ปิดเบราว์เซอร์ ปิดเครื่อง บอทก็ยังเทรดต่อ',
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
            'execution' => 'cloud',
            'credits_per_day' => 90,
            'price_tpix_per_day' => 900,
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
            'execution' => 'cloud',
            'credits_per_day' => 240,
            'price_tpix_per_day' => 2400,
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
