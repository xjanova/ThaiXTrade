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

        /*
         * เพิ่ม 2026-11-03 — ตรวจกับเชนจริงก่อนใส่ทุกตัว: symbol() ตรง · ราคาที่ PancakeSwap V2 ให้
         * สำหรับไม้ \$100 ทั้งซื้อและขายห่างราคา Binance ไม่เกิน 2% (ตัวที่พูลบางหรือ address ปลอมตกหมด)
         * ไม้ใหญ่กว่าที่พูลรับไหวยังถูกด่าน price impact / ราคาเบี่ยง 10% ในหน้าเทรดกันไว้
         */
        'CAKE' => ['name' => 'PancakeSwap', 'address' => '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82', 'decimals' => 18, 'coingecko_id' => 'pancakeswap-token', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82/logo.png', 'sort_order' => 28],
        'INJ' => ['name' => 'Injective', 'address' => '0xa2B726B1145A4773F68593CF171187d8EBe4d495', 'decimals' => 18, 'coingecko_id' => 'injective-protocol', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xa2B726B1145A4773F68593CF171187d8EBe4d495/logo.png', 'sort_order' => 29],
        'FIL' => ['name' => 'Filecoin', 'address' => '0x0D8Ce2A99Bb6e3B7Db580eD848240e4a0F9aE153', 'decimals' => 18, 'coingecko_id' => 'filecoin', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x0D8Ce2A99Bb6e3B7Db580eD848240e4a0F9aE153/logo.png', 'sort_order' => 30],
        'AXS' => ['name' => 'Axie Infinity', 'address' => '0x715D400F88C167884bbCc41C5FeA407ed4D2f8A0', 'decimals' => 18, 'coingecko_id' => 'axie-infinity', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x715D400F88C167884bbCc41C5FeA407ed4D2f8A0/logo.png', 'sort_order' => 31],
        'EGLD' => ['name' => 'MultiversX', 'address' => '0xbF7c81FFF98BbE61B40Ed186e4AfD6DDd01337fe', 'decimals' => 18, 'coingecko_id' => 'elrond-erd-2', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xbF7c81FFF98BbE61B40Ed186e4AfD6DDd01337fe/logo.png', 'sort_order' => 32],
        'ZIL' => ['name' => 'Zilliqa', 'address' => '0xb86AbCb37C3A4B64f74f59301AFF131a1BEcC787', 'decimals' => 12, 'coingecko_id' => 'zilliqa', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xb86AbCb37C3A4B64f74f59301AFF131a1BEcC787/logo.png', 'sort_order' => 33],
        'FLOKI' => ['name' => 'FLOKI', 'address' => '0xfb5B838b6cfEEdC2873aB27866079AC55363D37E', 'decimals' => 9, 'coingecko_id' => 'floki', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xfb5B838b6cfEEdC2873aB27866079AC55363D37E/logo.png', 'sort_order' => 34],
        'TWT' => ['name' => 'Trust Wallet Token', 'address' => '0x4B0F1812e5Df2A09796481Ff14017e6005508003', 'decimals' => 18, 'coingecko_id' => 'trust-wallet-token', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x4B0F1812e5Df2A09796481Ff14017e6005508003/logo.png', 'sort_order' => 35],
        'XVS' => ['name' => 'Venus', 'address' => '0xcF6BB5389c92Bdda8a3747Ddb454cB7a64626C63', 'decimals' => 18, 'coingecko_id' => 'venus', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xcF6BB5389c92Bdda8a3747Ddb454cB7a64626C63/logo.png', 'sort_order' => 36],
        'ASTER' => ['name' => 'Aster', 'address' => '0x000Ae314E2A2172a039B26378814C252734f556A', 'decimals' => 18, 'coingecko_id' => 'aster-2', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x000Ae314E2A2172a039B26378814C252734f556A/logo.png', 'sort_order' => 37],
        'BAND' => ['name' => 'Band Protocol', 'address' => '0xAD6cAEb32CD2c308980a548bD0Bc5AA4306c6c18', 'decimals' => 18, 'coingecko_id' => 'band-protocol', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xAD6cAEb32CD2c308980a548bD0Bc5AA4306c6c18/logo.png', 'sort_order' => 38],
        'C98' => ['name' => 'Coin98', 'address' => '0xaEC945e04baF28b135Fa7c640f624f8D90F1C3a6', 'decimals' => 18, 'coingecko_id' => 'coin98', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xaEC945e04baF28b135Fa7c640f624f8D90F1C3a6/logo.png', 'sort_order' => 39],
        'SFP' => ['name' => 'SafePal', 'address' => '0xD41FDb03Ba84762dD66a0af1a6C8540FF1ba5dfb', 'decimals' => 18, 'coingecko_id' => 'safepal', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xD41FDb03Ba84762dD66a0af1a6C8540FF1ba5dfb/logo.png', 'sort_order' => 40],
        'DODO' => ['name' => 'DODO', 'address' => '0x67ee3Cb086F8a16f34beE3ca72FAD36F7Db929e2', 'decimals' => 18, 'coingecko_id' => 'dodo', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x67ee3Cb086F8a16f34beE3ca72FAD36F7Db929e2/logo.png', 'sort_order' => 41],
        'ALICE' => ['name' => 'My Neighbor Alice', 'address' => '0xAC51066d7bEC65Dc4589368da368b212745d63E8', 'decimals' => 6, 'coingecko_id' => 'my-neighbor-alice', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xAC51066d7bEC65Dc4589368da368b212745d63E8/logo.png', 'sort_order' => 42],
        'TKO' => ['name' => 'Toko Token', 'address' => '0x9f589e3eabe42ebC94A44727b3f3531C0c877809', 'decimals' => 18, 'coingecko_id' => 'tokocrypto', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x9f589e3eabe42ebC94A44727b3f3531C0c877809/logo.png', 'sort_order' => 43],
        'SANTOS' => ['name' => 'Santos FC Fan Token', 'address' => '0xA64455a4553C9034236734FadDAddbb64aCE4Cc7', 'decimals' => 8, 'coingecko_id' => 'santos-fc-fan-token', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xA64455a4553C9034236734FadDAddbb64aCE4Cc7/logo.png', 'sort_order' => 44],
        'TST' => ['name' => 'Test', 'address' => '0x86Bb94DdD16Efc8bc58e6b056e8df71D9e666429', 'decimals' => 18, 'coingecko_id' => null, 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x86Bb94DdD16Efc8bc58e6b056e8df71D9e666429/logo.png', 'sort_order' => 45],
        'BROCCOLI714' => ['name' => 'Broccoli', 'address' => '0x6d5AD1592ed9D6D1dF9b93c793AB759573Ed6714', 'decimals' => 18, 'coingecko_id' => null, 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x6d5AD1592ed9D6D1dF9b93c793AB759573Ed6714/logo.png', 'sort_order' => 46],
        'USD1' => ['name' => 'World Liberty Financial USD', 'address' => '0x8d0D000Ee44948FC98c9B98A4FA4921476f08B0d', 'decimals' => 18, 'coingecko_id' => 'usd1-wlfi', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x8d0D000Ee44948FC98c9B98A4FA4921476f08B0d/logo.png', 'sort_order' => 47],

        /*
         * เพิ่ม 2026-11-03 (PancakeSwap V3) — เกณฑ์เดียวกับชุดบน: symbol() ตรง · ไม้ \$100 ทั้งซื้อและขาย
         * ห่างราคา Binance ไม่เกิน 2% บนเส้นทางที่ดีที่สุด (V2 หรือ V3) — ส่วนใหญ่ผ่านเพราะสภาพคล่องอยู่บน V3
         * (บน V2 ตัวเดียวกันห่าง 3%–ล้าน%) · LAZIO ผ่านบน V2
         */
        'ETC' => ['name' => 'Ethereum Classic', 'address' => '0x3d6545b08693daE087E957cb1180ee38B9e3c25E', 'decimals' => 18, 'coingecko_id' => 'ethereum-classic', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x3d6545b08693daE087E957cb1180ee38B9e3c25E/logo.png', 'sort_order' => 48],
        'AAVE' => ['name' => 'Aave', 'address' => '0xfb6115445Bff7b52FeB98650C87f44907E58f802', 'decimals' => 18, 'coingecko_id' => 'aave', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xfb6115445Bff7b52FeB98650C87f44907E58f802/logo.png', 'sort_order' => 49],
        'MANA' => ['name' => 'Decentraland', 'address' => '0x26433c8127d9b4e9B71Eaa15111DF99Ea2EeB2f8', 'decimals' => 18, 'coingecko_id' => 'decentraland', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x26433c8127d9b4e9B71Eaa15111DF99Ea2EeB2f8/logo.png', 'sort_order' => 50],
        'BCH' => ['name' => 'Bitcoin Cash', 'address' => '0x8fF795a6F4D97E7887C79beA79aba5cc76444aDf', 'decimals' => 18, 'coingecko_id' => 'bitcoin-cash', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x8fF795a6F4D97E7887C79beA79aba5cc76444aDf/logo.png', 'sort_order' => 51],
        'ZEC' => ['name' => 'Zcash', 'address' => '0x1Ba42e5193dfA8B03D15dd1B86a3113bbBEF8Eeb', 'decimals' => 18, 'coingecko_id' => 'zcash', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x1Ba42e5193dfA8B03D15dd1B86a3113bbBEF8Eeb/logo.png', 'sort_order' => 52],
        'FET' => ['name' => 'Fetch.ai', 'address' => '0x031b41e504677879370e9DBcF937283A8691Fa7f', 'decimals' => 18, 'coingecko_id' => 'fetch-ai', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x031b41e504677879370e9DBcF937283A8691Fa7f/logo.png', 'sort_order' => 53],
        'PENDLE' => ['name' => 'Pendle', 'address' => '0xb3Ed0A426155B79B898849803E3B36552f7ED507', 'decimals' => 18, 'coingecko_id' => 'pendle', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xb3Ed0A426155B79B898849803E3B36552f7ED507/logo.png', 'sort_order' => 54],
        'ZRO' => ['name' => 'LayerZero', 'address' => '0x6985884C4392D348587B19cb9eAAf157F13271cd', 'decimals' => 18, 'coingecko_id' => 'layerzero', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x6985884C4392D348587B19cb9eAAf157F13271cd/logo.png', 'sort_order' => 55],
        'LISTA' => ['name' => 'Lista DAO', 'address' => '0xFceB31A79F71AC9CBDCF853519c1b12D379EdC46', 'decimals' => 18, 'coingecko_id' => 'lista', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xFceB31A79F71AC9CBDCF853519c1b12D379EdC46/logo.png', 'sort_order' => 56],
        'BONK' => ['name' => 'Bonk', 'address' => '0xA697e272a73744b343528C3Bc4702F2565b2F422', 'decimals' => 5, 'coingecko_id' => 'bonk', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xA697e272a73744b343528C3Bc4702F2565b2F422/logo.png', 'sort_order' => 57],
        'FDUSD' => ['name' => 'First Digital USD', 'address' => '0xc5f0f7b66764F6ec8C8Dff7BA683102295E16409', 'decimals' => 18, 'coingecko_id' => 'first-digital-usd', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xc5f0f7b66764F6ec8C8Dff7BA683102295E16409/logo.png', 'sort_order' => 58],
        'LAZIO' => ['name' => 'FC Lazio Fan Token', 'address' => '0x77d547256A2cD95F32F67aE0313E450Ac200648d', 'decimals' => 8, 'coingecko_id' => 'lazio-fan-token', 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x77d547256A2cD95F32F67aE0313E450Ac200648d/logo.png', 'sort_order' => 59],
        'MUBARAK' => ['name' => 'Mubarak', 'address' => '0x5C85D6C6825aB4032337F11Ee92a72DF936b46F6', 'decimals' => 18, 'coingecko_id' => null, 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x5C85D6C6825aB4032337F11Ee92a72DF936b46F6/logo.png', 'sort_order' => 60],
        'FORM' => ['name' => 'Four (FORM)', 'address' => '0x5b73A93b4E5e4f1FD27D8b3F8C97D69908b5E284', 'decimals' => 18, 'coingecko_id' => null, 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0x5b73A93b4E5e4f1FD27D8b3F8C97D69908b5E284/logo.png', 'sort_order' => 61],
        'COOKIE' => ['name' => 'Cookie DAO', 'address' => '0xC0041EF357B183448B235a8Ea73Ce4E4eC8c265F', 'decimals' => 18, 'coingecko_id' => null, 'logo' => 'https://assets-cdn.trustwallet.com/blockchains/smartchain/assets/0xC0041EF357B183448B235a8Ea73Ce4E4eC8c265F/logo.png', 'sort_order' => 62],
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
        'CAKE' => 4, 'INJ' => 3, 'FIL' => 4, 'AXS' => 4, 'EGLD' => 3, 'ZIL' => 6, 'FLOKI' => 8, 'TWT' => 4, 'XVS' => 3, 'ASTER' => 4, 'BAND' => 4, 'C98' => 5, 'SFP' => 4, 'DODO' => 5, 'ALICE' => 4, 'TKO' => 4, 'SANTOS' => 4, 'TST' => 5, 'BROCCOLI714' => 5, 'USD1' => 4,
        'ETC' => 3, 'AAVE' => 2, 'MANA' => 5, 'BCH' => 2, 'ZEC' => 2, 'FET' => 5, 'PENDLE' => 4, 'ZRO' => 4, 'LISTA' => 5, 'BONK' => 8, 'FDUSD' => 4, 'LAZIO' => 4, 'MUBARAK' => 5, 'FORM' => 4, 'COOKIE' => 6,
    ],
];
