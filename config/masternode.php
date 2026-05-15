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
        'max_lifetime_seconds' => env('MASTERNODE_DELEGATION_LIFETIME', 30 * 24 * 3600), // 30 วัน
        'message_prefix' => 'tpix-masternode-delegate',
        'heartbeat_message_prefix' => 'tpix-masternode-heartbeat',
    ],

    /*
    |--------------------------------------------------------------------------
    | NodeRegistry Contract
    |--------------------------------------------------------------------------
    | Address ของ NodeRegistry บน TPIX Chain (chain id 4289)
    | ถ้าไม่ตั้ง → fallback ใช้ balance check (operator ต้องมี TPIX >= min_balance)
    */

    'registry' => [
        'address' => env('NODE_REGISTRY_ADDRESS'),
        'rpc_url' => env('TPIX_RPC_URL', 'https://rpc.tpix.online'),
        'chain_id' => 4289,
        'fallback_min_balance_tpix' => env('MASTERNODE_FALLBACK_MIN_BALANCE', 100000), // 100K TPIX (Light tier)
    ],

    /*
    |--------------------------------------------------------------------------
    | Tier Min Stakes (ถ้า NodeRegistry ยังไม่ deploy ใช้ balance check)
    |--------------------------------------------------------------------------
    */

    'tiers' => [
        'Validator' => 10_000_000,   // 10M TPIX
        'Guardian'  => 1_000_000,    // 1M TPIX
        'Sentinel'  => 100_000,      // 100K TPIX
        'Light'     => 10_000,       // 10K TPIX
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
];
