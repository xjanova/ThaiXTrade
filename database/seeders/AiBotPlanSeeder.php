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
    /**
     * แพลนของทีมงาน — ไม่ขาย ไม่โชว์ในแคตตาล็อก.
     *
     * `is_active = false` จึงไม่โผล่ใน AiBotPlan::active() ที่ catalog() ใช้ —
     * ลูกค้าจึงไม่มีทางเห็นหรือเลือกได้ แต่ ensureFreeSubscription() ยังหาเจอ
     * ด้วย code เพื่อลงให้กระเป๋าของทีมงานโดยเฉพาะ
     *
     * ทำเป็นแพลนจริงแทนการใส่ if กระจายตามจุดต่างๆ เพราะทั้งการปลดล็อกกลยุทธ์
     * โควตาบอท การเดินบนคลาวด์ และเพดานทุน ล้วนอ่านจาก subscription->plan อยู่แล้ว
     * ใส่ if แยกทีละจุดแล้วสักจุดจะลืม กลายเป็นสิทธิ์หลุดโดยไม่มีใครสังเกต
     */
    public const ADMIN_PLAN_CODE = 'admin';

    private const PLANS = [
        [
            'code' => self::ADMIN_PLAN_CODE,
            'name' => 'Team (internal)',
            'name_th' => 'ทีมงาน (ภายใน)',
            'description' => 'Internal testing plan. Never sold and never listed.',
            'description_th' => 'แพลนสำหรับทีมงานทดสอบ ไม่ขายและไม่แสดงในรายการ',
            'tier' => 'vip',
            'execution' => 'cloud',
            'credits_per_day' => 0,
            'price_tpix_per_day' => 0,
            'max_bots' => 50,
            'max_capital_usd' => null,
            'badge' => 'TEAM',
            'sort_order' => 99,
            'is_active' => false,
            'features' => ['Internal testing only'],
            'features_th' => ['ใช้ทดสอบภายในเท่านั้น'],
        ],
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
            /*
             * ⚠️ 0 ที่นี่ไม่ได้แปลว่า "ไม่จำกัด" — null ต่างหากที่แปลว่าไม่จำกัด (ดู VIP)
             *
             * เดิมเป็น 0 แล้ว sanitizeRisk() บีบทุนต่อไม้เหลือ 0 → บอทของแพลนฟรี
             * เปิดไม้ไม่ได้สักไม้ตลอดอายุการใช้งาน และค่า 0 ยังค้างอยู่ในบอทหลังผู้ใช้
             * อัปเกรดเป็นแพลนเสียเงินด้วย (แก้แล้วที่ AiBotService::subscribe)
             */
            'max_capital_usd' => 100,
            'badge' => 'FREE',
            'sort_order' => 5,
            'features' => [
                'Auto-trading with the Grid and DCA strategies',
                'Demo credits — practise at real market prices',
                'One bot at a time',
                'Up to $100 of capital per trade',
                '⚠️ Runs only while this page stays open — closing the tab stops the bot',
                '⚠️ No cloud execution — the bot stops when you leave',
            ],
            'features_th' => [
                'เทรดอัตโนมัติด้วยกลยุทธ์ Grid และ DCA',
                'ใช้เครดิตทดลอง ฝึกด้วยราคาจริงจากตลาด',
                'ใช้ได้ครั้งละ 1 บอท',
                'เพดานทุนต่อไม้ $100',
                '⚠️ ทำงานเฉพาะตอนเปิดหน้านี้ทิ้งไว้ — ปิดแท็บแล้วบอทหยุดทันที',
                '⚠️ ไม่รันบนคลาวด์ — ออกจากหน้าแล้วบอทหยุด',
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
            'description' => 'Three bots, advanced strategies, and a 3-minute execution cadence.',
            'description_th' => 'บอท 3 ตัว พร้อมกลยุทธ์ขั้นสูง และรอบประมวลผลทุก 3 นาที (แพลนอื่น 5 นาที)',
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
                'Runs every 3 minutes instead of every 5',
            ],
            'features_th' => [
                'ทุกอย่างในแพลนสตาร์ทเตอร์',
                'เพิ่ม RSI Mean Reversion / Breakout / Scalper',
                'รันพร้อมกันได้ 3 บอท คนละคู่เทรด',
                'เพดานทุนต่อไม้สูงถึง $10,000',
                'เดินทุก 3 นาที แทนที่จะเป็น 5 นาที',
            ],
        ],
        [
            'code' => 'vip',
            'name' => 'VIP Cloud',
            'name_th' => 'วีไอพี คลาวด์',
            'description' => 'Every strategy including TPIX AI Signal, a 1-minute cadence with queue priority, and no capital cap.',
            'description_th' => 'ปลดล็อกทุกกลยุทธ์รวมถึงสัญญาณ AI ของ TPIX · เดินทุก 1 นาทีและได้คิวก่อน · ไม่จำกัดเพดานทุน',
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
                'TPIX AI Signal — the full model, not a teaser',
                'Spread arbitrage — opens when the TPIX DEX pool goes live',
                'Ten bots at once, first in the execution queue, every minute',
                'No capital cap per trade',
            ],
            'features_th' => [
                'ทุกอย่างในแพลนโปรเทรดเดอร์',
                'สัญญาณ AI ของ TPIX แบบเต็มความสามารถ',
                'อาร์บิทราจส่วนต่างราคา — เปิดใช้เมื่อพูล DEX ของ TPIX พร้อม',
                'รันพร้อมกันได้ 10 บอท · ได้คิวก่อนทุกแพลน · เดินทุก 1 นาที',
                'ไม่จำกัดเพดานทุนต่อไม้',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $plan) {
            // แพลนที่ระบุ is_active มาเองต้องได้ตามนั้น (แพลนทีมงานต้องปิดเสมอ)
            AiBotPlan::updateOrCreate(
                ['code' => $plan['code']],
                $plan + ['is_active' => true],
            );
        }

        $this->command?->info('✅ AI Trade plans seeded: '.count(self::PLANS));
    }
}
