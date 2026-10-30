<?php

/*
|--------------------------------------------------------------------------
| ซื้อ TPIX ด้วย USDT จากเชนอื่น — ตรวจเงินเข้าเองจากเชน ไม่มีขั้นแอดมิน
|--------------------------------------------------------------------------
|
| ลำดับ:
|   1. ผู้ซื้อ (กระเป๋าที่ยืนยันแล้ว + ผ่านด่านเดียวกับบัตร) ขอคำสั่งซื้อ → เซิร์ฟเวอร์ล็อกราคา
|      และออก "ยอดเฉพาะของคำสั่งซื้อนี้" เช่น 100.003471 USDT (เศษท้ายไม่ถึง 1 เซ็นต์)
|   2. ผู้ซื้อโอน USDT ยอดนั้นเป๊ะเข้ากระเป๋ารับเงินของรอบขาย (sale_wallet_address) ภายในเวลาที่กำหนด
|   3. sale:scan-crypto อ่าน Transfer เข้ากระเป๋านั้นเมื่อบล็อกนิ่งแล้ว → จับคู่ด้วยยอดเป๊ะ
|      → บันทึก SaleTransaction (confirmed) เข้าสายจ่ายเหรียญ + vesting เดียวกับบัตร
|   ยอดไม่ตรง / มาช้า / จ่ายซ้ำ / ผิดเหรียญ / หาคำสั่งซื้อไม่เจอ = needs_review (บันทึกไว้เสมอ ไม่หายเงียบ)
|
| เชนต้นทาง (ที่อยู่ USDT · decimals · RPC · finality · ขนาดช่วง getLogs) ใช้ชุดเดียวกับสะพาน
| ใน config/bridge.php — ตั้ง RPC แบบเสียเงินได้ที่ BRIDGE_RPC_<chainId> ตัวเดียวใช้ร่วมกัน
|
| ⚠️ กระเป๋ารับเงินต้อง **ไม่ใช่** vault ของสะพาน: ตัวสแกนสะพานนับทุก Transfer เข้า vault
|    เป็นเงินฝากแล้ว mint USDT_TPIX ให้ผู้โอน → ผู้ซื้อจะได้ทั้ง TPIX และ USDT_TPIX
|    (SaleCryptoConfig ปฏิเสธที่อยู่ภายในทุกใบ: vault, payout, relayer, guardian, กระเป๋าจ่ายเหรียญ)
|
*/

$list = static fn (string $raw): array => array_values(array_filter(array_map('trim', explode(',', $raw))));

return [
    /*
    | สวิตช์ติดตั้ง (ชั้นนอกสุด) — ค่าปริยายปิด
    | false = ไม่สแกน ไม่รับคำสั่งซื้อ · true = ยังต้องเพิ่ม "CRYPTO" ใน accept_currencies ของรอบขายด้วย
    | ห้ามใช้ "USDT" หรือ "BNB" แทน สองค่านั้นคือทางยื่น tx_hash แบบเก่า (ดู TokenSale::PAYMENT_CHANNELS)
    */
    'enabled' => (bool) env('SALE_CRYPTO_ENABLED', false),

    /*
    | เชนที่รับจ่าย (chain id คั่นด้วย ,) — ต้องมีอยู่ใน config/bridge.php chains
    | ค่าปริยายเปิดแค่ BSC — เชนอื่นเปิดได้เมื่อเจ้าของอนุมัติ (ETH 1 · Polygon 137 · Arbitrum 42161 · OP 10 · Avalanche 43114)
    | ไม่ผูกกับ BRIDGE_CHAIN_<id>_ENABLED — ปิดสะพานบนเชนไหนไม่ทำให้การรับชำระบนเชนนั้นปิดตาม และกลับกัน
    */
    'chains' => array_map('intval', $list((string) env('SALE_CRYPTO_CHAINS', '56'))),

    // ราคาที่ล็อกไว้ใช้ได้กี่นาที — นับจากเวลาบล็อกที่โอน ไม่ใช่เวลาที่ตัวสแกนเห็น (สแกนช้าไม่ทำให้ผู้ซื้อเสียสิทธิ์)
    'order_ttl_minutes' => (int) env('SALE_CRYPTO_ORDER_TTL_MINUTES', 30),

    /*
    | ยอดเฉพาะของคำสั่งซื้อจองไว้นานแค่ไหนหลังหมดเวลา — โอนมาช้าในช่วงนี้ยังจับคู่ได้ (needs_review: late)
    | พ้นช่วงนี้ยอดเดิมถูกปล่อยให้คำสั่งซื้อใหม่ใช้ได้ และการโอนช้ามาก ๆ จะตกเป็น unmatched (ยังบันทึกไว้)
    */
    'late_window_hours' => (int) env('SALE_CRYPTO_LATE_WINDOW_HOURS', 72),

    // คำสั่งซื้อที่ยังรอจ่ายพร้อมกันได้สูงสุดต่อกระเป๋า — กันกดรัวจนยอดเฉพาะหมด
    'max_open_orders_per_wallet' => (int) env('SALE_CRYPTO_MAX_OPEN_ORDERS', 3),

    // ต่ำกว่านี้ (หน่วย USDT เต็ม) ไม่บันทึก — Transfer ฝุ่นใครก็ยิงใส่กระเป๋าได้ฟรี (address poisoning)
    'dust' => env('SALE_CRYPTO_DUST', '0.01'),

    // ตัวสแกนเชนนั้นต้องสแกนผ่านภายในกี่นาที ถึงจะรับคำสั่งซื้อใหม่บนเชนนั้น (ตรวจเงินเข้าไม่ได้ = ไม่รับเงิน)
    'scan_stale_minutes' => (int) env('SALE_CRYPTO_SCAN_STALE_MINUTES', 10),

    /*
    | เหรียญอื่นที่คนมักโอนผิด — เฝ้าไว้เพื่อบันทึกเป็น needs_review (wrong_token) ให้ทีมงานคืนเงินได้
    | ไม่ใช่เหรียญที่รับชำระ · ตรวจ BNB/ETH ที่โอนตรงไม่ได้ด้วย getLogs (ไม่มี event) — หน้าเว็บย้ำว่ารับแค่ USDT
    */
    'watch_tokens' => [
        56 => [
            ['symbol' => 'USDC', 'address' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 'decimals' => 18],
            ['symbol' => 'BUSD', 'address' => '0xe9e7CEA3DedcA5984780Bafc599bD69ADd087D56', 'decimals' => 18],
        ],
        1 => [
            ['symbol' => 'USDC', 'address' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'decimals' => 6],
        ],
        137 => [
            ['symbol' => 'USDC', 'address' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359', 'decimals' => 6],
        ],
        42161 => [
            ['symbol' => 'USDC', 'address' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831', 'decimals' => 6],
        ],
        10 => [
            ['symbol' => 'USDC', 'address' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85', 'decimals' => 6],
        ],
        43114 => [
            ['symbol' => 'USDC', 'address' => '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E', 'decimals' => 6],
        ],
    ],
];
