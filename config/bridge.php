<?php

/*
|--------------------------------------------------------------------------
| สะพาน USDT หลายเชน → TPIX Chain (USDT_TPIX)
|--------------------------------------------------------------------------
|
| ฝาก: ผู้ใช้โอน USDT จริงเข้า "กระเป๋าเก็บเงินหนุน" (vault) บนเชนต้นทาง →
|      bridge:scan เห็น Transfer เมื่อบล็อก finalized แล้ว → bridge:relay mint USDT_TPIX
|      ผ่าน TPIXBridgeMinter ให้ "ที่อยู่เดียวกัน" บนเชน 4289
| ถอน: ผู้ใช้เรียก USDT_TPIX.bridgeBurn(amount, chainId, address) บนเชน 4289 →
|      bridge:scan เห็น Burned → bridge:payout โอน USDT จริงจากกระเป๋าจ่าย (payout) บนเชนปลายทาง
| กระทบยอด: bridge:reconcile ทุก 5 นาที — supply ของ USDT_TPIX ต้องไม่เกินเงินหนุนจริง
|           ถ้าเกิน guardian สั่ง pause minter บนเชนทันที
|
| ⚠️ ห้ามใส่ private key / passphrase ลงไฟล์นี้หรือฐานข้อมูล — คีย์อยู่ในไฟล์ keystore V3
|    (นอก public_html) และ passphrase อยู่ใน .env เท่านั้น แบบเดียวกับกระเป๋าร้อนของชั้นคลัง
|
| ที่อยู่สัญญาบนเชน TPIX (USDT_TPIX / TPIXBridgeMinter) อ่านผ่าน App\Services\ContractRegistry
| (สคริปต์ deploy ลงทะเบียนให้เองผ่าน /api/infra/contracts) ไม่ใช่จากไฟล์นี้
|
*/

/** รายการ RPC จาก env (คั่นด้วย ,) — ไม่ตั้ง = ใช้ค่าปริยายที่ตรวจสดแล้ว */
$rpc = static function (string $env, array $defaults): array {
    $raw = trim((string) env($env, ''));

    return $raw === '' ? $defaults : array_values(array_filter(array_map('trim', explode(',', $raw))));
};

