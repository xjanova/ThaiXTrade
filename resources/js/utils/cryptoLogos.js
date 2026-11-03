/**
 * TPIX TRADE - Crypto Logo Helper
 *
 * ลำดับการหาโลโก้ (CoinIcon.vue เป็นคนไล่ทีละชั้น):
 *   1. `src` ที่แอดมินตั้งเองใน /admin/tokens  (มาจาก API pairs → base_logo)
 *   2. getCoinLogo()          → โลโก้ในเครื่อง (TPIX) หรือ CoinCap CDN
 *   3. getCoinLogoFallback()  → CryptoLogos.cc หรือ Trust Wallet (ตาม address บน BSC)
 *   4. ตัวอักษรแรกของเหรียญ
 *
 * ทุกแหล่งเป็น CDN สาธารณะที่ hotlink ได้ ไม่ต้อง auth
 *
 * Developed by Xman Studio
 */

import { TPIX_COIN_ICON } from '@/utils/brand';

// CDN sources
const COINCAP_CDN = 'https://assets.coincap.io/assets/icons';
const CRYPTOLOGOS = 'https://cryptologos.cc/logos';
// ใช้ CDN ของ Trust Wallet ไม่ใช่ raw.githubusercontent — ตัวหลังเป็น raw file host
// ที่มี rate limit และไม่ได้ตั้งใจให้ hotlink จากเว็บโปรดักชัน
const TW_ASSETS = 'https://assets-cdn.trustwallet.com';

/**
 * Known symbol → CryptoLogos.cc slug mappings
 * ใช้เมื่อ CoinCap ไม่มีโลโก้
 */
const CRYPTOLOGOS_MAP = {
    BTC: 'bitcoin-btc-logo.png',
    ETH: 'ethereum-eth-logo.png',
    BNB: 'bnb-bnb-logo.png',
    USDT: 'tether-usdt-logo.png',
    USDC: 'usd-coin-usdc-logo.png',
    SOL: 'solana-sol-logo.png',
    XRP: 'xrp-xrp-logo.png',
    ADA: 'cardano-ada-logo.png',
    DOGE: 'dogecoin-doge-logo.png',
    DOT: 'polkadot-new-dot-logo.png',
    MATIC: 'polygon-matic-logo.png',
    // POL คือ MATIC ที่เปลี่ยนชื่อ — CryptoLogos ยังใช้ slug เดิมอยู่
    POL: 'polygon-matic-logo.png',
    AVAX: 'avalanche-avax-logo.png',
    LINK: 'chainlink-link-logo.png',
    UNI: 'uniswap-uni-logo.png',
    ATOM: 'cosmos-atom-logo.png',
    LTC: 'litecoin-ltc-logo.png',
    FIL: 'filecoin-fil-logo.png',
    APT: 'aptos-apt-logo.png',
    ARB: 'arbitrum-arb-logo.png',
    OP: 'optimism-ethereum-op-logo.png',
    NEAR: 'near-protocol-near-logo.png',
    AAVE: 'aave-aave-logo.png',
    CAKE: 'pancakeswap-cake-logo.png',
    TRX: 'tron-trx-logo.png',
    SHIB: 'shiba-inu-shib-logo.png',
    DAI: 'multi-collateral-dai-dai-logo.png',
    CRO: 'cronos-cro-logo.png',
    ALGO: 'algorand-algo-logo.png',
    FTM: 'fantom-ftm-logo.png',
    MANA: 'decentraland-mana-logo.png',
    SAND: 'the-sandbox-sand-logo.png',
    AXS: 'axie-infinity-axs-logo.png',
    '1INCH': '1inch-1inch-logo.png',
    SUSHI: 'sushiswap-sushi-logo.png',
    COMP: 'compound-comp-logo.png',
    MKR: 'maker-mkr-logo.png',
    SNX: 'synthetix-network-token-snx-logo.png',
    GRT: 'the-graph-grt-logo.png',
    ENJ: 'enjin-coin-enj-logo.png',
    ZEC: 'zcash-zec-logo.png',
    PEPE: 'pepe-pepe-logo.png',
    WIF: 'dogwifhat-wif-logo.png',
    SUI: 'sui-sui-logo.png',
    SEI: 'sei-sei-logo.png',
    INJ: 'injective-inj-logo.png',
    TIA: 'celestia-tia-logo.png',
    RENDER: 'render-token-rndr-logo.png',
    FET: 'fetch-ai-fet-logo.png',
    TAO: 'bittensor-tao-logo.png',
    BONK: 'bonk-bonk-logo.png',
    FLOKI: 'floki-inu-floki-logo.png',
    ETC: 'ethereum-classic-etc-logo.png',
    XLM: 'stellar-xlm-logo.png',
    HBAR: 'hedera-hbar-logo.png',
    VET: 'vechain-vet-logo.png',
    ICP: 'internet-computer-icp-logo.png',
    RUNE: 'thorchain-rune-logo.png',
    KUB: 'bitkub-coin-kub-logo.png',
};

