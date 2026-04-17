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

    'locked_addresses' => [
        // Validators (10M each × 4 = 40M) — pre-staked for IBFT 2.0 consensus
        [
            'address' => '0x11993fa65fFD72eFe8C0226429C8d83Ee52b1309',
            'label' => 'Validator 1 — IBFT Stake',
            'initial' => '10000000',
            'category' => 'validator',
        ],
        [
            'address' => '0x7A67ACEa14ddA82B14275DceeB681dbf4A532F03',
            'label' => 'Validator 2 — IBFT Stake',
            'initial' => '10000000',
            'category' => 'validator',
        ],
        [
            'address' => '0xafF40043811B634628b50d8Bf4e4bC98DFd6332F',
            'label' => 'Validator 3 — IBFT Stake',
            'initial' => '10000000',
            'category' => 'validator',
        ],
        [
            'address' => '0xcFc840b2F756fD369C28cC54Cb04A9A9D76a99b9',
            'label' => 'Validator 4 — IBFT Stake',
            'initial' => '10000000',
            'category' => 'validator',
        ],

        // Master Node Rewards + Community & Rewards (1.4B each)
        [
            'address' => '0x2112b98e3ec5A252b7b2A8f02d498B64a2186A7f',
            'label' => 'Master Node Rewards Pool',
            'initial' => '1400000000',
            'category' => 'rewards',
        ],
        [
            'address' => '0xA945d1bE9c1DDeaE75BBb9B39981D1CE6Ed7d9d5',
            'label' => 'Community & Airdrop Pool',
            'initial' => '1400000000',
            'category' => 'rewards',
        ],

        // Team & Advisors + Token Sale (700M each)
        [
            'address' => '0x3F8EB4046F5C79fd0D67C7547B5830cB2Cfb401A',
            'label' => 'Team & Advisors — Vesting',
            'initial' => '700000000',
            'category' => 'vesting',
        ],
        [
            'address' => '0xf46131C82819d7621163F482b3fe88a228A7807c',
            'label' => 'Token Sale — Vesting',
            'initial' => '700000000',
            'category' => 'vesting',
        ],

        // Liquidity & Market Making (1.05B)
        [
            'address' => '0x3da3776e0AB0F442c181aa031f47FA83696859AF',
            'label' => 'Liquidity & Market Making',
            'initial' => '1050000000',
            'category' => 'liquidity',
        ],

        // Ecosystem Development (1.71B = 1.75B minus 40M for validators)
        [
            'address' => '0xD2eAB07809921fcB36c7AB72D7B5D8D2C12A67d7',
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
];
