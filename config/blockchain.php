<?php

/*
|--------------------------------------------------------------------------
| Blockchain Deployment Configuration
|--------------------------------------------------------------------------
|
| ใช้สำหรับ Token Factory และ contract deployment บน TPIX Chain.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Deployer Wallet
    |--------------------------------------------------------------------------
    */

    'deployer_private_key' => env('DEPLOYER_PRIVATE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Token Factory Contract
    |--------------------------------------------------------------------------
    */

    'factory_address' => env('TOKEN_FACTORY_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | TPIX Chain RPC
    |--------------------------------------------------------------------------
    */

    'tpix_rpc_url' => env('TPIX_RPC_URL', 'https://rpc.tpix.online'),
    'tpix_chain_id' => 4289,

    /*
    |--------------------------------------------------------------------------
    | MasterNode Registry Contract
    |--------------------------------------------------------------------------
    */

    'masternode_registry' => env('MASTERNODE_REGISTRY_ADDRESS', ''),

    /*
    |--------------------------------------------------------------------------
    | Node.js path
    |--------------------------------------------------------------------------
    */

    'node_path' => env('NODE_PATH', 'node'),

    /*
    |--------------------------------------------------------------------------
    | Token type mapping
    |--------------------------------------------------------------------------
    */

    'token_types' => [
        'standard' => 0,
        'mintable' => 1,
        'burnable' => 2,
        'mintable_burnable' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Testnet Configuration
    |--------------------------------------------------------------------------
    |
    | Chain IDs ที่ถือว่าเป็น testnet — สร้าง token ฟรี, reset ทุก N เดือน
    |
    */

    'testnet_chain_ids' => [
        4290,       // TPIX Testnet
        11155111,   // Sepolia
        97,         // BSC Testnet
    ],

    'testnet_reset_months' => (int) env('TESTNET_RESET_MONTHS', 3),

    /*
    |--------------------------------------------------------------------------
    | Dynamic Token Creation Fee (TPIX)
    |--------------------------------------------------------------------------
    |
    | ค่าธรรมเนียมสร้างเหรียญแบบ dynamic — ยิ่งเลือกออฟชั่นเยอะ ยิ่งเสียเพิ่ม
    | Admin สามารถ override ผ่าน SiteSettings (factory group) ได้
    |
    | fee = base_fee + category_fee + type_fee + option_fees
    |
    */

    'creation_fees' => [
        // ค่าพื้นฐาน (ทุก token ต้องจ่าย)
        'base_fee' => (float) env('TOKEN_FACTORY_BASE_FEE', 50),

        // ค่าเพิ่มตาม category
        'category_fees' => [
            'fungible' => 0,       // ไม่เพิ่ม — category ปกติ
            'nft' => 30,           // +30 TPIX — NFT ต้องจัดการ metadata เพิ่ม
            'special' => 50,       // +50 TPIX — governance/stablecoin ซับซ้อน
        ],

        // ค่าเพิ่มตาม token type (ฟังก์ชันที่เพิ่มเข้ามา)
        'type_fees' => [
            'standard' => 0,             // base เท่านั้น
            'mintable' => 20,            // +20 — เพิ่ม mint function
            'burnable' => 20,            // +20 — เพิ่ม burn function
            'mintable_burnable' => 35,   // +35 — ทั้ง mint+burn (ลดจาก 40 เพราะ bundle)
            'utility' => 10,             // +10
            'reward' => 10,              // +10
            'nft' => 0,                  // ค่าเพิ่มอยู่ใน category แล้ว
            'nft_collection' => 20,      // +20 — collection ต้อง enumerate
            'governance' => 30,          // +30 — voting mechanism
            'stablecoin' => 50,          // +50 — reserve management
        ],

        // ค่าเพิ่มตาม option พิเศษ
        'option_fees' => [
            'custom_decimals' => 5,      // +5 ถ้าไม่ใช่ 18 (non-standard)
            'has_description' => 0,      // ฟรี
            'has_website' => 0,          // ฟรี
            'has_logo' => 0,             // ฟรี
            'large_supply' => 10,        // +10 ถ้า supply > 1B
            'very_large_supply' => 25,   // +25 ถ้า supply > 100B
        ],

        // Supply thresholds สำหรับ large supply fee
        'large_supply_threshold' => 1000000000,        // 1 Billion
        'very_large_supply_threshold' => 100000000000,  // 100 Billion
    ],

    /*
    |--------------------------------------------------------------------------
    | Blockscout Explorer API (สำหรับ auto-verify contract)
    |--------------------------------------------------------------------------
    */

    'explorer_api_url' => env('BLOCKSCOUT_API_URL', 'https://explorer.tpix.online/api'),
    'explorer_verify_enabled' => env('BLOCKSCOUT_VERIFY_ENABLED', true),
];
