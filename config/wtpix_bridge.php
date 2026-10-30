<?php

/*
|--------------------------------------------------------------------------
| สะพาน TPIX (เชน 4289) ⇄ wTPIX (BEP-20 บน BSC)
|--------------------------------------------------------------------------
|
| TPIX → BSC: ผู้ใช้ล็อก TPIX เข้า TPIXBridgeVault บนเชน 4289 (lock() หรือโอนตรงที่ให้แก๊สเกิน 21000)
|             → bridge:wtpix-scan เห็น Locked เมื่อผ่าน 2 confirmation → bridge:wtpix-relay
|             mint wTPIX ผ่าน WTPIXBridgeMinter บน BSC ให้ "ที่อยู่เดียวกัน" (หักค่าธรรมเนียม)
| BSC → TPIX: ผู้ใช้เผา wTPIX บน BSC (burn()) → ตัวสแกนเห็น Transfer(from, 0x0) เมื่อบล็อก finalized
|             → relay เรียก vault.release(to, amount, depositId) บนเชน 4289 ให้ที่อยู่ผู้เผา
| กระทบยอด:  TPIX ในคลัง − ยอดค้างปล่อย ≥ wTPIX supply บน BSC — ขาดสองรอบติด / mint หรือปล่อย
|             ที่ระบบไม่ได้สั่ง / มีคนเข้าคิวเพิ่ม minter = ปิดสวิตช์ + guardian pause ทั้งสองฝั่ง
|
| ⚠️ แยกจากสะพาน USDT (config/bridge.php) ทุกอย่าง — สวิตช์ กุญแจ ตาราง เคอร์เซอร์
|    ห้ามใช้ BRIDGE_VAULT_ADDRESS ที่นี่ (นั่นคือกระเป๋าเงินหนุน USDT ที่เป็น EOA)
|
| ⚠️ ที่อยู่สัญญาทั้งสามตัวต้องตรึงใน .env — ไม่อ่านจากทะเบียนสัญญา (site_settings) เพราะทะเบียนแก้ได้
|    ด้วย CONTRACT_REGISTRY_TOKEN: ชี้ vault ไปสัญญาปลอมที่ยิง Locked เปล่า ๆ = ได้ wTPIX ฟรี
|    ทะเบียนใช้แค่เทียบ — ถ้า wtpix_bridge_vault ในทะเบียนไม่ตรงกับ .env ระบบไม่ทำงาน
|
*/

$rpc = static function (string $env, array $defaults): array {
    $raw = trim((string) env($env, ''));

    return $raw === '' ? $defaults : array_values(array_filter(array_map('trim', explode(',', $raw))));
};

/*
 * BSC จริง (56) หรือ testnet (97) สำหรับซ้อม — เปลี่ยนแค่ WTPIX_BRIDGE_BSC_CHAIN_ID
 * เคอร์เซอร์และรายการผูกกับ (chain id, ที่อยู่สัญญา) จึงสลับไปมาแล้วไม่ปนกัน
 */
$bscChainId = (int) env('WTPIX_BRIDGE_BSC_CHAIN_ID', 56);
$bscProfiles = [
    56 => [
        'name' => 'BNB Smart Chain',
        'rpc' => ['https://bsc-rpc.publicnode.com', 'https://bsc-dataseed.binance.org', 'https://bsc.drpc.org'],
        'explorer' => 'https://bscscan.com',
    ],
    97 => [
        'name' => 'BNB Smart Chain Testnet',
        'rpc' => ['https://bsc-testnet-rpc.publicnode.com', 'https://data-seed-prebsc-1-s1.bnbchain.org:8545'],
        'explorer' => 'https://testnet.bscscan.com',
    ],
];
$bscProfile = $bscProfiles[$bscChainId] ?? ['name' => "BSC #{$bscChainId}", 'rpc' => [], 'explorer' => ''];