return [
    /*
    |--------------------------------------------------------------------------
    | สวิตช์ติดตั้ง (ชั้นนอกสุด)  ⚠️ ค่าปริยายคือปิด
    |--------------------------------------------------------------------------
    |
    | false = ไม่สแกน ไม่ mint ไม่จ่าย ไม่กระทบยอด — หน้าเว็บบอกว่ายังไม่เปิดให้บริการ
    | true  = ระบบทำงาน แต่การ mint/จ่ายยังต้องผ่านสวิตช์ในหลังบ้าน (site_settings bridge.enabled)
    |         และด่านความพร้อมทุกข้อ (BridgeConfig::readiness) อีกชั้น
    |
    | การสแกนทำงานแม้สวิตช์หลังบ้านปิดอยู่ — คนที่โอนเข้ามาระหว่างหยุดชั่วคราวต้องถูกบันทึกไว้
    | ไม่ใช่หายไปเฉย ๆ แล้วค่อย mint ให้ตอนเปิดใหม่
    |
    */

    'enabled' => (bool) env('BRIDGE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | กระเป๋าเก็บเงินหนุน (vault) — ที่อยู่เดียวกันทุกเชน EVM
    |--------------------------------------------------------------------------
    |
    | เป็น EOA ที่เจ้าของถือคีย์เอง (เซิร์ฟเวอร์ไม่มีคีย์ใบนี้และไม่ต้องมี) — เงินฝากทุกบาทเข้าที่นี่
    | ค่าปริยาย = กระเป๋าหนุนเดิมที่ถือ USDT (BEP-20) 19.99 ไว้หนุน supply 19.99 ของ USDT_TPIX
    | (ตรวจสดบน BSC 2026-10-11: balanceOf = 19990000000000000000 · eth_getCode = 0x)
    |
    */

    'vault_address' => env('BRIDGE_VAULT_ADDRESS', '0xf46131C82819d7621163F482b3fe88a228A7807c'),

    /*
    | ค่าสำรองของที่อยู่ TPIXBridgeMinter เมื่อทะเบียนสัญญา (site_settings กลุ่ม contracts) ยังว่าง
    | ปกติไม่ต้องตั้ง — deploy-bridge-minter.js ลงทะเบียนผ่าน /api/infra/contracts ให้เอง
    */
    'minter_address' => env('BRIDGE_MINTER_ADDRESS', ''),

    /*
    | Transfer เข้า vault จากที่อยู่เหล่านี้ = ย้ายเงินภายใน (เช่นเติมเงินหนุน) ไม่ใช่การฝาก → ไม่ mint
    | กระเป๋าจ่าย (payout) ถูกนับเป็นภายในให้อัตโนมัติ ไม่ต้องใส่ซ้ำ
    */
    'ignore_senders' => array_values(array_filter(array_map('trim', explode(',', (string) env('BRIDGE_IGNORE_SENDERS', ''))))),

    // Cloudflare หน้า rpc.tpix.online ตอบ 403/429 ให้ request ที่ไม่มี User-Agent
    'user_agent' => env('BRIDGE_RPC_USER_AGENT', 'TPIX-Bridge/1.0 (+https://tpix.online)'),
    'rpc_timeout' => (int) env('BRIDGE_RPC_TIMEOUT', 10),

    // รอใบเสร็จหลัง broadcast สูงสุดกี่วินาทีในรอบเดียว (ได้ใบเสร็จเร็ว = ทำรายการถัดไปต่อได้ในรอบเดียวกัน)
    'receipt_wait_seconds' => (int) env('BRIDGE_RECEIPT_WAIT_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | เชน TPIX (4289) — ฝั่ง mint / burn
    |--------------------------------------------------------------------------
    |
    | polygon-edge (IBFT) ปิดบล็อกแล้วจบ ไม่ย้อน แต่ไม่รองรับแท็ก "finalized"
    | (ตรวจสด 2026-10-11: eth_getBlockByNumber("finalized") → Invalid Params) จึงใช้นับ confirmation
    | eth_getLogs ช่วงเกิน 1000 บล็อกถูกปฏิเสธ — หั่นละ 900
    |
    */

    'tpix' => [
        'chain_id' => 4289,
        'name' => 'TPIX Chain',
        'rpc' => $rpc('BRIDGE_TPIX_RPC_URLS', [
            env('TPIX_RPC_URL', 'https://rpc.tpix.online'),
            'https://rpc1.tpix.online',
        ]),
        'confirmations' => 2,
        'explorer' => env('TPIX_EXPLORER_URL', 'https://explorer.tpix.online'),
        'log_chunk' => 900,
        'max_blocks_per_run' => 18000,
        // ว่าง = เริ่มที่บล็อกปัจจุบันตอนรันครั้งแรก (ไม่ไล่ย้อนหลัง)
        'start_block' => env('BRIDGE_TPIX_START_BLOCK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | กุญแจฝั่งเซิร์ฟเวอร์ — keystore V3 + passphrase ใน .env (แบบเดียวกับ TPIX_HOT_WALLET_*)
    |--------------------------------------------------------------------------
    |
    | relayer  — mint ผ่าน TPIXBridgeMinter บนเชน 4289 (ถูกจำกัดด้วย maxPerTx / dailyCap บนสัญญา)
    | payout   — จ่าย USDT จริงบนเชนปลายทาง ใบเดียวกันทุกเชน EVM (คนละใบกับ vault เสมอ)
    | guardian — สั่ง pause minter ได้อย่างเดียว (ไม่บังคับ ไม่ตั้ง = ปิดสวิตช์หลังบ้าน + แจ้งเตือนแทน)
    |
    | ทั้งสามใบต้องเป็นคนละกระเป๋ากัน และวางไว้ใน /home/admin/.tpix/ (อยู่ใน open_basedir แต่นอก public_html)
    | chmod 700 โฟลเดอร์ · chmod 600 ไฟล์
    |
    */

    'signers' => [
        'relayer' => [
            'keystore' => env('BRIDGE_RELAYER_KEYSTORE', '/home/admin/.tpix/bridge-relayer.keystore.json'),
            'passphrase' => env('BRIDGE_RELAYER_PASS'),
        ],
        'payout' => [
            'keystore' => env('BRIDGE_PAYOUT_KEYSTORE', '/home/admin/.tpix/bridge-payout.keystore.json'),
            'passphrase' => env('BRIDGE_PAYOUT_PASS'),
        ],
        'guardian' => [
            'keystore' => env('BRIDGE_GUARDIAN_KEYSTORE', ''),
            'passphrase' => env('BRIDGE_GUARDIAN_PASS'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | วงเงินและค่าธรรมเนียม (หน่วย USDT เต็ม) — ค่าปริยาย แอดมินแก้ได้ที่ /admin/bridge
    |--------------------------------------------------------------------------
    |
    | เกินวงเงิน = needs_review ให้แอดมินดู ไม่ mint/ไม่จ่ายเองเด็ดขาด
    | เพดานบนสัญญา (maxPerTx 1,000 · dailyCap 5,000) เป็นด่านสุดท้ายอีกชั้นที่แก้จากเว็บไม่ได้
    |
    */

    'limits' => [
        'min_deposit' => '1',
        'max_deposit_per_tx' => '1000',
        'max_deposit_per_address_daily' => '2000',
        'deposit_fee' => '0',
        'min_withdraw' => '2',
        'max_withdraw_per_tx' => '1000',
        'max_payout_daily' => '5000',
    ],

    /*
    |--------------------------------------------------------------------------
    | กระทบยอด
    |--------------------------------------------------------------------------
    |
    | ขาด (supply − เงินหนุนสุทธิ) เกิน tolerance สองรอบติด = สั่งหยุด
    | ที่ต้องสองรอบเพราะยอดจากหลายเชนอ่านไม่พร้อมกันในเสี้ยววินาทีเดียว — ธุรกรรมที่ลงบล็อก
    | ระหว่างอ่านทำให้รอบเดียวคลาดได้ชั่วคราว แต่การ mint เกินจริงจะค้างอยู่ทุกรอบ
    | ส่วน "mint ที่ไม่มีรายการในระบบ" (คีย์ relayer หลุด) สั่งหยุดทันทีรอบแรก
    |
    */

    'reconcile' => [
        'tolerance' => env('BRIDGE_RECONCILE_TOLERANCE', '1'),
        'auto_pause' => (bool) env('BRIDGE_RECONCILE_AUTO_PAUSE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | เชนต้นทาง/ปลายทาง (USDT เท่านั้น — USDT_TPIX คือ "Tether bridged" ห้ามหนุนด้วย USDC)
    |--------------------------------------------------------------------------
    |
    | ทุกที่อยู่และ decimals ตรวจสดด้วย eth_call symbol()/name()/decimals() เมื่อ 2026-10-11
    | (ดูบรรทัดคอมเมนต์ของแต่ละเชน) — BSC ใช้ 18 ทศนิยม ไม่ใช่ 6!
    |
    | finality:
    |   'finalized' = หัวที่ปลอดภัย = min(บล็อก finalized, ล่าสุด − min_confirmations)
    |                 ถ้า RPC ไม่รองรับแท็ก → ถอยไปใช้ ล่าสุด − fallback_confirmations
    |   'confirmations' = ล่าสุด − min_confirmations
    |
    | rpc: ตั้งทับได้ด้วย BRIDGE_RPC_<chainId> (คั่นด้วย ,) — ตัวแรกที่ตอบได้ถูกใช้
    |
    */

    'chains' => [
        // ตรวจสด: symbol=USDT name="Tether USD" decimals=6 · finalized ตามหลังล่าสุด ~72 บล็อก (~15 นาที)
        1 => [
            'name' => 'Ethereum',
            'short' => 'ETH',
            'native' => 'ETH',
            'rpc' => $rpc('BRIDGE_RPC_1', [
                'https://ethereum-rpc.publicnode.com',
                'https://rpc.mevblocker.io',
                'https://eth.drpc.org',
            ]),
            'usdt' => ['address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'decimals' => 6, 'symbol' => 'USDT'],
            'finality' => 'finalized',
            'min_confirmations' => 12,
            'fallback_confirmations' => 64,
            'explorer' => 'https://etherscan.io',
            'enabled' => (bool) env('BRIDGE_CHAIN_1_ENABLED', true),
            'withdraw_fee' => '1',
            'eta_minutes' => 20,
            'log_chunk' => 1000,
            'max_blocks_per_run' => 20000,
            'start_block' => env('BRIDGE_START_BLOCK_1'),
            'gas_price_multiplier_bps' => 12500,
            'max_gas_price_gwei' => '150',
        ],

        // ตรวจสด: symbol=USDT name="Tether USD" decimals=18 (!) · รองรับแท็ก finalized (fast finality)
        56 => [
            'name' => 'BNB Smart Chain',
            'short' => 'BSC',
            'native' => 'BNB',
            'rpc' => $rpc('BRIDGE_RPC_56', [
                'https://bsc-rpc.publicnode.com',
                // dataseed ของ Binance ไม่รับ eth_getLogs (limit exceeded) แต่ใช้อ่านยอด/ส่งธุรกรรมได้
                'https://bsc-dataseed.binance.org',
                'https://bsc.drpc.org',
            ]),
            'usdt' => ['address' => '0x55d398326f99059fF775485246999027B3197955', 'decimals' => 18, 'symbol' => 'USDT'],
            'finality' => 'finalized',
            'min_confirmations' => 15,
            'fallback_confirmations' => 30,
            'explorer' => 'https://bscscan.com',
            'enabled' => (bool) env('BRIDGE_CHAIN_56_ENABLED', true),
            'withdraw_fee' => '0.3',
            'eta_minutes' => 2,
            'log_chunk' => 1000,
            'max_blocks_per_run' => 20000,
            'start_block' => env('BRIDGE_START_BLOCK_56'),
            'gas_price_multiplier_bps' => 12000,
            'max_gas_price_gwei' => '20',
        ],

        // ตรวจสด: symbol=USDT0 name=USDT0 decimals=6 (สัญญาเดิมของ USDT บน Polygon อัปเกรดเป็น USDT0)
        137 => [
            'name' => 'Polygon PoS',
            'short' => 'POL',
            'native' => 'POL',
            'rpc' => $rpc('BRIDGE_RPC_137', [
                // ตรวจสด 2026-10-11: polygon-rpc.com / ankr ต้องมีคีย์แล้ว · publicnode ตอบ getLogs เฉพาะบล็อกล่าสุดมาก ๆ
                'https://polygon.drpc.org',
                'https://polygon-bor-rpc.publicnode.com',
            ]),
            'usdt' => ['address' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 'decimals' => 6, 'symbol' => 'USDT0'],
            'finality' => 'finalized',
            'min_confirmations' => 32,
            'fallback_confirmations' => 256,
            'explorer' => 'https://polygonscan.com',
            'enabled' => (bool) env('BRIDGE_CHAIN_137_ENABLED', true),
            'withdraw_fee' => '0.3',
            'eta_minutes' => 3,
            // RPC ฟรีของ Polygon รับ getLogs ได้แค่ช่วงละ ~100 บล็อก (drpc ตอบ "ranges over 10000…" ตั้งแต่ 300)
            'log_chunk' => 100,
            'max_blocks_per_run' => 3000,
            'start_block' => env('BRIDGE_START_BLOCK_137'),
            'gas_price_multiplier_bps' => 13000,
            'max_gas_price_gwei' => '3000',
        ],

        // ตรวจสด: symbol=USD₮0 name=USD₮0 decimals=6 · finalized ตามหลัง ~3,700 บล็อก (~15 นาที ตาม L1)
        42161 => [
            'name' => 'Arbitrum One',
            'short' => 'ARB',
            'native' => 'ETH',
            'rpc' => $rpc('BRIDGE_RPC_42161', [
                // publicnode ของ Arbitrum ปฏิเสธ getLogs ย้อนหลังเกิน ~ไม่กี่สิบบล็อก (archive) — ใช้ของ Arbitrum เองก่อน
                'https://arb1.arbitrum.io/rpc',
                'https://arbitrum-one-rpc.publicnode.com',
                'https://arbitrum.drpc.org',
            ]),
            'usdt' => ['address' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9', 'decimals' => 6, 'symbol' => 'USD₮0'],
            'finality' => 'finalized',
            'min_confirmations' => 20,
            'fallback_confirmations' => 5000,
            'explorer' => 'https://arbiscan.io',
            'enabled' => (bool) env('BRIDGE_CHAIN_42161_ENABLED', true),
            'withdraw_fee' => '0.3',
            'eta_minutes' => 20,
            'log_chunk' => 1000,
            'max_blocks_per_run' => 50000,
            'start_block' => env('BRIDGE_START_BLOCK_42161'),
            'gas_price_multiplier_bps' => 15000,
            'max_gas_price_gwei' => '5',
        ],

        // ตรวจสด: 0x94b0…8e58 symbol=USDT name="Tether USD" decimals=6 (supply ~360M)
        //         และ USD₮0 0x01bF…1071 decimals=6 (supply ~50M) — ใช้ตัวที่คนถือมากกว่าเป็นค่าปริยาย
        //         เปลี่ยนได้ด้วย BRIDGE_OPTIMISM_USDT (ต้องเป็น 6 ทศนิยม) · finalized ตามหลัง ~700 บล็อก
        10 => [
            'name' => 'OP Mainnet',
            'short' => 'OP',
            'native' => 'ETH',
            'rpc' => $rpc('BRIDGE_RPC_10', [
                'https://optimism-rpc.publicnode.com',
                'https://mainnet.optimism.io',
                'https://optimism.drpc.org',
            ]),
            'usdt' => ['address' => env('BRIDGE_OPTIMISM_USDT', '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58'), 'decimals' => 6, 'symbol' => 'USDT'],
            'finality' => 'finalized',
            'min_confirmations' => 20,
            'fallback_confirmations' => 1800,
            'explorer' => 'https://optimistic.etherscan.io',
            'enabled' => (bool) env('BRIDGE_CHAIN_10_ENABLED', true),
            'withdraw_fee' => '0.3',
            'eta_minutes' => 25,
            'log_chunk' => 1000,
            'max_blocks_per_run' => 20000,
            'start_block' => env('BRIDGE_START_BLOCK_10'),
            'gas_price_multiplier_bps' => 15000,
            'max_gas_price_gwei' => '5',
        ],

        // ตรวจสด: symbol=USDt name=TetherToken decimals=6 · finality ทันที (finalized ตามหลัง ~2 บล็อก)
        43114 => [
            'name' => 'Avalanche C-Chain',
            'short' => 'AVAX',
            'native' => 'AVAX',
            'rpc' => $rpc('BRIDGE_RPC_43114', [
                'https://avalanche-c-chain-rpc.publicnode.com',
                'https://api.avax.network/ext/bc/C/rpc',
                'https://avalanche.drpc.org',
            ]),
            'usdt' => ['address' => '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7', 'decimals' => 6, 'symbol' => 'USDt'],
            'finality' => 'finalized',
            'min_confirmations' => 10,
            'fallback_confirmations' => 30,
            'explorer' => 'https://snowtrace.io',
            'enabled' => (bool) env('BRIDGE_CHAIN_43114_ENABLED', true),
            'withdraw_fee' => '0.3',
            'eta_minutes' => 2,
            'log_chunk' => 1000,
            'max_blocks_per_run' => 20000,
            'start_block' => env('BRIDGE_START_BLOCK_43114'),
            'gas_price_multiplier_bps' => 12500,
            'max_gas_price_gwei' => '100',
        ],
    ],
];
