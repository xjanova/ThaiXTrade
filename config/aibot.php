<?php

/*
 * TPIX TRADE — AI Trade (Cloud Bot) configuration.
 *
 * แคตตาล็อกกลยุทธ์ + พารามิเตอร์ + เพดานความเสี่ยง ของระบบเช่าบอทเทรดบนคลาวด์
 * ใช้ร่วมกันระหว่างเว็บ (/ai-trade) และแอพมือถือ ผ่าน GET /api/v1/ai-bot/plans
 *
 * ทำไมอยู่ในไฟล์ config ไม่ใช่ตาราง DB:
 *  - schema ของพารามิเตอร์แต่ละกลยุทธ์ผูกกับโค้ดของ engine ที่รันบอท
 *    ถ้าให้แก้ใน DB ได้ จะตั้งค่าที่ engine ไม่รู้จักแล้วบอทตายเงียบ
 *  - ราคาเช่า/จำนวนบอทสูงสุด อยู่ในตาราง ai_bot_plans (แอดมินปรับได้)
 *
 * Developed by Xman Studio.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | เปิดโหมดเทรดจริงหรือยัง
    |--------------------------------------------------------------------------
    | false = ทั้งระบบใช้ได้เฉพาะโหมดทดลอง (paper trading ด้วยราคาจริง)
    |
    | ตั้งใจปิดไว้ก่อนตามที่เจ้าของสั่ง — ให้ผู้ใช้ทดลองจนมั่นใจก่อน แล้วค่อยเปิด
    | เปิดเมื่อไหร่ให้เปลี่ยนเป็น true ที่นี่ที่เดียว (หรือใส่ AIBOT_LIVE_ENABLED=true ใน .env)
    |
    | ⚠️ ถึงเปิดแล้ว โหมดจริงก็ยังไม่ส่งธุรกรรมเอง — ระบบไม่ถือกุญแจผู้ใช้
    |    มันจะบันทึกเป็น "สัญญาณรอยืนยัน" ให้ผู้ใช้กดเซ็นในกระเป๋าเท่านั้น
    */
    'live_enabled' => env('AIBOT_LIVE_ENABLED', false),

    /* ระยะห่างขั้นต่ำระหว่างรอบคิดของบอทที่เบราว์เซอร์เป็นคนสั่ง (วินาที) */
    'browser_tick_min_seconds' => 30,

    /*
    |--------------------------------------------------------------------------
    | เครดิตการทำงาน (work credits)
    |--------------------------------------------------------------------------
    | 1 เครดิต = 1 หน่วยการทำงานของคลาวด์ ตัดตามจำนวนวันที่เช่า × ราคาแพลน
    | ยอดคงเหลือคำนวณจาก ledger (ai_bot_credits) เสมอ ไม่เก็บ balance ซ้ำในตารางอื่น
    */
    'credits' => [
        /*
         * แพ็กเกจเติมเครดิต — ชำระด้วย TPIX เท่านั้น (เจ้าของกำหนด)
         *
         * ที่ไม่รับสกุลอื่น เพราะเครดิตบอทเป็นอุปสงค์ที่ผูกกับเหรียญของเราโดยตรง
         * ทุกการเช่าบอทคือแรงซื้อ TPIX ไม่ใช่รายได้ที่รั่วออกไปนอกระบบ
         */
        'currency' => 'TPIX',

        /*
         * เปิดให้เติมเครดิตหรือยัง
         *
         * false = ยังไม่มีรางรับเงินจริง — API ปฏิเสธพร้อมเหตุผล และหน้าเว็บขึ้น
         * ป้าย "ยังไม่เปิด" แทนปุ่มที่กดแล้วบอกว่าสำเร็จทั้งที่ไม่มีอะไรเกิดขึ้น
         *
         * ก่อนเปิดเป็น true ต้องต่อตัวรับเงินจริงให้เสร็จก่อน (ใช้ซ้ำได้จาก
         * TradingTopupService ซึ่งยืนยันการโอน TPIX บนเชน 4289 ได้แล้ว)
         */
        'topup_enabled' => env('AIBOT_TOPUP_ENABLED', false),

        'packs' => [
            ['code' => 'pack_500', 'credits' => 500, 'price_tpix' => 50, 'bonus' => 0],
            ['code' => 'pack_1500', 'credits' => 1500, 'price_tpix' => 140, 'bonus' => 100],
            ['code' => 'pack_5000', 'credits' => 5000, 'price_tpix' => 450, 'bonus' => 500],
            ['code' => 'pack_15000', 'credits' => 15000, 'price_tpix' => 1290, 'bonus' => 2500],
        ],

        // เครดิตแถมครั้งแรกให้ทดลองใช้ (ตัดผ่าน ledger ประเภท bonus ครั้งเดียวต่อ wallet)
        'welcome_bonus' => 100,

        // จำนวนวันเช่าที่เลือกได้
        'rental_days' => [1, 7, 30, 90],
    ],

    /*
    |--------------------------------------------------------------------------
    | เพดานความปลอดภัย (บังคับที่ server เสมอ — client ส่งอะไรมาก็ถูก clamp)
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_bots_hard_cap' => 25,        // กันแพลนที่แอดมินตั้งเพี้ยนจนสร้างบอทไม่จำกัด
        'max_name_length' => 60,
        'stop_loss_pct' => ['min' => 0.5, 'max' => 50, 'default' => 5],
        'take_profit_pct' => ['min' => 0.5, 'max' => 200, 'default' => 10],
        'max_position_usd' => ['min' => 10, 'max' => 1000000, 'default' => 100],
        'max_daily_loss_usd' => ['min' => 5, 'max' => 1000000, 'default' => 50],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeframe ที่ engine รองรับ
    |--------------------------------------------------------------------------
    */
    'timeframes' => ['1m', '5m', '15m', '1h', '4h', '1d'],

    /*
    |--------------------------------------------------------------------------
    | แคตตาล็อกกลยุทธ์
    |--------------------------------------------------------------------------
    | params: schema ที่ฟอร์มฝั่ง client ใช้ render และที่ server ใช้ validate
    |   type: number → ต้องมี min/max/step ; select → ต้องมี options ; bool → true/false
    | tiers: tier ของแพลนที่ปลดล็อกกลยุทธ์นี้ (free < basic < pro < vip)
    |        free = ใช้ได้แม้ไม่เสียเงิน แต่บอทจะเดินเฉพาะตอนเปิดหน้าเว็บทิ้งไว้
    */
    /*
    |--------------------------------------------------------------------------
    | เปิดขายให้คนทั่วไปหรือยัง
    |--------------------------------------------------------------------------
    | false = อยู่ระหว่างทดสอบ — ผู้ใช้ทั่วไปเช่าแพลนเสียเงินไม่ได้
    |         (โหมดทดลองยังใช้ได้ตามปกติ เพราะไม่มีใครเสียเงิน)
    |
    | ⚠️ นี่คือด่านสุดท้ายที่กันไม่ให้คนจ่ายเงินก่อนเจ้าของยืนยันว่าบอททำงานถูกต้อง
    |    อย่าเปิดจนกว่าจะทดสอบกลยุทธ์ครบและพอใจกับผลจริง
    */
    'sales_open' => env('AIBOT_SALES_OPEN', false),

    /*
    |--------------------------------------------------------------------------
    | กระเป๋าของทีมงาน — ใช้ทุกฟังก์ชันได้โดยไม่ต้องเช่าหรือเติมเครดิต
    |--------------------------------------------------------------------------
    | ใส่เป็นรายการคั่นด้วยจุลภาคใน .env:
    |   AIBOT_ADMIN_WALLETS=0xabc...,0xdef...
    |
    | ทำไมต้องเป็นรายชื่อกระเป๋า ไม่ใช่ role ในตาราง admin:
    | ตัวเดินบอท (aibot:tick) ทำงานจาก cron ไม่มี session ให้ดูว่าใครล็อกอินอยู่
    | มันเห็นแค่ที่อยู่กระเป๋าของเจ้าของบอทเท่านั้น — ถ้าผูกสิทธิ์ไว้กับ session
    | บอทของแอดมินจะเดินไม่ได้เวลาไม่มีใครเปิดเว็บ ซึ่งคือตอนที่ต้องทดสอบพอดี
    |
    | ⚠️ กระเป๋าในรายการนี้ได้สิทธิ์เต็มโดยไม่จ่ายอะไรเลย — ใส่เฉพาะของทีมงาน
    */
    'admin_wallets' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AIBOT_ADMIN_WALLETS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | รอบประมวลผลของบอทคลาวด์ ตามระดับแพลน (นาที)
    |--------------------------------------------------------------------------
    | ตัวจับเวลาเรียก `aibot:tick` ทุกนาที แล้วแต่ละบอทตัดสินเองว่าถึงรอบหรือยัง
    |
    | ⚠️ นี่คือของที่หน้าเช่าโฆษณาไว้ว่า "รอบประมวลผลถี่ขึ้น" — ถ้าจะแก้ตัวเลขนี้
    |    ต้องแก้คำโฆษณาใน AiBotPlanSeeder ให้ตรงกันด้วย ไม่งั้นกลับไปเป็นคำลอยๆ อีก
    |
    | ยิ่งถี่ยิ่งกินโควตาการยิงตลาดอ้างอิง — 1 นาทีเป็นค่าต่ำสุดที่ยังปลอดภัย
    */
    'tick_interval_minutes' => [
        'vip' => 1,
        'pro' => 3,
        'basic' => 5,
        'free' => 5,
    ],

    'strategies' => [
        [
            'code' => 'grid',
            'name' => 'Grid Trading',
            'name_th' => 'ตารางเทรด (Grid)',
            /*
             * ⚠️ ห้ามเขียนว่า "วางหลายชั้นพร้อมกัน" — เอนจินถือได้ทีละไม้เดียวเสมอ
             *    (allowsPyramiding() = false และ BotRunner แปลงสัญญาณซื้อเป็น hold
             *    ทุกครั้งที่ยังถือของอยู่) จำนวนชั้นเป็นแค่ตัวหารระยะทำกำไร
             */
            'description' => 'Buys when price dips inside its range and sells one grid step higher. Holds one position at a time.',
            'description_th' => 'ซื้อเมื่อราคาย่อลงในกรอบ แล้วขายเมื่อเด้งขึ้นครบหนึ่งชั้น ถือครั้งละหนึ่งไม้ เหมาะกับตลาด sideway',
            'risk' => 'low',
            'tier' => 'free',
            'icon' => 'grid',
            'timeframes' => ['5m', '15m', '1h'],
            'params' => [
                // ยิ่งมาก = เป้าทำกำไรต่อรอบเล็กลง (เก็บถี่ขึ้น) ไม่ใช่จำนวนไม้ที่เปิดพร้อมกัน
                ['key' => 'grid_levels', 'label' => 'ความถี่การเก็บกำไร (ชั้น)', 'label_en' => 'Profit steps', 'type' => 'number', 'default' => 10, 'min' => 3, 'max' => 60, 'step' => 1],
                ['key' => 'range_pct', 'label' => 'กรอบราคา (%)', 'label_en' => 'Price range (%)', 'type' => 'number', 'default' => 6, 'min' => 0.5, 'max' => 50, 'step' => 0.5],
                ['key' => 'order_size_usd', 'label' => 'ขนาดต่อไม้ (USD)', 'label_en' => 'Order size (USD)', 'type' => 'number', 'default' => 20, 'min' => 5, 'max' => 100000, 'step' => 5],
            ],
        ],
        [
            'code' => 'dca',
            'name' => 'Smart DCA',
            'name_th' => 'ทยอยสะสม (DCA)',
            /*
             * ⚠️ "ไม้พิเศษ" ไม่มีอยู่จริง — ด่านรอบเวลามาก่อนการเช็คราคาย่อเสมอ
             *    ราคาจะดิ่งแค่ไหนก็ไม่ซื้อนอกรอบ ที่ย่อแล้วต่างคือไม้ตามรอบใหญ่ขึ้น
             */
            'description' => 'Buys on a fixed schedule, and buys bigger when price is below its moving average.',
            'description_th' => 'ซื้อตามรอบเวลาที่ตั้งไว้ และซื้อหนักขึ้นเมื่อถึงรอบพอดีกับที่ราคาย่อต่ำกว่าเส้นค่าเฉลี่ย',
            'risk' => 'low',
            'tier' => 'free',
            'icon' => 'stack',
            'timeframes' => ['1h', '4h', '1d'],
            'params' => [
                ['key' => 'interval_hours', 'label' => 'รอบเข้าซื้อ (ชม.)', 'label_en' => 'Buy interval (h)', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 720, 'step' => 1],
                ['key' => 'budget_usd', 'label' => 'งบต่อรอบ (USD)', 'label_en' => 'Budget per buy (USD)', 'type' => 'number', 'default' => 25, 'min' => 5, 'max' => 100000, 'step' => 5],
                ['key' => 'dip_boost_pct', 'label' => 'เพิ่มไม้เมื่อย่อ (%)', 'label_en' => 'Dip boost (%)', 'type' => 'number', 'default' => 3, 'min' => 0, 'max' => 50, 'step' => 0.5],
            ],
        ],
        [
            'code' => 'momentum',
            'name' => 'Momentum Trend',
            'name_th' => 'ตามเทรนด์ (Momentum)',
            'description' => 'Rides trends using an EMA crossover confirmed by volume expansion.',
            'description_th' => 'เข้าตามเทรนด์เมื่อเส้น EMA ตัดกันและมีวอลุ่มยืนยัน ออกเมื่อโมเมนตัมหมด',
            'risk' => 'medium',
            'tier' => 'basic',
            'icon' => 'trend',
            'timeframes' => ['15m', '1h', '4h'],
            'params' => [
                ['key' => 'fast_ema', 'label' => 'EMA เร็ว', 'label_en' => 'Fast EMA', 'type' => 'number', 'default' => 12, 'min' => 2, 'max' => 100, 'step' => 1],
                ['key' => 'slow_ema', 'label' => 'EMA ช้า', 'label_en' => 'Slow EMA', 'type' => 'number', 'default' => 26, 'min' => 3, 'max' => 400, 'step' => 1],
                ['key' => 'volume_filter', 'label' => 'กรองด้วยวอลุ่ม', 'label_en' => 'Volume filter', 'type' => 'bool', 'default' => true],
            ],
        ],
        [
            'code' => 'mean_reversion',
            'name' => 'RSI Mean Reversion',
            'name_th' => 'สวนกลับค่าเฉลี่ย (RSI)',
            'description' => 'Buys statistical oversold and sells overbought around a rolling mean.',
            'description_th' => 'เข้าซื้อเมื่อ RSI ต่ำเกินไป ขายเมื่อสูงเกินไป รอบเส้นค่าเฉลี่ยเคลื่อนที่',
            'risk' => 'medium',
            'tier' => 'pro',
            'icon' => 'wave',
            'timeframes' => ['5m', '15m', '1h', '4h'],
            'params' => [
                ['key' => 'rsi_period', 'label' => 'คาบ RSI', 'label_en' => 'RSI period', 'type' => 'number', 'default' => 14, 'min' => 2, 'max' => 100, 'step' => 1],
                ['key' => 'oversold', 'label' => 'ระดับ oversold', 'label_en' => 'Oversold', 'type' => 'number', 'default' => 30, 'min' => 5, 'max' => 49, 'step' => 1],
                ['key' => 'overbought', 'label' => 'ระดับ overbought', 'label_en' => 'Overbought', 'type' => 'number', 'default' => 70, 'min' => 51, 'max' => 95, 'step' => 1],
            ],
        ],
        [
            'code' => 'breakout',
            'name' => 'Volatility Breakout',
            'name_th' => 'เบรกเอาต์ผันผวน',
            'description' => 'Enters when price closes outside a Donchian channel with an ATR-scaled stop.',
            'description_th' => 'เข้าเมื่อราคาปิดทะลุกรอบสูงสุด/ต่ำสุด พร้อมตั้ง stop ตามค่าความผันผวน (ATR)',
            'risk' => 'high',
            'tier' => 'pro',
            'icon' => 'bolt',
            'timeframes' => ['15m', '1h', '4h', '1d'],
            'params' => [
                ['key' => 'channel_period', 'label' => 'คาบกรอบราคา', 'label_en' => 'Channel period', 'type' => 'number', 'default' => 20, 'min' => 5, 'max' => 200, 'step' => 1],
                ['key' => 'atr_multiple', 'label' => 'ตัวคูณ ATR', 'label_en' => 'ATR multiple', 'type' => 'number', 'default' => 2, 'min' => 0.5, 'max' => 10, 'step' => 0.1],
                ['key' => 'direction', 'label' => 'ทิศทาง', 'label_en' => 'Direction', 'type' => 'select', 'default' => 'both', 'options' => ['long', 'short', 'both']],
            ],
        ],
        [
            'code' => 'scalping',
            'name' => 'Micro Scalper',
            'name_th' => 'สแกลป์รอบสั้น',
            'description' => 'High-frequency micro trades on order-book imbalance. Needs tight spreads.',
            /*
             * ⚠️ ห้ามเขียนว่าอ่าน "สมุดคำสั่ง" — โค้ดไม่แตะ order book เลยสักบรรทัด
             *    (ScalpingStrategy รับแค่ราคาปิด 3 แท่งกับ ATR) คอมเมนต์ในคลาสนั้น
             *    ยอมรับเรื่องนี้ไว้เองแล้ว การโฆษณาสวนทางกับคอมเมนต์ตัวเอง
             *    คือหลักฐานที่ใช้แก้ต่างไม่ได้เลยถ้ามีข้อพิพาท
             */
            'description_th' => 'เก็บกำไรรอบสั้นจากราคาที่ขึ้นต่อเนื่องสามแท่งในตลาดที่ผันผวนต่ำ ปิดไม้เมื่อถึงเป้าที่ตั้งไว้',
            'risk' => 'high',
            'tier' => 'pro',
            'icon' => 'pulse',
            'timeframes' => ['1m', '5m'],
            'params' => [
                /*
                 * ⚠️ เป้ากำไรต้องเกินต้นทุนไปกลับ (ค่าธรรมเนียม 2 ครั้ง + slippage 2 ครั้ง)
                 *    ซึ่งตอนนี้ = 36 bps ตาม config/aibot_risk.php
                 *
                 * ค่าเดิม default 15 / min 3 อยู่ใต้ต้นทุนทั้งคู่ → ทุกไม้ที่ปิดด้วย
                 * เหตุผล "ถึงเป้ากำไรของรอบสแกลป์" มีกำไรติดลบจริงๆ ป้ายกับตัวเลขขัดกัน
                 *
                 * `AiBotService::applyCrossParamRules()` ยกขึ้นให้อัตโนมัติถ้าต้นทุนเปลี่ยน
                 * ค่าที่นี่จึงเป็นแค่จุดตั้งต้นที่สมเหตุสมผล ไม่ใช่ด่านสุดท้าย
                 */
                ['key' => 'target_bps', 'label' => 'เป้ากำไร (bps)', 'label_en' => 'Target (bps)', 'type' => 'number', 'default' => 45, 'min' => 40, 'max' => 300, 'step' => 1],
                /*
                 * ⚠️ ชื่อเดิมคือ "สเปรดสูงสุด" ซึ่งไม่ตรงกับสิ่งที่โค้ดวัด
                 *    ของจริงวัด ATR ต่อราคา = ความผันผวนต่อแท่ง ไม่ใช่สเปรด bid/ask
                 *    (ระบบไม่ได้อ่านสมุดคำสั่งเลย — ดูคอมเมนต์ใน ScalpingStrategy)
                 *    และเกณฑ์เดิมถูกคูณ 10 ในโค้ด ค่าปริยายจริงจึงเท่ากับ 80
                 */
                ['key' => 'max_volatility_bps', 'label' => 'ความผันผวนสูงสุดต่อแท่ง (bps)', 'label_en' => 'Max volatility per bar (bps)', 'type' => 'number', 'default' => 80, 'min' => 10, 'max' => 2000, 'step' => 5],
                ['key' => 'cooldown_sec', 'label' => 'พักระหว่างไม้ (วิ)', 'label_en' => 'Cooldown (s)', 'type' => 'number', 'default' => 20, 'min' => 1, 'max' => 3600, 'step' => 1],
            ],
        ],
        [
            'code' => 'arbitrage',
            'name' => 'Spread Arbitrage',
            'name_th' => 'อาร์บิทราจส่วนต่างราคา',
            'description' => 'Captures the price gap between the TPIX DEX pool and the reference CEX price.',
            'description_th' => 'จับส่วนต่างราคาระหว่างพูล DEX ของ TPIX กับราคาอ้างอิงจากตลาดกลาง',
            'risk' => 'medium',
            'tier' => 'vip',
            'icon' => 'swap',
            'timeframes' => ['1m', '5m'],
            'params' => [
                ['key' => 'min_edge_bps', 'label' => 'ส่วนต่างขั้นต่ำ (bps)', 'label_en' => 'Min edge (bps)', 'type' => 'number', 'default' => 25, 'min' => 5, 'max' => 500, 'step' => 1],
                ['key' => 'max_gas_usd', 'label' => 'ค่าแก๊สสูงสุด (USD)', 'label_en' => 'Max gas (USD)', 'type' => 'number', 'default' => 1.5, 'min' => 0.1, 'max' => 100, 'step' => 0.1],
            ],
        ],
        [
            'code' => 'ai_signal',
            'name' => 'TPIX AI Signal',
            'name_th' => 'สัญญาณ AI ของ TPIX',
            /*
             * ⚠️ คำบรรยายต้องตรงกับที่ AiSignalStrategy ทำจริง
             *
             * เดิมเขียนว่ารวม "ฟันดิ้ง" กับ "การไหลของเงินบนเชน" ซึ่งไม่มีในโค้ดเลย
             * (grep ทั้งโปรเจคเจอคำว่า funding ที่บรรทัดโฆษณานี้บรรทัดเดียว) และ
             * คู่เทรดทั้งหมดเป็น spot ฟันดิ้งเรตไม่มีอยู่ในสถาปัตยกรรมนี้ตั้งแต่แรก
             */
            'description' => 'Blends four views of price action — EMA trend, momentum, RSI mean-reversion and Bollinger position — and weights them by whether the market is trending or ranging.',
            'description_th' => 'รวมสี่มุมมองจากพฤติกรรมราคา — เทรนด์ EMA · โมเมนตัม · RSI สวนค่าเฉลี่ย · ตำแหน่งในกรอบ Bollinger — แล้วถ่วงน้ำหนักตามว่าตลาดกำลังมีทิศทางหรือออกข้าง',
            'risk' => 'medium',
            'tier' => 'vip',
            'icon' => 'spark',
            'timeframes' => ['15m', '1h', '4h'],
            'params' => [
                /*
                 * ⚠️ ต่ำสุด 55 ไม่ใช่ 50
                 *
                 * ที่ 50 ประตูซื้อ (confidence >= 50) กับประตูขาย (100 − confidence >= 50)
                 * ชนกันพอดี → ช่วง "ถือ" หายไปทั้งหมด อินพุตชุดเดียวกันให้ผลว่า
                 * ไม่มีไม้ = ซื้อ · มีไม้ = ขาย สลับไปมาทุกติ๊ก วัดจริงในตลาดออกข้าง
                 * 201 ติ๊กได้ −5.6% จากค่าธรรมเนียมล้วนโดยไม่ได้เดาทิศผิดสักครั้ง
                 */
                ['key' => 'confidence_min', 'label' => 'ความมั่นใจขั้นต่ำ (%)', 'label_en' => 'Min confidence (%)', 'type' => 'number', 'default' => 65, 'min' => 55, 'max' => 95, 'step' => 1],
                ['key' => 'mode', 'label' => 'สไตล์', 'label_en' => 'Style', 'type' => 'select', 'default' => 'balanced', 'options' => ['conservative', 'balanced', 'aggressive']],
                ['key' => 'news_filter', 'label' => 'หยุดเทรดช่วงข่าวแรง', 'label_en' => 'Pause on high-impact news', 'type' => 'bool', 'default' => true],
            ],
        ],
    ],
];
