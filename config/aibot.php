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

    /*
    |--------------------------------------------------------------------------
    | สัญญาณชีพของวอร์กเกอร์ — บอทต้องกลับมาออนไลน์ได้เองหลังเซิร์ฟเวอร์ดับ
    |--------------------------------------------------------------------------
    | วอร์กเกอร์ (aibot:tick) เต้นทุกครั้งที่ถูก cron เรียกและหลังบอทแต่ละตัว
    | ยาม (aibot:health) ถือว่า "เงียบ" เมื่อไม่เต้นเกิน stale_minutes แล้วปลดล็อก
    | กันซ้อนที่ค้างให้ทันที ตัวเลขนี้ต้องสั้นกว่าอายุล็อก (10 นาที) จึงจะมีประโยชน์
    | แต่ยาวกว่ารอบที่ช้าที่สุดตามปกติ (ตลาดตอบช้าไม่เกิน ~1 นาที)
    */
    'health' => [
        'stale_minutes' => (int) env('AIBOT_HEALTH_STALE_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | กระเป๋าบอท — กระเป๋าแยกต่อผู้ใช้ที่บอทใช้ลงมือในโหมดจริง
    |--------------------------------------------------------------------------
    | เจ้าของสั่ง: "บอทจะล็อกกระเป๋าแยกไปต่างหาก ผู้ใช้ต้องโอนไปใส่กระเป๋าบอทก่อน
    | เปิดโหมดบอทแล้วยังเทรดเองได้ปกติ ไม่กระทบกัน — คนละกระเป๋ากับที่ผู้ใช้เทรด"
    |
    | ระบบสร้างคู่กุญแจให้ผู้ใช้หนึ่งใบ กุญแจถูกห่อสองชั้น (AES-256-GCM ด้วยคีย์ใน env
    | นี้ + Crypt ของแอป) ถอดได้เฉพาะฝั่ง CLI ตอนเซ็นธุรกรรมเท่านั้น
    | เงินถอนออกได้ทางเดียวคือกลับไปกระเป๋าของเจ้าของที่ยืนยันแล้ว — ไม่รับปลายทางอื่น
    |
    | ⚠️ ไม่มี AIBOT_BOT_WALLET_KEY = สร้างกระเป๋าไม่ได้ (fail-closed) และห้ามเปลี่ยนคีย์นี้
    |    หลังมีกระเป๋าแล้ว — เปลี่ยนแล้วกุญแจทุกใบอ่านไม่ออก เท่ากับเงินหาย
    */
    'bot_wallet' => [
        'enabled' => (bool) env('AIBOT_BOT_WALLET_ENABLED', false),
        'chain_id' => (int) env('AIBOT_BOT_WALLET_CHAIN_ID', 56),
        'encryption_key' => env('AIBOT_BOT_WALLET_KEY'),
        'rpc_user_agent' => env('AIBOT_BOT_WALLET_UA', 'TPIX-BotWallet/1.0'),
        'confirmations' => (int) env('AIBOT_BOT_WALLET_CONFIRMATIONS', 3),
        // เพดานถอนต่อวันต่อกระเป๋า (หน่วยของสินทรัพย์) — กันคำสั่งหลุดวนถอนซ้ำ
        'withdraw_daily_cap' => (float) env('AIBOT_BOT_WALLET_DAILY_CAP', 5000),
        // สินทรัพย์ที่กระเป๋าบอทรู้จักบน BSC — BNB ไว้จ่ายแก๊ส USDT คือเงินที่บอทใช้เทรด
        'assets' => [
            'BNB' => ['type' => 'native', 'decimals' => 18, 'min_withdraw' => 0.001],
            'USDT' => ['type' => 'erc20', 'address' => '0x55d398326f99059fF775485246999027B3197955', 'decimals' => 18, 'min_withdraw' => 1],
        ],
        // แก๊สขั้นต่ำที่ต้องเหลือไว้ (BNB) ไม่งั้นถอน USDT/เทรดครั้งถัดไปไม่ได้
        'gas_reserve_bnb' => (float) env('AIBOT_BOT_WALLET_GAS_RESERVE', 0.002),
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
            /*
             * แท่งสั้นถูกถอดออก 2 ก.ย. 2026 — backtest 180 วัน กริดบน 5m ได้ edge −11..+6 bps
             * PF 0.66–0.77 ทุกเหรียญ (ชั้นเล็กกว่าต้นทุน 0.36%) ส่วน 4h ได้ +53 bps PF 1.13
             * ตัวเลือกที่พิสูจน์แล้วว่าแพ้โดยโครงสร้างไม่ใช่ "ความยืดหยุ่น" แต่เป็นกับดัก
             */
            'timeframes' => ['1h', '4h'],
            'params' => [
                // ยิ่งมาก = เป้าทำกำไรต่อรอบเล็กลง (เก็บถี่ขึ้น) ไม่ใช่จำนวนไม้ที่เปิดพร้อมกัน
                /*
                 * ค่าปริยาย 8 ชั้นในกรอบ 10% (ชั้นละ 1.25%) บนแท่ง 4 ชม. — จูนจาก backtest
                 * 180 วัน (2 ก.ย. 2026): ชุดเดิม 10 ชั้น/6% (ชั้นละ 0.6% ≈ ต้นทุน 0.36%
                 * แค่ 1.7 เท่า) edge 17-28 bps แพ้ต้นทุนทุกเหรียญ · ชุดนี้ +53 bps PF 1.13
                 * ทั้ง BTC และ ETH — ชั้นต้องกว้างกว่าต้นทุนหลายเท่าถึงจะเหลือกำไร
                 */
                ['key' => 'grid_levels', 'label' => 'ความถี่การเก็บกำไร (ชั้น)', 'label_en' => 'Profit steps', 'type' => 'number', 'default' => 8, 'min' => 3, 'max' => 60, 'step' => 1],
                ['key' => 'range_pct', 'label' => 'กรอบราคา (%)', 'label_en' => 'Price range (%)', 'type' => 'number', 'default' => 10, 'min' => 0.5, 'max' => 50, 'step' => 0.5],
                ['key' => 'order_size_usd', 'label' => 'ขนาดต่อไม้ (USD)', 'label_en' => 'Order size (USD)', 'type' => 'number', 'default' => 20, 'min' => 5, 'max' => 100000, 'step' => 5],
                /*
                 * กริดชนะเฉพาะตลาดออกข้าง — backtest 180 วัน (2 ก.ย. 2026): ค่าที่ชนะช่วง
                 * ทดสอบ (+107 bps) แพ้ช่วงจูน (+20 bps) เพราะช่วงจูนเป็นขาลง กริดที่ซื้อ
                 * ต่อเนื่องในขาลงคือการรับมีดตกทีละชั้น ตัวกรองนี้ให้เข้าเฉพาะตอน
                 * efficiency ratio ต่ำกว่า er_max (ดู MarketRegime)
                 */
                ['key' => 'regime_filter', 'label' => 'เข้าเฉพาะตลาดออกข้าง', 'label_en' => 'Only trade ranging markets', 'type' => 'bool', 'default' => true, 'group' => 'advanced'],
                ['key' => 'er_max', 'label' => 'เกณฑ์ "ออกข้าง" (efficiency ratio)', 'label_en' => 'Ranging threshold (efficiency ratio)', 'type' => 'number', 'default' => 0.35, 'min' => 0.1, 'max' => 0.9, 'step' => 0.05, 'group' => 'advanced'],
            ],
            'default_timeframe' => '4h',
            'templates' => [
                ['code' => 'conservative', 'name' => 'Wide & slow', 'name_th' => 'กรอบกว้าง เก็บช้า', 'tagline_th' => 'กรอบ 10% แบ่ง 5 ชั้นบนแท่ง 4 ชม. — เก็บกำไรชั้นละ 2% นานๆ ครั้ง เหมาะกับคนไม่อยากดูจอ (ETH 180 วัน edge +85 bps)', 'tagline_en' => '10% range in 5 steps on 4h — takes 2% per step, rarely. For people who do not watch the screen (ETH 180d: +85 bps edge).', 'timeframe' => '4h', 'params' => ['grid_levels' => 5, 'range_pct' => 10, 'order_size_usd' => 20, 'regime_filter' => true, 'er_max' => 0.3], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 8, 'take_profit_pct' => 20, 'max_daily_loss_usd' => 50]],
                ['code' => 'balanced', 'name' => 'Balanced', 'name_th' => 'สมดุล', 'tagline_th' => 'กรอบ 10% แบ่ง 8 ชั้นบนแท่ง 4 ชม. เข้าเฉพาะตลาดออกข้าง — ค่าปริยายที่จูนจาก backtest 180 วัน (BTC/ETH edge +53 bps PF 1.13)', 'tagline_en' => '10% range in 8 steps on 4h, ranging markets only — the backtested defaults (BTC/ETH +53 bps edge, PF 1.13).', 'timeframe' => '4h', 'params' => ['grid_levels' => 8, 'range_pct' => 10, 'order_size_usd' => 20, 'regime_filter' => true, 'er_max' => 0.35], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 6, 'take_profit_pct' => 15, 'max_daily_loss_usd' => 50]],
                ['code' => 'aggressive', 'name' => 'Faster grid', 'name_th' => 'เก็บถี่ขึ้น', 'tagline_th' => 'กรอบ 10% แบ่ง 8 ชั้นบนแท่ง 1 ชม. — ไม้ถี่ขึ้น แต่ backtest บอกว่าแท่งสั้นให้ edge ต่ำกว่า 4 ชม.', 'tagline_en' => '10% range in 8 steps on 1h — more trades, but backtests show shorter bars earn less edge than 4h.', 'timeframe' => '1h', 'params' => ['grid_levels' => 8, 'range_pct' => 10, 'order_size_usd' => 20, 'regime_filter' => true, 'er_max' => 0.4], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 5, 'take_profit_pct' => 10, 'max_daily_loss_usd' => 50]],
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
                /*
                 * ปิดไว้โดยปริยาย — หัวใจของ DCA คือซื้อสม่ำเสมอ "รวมถึงตอนลง"
                 * เปิดเมื่อผู้ใช้อยากหยุดสะสมช่วงที่ราคาอยู่ใต้เส้นเทรนด์ยาว (EMA 200)
                 * แล้วค่อยกลับมาซื้อเมื่อขึ้นเหนือเส้น — ลด drawdown แลกกับต้นทุนเฉลี่ยที่สูงขึ้น
                 */
                ['key' => 'pause_in_downtrend', 'label' => 'พักสะสมช่วงขาลงใหญ่', 'label_en' => 'Pause accumulating in a major downtrend', 'type' => 'bool', 'default' => false, 'group' => 'advanced'],
            ],
            'default_timeframe' => '1h',
            'templates' => [
                ['code' => 'conservative', 'name' => 'Weekly', 'name_th' => 'รายสัปดาห์', 'tagline_th' => 'ซื้อทุก 7 วัน งบ 25 เพิ่มไม้เมื่อย่อ 5% — สะสมช้าๆ ไม่ดูจอ', 'tagline_en' => 'Buys every 7 days, $25 each, bigger on a 5% dip — slow accumulation, no screen time.', 'timeframe' => '1d', 'params' => ['interval_hours' => 168, 'budget_usd' => 25, 'dip_boost_pct' => 5, 'pause_in_downtrend' => false], 'risk' => ['max_position_usd' => 200, 'stop_loss_pct' => 15, 'take_profit_pct' => 40, 'max_daily_loss_usd' => 50]],
                ['code' => 'balanced', 'name' => 'Daily', 'name_th' => 'รายวัน', 'tagline_th' => 'ซื้อทุก 24 ชม. งบ 25 เพิ่มไม้เมื่อย่อ 3% — backtest 180 วัน ต่อทุน +14.5% เทียบถือเฉยๆ +9.9%', 'tagline_en' => 'Buys every 24h, $25 each, bigger on a 3% dip — 180-day backtest: +14.5% on capital vs +9.9% buy-and-hold.', 'timeframe' => '1h', 'params' => ['interval_hours' => 24, 'budget_usd' => 25, 'dip_boost_pct' => 3, 'pause_in_downtrend' => false], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 5, 'take_profit_pct' => 10, 'max_daily_loss_usd' => 50]],
                ['code' => 'aggressive', 'name' => 'Twice daily', 'name_th' => 'วันละสองรอบ', 'tagline_th' => 'ซื้อทุก 12 ชม. งบ 50 เพิ่มไม้เมื่อย่อ 2% — ถึงเพดานทุนเร็ว ต้องตั้งเพดานให้พอ', 'tagline_en' => 'Buys every 12h, $50 each, bigger on a 2% dip — hits the position cap fast; size the cap accordingly.', 'timeframe' => '4h', 'params' => ['interval_hours' => 12, 'budget_usd' => 50, 'dip_boost_pct' => 2, 'pause_in_downtrend' => true], 'risk' => ['max_position_usd' => 300, 'stop_loss_pct' => 8, 'take_profit_pct' => 15, 'max_daily_loss_usd' => 100]],
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
            // 15m ถอดออก 2 ก.ย. 2026 — backtest 180 วัน PF 0.25–0.58 ทุกเหรียญ (ต้นทุนกินหมด)
            'timeframes' => ['1h', '4h'],
            'params' => [
                ['key' => 'fast_ema', 'label' => 'EMA เร็ว', 'label_en' => 'Fast EMA', 'type' => 'number', 'default' => 12, 'min' => 2, 'max' => 100, 'step' => 1],
                ['key' => 'slow_ema', 'label' => 'EMA ช้า', 'label_en' => 'Slow EMA', 'type' => 'number', 'default' => 26, 'min' => 3, 'max' => 400, 'step' => 1],
                ['key' => 'volume_filter', 'label' => 'กรองด้วยวอลุ่ม', 'label_en' => 'Volume filter', 'type' => 'bool', 'default' => true],
                // วอลุ่มแท่งที่ตัดขึ้นต้องไม่น้อยกว่ากี่เท่าของค่าเฉลี่ย 20 แท่ง — AI ผ่อนลงได้ถึง 0.5 (withAiRelief)
                ['key' => 'volume_ratio', 'label' => 'วอลุ่มขั้นต่ำ (เท่าของค่าเฉลี่ย)', 'label_en' => 'Minimum volume (× 20-bar average)', 'type' => 'number', 'default' => 1.0, 'min' => 0.5, 'max' => 3, 'step' => 0.05, 'group' => 'advanced'],
                /*
                 * backtest 180 วัน (2 ก.ย. 2026): momentum 1h บน BTC edge 36 bps = เท่าทุนพอดี
                 * ชนะ 36% ถือเฉลี่ย 33 แท่ง — ไม้ที่ "ตัดขึ้นแล้วไปไม่ถึงไหน" คือส่วนที่กินกำไร
                 *   htf_confirm   : ไม่ซื้อตอนราคายังอยู่ใต้เส้นเทรนด์ยาว (EMA 200) — การตัดขึ้น
                 *                   ในขาลงใหญ่คือเด้งสั้นๆ ไม่ใช่เทรนด์ใหม่
                 *   max_hold_bars : ถือครบแล้วยังไม่กำไร = ปิดคืนทุน (time stop) 0 = ปิดใช้
                 *   min_atr_pct   : ความผันผวนต่อแท่งต้องพอคุ้มต้นทุนเข้า-ออก 0.36%
                 */
                ['key' => 'htf_confirm', 'label' => 'ซื้อเฉพาะเหนือเส้นเทรนด์ใหญ่ (EMA 200)', 'label_en' => 'Only buy above the long-term trend (EMA 200)', 'type' => 'bool', 'default' => true, 'group' => 'advanced'],
                ['key' => 'max_hold_bars', 'label' => 'ถือสูงสุด (แท่ง) ถ้ายังไม่กำไร — 0 = ไม่จำกัด', 'label_en' => 'Max hold (bars) while not in profit — 0 = unlimited', 'type' => 'number', 'default' => 48, 'min' => 0, 'max' => 500, 'step' => 1, 'group' => 'advanced'],
                ['key' => 'min_atr_pct', 'label' => 'ความผันผวนขั้นต่ำต่อแท่ง (ATR %)', 'label_en' => 'Minimum volatility per bar (ATR %)', 'type' => 'number', 'default' => 0.15, 'min' => 0, 'max' => 5, 'step' => 0.05, 'group' => 'advanced'],
            ],
            'default_timeframe' => '1h',
            'templates' => [
                ['code' => 'conservative', 'name' => 'Slow trend', 'name_th' => 'ตามเทรนด์ช้า', 'tagline_th' => 'EMA 20/50 บนแท่ง 4 ชม. ยืนยันด้วยเทรนด์ใหญ่ — เข้าไม่กี่ไม้ต่อเดือน แต่แต่ละไม้ใหญ่', 'tagline_en' => 'EMA 20/50 on 4h with long-trend confirmation — a few trades a month, each one meaningful.', 'timeframe' => '4h', 'params' => ['fast_ema' => 20, 'slow_ema' => 50, 'volume_filter' => true, 'htf_confirm' => true, 'max_hold_bars' => 40, 'min_atr_pct' => 0.3], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 4, 'take_profit_pct' => 12, 'max_daily_loss_usd' => 50]],
                ['code' => 'balanced', 'name' => 'Balanced', 'name_th' => 'สมดุล', 'tagline_th' => 'EMA 12/26 บนแท่ง 1 ชม. + เทรนด์ใหญ่ + time stop 48 แท่ง — ค่าปริยายที่ backtest แล้ว', 'tagline_en' => 'EMA 12/26 on 1h + long-trend filter + 48-bar time stop — the backtested defaults.', 'timeframe' => '1h', 'params' => ['fast_ema' => 12, 'slow_ema' => 26, 'volume_filter' => true, 'htf_confirm' => true, 'max_hold_bars' => 48, 'min_atr_pct' => 0.15], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 5, 'take_profit_pct' => 10, 'max_daily_loss_usd' => 50]],
                ['code' => 'aggressive', 'name' => 'Fast cross', 'name_th' => 'ตัดเร็ว', 'tagline_th' => 'EMA 8/21 ไม่กรองเทรนด์ใหญ่ ไม่กรองวอลุ่ม — ไม้เยอะ สัญญาณหลอกเยอะ เหมาะกับคนที่ยอมรับ drawdown', 'tagline_en' => 'EMA 8/21 with no trend or volume filter — many trades, many fakeouts; for people who accept drawdown.', 'timeframe' => '1h', 'params' => ['fast_ema' => 8, 'slow_ema' => 21, 'volume_filter' => false, 'htf_confirm' => false, 'max_hold_bars' => 24, 'min_atr_pct' => 0.1], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 6, 'take_profit_pct' => 15, 'max_daily_loss_usd' => 80]],
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
            // 5m/15m ถอดออก 2 ก.ย. 2026 — backtest 180 วัน 5m ได้ 190+ ไม้ PF 0.53–0.77 (ต้นทุน 35 บน gross 6)
            'timeframes' => ['1h', '4h'],
            'params' => [
                ['key' => 'rsi_period', 'label' => 'คาบ RSI', 'label_en' => 'RSI period', 'type' => 'number', 'default' => 14, 'min' => 2, 'max' => 100, 'step' => 1],
                ['key' => 'oversold', 'label' => 'ระดับ oversold', 'label_en' => 'Oversold', 'type' => 'number', 'default' => 30, 'min' => 5, 'max' => 49, 'step' => 1],
                ['key' => 'overbought', 'label' => 'ระดับ overbought', 'label_en' => 'Overbought', 'type' => 'number', 'default' => 70, 'min' => 51, 'max' => 95, 'step' => 1],
                /*
                 * backtest 180 วัน (2 ก.ย. 2026): สวนค่าเฉลี่ยบน SOL ที่เป็นขาลง edge −66 bps
                 * บน ETH ที่ออกข้าง +70 bps — RSI ต่ำในขาลงใหญ่คือ "มีดตก" ไม่ใช่ของถูก
                 * ตัวกรองนี้ห้ามซื้อตอนราคาอยู่ใต้เส้นเทรนด์ยาว (EMA 200) ส่วนการย่อ
                 * ในขาขึ้นยังซื้อได้ตามปกติ (นั่นคือฉากที่กลยุทธ์นี้ทำเงินจริง)
                 */
                ['key' => 'regime_filter', 'label' => 'ไม่ซื้อสวนขาลงใหญ่ (ใต้ EMA 200)', 'label_en' => 'Do not buy below the long-term trend (EMA 200)', 'type' => 'bool', 'default' => true, 'group' => 'advanced'],
                // การย่อที่ RSI ต่ำมักลงไปใต้เส้นนิดหน่อยเสมอ — ห้ามเฉพาะที่ "ลึก" กว่าค่านี้ (ดู MeanReversionStrategy)
                ['key' => 'max_below_ema_pct', 'label' => 'ยอมซื้อใต้ EMA 200 ได้ไม่เกิน (%)', 'label_en' => 'Max distance below EMA 200 to still buy (%)', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 50, 'step' => 0.5, 'group' => 'advanced'],
            ],
            'default_timeframe' => '1h',
            'templates' => [
                /*
                 * ⚠️ ไม่มีเทมเพลตบนแท่ง 4 ชม. — backtest 180 วัน RSI(14) < 30 บน 4h ไม่เกิดเลย
                 *    (0 ไม้ทั้ง BTC และ ETH) เทมเพลตที่ไม่เคยเข้าไม้คือของหลอก
                 */
                ['code' => 'conservative', 'name' => 'Deep dips only', 'name_th' => 'เฉพาะย่อลึก', 'tagline_th' => 'RSI 25/75 บนแท่ง 1 ชม. ยอมใต้ EMA 200 แค่ 3% — รอของถูกจริงในขาขึ้น (ETH 180 วัน edge +191 bps ที่ระยะ 3%)', 'tagline_en' => 'RSI 25/75 on 1h, at most 3% below EMA 200 — waits for real dips inside an uptrend (ETH 180d: +191 bps at the 3% tolerance).', 'timeframe' => '1h', 'params' => ['rsi_period' => 14, 'oversold' => 25, 'overbought' => 75, 'regime_filter' => true, 'max_below_ema_pct' => 3], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 6, 'take_profit_pct' => 12, 'max_daily_loss_usd' => 50]],
                ['code' => 'balanced', 'name' => 'Balanced', 'name_th' => 'สมดุล', 'tagline_th' => 'RSI 30/70 บนแท่ง 1 ชม. ยอมใต้ EMA 200 ไม่เกิน 5% — ค่าปริยายที่จูนแล้ว (BTC edge 43 → 87 bps, SOL −66 → −12)', 'tagline_en' => 'RSI 30/70 on 1h, at most 5% below EMA 200 — the tuned defaults (BTC edge 43 → 87 bps, SOL −66 → −12).', 'timeframe' => '1h', 'params' => ['rsi_period' => 14, 'oversold' => 30, 'overbought' => 70, 'regime_filter' => true, 'max_below_ema_pct' => 5], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 5, 'take_profit_pct' => 10, 'max_daily_loss_usd' => 50]],
                ['code' => 'aggressive', 'name' => 'Shallow dips', 'name_th' => 'ย่อตื้นก็เข้า', 'tagline_th' => 'RSI 35/65 บนแท่ง 1 ชม. ไม่กรองเทรนด์ — ไม้ถี่ขึ้นแต่รับมีดตกได้ในขาลง', 'tagline_en' => 'RSI 35/65 on 1h, no trend filter — more trades, but catches falling knives in a downtrend.', 'timeframe' => '1h', 'params' => ['rsi_period' => 14, 'oversold' => 35, 'overbought' => 65, 'regime_filter' => false], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 4, 'take_profit_pct' => 8, 'max_daily_loss_usd' => 60]],
            ],
        ],
        [
            'code' => 'breakout',
            'name' => 'Volatility Breakout',
            'name_th' => 'เบรกเอาต์ผันผวน',
            // เข้าเฉพาะตอนทะลุ "ขอบบน" — ขอบล่างใช้เป็นสัญญาณปิดไม้ ไม่ใช่เปิดไม้ฝั่งขาย
            'description' => 'Enters when price closes above the Donchian channel, with an ATR-scaled stop.',
            'description_th' => 'เข้าเมื่อราคาปิดทะลุกรอบด้านบน พร้อมตั้ง stop ตามค่าความผันผวน (ATR) และปิดไม้เมื่อหลุดกรอบล่าง',
            'risk' => 'high',
            'tier' => 'pro',
            'icon' => 'bolt',
            // 15m ถอดออก 2 ก.ย. 2026 — backtest 180 วัน 170–194 ไม้ PF 0.39–0.65 ต่อทุน −41..−73%
            'timeframes' => ['1h', '4h', '1d'],
            'params' => [
                ['key' => 'channel_period', 'label' => 'คาบกรอบราคา', 'label_en' => 'Channel period', 'type' => 'number', 'default' => 20, 'min' => 5, 'max' => 200, 'step' => 1],
                ['key' => 'atr_multiple', 'label' => 'ตัวคูณ ATR', 'label_en' => 'ATR multiple', 'type' => 'number', 'default' => 2, 'min' => 0.5, 'max' => 10, 'step' => 0.1],
                /*
                 * ⚠️ เหลือ long อย่างเดียว — short/both เป็นตัวเลือกหลอก
                 *
                 * BreakoutStrategy ใช้ค่านี้ที่เดียวคือปฏิเสธ short ส่วน long กับ both
                 * เดินเส้นทางเดียวกันเป๊ะ · ทั้งเอนจินเป็น spot ฝั่งซื้ออย่างเดียว
                 * โดยออกแบบ (ไม่มีตลาดยืมเหรียญ) ลูกค้าที่เลือก short จึงได้บอทที่
                 * ไม่มีวันเข้าไม้เลย โดยไม่มีอะไรบอกว่าทำไม
                 *
                 * บอทเก่าที่บันทึก 'both'/'short' ไว้จะถูก sanitizeParams ยกมาเป็น
                 * 'long' ให้เองตอนรัน (select ที่ค่าไม่อยู่ใน options → ใช้ default)
                 */
                ['key' => 'direction', 'label' => 'ทิศทาง', 'label_en' => 'Direction', 'type' => 'select', 'default' => 'long', 'options' => ['long']],
                /*
                 * backtest 180 วัน (2 ก.ย. 2026): breakout บนแท่ง 4 ชม. edge +315 bps PF 3.5
                 * แต่บนแท่ง 1 ชม. PF 0.64 — ทะลุกรอบบนแท่งสั้นส่วนใหญ่เป็นสัญญาณหลอก
                 *   htf_confirm      : ทะลุขึ้นแต่ยังอยู่ใต้ EMA 200 = เด้งในขาลง ไม่เอา
                 *   min_breakout_atr : ทะลุนิดเดียว (เทียบ ATR) หลอกบ่อย ต้องทะลุให้ชัด
                 *   max_hold_bars    : ทะลุแล้วไม่ไปต่อ = ปิดคืนทุน (time stop)
                 */
                ['key' => 'htf_confirm', 'label' => 'ซื้อเฉพาะเหนือเส้นเทรนด์ใหญ่ (EMA 200)', 'label_en' => 'Only buy above the long-term trend (EMA 200)', 'type' => 'bool', 'default' => true, 'group' => 'advanced'],
                ['key' => 'min_breakout_atr', 'label' => 'ต้องทะลุอย่างน้อย (เท่าของ ATR)', 'label_en' => 'Minimum breakout size (× ATR)', 'type' => 'number', 'default' => 0.1, 'min' => 0, 'max' => 3, 'step' => 0.05, 'group' => 'advanced'],
                // 120 ไม่ใช่ 60: backtest 180 วันบน BTC ตัด time stop 60 แท่ง (10 วัน) ไปโดนไม้ที่ทำกำไรใหญ่ที่สุด
                // (edge 197 → 360 bps เมื่อยืดออก) เบรกเอาต์ของจริงใช้เวลา — ด่านนี้มีไว้กันไม้ที่ค้างเป็นเดือน
                ['key' => 'max_hold_bars', 'label' => 'ถือสูงสุด (แท่ง) ถ้ายังไม่กำไร — 0 = ไม่จำกัด', 'label_en' => 'Max hold (bars) while not in profit — 0 = unlimited', 'type' => 'number', 'default' => 120, 'min' => 0, 'max' => 500, 'step' => 1, 'group' => 'advanced'],
            ],
            'default_timeframe' => '4h',
            'templates' => [
                ['code' => 'conservative', 'name' => 'Big breakouts', 'name_th' => 'ทะลุกรอบใหญ่', 'tagline_th' => 'กรอบ 55 แท่งบนแท่งวัน stop 3 ATR — เข้าปีละไม่กี่ครั้ง ตอนตลาดเปลี่ยนโครงสร้างจริง', 'tagline_en' => '55-bar channel on daily candles, 3-ATR stop — a handful of trades a year, only on real structural breaks.', 'timeframe' => '1d', 'params' => ['channel_period' => 55, 'atr_multiple' => 3, 'direction' => 'long', 'htf_confirm' => true, 'min_breakout_atr' => 0.2, 'max_hold_bars' => 40], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 8, 'take_profit_pct' => 30, 'max_daily_loss_usd' => 50]],
                ['code' => 'balanced', 'name' => 'Balanced', 'name_th' => 'สมดุล', 'tagline_th' => 'กรอบ 20 แท่งบนแท่ง 4 ชม. stop 2 ATR — ชุดที่ชนะ walk-forward 180 วัน (edge +315 bps, ต่อทุน +20.6% เทียบถือ +10.5%)', 'tagline_en' => '20-bar channel on 4h, 2-ATR stop — won the 180-day walk-forward (+315 bps edge, +20.6% on capital vs +10.5% buy-and-hold).', 'timeframe' => '4h', 'params' => ['channel_period' => 20, 'atr_multiple' => 2, 'direction' => 'long', 'htf_confirm' => true, 'min_breakout_atr' => 0.1, 'max_hold_bars' => 120], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 6, 'take_profit_pct' => 20, 'max_daily_loss_usd' => 50]],
                ['code' => 'aggressive', 'name' => 'Quick breaks', 'name_th' => 'ทะลุเร็ว', 'tagline_th' => 'กรอบ 10 แท่งบนแท่ง 4 ชม. stop 1.5 ATR — ไม้ถี่ขึ้น สัญญาณหลอกมากขึ้น', 'tagline_en' => '10-bar channel on 4h, 1.5-ATR stop — more trades, more fakeouts.', 'timeframe' => '4h', 'params' => ['channel_period' => 10, 'atr_multiple' => 1.5, 'direction' => 'long', 'htf_confirm' => false, 'min_breakout_atr' => 0.05, 'max_hold_bars' => 30], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 5, 'take_profit_pct' => 15, 'max_daily_loss_usd' => 80]],
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
            /*
             * ⛔ ถอดออกจากการขาย 2 ก.ย. 2026 — แพ้ทางคณิตศาสตร์ ไม่ใช่แค่โชคร้าย
             *
             * วัดจริงบน prod 13 วัน 608 ไม้: ปิดด้วยกฎ "ย้อน 2 แท่ง" 92% (ชนะ 8 ใน 279)
             * ถึงเป้าแค่ 23 ไม้ · ต้องชนะ 47% ที่ payoff 1.1 ถึงเท่าทุน ได้จริง 10%
             * แท่ง 5 นาทีของ BTC ขยับเฉลี่ย 8.4 bps ขณะที่ต้นทุนไป-กลับ 36 bps —
             * เป้ากำไรที่ถูกบังคับให้อยู่เหนือต้นทุน (≥ 43 bps) เกิดขึ้นแค่ 1.6% ของเวลา
             * ส่วนกฎออกเกิดทุก ~20 นาที ไม่มีพารามิเตอร์ไหนปิดช่องว่างนี้ได้
             *
             * เก็บคลาสและรายการนี้ไว้เพื่อให้บอทเก่าอ่านประวัติได้ และเพื่อเปิดกลับ
             * เมื่อมีต้นทุน < ~5 bps หรือกลยุทธ์ถูกออกแบบใหม่และผ่าน backtest แล้ว
             * StrategyAvailability อ่านธงนี้ → สร้าง/เริ่มบอทใหม่ไม่ได้ · BotRunner
             * พักบอทที่ยังเดินอยู่พร้อมบอกเหตุผล
             */
            'retired' => true,
            'retired_reason' => 'ถอดออกจากการขายแล้ว — วัดจริง 608 ไม้ ต้นทุนเข้า-ออก 0.36% สูงกว่าการเคลื่อนไหวของแท่ง 5 นาที (เฉลี่ย 0.08%) จึงขาดทุนโดยโครงสร้าง',
            'retired_reason_en' => 'Retired — measured over 608 live trades: the 0.36% round-trip cost exceeds a typical 5-minute move (0.08%), so it loses by construction.',
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
            'default_timeframe' => '1h',
            'templates' => [
                ['code' => 'conservative', 'name' => 'High conviction', 'name_th' => 'มั่นใจสูงเท่านั้น', 'tagline_th' => 'ความมั่นใจ ≥ 75% บนแท่ง 4 ชม. สไตล์ระมัดระวัง — เข้าน้อยครั้ง ไม้เล็ก', 'tagline_en' => 'Confidence ≥ 75% on 4h, conservative sizing — few entries, small size.', 'timeframe' => '4h', 'params' => ['confidence_min' => 75, 'mode' => 'conservative', 'news_filter' => true], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 5, 'take_profit_pct' => 12, 'max_daily_loss_usd' => 50]],
                ['code' => 'balanced', 'name' => 'Balanced', 'name_th' => 'สมดุล', 'tagline_th' => 'ความมั่นใจ ≥ 65% บนแท่ง 1 ชม. — ค่าปริยาย', 'tagline_en' => 'Confidence ≥ 65% on 1h — the defaults.', 'timeframe' => '1h', 'params' => ['confidence_min' => 65, 'mode' => 'balanced', 'news_filter' => true], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 5, 'take_profit_pct' => 10, 'max_daily_loss_usd' => 50]],
                ['code' => 'aggressive', 'name' => 'Active', 'name_th' => 'ลุย', 'tagline_th' => 'ความมั่นใจ ≥ 58% บนแท่ง 1 ชม. สไตล์ดุดัน — ไม้ใหญ่ขึ้น ผิดบ่อยขึ้น', 'tagline_en' => 'Confidence ≥ 58% on 1h, aggressive sizing — bigger size, more mistakes.', 'timeframe' => '1h', 'params' => ['confidence_min' => 58, 'mode' => 'aggressive', 'news_filter' => true], 'risk' => ['max_position_usd' => 100, 'stop_loss_pct' => 6, 'take_profit_pct' => 15, 'max_daily_loss_usd' => 80]],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | สวิตช์ที่ทุกกลยุทธ์มีเหมือนกัน
    |--------------------------------------------------------------------------
    | `sanitizeParams()` สร้างค่าที่บันทึกจากรายการ params ของกลยุทธ์นั้นๆ เท่านั้น
    | คีย์ที่ไม่ได้ประกาศไว้จะถูก **ตัดทิ้งเงียบๆ** — ค่าที่ผู้ใช้ตั้งไม่เคยลงฐานข้อมูล
    | และไม่มีอะไรฟ้องเลยสักอย่าง
    |
    | สวิตช์สองตัวนี้ไม่ได้เป็นของกลยุทธ์ใดกลยุทธ์หนึ่ง — BotRunner อ่านมันกับบอท
    | ทุกตัว จึงต้องมีที่อยู่ของตัวเอง ไม่ใช่ไปแปะไว้ในรายการของกลยุทธ์ใดกลยุทธ์หนึ่ง
    |
    | ⚠️ `news_filter` เคยประกาศไว้ที่ ai_signal ตัวเดียว ขณะที่ BotRunner อ่านมัน
    |    กับทุกกลยุทธ์ — บอทกลยุทธ์อื่นจึงตกไปใช้ค่าปริยาย `true` เสมอ ปิดไม่ได้เลย
    |    แม้จะส่งค่ามา ย้ายมาตรงนี้แล้วทุกกลยุทธ์ตั้งได้จริงตามที่ป้ายบอก
    */
    'common_params' => [
        ['key' => 'news_filter', 'label' => 'หยุดเทรดช่วงข่าวแรง', 'label_en' => 'Pause on high-impact news', 'type' => 'bool', 'default' => true],
        ['key' => 'auto_pair', 'label' => 'ให้ AI เลือกเหรียญให้', 'label_en' => 'Let AI pick the coin', 'type' => 'bool', 'default' => false],
        /*
         * ให้มุมมองตลาดของ AI มีผลกับบอทตัวนี้ไหม
         *
         * มีไว้เพื่อ "กลุ่มควบคุม" — การทดลอง 21 ส.ค. – 2 ก.ย. 2026 เปิด AI ให้บอท
         * ทุกตัวพร้อมกัน จึงแยกไม่ได้เลยว่าผลที่เห็นมาจาก AI หรือจากตลาด ต่อไป
         * ต้องรันกลยุทธ์ละสองตัว (เปิด/ปิดสวิตช์นี้) บนเหรียญและช่วงเวลาเดียวกัน
         * ถึงจะพูดได้ว่า AI ช่วยหรือทำร้าย
         *
         * ปิดแล้ว = กฎล้วน ไม่ต่างจากตอนที่ยังไม่มี AI ในระบบ (ด่านความเสี่ยงแบบกฎ
         * ยังทำงานตามปกติ — สวิตช์นี้ไม่ได้ปิดด่านข่าว นั่นคือ news_filter)
         */
        ['key' => 'ai_gate', 'label' => 'ให้ AI ร่วมตัดสินใจ', 'label_en' => 'Let the AI market view weigh in', 'type' => 'bool', 'default' => true],
    ],
];
