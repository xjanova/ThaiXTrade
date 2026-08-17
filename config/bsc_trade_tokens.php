<?php

/*
 * TPIX TRADE — ทะเบียนเหรียญที่เทรดจริงบน BSC (chain 56)
 *
 * ⚠️ ไฟล์นี้ต้อง "ตรงกับ" resources/js/Config/bscTradeTokens.js เสมอ
 *    ฝั่ง JS คือตัวที่ส่งธุรกรรมจริงเข้า PancakeSwap ส่วนไฟล์นี้คือตัวที่ seed
 *    ลงตาราง tokens/trading_pairs ให้หลังบ้านเห็นตรงกัน
 *    มีเทสต์ BscTokenRegistrySyncTest คอยเทียบสองไฟล์นี้ — แก้ที่เดียวแล้วเทสต์จะแดง
 *
 * ทำไมต้องมีทั้งสองที่: ฝั่ง JS อ่าน address ตอน runtime ในเบราว์เซอร์ ส่วน seeder
 * เป็น PHP รันตอน deploy — แชร์ไฟล์เดียวกันไม่ได้ จึงใช้เทสต์เป็นตัวล็อกแทน
 *
 * Developed by Xman Studio.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | เชนที่ใช้เทรดจริง
    |--------------------------------------------------------------------------
    | chain_id_hex ใช้หาแถวใน chains (คอลัมน์นี้คือคีย์ที่ระบบใช้ map chainId → PK)
    */
    'chain_id' => 56,
    'chain_id_hex' => '0x38',

    /*
    |--------------------------------------------------------------------------
    | เหรียญคู่ quote
    |--------------------------------------------------------------------------
    */
    'quote' => 'USDT',

    /*
    |--------------------------------------------------------------------------
    | Binance-Peg / canonical token addresses บน BSC mainnet
    |--------------------------------------------------------------------------
    | decimals ที่นี่เป็นค่าไว้แสดงผล/ตั้งค่าเริ่มต้นเท่านั้น — ตอนเทรดจริงฝั่ง JS
    | อ่าน decimals() จาก contract ทุกครั้ง (getVerifiedTradeToken)
    |
    | native = BNB ไม่มี contract จึงใช้ address มาตรฐานของ EIP "native placeholder"
    */
    'tokens' => [
        'BNB' => ['name' => 'BNB', 'address' => '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE', 'decimals' => 18, 'native' => true, 'coingecko_id' => 'binancecoin', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/binance/info/logo.png', 'sort_order' => 12],
        'USDT' => ['name' => 'Tether USD', 'address' => '0x55d398326f99059fF775485246999027B3197955', 'decimals' => 18, 'coingecko_id' => 'tether', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0xdAC17F958D2ee523a2206206994597C13D831ec7/logo.png', 'sort_order' => 2],
        'USDC' => ['name' => 'USD Coin', 'address' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 'decimals' => 18, 'coingecko_id' => 'usd-coin', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48/logo.png', 'sort_order' => 3],
        'BTC' => ['name' => 'Bitcoin (BTCB)', 'address' => '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c', 'decimals' => 18, 'coingecko_id' => 'bitcoin', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/bitcoin/info/logo.png', 'sort_order' => 10],
        'ETH' => ['name' => 'Ethereum', 'address' => '0x2170Ed0880ac9A755fd29B2688956BD959F933F8', 'decimals' => 18, 'coingecko_id' => 'ethereum', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/info/logo.png', 'sort_order' => 11],
        'SOL' => ['name' => 'Solana', 'address' => '0x570A5D26f7765Ecb712C0924E4De545B89fD43dF', 'decimals' => 18, 'coingecko_id' => 'solana', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/solana/info/logo.png', 'sort_order' => 13],
        'XRP' => ['name' => 'XRP', 'address' => '0x1D2F0da169ceB9fC7B3144628dB156f3F6c60dBE', 'decimals' => 18, 'coingecko_id' => 'ripple', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ripple/info/logo.png', 'sort_order' => 14],
        'DOGE' => ['name' => 'Dogecoin', 'address' => '0xbA2aE424d960c26247Dd6c32edC70B295c744C43', 'decimals' => 8, 'coingecko_id' => 'dogecoin', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/doge/info/logo.png', 'sort_order' => 15],
        'ADA' => ['name' => 'Cardano', 'address' => '0x3EE2200Efb3400fAbB9AacF31297cBdD1d435D47', 'decimals' => 18, 'coingecko_id' => 'cardano', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/cardano/info/logo.png', 'sort_order' => 16],
        'POL' => ['name' => 'Polygon (ex-MATIC)', 'address' => '0xCC42724C6683B7E57334c4E856f4c9965ED682bD', 'decimals' => 18, 'coingecko_id' => 'matic-network', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/polygon/info/logo.png', 'sort_order' => 17],
        'AVAX' => ['name' => 'Avalanche', 'address' => '0x1CE0c2827e2eF14D5C4f29a091d735A204794041', 'decimals' => 18, 'coingecko_id' => 'avalanche-2', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/avalanchec/info/logo.png', 'sort_order' => 18],
        'DOT' => ['name' => 'Polkadot', 'address' => '0x7083609fCE4d1d8Dc0C979AAb8c869Ea2C873402', 'decimals' => 18, 'coingecko_id' => 'polkadot', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/polkadot/info/logo.png', 'sort_order' => 19],
        'LINK' => ['name' => 'Chainlink', 'address' => '0xF8A0BF9cF54Bb92F17374d9e9A321E6a111a51bD', 'decimals' => 18, 'coingecko_id' => 'chainlink', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x514910771AF9Ca656af840dff83E8264EcF986CA/logo.png', 'sort_order' => 20],
        'UNI' => ['name' => 'Uniswap', 'address' => '0xBf5140A22578168FD562DCcF235E5D43A02ce9B1', 'decimals' => 18, 'coingecko_id' => 'uniswap', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x1f9840a85d5aF5bf1D1762F925BDADdC4201F984/logo.png', 'sort_order' => 21],
        'LTC' => ['name' => 'Litecoin', 'address' => '0x4338665CBB7B2485A8855A139b75D5e34AB0DB94', 'decimals' => 18, 'coingecko_id' => 'litecoin', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/litecoin/info/logo.png', 'sort_order' => 22],
        'TRX' => ['name' => 'TRON', 'address' => '0xCE7de646e7208a4Ef112cb6ed5038FA6cC6b12e3', 'decimals' => 6, 'coingecko_id' => 'tron', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/tron/info/logo.png', 'sort_order' => 23],
        'ATOM' => ['name' => 'Cosmos', 'address' => '0x0Eb3a705fc54725037CC9e008bDede697f62F335', 'decimals' => 18, 'coingecko_id' => 'cosmos', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/cosmos/info/logo.png', 'sort_order' => 24],
        'NEAR' => ['name' => 'NEAR Protocol', 'address' => '0x1Fa4a73a3F0133f0025378af00236f3aBDEE5D63', 'decimals' => 18, 'coingecko_id' => 'near', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/near/info/logo.png', 'sort_order' => 25],
        'SHIB' => ['name' => 'Shiba Inu', 'address' => '0x2859e4544C4bB03966803b044A93563Bd2D0DD4D', 'decimals' => 18, 'coingecko_id' => 'shiba-inu', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x95aD61b0a150d79219dCF64E1E6Cc01f0B64C4cE/logo.png', 'sort_order' => 26],
        'PEPE' => ['name' => 'Pepe', 'address' => '0x25d887Ce7a35172C62FeBFD67a1856F20FaEbB00', 'decimals' => 18, 'coingecko_id' => 'pepe', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0x6982508145454Ce325dDbE47a25d4ec3d2311933/logo.png', 'sort_order' => 27],
    ],

    /*
    |--------------------------------------------------------------------------
    | ทศนิยมของราคาในสมุดคำสั่ง (ตามขนาดราคาของแต่ละเหรียญ)
    |--------------------------------------------------------------------------
    */
    'price_precision' => [
        'BTC' => 2, 'ETH' => 2, 'BNB' => 2, 'SOL' => 2, 'LTC' => 2, 'AVAX' => 2, 'LINK' => 2, 'ATOM' => 2,
        'XRP' => 4, 'ADA' => 4, 'DOGE' => 5, 'POL' => 4, 'DOT' => 3, 'UNI' => 3, 'TRX' => 5, 'NEAR' => 3,
        'SHIB' => 8, 'PEPE' => 8, 'USDC' => 4,
    ],
];
