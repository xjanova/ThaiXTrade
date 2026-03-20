<?php

/**
 * Blockchain deployment configuration.
 * ใช้สำหรับ Token Factory และ contract deployment บน TPIX Chain
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Deployer Wallet
    |--------------------------------------------------------------------------
    | Private key ของ wallet ที่ใช้ deploy contracts
    | *** ห้ามเก็บใน version control ***
    */
    'deployer_private_key' => env('DEPLOYER_PRIVATE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Token Factory Contract
    |--------------------------------------------------------------------------
    | Address ของ TPIXTokenFactory contract ที่ deploy แล้วบน TPIX Chain
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

];