/**
 * BSC token addresses สำหรับ fallback ผ่าน Trust Wallet
 *
 * ต้องครอบคลุม "ทุกเหรียญที่เปิดเทรดจริง" เป็นอย่างน้อย — ไม่งั้นถ้า CoinCap
 * กับ CryptoLogos ล่มพร้อมกัน เหรียญที่เทรดได้จะเหลือแค่ตัวอักษร
 * address ชุดนี้ตรงกับ Config/bscTradeTokens.js (ทะเบียนที่ใช้ส่งธุรกรรมจริง)
 */
const BSC_TOKEN_ADDRESSES = {
    USDT: '0x55d398326f99059fF775485246999027B3197955',
    USDC: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
    ETH: '0x2170Ed0880ac9A755fd29B2688956BD959F933F8',
    BTC: '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c',
    SOL: '0x570A5D26f7765Ecb712C0924E4De545B89fD43dF',
    XRP: '0x1D2F0da169ceB9fC7B3144628dB156f3F6c60dBE',
    DOGE: '0xbA2aE424d960c26247Dd6c32edC70B295c744C43',
    ADA: '0x3EE2200Efb3400fAbB9AacF31297cBdD1d435D47',
    POL: '0xCC42724C6683B7E57334c4E856f4c9965ED682bD',
    AVAX: '0x1CE0c2827e2eF14D5C4f29a091d735A204794041',
    DOT: '0x7083609fCE4d1d8Dc0C979AAb8c869Ea2C873402',
    LINK: '0xF8A0BF9cF54Bb92F17374d9e9A321E6a111a51bD',
    UNI: '0xBf5140A22578168FD562DCcF235E5D43A02ce9B1',
    LTC: '0x4338665CBB7B2485A8855A139b75D5e34AB0DB94',
    TRX: '0xCE7de646e7208a4Ef112cb6ed5038FA6cC6b12e3',
    ATOM: '0x0Eb3a705fc54725037CC9e008bDede697f62F335',
    NEAR: '0x1Fa4a73a3F0133f0025378af00236f3aBDEE5D63',
    SHIB: '0x2859e4544C4bB03966803b044A93563Bd2D0DD4D',
    PEPE: '0x25d887Ce7a35172C62FeBFD67a1856F20FaEbB00',
    CAKE: '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82',
    DAI: '0x1AF3F329e8BE154074D8769D1FFa4eE058B1DBc3',
    AAVE: '0xfb6115445Bff7b52FeB98650C87f44907E58f802',
    INJ: '0xa2B726B1145A4773F68593CF171187d8EBe4d495',
    FIL: '0x0D8Ce2A99Bb6e3B7Db580eD848240e4a0F9aE153',
    AXS: '0x715D400F88C167884bbCc41C5FeA407ed4D2f8A0',
    EGLD: '0xbF7c81FFF98BbE61B40Ed186e4AfD6DDd01337fe',
    ZIL: '0xb86AbCb37C3A4B64f74f59301AFF131a1BEcC787',
    FLOKI: '0xfb5B838b6cfEEdC2873aB27866079AC55363D37E',
    TWT: '0x4B0F1812e5Df2A09796481Ff14017e6005508003',
    XVS: '0xcF6BB5389c92Bdda8a3747Ddb454cB7a64626C63',
    ASTER: '0x000Ae314E2A2172a039B26378814C252734f556A',
    BAND: '0xAD6cAEb32CD2c308980a548bD0Bc5AA4306c6c18',
    C98: '0xaEC945e04baF28b135Fa7c640f624f8D90F1C3a6',
    SFP: '0xD41FDb03Ba84762dD66a0af1a6C8540FF1ba5dfb',
    DODO: '0x67ee3Cb086F8a16f34beE3ca72FAD36F7Db929e2',
    ALICE: '0xAC51066d7bEC65Dc4589368da368b212745d63E8',
    TKO: '0x9f589e3eabe42ebC94A44727b3f3531C0c877809',
    SANTOS: '0xA64455a4553C9034236734FadDAddbb64aCE4Cc7',
    TST: '0x86Bb94DdD16Efc8bc58e6b056e8df71D9e666429',
    BROCCOLI714: '0x6d5AD1592ed9D6D1dF9b93c793AB759573Ed6714',
    USD1: '0x8d0D000Ee44948FC98c9B98A4FA4921476f08B0d',
    ETC: '0x3d6545b08693daE087E957cb1180ee38B9e3c25E',
    MANA: '0x26433c8127d9b4e9B71Eaa15111DF99Ea2EeB2f8',
    BCH: '0x8fF795a6F4D97E7887C79beA79aba5cc76444aDf',
    ZEC: '0x1Ba42e5193dfA8B03D15dd1B86a3113bbBEF8Eeb',
    FET: '0x031b41e504677879370e9DBcF937283A8691Fa7f',
    PENDLE: '0xb3Ed0A426155B79B898849803E3B36552f7ED507',
    ZRO: '0x6985884C4392D348587B19cb9eAAf157F13271cd',
    LISTA: '0xFceB31A79F71AC9CBDCF853519c1b12D379EdC46',
    BONK: '0xA697e272a73744b343528C3Bc4702F2565b2F422',
    FDUSD: '0xc5f0f7b66764F6ec8C8Dff7BA683102295E16409',
    LAZIO: '0x77d547256A2cD95F32F67aE0313E450Ac200648d',
    MUBARAK: '0x5C85D6C6825aB4032337F11Ee92a72DF936b46F6',
    FORM: '0x5b73A93b4E5e4f1FD27D8b3F8C97D69908b5E284',
    COOKIE: '0xC0041EF357B183448B235a8Ea73Ce4E4eC8c265F',
};