return [
    /*
    | สวิตช์ติดตั้ง (ชั้นนอกสุด) ⚠️ ค่าปริยายปิด — false = ไม่สแกน ไม่ mint ไม่ปล่อย ไม่กระทบยอด
    | true = ระบบทำงาน แต่ mint/ปล่อยยังต้องผ่านสวิตช์หลังบ้าน (/admin/bridge/wtpix) และด่านความพร้อมทุกข้อ
    | แยกจาก BRIDGE_ENABLED ของสะพาน USDT — ปิดตัวหนึ่งไม่กระทบอีกตัว
    */
    'enabled' => (bool) env('WTPIX_BRIDGE_ENABLED', false),

    // TPIXBridgeVault บนเชน 4289 (ตรึง — ดูหัวไฟล์)
    'vault_address' => env('WTPIX_BRIDGE_VAULT_ADDRESS', ''),

    // wTPIX (BEP-20) + WTPIXBridgeMinter บน BSC — deploy-wtpix-bsc.js พิมพ์สองบรรทัดนี้ให้
    'wtpix_address' => env('WTPIX_BSC_ADDRESS', ''),
    'minter_address' => env('WTPIX_BSC_MINTER_ADDRESS', ''),

    // โลโก้ที่ส่งให้กระเป๋าตอน "เพิ่ม wTPIX" (wallet_watchAsset ต้องเป็น URL เต็ม) — ?v= กันแคช Cloudflare
    'token_logo' => env('WTPIX_BRIDGE_TOKEN_LOGO', 'https://tpix.online/images/brand/tpix-coin.png?v=2'),

    'user_agent' => env('WTPIX_BRIDGE_RPC_USER_AGENT', env('BRIDGE_RPC_USER_AGENT', 'TPIX-Bridge/1.0 (+https://tpix.online)')),
    'rpc_timeout' => (int) env('WTPIX_BRIDGE_RPC_TIMEOUT', 10),
    'receipt_wait_seconds' => (int) env('WTPIX_BRIDGE_RECEIPT_WAIT_SECONDS', 30),

    /*
    | เชน TPIX — polygon-edge (IBFT) ปิดบล็อกแล้วจบ แต่ไม่มีแท็ก "finalized" → นับ confirmation
    | eth_getLogs ช่วงเกิน 1000 บล็อกถูกปฏิเสธ — หั่นละ 900
    */
    // ธุรกรรมปลายทางค้างนานเท่านี้ (นาที) แจ้งแอดมิน — ทิศนั้นหยุดรอจนกว่าจะลงบล็อกหรือถูกแทนที่
    'stuck_after_minutes' => (int) env('WTPIX_BRIDGE_STUCK_AFTER_MINUTES', 30),

    'tpix' => [
        'chain_id' => 4289,
        'name' => 'TPIX Chain',
        'rpc' => $rpc('WTPIX_BRIDGE_TPIX_RPC_URLS', [
            env('TPIX_RPC_URL', 'https://rpc1.tpix.online'),
            'https://rpc.tpix.online',
        ]),
        // RPC ที่ส่งให้เบราว์เซอร์อ่านยอด (ต้องเป็นของสาธารณะ ไม่ใช่ RPC ภายใน)
        'public_rpc' => env('WTPIX_BRIDGE_TPIX_PUBLIC_RPC', 'https://rpc1.tpix.online'),
        'confirmations' => max(2, (int) env('WTPIX_BRIDGE_TPIX_CONFIRMATIONS', 2)),
        'explorer' => env('TPIX_EXPLORER_URL', 'https://explorer.tpix.online'),
        'log_chunk' => 900,
        'max_blocks_per_run' => 18000,
        // ว่าง = เริ่มที่บล็อกปัจจุบันตอนรันครั้งแรก (ไม่ไล่ย้อนหลัง — ไม่ mint ให้ประวัติเก่า)
        'start_block' => env('WTPIX_BRIDGE_TPIX_START_BLOCK'),
        'eta_minutes' => 3,
    ],

    'bsc' => [
        'chain_id' => $bscChainId,
        'name' => $bscProfile['name'],
        'rpc' => $rpc('WTPIX_BRIDGE_BSC_RPC_URLS', $bscProfile['rpc']),
        // เบราว์เซอร์อ่านยอด wTPIX ผ่านตัวนี้ — ต้องอยู่ใน CSP connect-src (SecurityHeaders) ไม่งั้นอ่านไม่ได้เงียบ ๆ
        // bsc-dataseed ไม่รับ eth_getLogs แต่อ่าน balanceOf ได้ และอนุญาตใน CSP อยู่แล้ว
        'public_rpc' => env('WTPIX_BRIDGE_BSC_PUBLIC_RPC', $bscChainId === 56 ? 'https://bsc-dataseed.binance.org' : ($bscProfile['rpc'][0] ?? '')),
        'explorer' => env('WTPIX_BRIDGE_BSC_EXPLORER', $bscProfile['explorer']),
        // หัวที่ปลอดภัย = min(finalized, ล่าสุด − 15) · RPC ไม่รองรับแท็ก → ล่าสุด − 30 (แบบเดียวกับ USDT บน BSC)
        'finality' => 'finalized',
        'min_confirmations' => (int) env('WTPIX_BRIDGE_BSC_CONFIRMATIONS', 15),
        'fallback_confirmations' => 30,
        'log_chunk' => 1000,
        'max_blocks_per_run' => 20000,
        'start_block' => env('WTPIX_BRIDGE_BSC_START_BLOCK'),
        'gas_price_multiplier_bps' => 12000,
        'max_gas_price_gwei' => env('WTPIX_BRIDGE_BSC_MAX_GAS_GWEI', '20'),
        // BNB ขั้นต่ำในกระเป๋า relayer ก่อนเซ็น mint — ต่ำกว่านี้ = awaiting_liquidity (ไม่เซ็นครึ่ง ๆ กลาง ๆ)
        'min_gas_balance' => env('WTPIX_BRIDGE_MIN_BNB', '0.002'),
        'eta_minutes' => 2,
    ],

    /*
    | กุญแจฝั่งเซิร์ฟเวอร์ — keystore V3 + passphrase ใน .env (ไม่เคยเข้าฐานข้อมูล ไม่เคยถูก log)
    |
    | relayer  — mint บน BSC (ต้องมี BNB) + release บนเชน 4289 (แก๊ส 0) ใบเดียวกันสองเชน
    | guardian — สั่ง pause() ได้อย่างเดียว ทั้ง minter บน BSC (ต้องมี BNB) และ vault บนเชน 4289
    |            ไม่ตั้ง = ตอนเกิดเหตุทำได้แค่ปิดสวิตช์หลังบ้าน + แจ้งเตือนวิกฤต
    |
    | ⚠️ แนะนำให้แยกจากกุญแจของสะพาน USDT — ใช้ไฟล์เดียวกันได้ แต่คีย์หลุดหนึ่งใบจะกระทบสองสะพาน
    |    (และ relay สองสะพานต้องรอคิว nonce กัน — ระบบล็อกให้เองเมื่อเห็นว่าเป็นที่อยู่เดียวกัน)
    */
    'signers' => [
        'relayer' => [
            'keystore' => env('WTPIX_BRIDGE_RELAYER_KEYSTORE', '/home/admin/.tpix/wtpix-bridge-relayer.keystore.json'),
            'passphrase' => env('WTPIX_BRIDGE_RELAYER_PASS'),
        ],
        'guardian' => [
            'keystore' => env('WTPIX_BRIDGE_GUARDIAN_KEYSTORE', ''),
            'passphrase' => env('WTPIX_BRIDGE_GUARDIAN_PASS'),
        ],
    ],

    /*
    | วงเงิน/ค่าธรรมเนียม (หน่วย TPIX เต็ม) — ค่าปริยาย แอดมินแก้ได้ที่ /admin/bridge/wtpix
    | เกินวงเงิน = needs_review ไม่ทำเอง · เพดานบนสัญญา (100,000 ต่อครั้ง · 500,000 ต่อวัน UTC
    | ทั้งสองฝั่ง) เป็นด่านสุดท้ายที่แก้จากเว็บไม่ได้
    | ค่าธรรมเนียมไม่ถูกโอนออก — ค้างในคลังเป็นส่วนเกิน (คลัง ≥ wTPIX supply เสมอ)
    */
    'limits' => [
        'min_lock' => '10',
        'max_lock_per_tx' => '100000',
        'max_lock_per_address_daily' => '200000',
        // ชดเชยค่า BNB ที่ relayer จ่ายตอน mint บน BSC
        'lock_fee' => '1',
        'min_burn' => '10',
        'max_burn_per_tx' => '100000',
        'max_burn_per_address_daily' => '200000',
        // ปล่อยบนเชน 4289 แก๊สเป็นศูนย์ — ผู้ใช้จ่าย BNB ตอนเผาเองอยู่แล้ว
        'burn_fee' => '0',
    ],

    /*
    | ขาด (supply − คลังสุทธิ) เกิน tolerance สองรอบติด = สั่งหยุด
    | mint/ปล่อยที่ระบบไม่ได้สั่ง หรือ supply เกินยอดที่ minter เคย mint = หยุดทันทีรอบแรก
    */
    'reconcile' => [
        'tolerance' => env('WTPIX_BRIDGE_RECONCILE_TOLERANCE', '1'),
        'auto_pause' => (bool) env('WTPIX_BRIDGE_RECONCILE_AUTO_PAUSE', true),
    ],

    // Locked จากที่อยู่เหล่านี้ = ย้ายเงินภายใน ไม่ mint (คั่นด้วย ,)
    'ignore_senders' => array_values(array_filter(array_map('trim', explode(',', (string) env('WTPIX_BRIDGE_IGNORE_SENDERS', ''))))),
];
