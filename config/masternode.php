<?php

/*
|--------------------------------------------------------------------------
| Masternode / Validator Auto-Allowlist Configuration
|--------------------------------------------------------------------------
|
| ระบบ auto-allowlist สำหรับ operator ของ Masternode / Validator
| - operator พิสูจน์ตัวตนผ่าน wallet signature (delegate-key pattern)
| - server verify กับ NodeRegistry contract บน TPIX Chain
| - ถ้าผ่าน → เพิ่ม IP เข้า Cloudflare allowlist TTL = HEARTBEAT_TTL
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Heartbeat
    |--------------------------------------------------------------------------
    | ttl_seconds: นานเท่าไหร่ที่ allowlist อยู่ก่อนหมดอายุ (operator ต้อง renew)
    | timestamp_window: timestamp ใน heartbeat อยู่ในกรอบ ±N วินาที (anti-replay)
    | rate_limit_per_minute: throttle จำกัด heartbeat ต่อ wallet
    */

    'heartbeat' => [
        'ttl_seconds' => env('MASTERNODE_HEARTBEAT_TTL', 3600), // 1 ชั่วโมง
        'timestamp_window' => env('MASTERNODE_TIMESTAMP_WINDOW', 300), // ±5 นาที
        'rate_limit_per_minute' => env('MASTERNODE_RATE_LIMIT', 10),
        'min_renew_interval_seconds' => env('MASTERNODE_MIN_RENEW', 60), // ห้าม heartbeat ถี่กว่านี้
    ],

    /*
    |--------------------------------------------------------------------------
    | Delegation
    |--------------------------------------------------------------------------
    | max_lifetime_seconds: delegation อยู่ได้ไม่เกินกี่วินาที (default 30 วัน)
    | message_prefix: prefix ของ delegation message (ใช้ verify signature)
    */

    'delegation' => [
        // 180 วัน: การมอบอำนาจผูกกระเป๋ากับกุญแจโหนดเพื่อ "รายงานว่ารันอยู่" เท่านั้น
        // สั้นกว่านี้ผู้ใช้ต้องกลับมาใส่รหัสกระเป๋าบ่อย ลืมเมื่อไหร่ uptime = 0 = ไม่ได้รางวัล
        'max_lifetime_seconds' => env('MASTERNODE_DELEGATION_LIFETIME', 180 * 24 * 3600),
        'message_prefix' => 'tpix-masternode-delegate',
        'heartbeat_message_prefix' => 'tpix-masternode-heartbeat',
    ],

    /*
    |--------------------------------------------------------------------------
    | Uptime Oracle — "รันโหนดจริงเท่านั้นถึงได้รางวัล"
    |--------------------------------------------------------------------------
    | heartbeat รุ่น 2 (กุญแจโหนดลงนาม + บล็อกที่โหนดเห็น) → ช่องเวลา slot_seconds
    | ทุก period_minutes: uptime = ช่องที่ผ่าน / ช่องทั้งหมด → updateUptimeBatch บนเชน
    |
    | ช่องหนึ่ง "ผ่าน" เมื่อ heartbeat ผ่าน และ (ถ้า require_peer_seen) bootnode ของเรา
    | เห็น peer ID นั้นต่ออยู่ในช่องเดียวกันหรือช่องติดกัน — แฮชบล็อกเป็นข้อมูลสาธารณะ
    | การเห็น peer ที่ bootnode คือหลักฐานเดียวว่ามีโหนดรันอยู่จริง
    |
    | ค่าที่ตั้งตอนจบรอบ H มีผลกับรางวัลของรอบ H+1 (สัญญา settle ที่ค่าเก่าก่อนเปลี่ยน)
    */

    'uptime' => [
        'slot_seconds' => 300,
        'period_minutes' => (int) env('MASTERNODE_UPTIME_PERIOD_MINUTES', 60),
        // บล็อกที่โหนดเห็นล้าหลังหัวเชนได้ไม่เกินเท่านี้ (บล็อก 2-6 วิ → 2-6 นาที)
        'max_block_lag' => (int) env('MASTERNODE_UPTIME_MAX_BLOCK_LAG', 60),
        'require_peer_seen' => (bool) env('MASTERNODE_UPTIME_REQUIRE_PEER', true),
        // bootnode ต้องรายงานครบอย่างน้อยเท่านี้ของนาทีในรอบ ไม่งั้นถือว่าข้อมูลฝั่งเราขาด
        // → รอบนั้นไม่ลด uptime ของใคร (ความผิดเรา ไม่ใช่ของผู้รันโหนด)
        'peer_feed_min_coverage' => (float) env('MASTERNODE_UPTIME_FEED_COVERAGE', 0.8),
        // NodeRegistryV2.MAX_UPTIME_BATCH = 100 (วัดจริง ~4.6M gas) — 50 เผื่อเพดานบล็อก 10M ไว้ครึ่งหนึ่ง
        'batch_size' => (int) env('MASTERNODE_UPTIME_BATCH_SIZE', 50),
        'retention_days' => (int) env('MASTERNODE_UPTIME_RETENTION_DAYS', 30),

        // Reviewed calendar-reward / signed-registration release, deployed 2026-10-26.
        // Same runtime pin as Wallet and PC; mismatches stop the oracle (fail closed).
        // ห้ามแก้เองเพื่อให้ "ผ่าน" — สัญญาใหม่ต้องผ่านการตรวจแล้วปักใหม่ทั้งแอป กระเป๋า และที่นี่
        'registry_code_hash' => strtolower((string) env(
            'MASTERNODE_REGISTRY_CODE_HASH',
            '0xd7e2e84e40b4abe29cb54e3ee0cba2cd1077a4d6b26f62a25b6f8e713c49ebd9'
        )),

        // กุญแจ oracle — ตั้งได้แค่ uptime เท่านั้น ห้ามใช้กุญแจ owner/deployer/treasury
        // keystore V3 (ethers) เซ็นผ่าน scripts/blockchain/uptime-oracle.cjs ฝั่ง CLI เท่านั้น
        'oracle' => [
            'keystore_path' => env('MASTERNODE_ORACLE_KEYSTORE_PATH'),
            'passphrase' => env('MASTERNODE_ORACLE_KEYSTORE_PASS'),
            // ที่อยู่ที่คาดว่าจะถอด keystore ได้ — ไม่ตรง = ไม่เซ็น
            'address' => env('MASTERNODE_ORACLE_ADDRESS'),
        ],
    ],

    /*
    | bootnode สาธารณะที่แอปมาสเตอร์โหนดใช้ซิงก์เชน — คั่นด้วยจุลภาค
    | รูปแบบ /ip4/<IPv4>/tcp/<port>/p2p/16Uiu2…  (polygon-edge 0.9 รับจาก genesis เท่านั้น)
    | ส่งออกทาง GET /api/v1/masternode/config — เปิด bootnode แล้วไม่ต้องปล่อยแอปใหม่
    */
    'public_bootnodes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MASTERNODE_PUBLIC_BOOTNODES', ''))
    ))),

    /*
    | bootnode รายงานรายชื่อ peer ที่ต่ออยู่ทุกนาที (POST /api/infra/node-peers)
    | token แยกจาก TPIX_INFRA_ALERT_TOKEN — หลุดตัวเดียวไม่พาอีกระบบไปด้วย
    */
    'node_peers_token' => env('TPIX_NODE_PEERS_TOKEN'),

    /*
    | GeoLite2 — แปลง IP ของ heartbeat เป็นประเทศ/เมือง (แสดงสาธารณะแค่ระดับนั้น)
    | โหลดด้วย `php artisan geoip:update` (ต้องสมัคร MaxMind ฟรี ได้ account id + license key)
    */
    'geoip' => [
        'database_path' => env('GEOIP_DATABASE_PATH', storage_path('app/geoip/GeoLite2-City.mmdb')),
        'account_id' => env('MAXMIND_ACCOUNT_ID'),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | NodeRegistry Contract
    |--------------------------------------------------------------------------
    | Address ของ NodeRegistry บน TPIX Chain (chain id 4289)
    | ถ้าไม่ตั้ง → fallback ใช้ balance check (operator ต้องมี TPIX >= min_balance)
    */

    'registry' => [
        // ตัวแปรเดียวคือ MASTERNODE_REGISTRY_ADDRESS
        // NODE_REGISTRY_ADDRESS รับไว้เป็นชื่อเก่าเพื่อไม่ให้ .env ที่ตั้งไว้แล้วพัง
        //
        // เดิมไฟล์นี้อ่าน NODE_REGISTRY_ADDRESS ส่วน config/blockchain.php อ่าน
        // MASTERNODE_REGISTRY_ADDRESS → ตั้งตัวไหนตัวเดียวก็มีอีกครึ่งระบบที่ยัง
        // คิดว่า "สัญญายังไม่ deploy" อยู่ดี
        'address' => env('MASTERNODE_REGISTRY_ADDRESS', env('NODE_REGISTRY_ADDRESS')),
        'rpc_url' => env('TPIX_RPC_URL', 'https://rpc1.tpix.online'),
        // RPC ที่ประกาศให้แอปนอกเบราว์เซอร์ใช้ผ่าน GET /api/v1/masternode/config
        // (โปรแกรมมาสเตอร์โหนดบน PC + TPIX Wallet) — rpc.tpix.online ตอบ 403 ให้ client
        // ที่ไม่ใช่เบราว์เซอร์ ส่วน rpc1 ไม่มีกฎนี้ จึงเป็นค่าเริ่มต้นของแอป
        'public_rpc_url' => env('MASTERNODE_PUBLIC_RPC_URL', 'https://rpc1.tpix.online'),
        'chain_id' => 4289,
        'fallback_min_balance_tpix' => env('MASTERNODE_FALLBACK_MIN_BALANCE', 100000), // 100K TPIX (Sentinel tier)
    ],

    /*
    |--------------------------------------------------------------------------
    | Tier Min Stakes
    |--------------------------------------------------------------------------
    | ต้องตรงกับ constructor ของ NodeRegistryV2 (tiers[] ใน src/masternode/NodeRegistryV2.sol)
    | ลำดับ index สำคัญ: enum NodeTier { Guardian=0, Sentinel=1, Light=2, Validator=3 }
    | (Guardian=0 เพื่อความเข้ากันได้กับ V1 ที่ index 0 เคยชื่อ Validator)
    |
    | ค่าที่นี่ใช้ตอนสัญญายังไม่ deploy เท่านั้น — ถ้า deploy แล้ว ระบบอ่าน
    | getTierInfo() จากเชนเป็นหลักเสมอ
    */

    'tier_index' => [
        'Guardian' => 0,
        'Sentinel' => 1,
        'Light' => 2,
        'Validator' => 3,
    ],

    'tiers' => [
        'Validator' => 10_000_000,   // 10M TPIX — IBFT2 sealer, ต้องผ่าน KYC
        'Guardian' => 1_000_000,    // 1M TPIX
        'Sentinel' => 100_000,      // 100K TPIX
        'Light' => 10_000,       // 10K TPIX
    ],

    /*
    | เพดาน APR ต่อโหนด (basis points ของ minStake) — ค่าเริ่มต้นใน constructor ของ NodeRegistryV2
    | ใช้แสดงผลตอนสัญญายังไม่ deploy เท่านั้น ถ้า deploy แล้วอ่าน tierAprCapBps() จากเชนเสมอ
    | (เจ้าของสัญญาปรับได้ด้วย setTierAprCap() ช่วง 1..5000)
    |
    | ที่ต้องมีเพดาน: ถ้าไม่มี โหนด Light ตัวแรกตัวเดียวได้ 15% ของ emission ปีแรก 600M
    | = ราว 900,000% APR ของเงินวางค้ำ 10K แล้วพูลหมดก่อนคนอื่นจะได้อะไร
    */
    'apr_cap_bps' => [
        'Guardian' => 1200,  // 12%
        'Sentinel' => 900,   // 9%
        'Light' => 600,      // 6%
        'Validator' => 2000, // 20%
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare API (สำหรับ allowlist rule)
    |--------------------------------------------------------------------------
    */

    'cloudflare' => [
        'api_token' => env('CF_API_TOKEN'),
        'zone_id' => env('CF_ZONE_ID'),
        'rule_mode' => 'whitelist', // 'whitelist' | 'allow' | 'js_challenge' | 'block'
        'rule_notes_prefix' => 'tpix-masternode-auto', // prefix สำหรับ note (ใช้ filter ตอน cleanup)

        // ถ้า true (default): ไว้ใจ header CF-Connecting-IP เฉพาะเมื่อ CF-Ray มาด้วย
        // ถ้า origin server ตั้ง firewall รับเฉพาะ CF IP ranges อยู่แล้ว → ปลอดภัย
        // ถ้า origin ถูก bypass ได้ → ตั้งเป็น false เพื่อใช้ $request->ip() อย่างเดียว
        'trust_cf_headers' => env('MASTERNODE_TRUST_CF_HEADERS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Genesis Validators (Phase 1 — hardcoded fallback)
    |--------------------------------------------------------------------------
    | ใช้ตอน NodeRegistry ยังไม่ deploy
    | Wallets เหล่านี้ได้รับ allowlist auto โดยไม่ต้องมี stake
    | (ใช้ BIP-44 wallet จาก whitepaper allocation)
    */

    'genesis_operators' => [
        '0xf54c0deE404ec728a03b467cba7bBA171CC77dad' => 'Validator', // Master Node Rewards
        '0x6E176Bf5Aa39Fb4217E0ebd00E14B67aDfFaf440' => 'Validator', // Ecosystem Development
        '0x87e62D9e0C2aF15d634D3301Dd2D4DA57972052d' => 'Guardian',  // Team & Advisors
        '0x4BcC1844Ad9E8587f7005f092928a5D14C30F463' => 'Guardian',  // Token Sale
        '0x2644A740A06e0401D21F8B4A840400fFe8dB42A9' => 'Guardian',  // Liquidity & Market Making
        '0x6dECa2E185CF37e7c838fE5Ae6897aED025c9921' => 'Sentinel',  // Community & Rewards
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'log_channel' => env('MASTERNODE_LOG_CHANNEL', 'daily'),

    /*
    |--------------------------------------------------------------------------
    | Explorer
    |--------------------------------------------------------------------------
    | ใช้ทำลิงก์ tx / address ให้ผู้ใช้กดดูหลักฐานบนเชนได้เอง
    */

    'explorer_url' => env('BLOCKSCOUT_URL', 'https://explorer.tpix.online'),
];
