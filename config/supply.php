<?php

/*
|--------------------------------------------------------------------------
| TPIX Supply Configuration
|--------------------------------------------------------------------------
|
| Canonical source for TPIX total / circulating / max supply. Used by:
|   - /api/v1/supply/* (CoinGecko plain-text spec)
|   - /api/v1/cmc/assets + /cmc/tickers (CoinMarketCap DEX spec)
|   - TpixPriceController (market_cap calculation)
|
| All addresses below are GENESIS allocation pools — derived from
| infrastructure/genesis.json in the TPIX-Coin repo. They are locked
| positions that should NOT count toward circulating supply until the
| tokens are distributed to end-users.
|
| Circulating supply = TOTAL_SUPPLY - sum(current RPC balance of all
| addresses in `locked_addresses`).
|
| This is a fully objective, on-chain-verifiable computation — any third
| party (CoinGecko, CMC, DeFiLlama) can reproduce it independently.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Fixed Supply Parameters
    |--------------------------------------------------------------------------
    */

    'total_supply' => '7000000000',     // string to avoid float loss
    'max_supply' => '7000000000',       // same as total — no minting possible
    'decimals' => 18,

    /*
    |--------------------------------------------------------------------------
    | Genesis-Locked Addresses
    |--------------------------------------------------------------------------
    |
    | Subtracted from total_supply to compute circulating.
    | Labels identify each allocation pool per the TPIX tokenomics plan.
    |
    */

    // ปรับเป็นชุดหลัง regenesis 2026-08-06 — ที่อยู่ชุดเดิมเป็นของเชนก่อน
    // regenesis ทุกใบมียอด 0 บนเชนปัจจุบัน ทำให้ locked = 0 และเว็บรายงาน
    // circulating = 7,000,000,000 เต็มจำนวนให้ CoinGecko / CMC
    // ที่มา: TPIX-Coin/infrastructure/genesis.json + docs/WHITEPAPER.md (ยอดตรงกันทุกใบ)
    'locked_addresses' => [
        // Validators (10M each × 4 = 40M) — pre-staked for IBFT 2.0 consensus
        [
            'address' => '0x24CD5d5A6B5EcC6520c76f5427DB06F81BcC61C5',
            'label' => 'Validator 1 — IBFT Stake',
            'initial' => '10000000',
            'category' => 'validator',
        ],
        [
            'address' => '0x394418d33641D967C3553e45Af0646d565F51Ba7',
            'label' => 'Validator 2 — IBFT Stake',
            'initial' => '10000000',
            'category' => 'validator',
        ],
        [
            'address' => '0x9D6Fc1cf3C17b495057356B95e995834248993F0',
            'label' => 'Validator 3 — IBFT Stake',
            'initial' => '10000000',
            'category' => 'validator',
        ],
        [
            'address' => '0xec91028198E8cC55B284c018aBB4B2A87c6f3F12',
            'label' => 'Validator 4 — IBFT Stake',
            'initial' => '10000000',
            'category' => 'validator',
        ],

        // Master Node Rewards + Community & Rewards (1.4B each)
        [
            'address' => '0xf54c0deE404ec728a03b467cba7bBA171CC77dad',
            'label' => 'Master Node Rewards Pool',
            'initial' => '1400000000',
            'category' => 'rewards',
        ],
        [
            'address' => '0x6dECa2E185CF37e7c838fE5Ae6897aED025c9921',
            'label' => 'Community & Airdrop Pool',
            'initial' => '1400000000',
            'category' => 'rewards',
        ],

        // Team & Advisors + Token Sale (700M each)
        [
            'address' => '0x87e62D9e0C2aF15d634D3301Dd2D4DA57972052d',
            'label' => 'Team & Advisors — Vesting',
            'initial' => '700000000',
            'category' => 'vesting',
        ],
        [
            'address' => '0x4BcC1844Ad9E8587f7005f092928a5D14C30F463',
            'label' => 'Token Sale — Vesting',
            'initial' => '700000000',
            'category' => 'vesting',
        ],

        // Liquidity & Market Making (1.05B)
        [
            'address' => '0x2644A740A06e0401D21F8B4A840400fFe8dB42A9',
            'label' => 'Liquidity & Market Making',
            'initial' => '1050000000',
            'category' => 'liquidity',
        ],

        // Ecosystem Development (1.71B = 1.75B minus 40M for validators)
        [
            'address' => '0x6E176Bf5Aa39Fb4217E0ebd00E14B67aDfFaf440',
            'label' => 'Ecosystem Development Fund',
            'initial' => '1710000000',
            'category' => 'treasury',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circulating Supply Strategy
    |--------------------------------------------------------------------------
    |
    | 'onchain'  — Query RPC in real-time: circulating = total - sum(locked balances)
    |              (Most objective, verifiable by third parties)
    |
    | 'manual'   — Use `circulating_override` (set by admin for edge cases)
    |
    */

    'strategy' => env('SUPPLY_STRATEGY', 'onchain'),
    'circulating_override' => env('TPIX_CIRCULATING_OVERRIDE'),

    /*
    |--------------------------------------------------------------------------
    | RPC & Cache
    |--------------------------------------------------------------------------
    */

    'rpc_url' => env('TPIX_RPC_URL', 'https://rpc.tpix.online'),
    'cache_ttl' => env('SUPPLY_CACHE_TTL', 60), // seconds — circulating rarely moves

    // ถามยอดทีละที่อยู่แบบ sequential — timeout ต้องคูณจำนวนที่อยู่เสมอ
    // เคยเจอ request เดียวค้าง 98 วินาทีตอน RPC ล่ม จึงตั้งเพดานให้ต่ำ
    'rpc_timeout' => env('SUPPLY_RPC_TIMEOUT', 5),
    'rpc_connect_timeout' => env('SUPPLY_RPC_CONNECT_TIMEOUT', 3),
];