/**
 * โลโก้ในเครื่องของเหรียญในระบบนิเวศ TPIX
 *
 * โลโก้เหรียญ TPIX = ตราเดียวกับแพลตฟอร์ม (ตัวย่อ) — ที่อยู่ไฟล์อยู่ใน utils/brand.js
 *    ของเดิมชี้ /logo.png ซึ่งเป็นโลโก้แบรนด์ TPIX TRADE ขนาด 333KB — ผิดทั้งภาพ
 *    และหนักเกินไปสำหรับไอคอนขนาด 24px ในตาราง
 */
const LOCAL_LOGOS = {
    TPIX: TPIX_COIN_ICON,
    WTPIX: TPIX_COIN_ICON,
};

/**
 * Symbol ที่ต้อง map ไปหาเหรียญฐานก่อนค้นหาใน CDN
 */
const SYMBOL_MAP = {
    MATIC: 'matic',
    '1INCH': '1inch',
    WBNB: 'BNB',
    WETH: 'ETH',
    WBTC: 'BTC',
    BTCB: 'BTC',
    'BSC-USD': 'USDT',
};

/** ตัดคู่เทรด/ช่องว่างออกให้เหลือ symbol ล้วน */
function normalize(symbol) {
    return String(symbol || '').trim().toUpperCase().replace(/[/-].*$/, '');
}

