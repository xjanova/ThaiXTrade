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
    | Phase 2: Factory V2 Contracts
    |--------------------------------------------------------------------------
    |
    | V2 factory สำหรับ token ทุกประเภท (ERC-20 + NFT)
    | ถ้าไม่ตั้ง V2 address → fallback ใช้ V1 สำหรับ basic ERC-20 types
    |
    */

    'factory_v2_address' => env('TOKEN_FACTORY_V2_ADDRESS'),
    'nft_factory_address' => env('NFT_FACTORY_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | TPIX Chain RPC
    |--------------------------------------------------------------------------
    */

    'tpix_rpc_url' => env('TPIX_RPC_URL', 'https://rpc.tpix.online'),
    'tpix_chain_id' => 4289,
    // RPC ที่ส่งให้เบราว์เซอร์/แอปใช้อ่านราคา DEX (เบราว์เซอร์มี User-Agent อยู่แล้ว ผ่าน Cloudflare ได้)
    'tpix_public_rpc_url' => env('TPIX_PUBLIC_RPC_URL', 'https://rpc.tpix.online'),

    /*
    |--------------------------------------------------------------------------
    | TPIX DEX (AMM แบบ Uniswap V2 บนเชน 4289)
    |--------------------------------------------------------------------------
    |
    | ค่าสำรองเมื่อไม่มีในทะเบียน SiteSetting กลุ่ม contracts (สคริปต์ deploy-dex.js
    | ลงทะเบียนให้เองผ่าน /api/infra/contracts) — ทางนี้ไว้สำหรับคนที่อยากตั้งใน .env
    | ที่อยู่ทั้งหมดอ่านผ่าน App\Services\ContractRegistry ที่เดียว ห้ามอ่าน env ตรง
    |
    */

    'dex' => [
        'wtpix' => env('TPIX_DEX_WTPIX_ADDRESS', ''),
        'usdt' => env('TPIX_DEX_USDT_ADDRESS', ''),
        'factory' => env('TPIX_DEX_FACTORY_ADDRESS', ''),
        'router' => env('TPIX_DEX_ROUTER_ADDRESS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | MasterNode Registry Contract
    |--------------------------------------------------------------------------
    */

    // ต้อง resolve เหมือน config/masternode.php ทุกประการ ไม่งั้นครึ่งระบบเห็นสัญญา อีกครึ่งไม่เห็น
    'masternode_registry' => env('MASTERNODE_REGISTRY_ADDRESS', env('NODE_REGISTRY_ADDRESS', '')),

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
        'utility' => 10,
        'reward' => 11,
        'nft' => 20,
        'nft_collection' => 21,
        'governance' => 30,
        'stablecoin' => 31,
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
            // Sub-options fees
            'pausable' => 5,
            'blacklist' => 5,
            'tax_system' => 15,
            'anti_whale' => 10,
            'anti_bot' => 10,
            'auto_liquidity' => 15,
            'reflection' => 20,
            'vesting' => 10,
            'royalty' => 5,
            'delayed_reveal' => 10,
            'treasury' => 15,
            'delegation' => 5,
            'soulbound' => 5,
            'freeze' => 5,
            'kyc' => 5,
            'auto_burn' => 10,
        ],

        // Supply thresholds สำหรับ large supply fee
        'large_supply_threshold' => 1000000000,        // 1 Billion
        'very_large_supply_threshold' => 100000000000,  // 100 Billion
    ],

    /*
    |--------------------------------------------------------------------------
    | นโยบายโรงงานสร้างเหรียญ — ค่าตั้งต้น (แอดมินแก้ได้ที่ /admin/token-factory/policy)
    |--------------------------------------------------------------------------
    |
    | เจ้าของสั่ง: ปิดทุกอย่างที่เสี่ยงไว้ก่อน แอดมินเปิด/ปิดเองทีละตัว
    | ค่าที่แอดมินบันทึกเก็บใน site_settings (token_factory_policy.policy) และชนะค่าตรงนี้
    | ของที่ "ล็อกถาวร" (utility, reward, stablecoin, auto_burn) อยู่ในโค้ด
    | App\Services\TokenFactory\TokenFactoryPolicy::LOCKED — แก้ตรงนี้ให้เป็น on ก็ไม่มีผล
    |
    | state: on = เปิด · off = ปิด (ค่าตั้งต้นของของเสี่ยงทุกตัว)
    | fee: TPIX (native บนเชน 4289) · ค่าธรรมเนียมรวม = ค่าประเภท + ค่าตัวเลือกที่เลือก
    | max_supply: จำนวนเหรียญสูงสุด (หน่วยเต็ม ไม่ใช่ wei) เป็น string กันเลขใหญ่เพี้ยน
    |
    */

    'token_factory_policy' => [
        // null = ใช้สวิตช์เดิม factory.creation_enabled จนกว่าแอดมินจะบันทึกหน้านโยบาย
        'creation_enabled' => null,
        // ว่าง = ใช้ factory.fee_wallet → revenue.tpix_wallet → revenue.wallet_address
        'fee_wallet' => '',
        'required_confirmations' => (int) env('TOKEN_FACTORY_FEE_CONFIRMATIONS', 2),
        'quote_ttl_minutes' => 30,
        'daily_limit_per_wallet' => 3,
        'name_max_length' => 50,
        'symbol_max_length' => 11,
        // ลิสต์ขึ้นหน้า Explore + เปิดคู่เทรดใน dex:sync ทันทีที่ deploy เสร็จหรือไม่ (ค่าตั้งต้น: แอดมินตัดสินเอง)
        'auto_list_on_deploy' => false,
        'require_kyc' => false,
        // สัญลักษณ์สงวน => กระเป๋าที่อนุญาตให้ใช้ (ว่าง = ไม่มีใครใช้ได้)
        'reserved_symbols' => [
            'TPIX' => [], 'WTPIX' => [], 'USDT' => [], 'USDC' => [],
            'BTC' => [], 'ETH' => [], 'BNB' => [], 'IDTC' => [],
        ],
        'types' => [
            'erc20' => ['state' => 'on', 'fee' => 50, 'max_supply' => '1000000000000000', 'decimals_min' => 18, 'decimals_max' => 18, 'auto_approve' => false, 'require_kyc' => false],
            'utility' => ['state' => 'off', 'fee' => 60, 'max_supply' => '1000000000000000', 'decimals_min' => 18, 'decimals_max' => 18, 'auto_approve' => false, 'require_kyc' => false],
            'reward' => ['state' => 'off', 'fee' => 60, 'max_supply' => '1000000000000000', 'decimals_min' => 18, 'decimals_max' => 18, 'auto_approve' => false, 'require_kyc' => false],
            'governance' => ['state' => 'off', 'fee' => 130, 'max_supply' => '1000000000000000', 'decimals_min' => 18, 'decimals_max' => 18, 'auto_approve' => false, 'require_kyc' => false],
            'stablecoin' => ['state' => 'off', 'fee' => 150, 'max_supply' => '1000000000000000', 'decimals_min' => 6, 'decimals_max' => 18, 'auto_approve' => false, 'require_kyc' => false],
            'nft' => ['state' => 'off', 'fee' => 80, 'max_supply' => '10000', 'decimals_min' => 0, 'decimals_max' => 0, 'auto_approve' => false, 'require_kyc' => false],
            'nft_collection' => ['state' => 'off', 'fee' => 100, 'max_supply' => '100000', 'decimals_min' => 0, 'decimals_max' => 0, 'auto_approve' => false, 'require_kyc' => false],
        ],
        'options' => [
            // ผู้ถือเผาเหรียญของตัวเอง — ไม่มีอำนาจเหนือคนอื่น จึงเปิดไว้
            'burnable' => ['state' => 'on', 'fee' => 0],
            'mintable' => ['state' => 'off', 'fee' => 20],
            'mint_cap' => ['state' => 'off', 'fee' => 0],
            'pausable' => ['state' => 'off', 'fee' => 5],
            'blacklist' => ['state' => 'off', 'fee' => 5],
            'auto_burn' => ['state' => 'off', 'fee' => 10],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blockscout Explorer API (สำหรับ auto-verify contract)
    |--------------------------------------------------------------------------
    */

    'explorer_api_url' => env('BLOCKSCOUT_API_URL', 'https://explorer.tpix.online/api'),
    'explorer_verify_enabled' => env('BLOCKSCOUT_VERIFY_ENABLED', true),
];
