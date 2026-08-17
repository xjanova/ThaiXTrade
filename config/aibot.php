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
    | เครดิตการทำงาน (work credits)
    |--------------------------------------------------------------------------
    | 1 เครดิต = 1 หน่วยการทำงานของคลาวด์ ตัดตามจำนวนวันที่เช่า × ราคาแพลน
    | ยอดคงเหลือคำนวณจาก ledger (ai_bot_credits) เสมอ ไม่เก็บ balance ซ้ำในตารางอื่น
    */
    'credits' => [
        // แพ็กเกจเติมเครดิตที่เปิดให้เลือก (ราคาเป็น USD — ชำระผ่านช่องทางที่แอดมินยืนยัน)
        'packs' => [
            ['code' => 'pack_500', 'credits' => 500, 'price_usd' => 5, 'bonus' => 0],
            ['code' => 'pack_1500', 'credits' => 1500, 'price_usd' => 14, 'bonus' => 100],
            ['code' => 'pack_5000', 'credits' => 5000, 'price_usd' => 45, 'bonus' => 500],
            ['code' => 'pack_15000', 'credits' => 15000, 'price_usd' => 129, 'bonus' => 2500],
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
    | tiers: tier ของแพลนที่ปลดล็อกกลยุทธ์นี้ (basic < pro < vip)
    */
    'strategies' => [
        [
            'code' => 'grid',
            'name' => 'Grid Trading',
            'name_th' => 'ตารางเทรด (Grid)',
            'description' => 'Places a ladder of buy/sell orders inside a price range and profits from every oscillation.',
            'description_th' => 'วางคำสั่งซื้อ-ขายเป็นชั้นๆ ในกรอบราคา เก็บกำไรทุกครั้งที่ราคาแกว่งขึ้นลง เหมาะกับตลาด sideway',
            'risk' => 'low',
            'tier' => 'basic',
            'icon' => 'grid',
            'timeframes' => ['5m', '15m', '1h'],
            'params' => [
                ['key' => 'grid_levels', 'label' => 'จำนวนชั้น', 'label_en' => 'Grid levels', 'type' => 'number', 'default' => 10, 'min' => 3, 'max' => 60, 'step' => 1],
                ['key' => 'range_pct', 'label' => 'กรอบราคา (%)', 'label_en' => 'Price range (%)', 'type' => 'number', 'default' => 6, 'min' => 0.5, 'max' => 50, 'step' => 0.5],
                ['key' => 'order_size_usd', 'label' => 'ขนาดต่อไม้ (USD)', 'label_en' => 'Order size (USD)', 'type' => 'number', 'default' => 20, 'min' => 5, 'max' => 100000, 'step' => 5],
            ],
        ],
        [
            'code' => 'dca',
            'name' => 'Smart DCA',
            'name_th' => 'ทยอยสะสม (DCA)',
            'description' => 'Buys a fixed budget on a schedule and adds extra on dips below a moving average.',
            'description_th' => 'ซื้อด้วยงบเท่ากันตามรอบเวลา และเพิ่มไม้พิเศษเมื่อราคาหลุดต่ำกว่าเส้นค่าเฉลี่ย',
            'risk' => 'low',
            'tier' => 'basic',
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
            'description_th' => 'เก็บกำไรรอบสั้นมากจากความไม่สมดุลของสมุดคำสั่ง ต้องใช้กับคู่ที่สเปรดแคบ',
            'risk' => 'high',
            'tier' => 'pro',
            'icon' => 'pulse',
            'timeframes' => ['1m', '5m'],
            'params' => [
                ['key' => 'target_bps', 'label' => 'เป้ากำไร (bps)', 'label_en' => 'Target (bps)', 'type' => 'number', 'default' => 15, 'min' => 3, 'max' => 300, 'step' => 1],
                ['key' => 'max_spread_bps', 'label' => 'สเปรดสูงสุด (bps)', 'label_en' => 'Max spread (bps)', 'type' => 'number', 'default' => 8, 'min' => 1, 'max' => 200, 'step' => 1],
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
            'description' => 'Model-driven entries blending price action, funding, and on-chain flow.',
            'description_th' => 'ให้โมเดลของ TPIX ตัดสินใจเข้า-ออก โดยรวมพฤติกรรมราคา ฟันดิ้ง และการไหลของเงินบนเชน',
            'risk' => 'medium',
            'tier' => 'vip',
            'icon' => 'spark',
            'timeframes' => ['15m', '1h', '4h'],
            'params' => [
                ['key' => 'confidence_min', 'label' => 'ความมั่นใจขั้นต่ำ (%)', 'label_en' => 'Min confidence (%)', 'type' => 'number', 'default' => 65, 'min' => 50, 'max' => 95, 'step' => 1],
                ['key' => 'mode', 'label' => 'สไตล์', 'label_en' => 'Style', 'type' => 'select', 'default' => 'balanced', 'options' => ['conservative', 'balanced', 'aggressive']],
                ['key' => 'news_filter', 'label' => 'หยุดเทรดช่วงข่าวแรง', 'label_en' => 'Pause on high-impact news', 'type' => 'bool', 'default' => true],
            ],
        ],
    ],
];