/**
 * โลโก้แหล่งหลัก: ในเครื่อง → CoinCap CDN
 * @param {string} symbol
 * @returns {string}
 */
export function getCoinLogo(symbol) {
    const upper = normalize(symbol);
    if (!upper) return '';

    if (LOCAL_LOGOS[upper]) return LOCAL_LOGOS[upper];

    const mapped = SYMBOL_MAP[upper] || upper;

    // เหรียญที่ map แล้วเป็นของ TPIX เอง (เช่น WTPIX) ยังต้องใช้ไฟล์ในเครื่อง
    if (LOCAL_LOGOS[mapped]) return LOCAL_LOGOS[mapped];

    return `${COINCAP_CDN}/${mapped.toLowerCase()}@2x.png`;
}

/**
 * แหล่งสำรองชั้นที่สอง — ใช้เมื่อรูปจาก getCoinLogo โหลดไม่สำเร็จ (@error)
 * @param {string} symbol
 * @returns {string|null} null = ไม่มีแหล่งสำรอง ให้ใช้ตัวอักษรแทน
 */
export function getCoinLogoFallback(symbol) {
    const upper = normalize(symbol);
    if (!upper) return null;

    const mapped = SYMBOL_MAP[upper] || upper;

    // เหรียญในเครื่องไม่มีแหล่งสำรอง (และไม่ต้องมี)
    if (LOCAL_LOGOS[mapped] || LOCAL_LOGOS[upper]) return null;

    if (CRYPTOLOGOS_MAP[mapped]) {
        return `${CRYPTOLOGOS}/${CRYPTOLOGOS_MAP[mapped]}?v=040`;
    }

    if (BSC_TOKEN_ADDRESSES[mapped]) {
        return getBSCTokenLogo(BSC_TOKEN_ADDRESSES[mapped]);
    }

    return null;
}

/**
 * โลโก้ token บน BSC จาก Trust Wallet Assets
 * @param {string} contractAddress
 * @returns {string}
 */
export function getBSCTokenLogo(contractAddress) {
    if (!contractAddress) return '';
    return `${TW_ASSETS}/blockchains/smartchain/assets/${contractAddress}/logo.png`;
}

/**
 * @param {string} symbol
 * @returns {string|null}
 */
export function getCoinLogoOrNull(symbol) {
    return getCoinLogo(symbol) || null;
}

/**
 * มีโลโก้ "ที่รู้จักจริง" ของเหรียญนี้ไหม (ไม่ใช่แค่เดา URL จาก symbol)
 *
 * ของเดิมคืน true ให้ทุก symbol ที่ไม่ว่าง ซึ่งทำให้ค่าที่คืนไม่มีความหมาย
 * ใครเรียกไปเช็คก็ได้คำตอบว่า "มี" เสมอ
 * @param {string} symbol
 * @returns {boolean}
 */
export function hasCoinLogo(symbol) {
    const upper = normalize(symbol);
    if (!upper) return false;

    const mapped = SYMBOL_MAP[upper] || upper;

    return !!(LOCAL_LOGOS[upper] || LOCAL_LOGOS[mapped]
        || CRYPTOLOGOS_MAP[mapped] || BSC_TOKEN_ADDRESSES[mapped]);
}

/**
 * ดึง symbol ฐานจากคู่เทรด ("BTC/USDT" หรือ "BTC-USDT" → "BTC")
 * @param {string} pair
 * @returns {string}
 */
export function getBaseSymbol(pair) {
    return normalize(pair);
}

/**
 * โลโก้ของคู่เทรด (ใช้โลโก้เหรียญฐาน)
 * @param {string} pair
 * @returns {string}
 */
export function getPairLogo(pair) {
    return getCoinLogo(getBaseSymbol(pair));
}

export default {
    getCoinLogo,
    getCoinLogoFallback,
    getCoinLogoOrNull,
    getBSCTokenLogo,
    hasCoinLogo,
    getBaseSymbol,
    getPairLogo,
};
