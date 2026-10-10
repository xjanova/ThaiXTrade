<?php

/*
|--------------------------------------------------------------------------
| TPIX Treasury (ชั้นคลัง)
|--------------------------------------------------------------------------
|
| กระเป๋าคลัง 6 ใบมาจาก genesis ของเชนจริง — ที่อยู่ชุดเดียวกับ
| TPIX-Coin/infrastructure/chain/alloc.env และชื่อบทบาทยึดตาม
| docs/WHITEPAPER.md §Distribution (ชุดเดียวกับการ์ดใน explorer)
|
| กระเป๋าทุกใบที่แตกจาก master wallet (BIP-44 m/44'/60'/0'/0/0..10)
| เซิร์ฟเวอร์ **ไม่มีคีย์ของมันและจะไม่มีวันมี** — เก็บแค่ที่อยู่ไว้อ่านยอดกับกระทบยอด
| การโอนออกเซ็นจากข้างนอกเท่านั้น: Masternode UI, send-tpix.ps1 ใน TPIX-Coin
| หรือหน้า /admin/treasury ที่ถอดรหัส keystore ในเบราว์เซอร์ของแอดมินเอง
| (ไฟล์ keystore กับรหัสผ่านไม่ถูกส่งมาที่เซิร์ฟเวอร์)
|
| กระเป๋าร้อนเป็นคีย์สุ่มอิสระ ไม่ derive จาก mnemonic ของคลัง เพราะ path
| m/44'/60'/0'/0/N เป็น non-hardened ถ้าคีย์ลูกหลุดพร้อม xpub ของแม่
| จะคำนวณคีย์พี่น้องได้ทุกใบ
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | กระเป๋าคลังทั้ง 11 ใบ — 6 ใบตาม tokenomics + กระเป๋าหลัก + stake ของ validator 4 ใบ
    |--------------------------------------------------------------------------
    |
    | genesis รวมกัน = 7,000,000,000 พอดี (6.96B + 4 × 10M) ตัวกระทบยอดจึงครอบทั้งเชน
    |
    */

    'pools' => [
        [
            'key' => 'main',
            'role' => 'Main (reward receiver)',
            'role_th' => 'กระเป๋าหลัก',
            'address' => '0x18A4076b9B107121280a4373cD8474f9858D5D3f',
            'path' => "m/44'/60'/0'/0/0",
            'genesis' => '0',
            'color' => 'slate',
        ],
        [
            'key' => 'masternode_rewards',
            'role' => 'Master Node Rewards',
            'role_th' => 'รางวัลมาสเตอร์โหนด',
            'address' => '0xf54c0deE404ec728a03b467cba7bBA171CC77dad',
            'path' => "m/44'/60'/0'/0/1",
            'genesis' => '1400000000',
            'color' => 'emerald',
        ],
        [
            'key' => 'ecosystem',
            'role' => 'Ecosystem Development',
            'role_th' => 'พัฒนาระบบนิเวศ',
            'address' => '0x6E176Bf5Aa39Fb4217E0ebd00E14B67aDfFaf440',
            'path' => "m/44'/60'/0'/0/2",
            'genesis' => '1710000000',
            'color' => 'cyan',
        ],
        [
            'key' => 'team',
            'role' => 'Team & Advisors',
            'role_th' => 'ทีมงานและที่ปรึกษา',
            'address' => '0x87e62D9e0C2aF15d634D3301Dd2D4DA57972052d',
            'path' => "m/44'/60'/0'/0/3",
            'genesis' => '700000000',
            'color' => 'violet',
        ],
        [
            'key' => 'token_sale',
            'role' => 'Token Sale',
            'role_th' => 'ขายเหรียญ',
            'address' => '0x4BcC1844Ad9E8587f7005f092928a5D14C30F463',
            'path' => "m/44'/60'/0'/0/4",
            'genesis' => '700000000',
            'color' => 'amber',
        ],
        [
            'key' => 'liquidity',
            'role' => 'Liquidity & Market Making',
            'role_th' => 'สภาพคล่อง',
            'address' => '0x2644A740A06e0401D21F8B4A840400fFe8dB42A9',
            'path' => "m/44'/60'/0'/0/5",
            'genesis' => '1050000000',
            'color' => 'blue',
        ],
        [
            'key' => 'community',
            'role' => 'Community & Rewards',
            'role_th' => 'ชุมชนและรางวัล',
            'address' => '0x6dECa2E185CF37e7c838fE5Ae6897aED025c9921',
            'path' => "m/44'/60'/0'/0/6",
            'genesis' => '1400000000',
            'color' => 'rose',
        ],
        [
            'key' => 'validator_1_stake',
            'role' => 'Validator 1 Stake',
            'role_th' => 'stake ผู้ตรวจสอบบล็อก 1',
            'address' => '0x24CD5d5A6B5EcC6520c76f5427DB06F81BcC61C5',
            'path' => "m/44'/60'/0'/0/7",
            'genesis' => '10000000',
            'color' => 'teal',
        ],
        [
            'key' => 'validator_2_stake',
            'role' => 'Validator 2 Stake',
            'role_th' => 'stake ผู้ตรวจสอบบล็อก 2',
            'address' => '0x394418d33641D967C3553e45Af0646d565F51Ba7',
            'path' => "m/44'/60'/0'/0/8",
            'genesis' => '10000000',
            'color' => 'teal',
        ],
        [
            'key' => 'validator_3_stake',
            'role' => 'Validator 3 Stake',
            'role_th' => 'stake ผู้ตรวจสอบบล็อก 3',
            'address' => '0x9D6Fc1cf3C17b495057356B95e995834248993F0',
            'path' => "m/44'/60'/0'/0/9",
            'genesis' => '10000000',
            'color' => 'teal',
        ],
        [
            'key' => 'validator_4_stake',
            'role' => 'Validator 4 Stake',
            'role_th' => 'stake ผู้ตรวจสอบบล็อก 4',
            'address' => '0xec91028198E8cC55B284c018aBB4B2A87c6f3F12',
            'path' => "m/44'/60'/0'/0/10",
            'genesis' => '10000000',
            'color' => 'teal',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ยอดรวมทั้งเชน — ใช้คำนวณสัดส่วนและกระทบยอด
    |--------------------------------------------------------------------------
    */

    'total_supply' => '7000000000',

    /*
    |--------------------------------------------------------------------------
    | กระเป๋าร้อน (ใช้จ่ายอัตโนมัติ)
    |--------------------------------------------------------------------------
    |
    | keystore เข้ารหัสอยู่นอก document root — passphrase อยู่ใน .env เท่านั้น
    | **ห้ามเก็บ passphrase ลง database และห้าม commit**
    | แอปถอดรหัสตอนรันไทม์ ไม่เขียน private key ลงดิสก์หรือ log
    |
    | ⚠️ พาธต้องอยู่ใน open_basedir ของ PHP ฝั่งเว็บ
    | เซิร์ฟเวอร์นี้ (DirectAdmin) จำกัดไว้ที่ /home/admin/ เป็นต้น การวางไว้ที่
    | /etc/tpix/ จึงอ่านไม่ได้เลยและทำให้หน้าคลังพังเป็น 500
    |
    | /home/admin/.tpix/ ปลอดภัยเท่ากันเพราะอยู่นอก public_html เว็บเข้าไม่ถึง
    | ตั้งสิทธิ์ให้แน่น: chmod 700 โฟลเดอร์ · chmod 600 ไฟล์
    |
    */

    'hot_wallet' => [
        'address' => env('TPIX_HOT_WALLET_ADDRESS', '0x78B81dF5345e69ef7A1af231dE1C5b1b30869C8f'),
        'keystore_path' => env('TPIX_HOT_WALLET_KEYSTORE', '/home/admin/.tpix/hot-wallet.keystore.json'),
        'passphrase' => env('TPIX_HOT_WALLET_PASS'),

        // เตือนเมื่อยอดต่ำกว่านี้ (หน่วย TPIX) — 0 = ปิดการเตือน
        'low_balance_warning' => env('TPIX_HOT_WALLET_LOW_WARNING', '1000000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | สวิตช์เปิดการจ่ายเงิน  ⚠️ ค่าเริ่มต้นคือ "ปิด"
    |--------------------------------------------------------------------------
    |
    | ต่อให้ตั้งเป็น true ระบบก็ยังจ่ายไม่ได้ถ้าเงื่อนไขอื่นไม่ครบ
    | (ดู TreasuryService::readiness()) — สวิตช์นี้เป็นแค่ด่านสุดท้าย
    | ที่ต้องตั้งใจเปิดเอง ไม่ใช่เปิดโดยบังเอิญจากการ deploy
    |
    | ตอนนี้ยังปิดอยู่เพราะกระเป๋าร้อนยังไม่ถูกเติมเงิน และยังไม่ได้วาง
    | keystore ไว้ที่เซิร์ฟเวอร์ — คิวอนุมัติใช้งานได้ แต่จะไม่เซ็น/ไม่ broadcast
    |
    */

    'payouts_enabled' => env('TPIX_TREASURY_PAYOUTS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | วงเงินจำกัด (หน่วย TPIX)
    |--------------------------------------------------------------------------
    |
    | ตรวจทั้งตอนสร้างรายการและตอนก่อนเซ็นอีกรอบ — กันกรณีที่รายการถูกสร้าง
    | ตอนวงเงินสูงแล้วค่อยมาอนุมัติหลังลดวงเงิน
    |
    */

    'limits' => [
        'per_transaction' => env('TPIX_TREASURY_MAX_PER_TX', '1000000'),
        'per_day' => env('TPIX_TREASURY_MAX_PER_DAY', '10000000'),

        // ต้องส่งเข้าปลายทางที่อยู่ใน whitelist เท่านั้น
        'require_whitelist' => env('TPIX_TREASURY_REQUIRE_WHITELIST', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | การกระทบยอด
    |--------------------------------------------------------------------------
    |
    | ยอมให้ยอด on-chain กับยอดที่ DB บันทึกต่างกันได้ไม่เกินนี้ (wei)
    | เกินกว่านี้ = ดังทันที ค่าเริ่มต้น 0 คือต้องตรงเป๊ะทุก wei
    |
    */

    'reconcile_tolerance_wei' => env('TPIX_TREASURY_RECONCILE_TOLERANCE', '0'),

    /*
    |--------------------------------------------------------------------------
    | RPC
    |--------------------------------------------------------------------------
    |
    | อ่านยอดจาก RPC ตรง ไม่ผ่าน Blockscout เพราะ Blockscout ไม่ index
    | กระเป๋าที่ไม่เคยมีธุรกรรม — กระเป๋าคลังที่ยังไม่เคยจ่ายจะไม่ปรากฏเลย
    |
    | Cloudflare WAF บล็อก request ที่ไม่มี User-Agent → ต้องตั้ง UA เสมอ
    |
    */

    'rpc_url' => env('TPIX_RPC_URL', 'https://rpc.tpix.online'),
    'rpc_user_agent' => env('TPIX_RPC_USER_AGENT', 'TPIX-Treasury/1.0 (+https://tpix.online)'),
    'rpc_timeout' => 10,
    'balance_cache_seconds' => 15,
];
