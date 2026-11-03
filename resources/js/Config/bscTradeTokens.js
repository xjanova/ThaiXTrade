/**
 * TPIX TRADE - BSC Trade Token Registry
 * แผนที่เหรียญ major (คู่เทรดในหน้า Trade) → token address บน BSC
 * ใช้กับ market order ที่ execute จริงผ่าน PancakeSwap (V2 หรือ V3 — useSwap เลือกเส้นทางที่ได้มากสุด)
 *
 * ความปลอดภัย (อย่าตัดออก):
 *  - decimals ในไฟล์นี้เป็นแค่ค่า fallback สำหรับแสดงผล — ค่าจริงอ่านจาก
 *    on-chain ทุกครั้งผ่าน getVerifiedTradeToken() (กัน decimals ผิดจนยอดเพี้ยน)
 *  - ก่อนเทรดต้องผ่านการตรวจ symbol() on-chain ให้ตรงกับ onchainSymbols
 *    ถ้าไม่ตรง = address ผิด/ถูกสลับ → บล็อกเทรดทันที (fail-closed)
 *  - ชั้นสุดท้าย: Trade.vue เทียบราคา effective จาก router กับราคา Binance
 *    ถ้าเบี่ยงเกินเกณฑ์จะไม่ส่งธุรกรรม
 *
 * Developed by Xman Studio
 */

import { Contract } from 'ethers';
import { NATIVE_TOKEN_ADDRESS, withBscReadFallback } from '@/utils/web3';
import { t } from '@/Composables/useTranslation';

/*
 * ข้อความ error ที่ติดธง isFriendly ถูกแสดงตรงๆ บนฟอร์มเทรด
 * เดิมเป็นอังกฤษล้วน ผู้ใช้หน้าไทยเห็น "Unable to verify token on BSC" กลางหน้าไทย
 */
function friendly(key, params) {
    const err = new Error(t(key, params));
    err.isFriendly = true;
    return err;
}

// Binance-Peg / canonical token addresses บน BSC mainnet (chain 56)
// onchainSymbols = symbol() ที่ยอมรับได้จาก contract จริง (บางตัวไม่ตรงชื่อที่โชว์
// เช่น POL ยังใช้สัญญา MATIC เดิม, USDT บางแหล่งรายงานเป็น BSC-USD)
export const BSC_TRADE_TOKENS = {
    BNB: { name: 'BNB', address: NATIVE_TOKEN_ADDRESS, decimals: 18, native: true },
    USDT: { name: 'Tether USD', address: '0x55d398326f99059fF775485246999027B3197955', decimals: 18, onchainSymbols: ['USDT', 'BSC-USD'] },
    USDC: { name: 'USD Coin', address: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', decimals: 18, onchainSymbols: ['USDC'] },
    BTC: { name: 'Bitcoin (BTCB)', address: '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c', decimals: 18, onchainSymbols: ['BTCB'] },
    ETH: { name: 'Ethereum', address: '0x2170Ed0880ac9A755fd29B2688956BD959F933F8', decimals: 18, onchainSymbols: ['ETH'] },
    SOL: { name: 'Solana', address: '0x570A5D26f7765Ecb712C0924E4De545B89fD43dF', decimals: 18, onchainSymbols: ['SOL'] },
    XRP: { name: 'XRP', address: '0x1D2F0da169ceB9fC7B3144628dB156f3F6c60dBE', decimals: 18, onchainSymbols: ['XRP'] },
    DOGE: { name: 'Dogecoin', address: '0xbA2aE424d960c26247Dd6c32edC70B295c744C43', decimals: 8, onchainSymbols: ['DOGE'] },
    ADA: { name: 'Cardano', address: '0x3EE2200Efb3400fAbB9AacF31297cBdD1d435D47', decimals: 18, onchainSymbols: ['ADA'] },
    POL: { name: 'Polygon (ex-MATIC)', address: '0xCC42724C6683B7E57334c4E856f4c9965ED682bD', decimals: 18, onchainSymbols: ['MATIC', 'POL'] },
    AVAX: { name: 'Avalanche', address: '0x1CE0c2827e2eF14D5C4f29a091d735A204794041', decimals: 18, onchainSymbols: ['AVAX'] },
    DOT: { name: 'Polkadot', address: '0x7083609fCE4d1d8Dc0C979AAb8c869Ea2C873402', decimals: 18, onchainSymbols: ['DOT'] },
    LINK: { name: 'Chainlink', address: '0xF8A0BF9cF54Bb92F17374d9e9A321E6a111a51bD', decimals: 18, onchainSymbols: ['LINK'] },
    UNI: { name: 'Uniswap', address: '0xBf5140A22578168FD562DCcF235E5D43A02ce9B1', decimals: 18, onchainSymbols: ['UNI'] },
    LTC: { name: 'Litecoin', address: '0x4338665CBB7B2485A8855A139b75D5e34AB0DB94', decimals: 18, onchainSymbols: ['LTC'] },
    TRX: { name: 'TRON', address: '0xCE7de646e7208a4Ef112cb6ed5038FA6cC6b12e3', decimals: 6, onchainSymbols: ['TRX'] },
    ATOM: { name: 'Cosmos', address: '0x0Eb3a705fc54725037CC9e008bDede697f62F335', decimals: 18, onchainSymbols: ['ATOM'] },
    NEAR: { name: 'NEAR Protocol', address: '0x1Fa4a73a3F0133f0025378af00236f3aBDEE5D63', decimals: 18, onchainSymbols: ['NEAR'] },
    SHIB: { name: 'Shiba Inu', address: '0x2859e4544C4bB03966803b044A93563Bd2D0DD4D', decimals: 18, onchainSymbols: ['SHIB'] },
    PEPE: { name: 'Pepe', address: '0x25d887Ce7a35172C62FeBFD67a1856F20FaEbB00', decimals: 18, onchainSymbols: ['PEPE'] },
    // เพิ่ม 2026-11-03 — ผ่านการตรวจ symbol() + ราคาพูลเทียบ Binance แล้ว (ดู config/bsc_trade_tokens.php)
    CAKE: { name: 'PancakeSwap', address: '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82', decimals: 18, onchainSymbols: ['Cake', 'CAKE'] },
    INJ: { name: 'Injective', address: '0xa2B726B1145A4773F68593CF171187d8EBe4d495', decimals: 18, onchainSymbols: ['INJ'] },
    FIL: { name: 'Filecoin', address: '0x0D8Ce2A99Bb6e3B7Db580eD848240e4a0F9aE153', decimals: 18, onchainSymbols: ['FIL'] },
    AXS: { name: 'Axie Infinity', address: '0x715D400F88C167884bbCc41C5FeA407ed4D2f8A0', decimals: 18, onchainSymbols: ['AXS'] },
    EGLD: { name: 'MultiversX', address: '0xbF7c81FFF98BbE61B40Ed186e4AfD6DDd01337fe', decimals: 18, onchainSymbols: ['EGLD'] },
    ZIL: { name: 'Zilliqa', address: '0xb86AbCb37C3A4B64f74f59301AFF131a1BEcC787', decimals: 12, onchainSymbols: ['ZIL'] },
    FLOKI: { name: 'FLOKI', address: '0xfb5B838b6cfEEdC2873aB27866079AC55363D37E', decimals: 9, onchainSymbols: ['FLOKI'] },
    TWT: { name: 'Trust Wallet Token', address: '0x4B0F1812e5Df2A09796481Ff14017e6005508003', decimals: 18, onchainSymbols: ['TWT'] },
    XVS: { name: 'Venus', address: '0xcF6BB5389c92Bdda8a3747Ddb454cB7a64626C63', decimals: 18, onchainSymbols: ['XVS'] },
    ASTER: { name: 'Aster', address: '0x000Ae314E2A2172a039B26378814C252734f556A', decimals: 18, onchainSymbols: ['ASTER'] },
    BAND: { name: 'Band Protocol', address: '0xAD6cAEb32CD2c308980a548bD0Bc5AA4306c6c18', decimals: 18, onchainSymbols: ['BAND'] },
    C98: { name: 'Coin98', address: '0xaEC945e04baF28b135Fa7c640f624f8D90F1C3a6', decimals: 18, onchainSymbols: ['C98'] },
    SFP: { name: 'SafePal', address: '0xD41FDb03Ba84762dD66a0af1a6C8540FF1ba5dfb', decimals: 18, onchainSymbols: ['SFP'] },
    DODO: { name: 'DODO', address: '0x67ee3Cb086F8a16f34beE3ca72FAD36F7Db929e2', decimals: 18, onchainSymbols: ['DODO'] },
    ALICE: { name: 'My Neighbor Alice', address: '0xAC51066d7bEC65Dc4589368da368b212745d63E8', decimals: 6, onchainSymbols: ['ALICE'] },
    TKO: { name: 'Toko Token', address: '0x9f589e3eabe42ebC94A44727b3f3531C0c877809', decimals: 18, onchainSymbols: ['TKO'] },
    SANTOS: { name: 'Santos FC Fan Token', address: '0xA64455a4553C9034236734FadDAddbb64aCE4Cc7', decimals: 8, onchainSymbols: ['SANTOS'] },
    TST: { name: 'Test', address: '0x86Bb94DdD16Efc8bc58e6b056e8df71D9e666429', decimals: 18, onchainSymbols: ['TST'] },
    BROCCOLI714: { name: 'Broccoli', address: '0x6d5AD1592ed9D6D1dF9b93c793AB759573Ed6714', decimals: 18, onchainSymbols: ['Broccoli', 'BROCCOLI'] },
    USD1: { name: 'World Liberty Financial USD', address: '0x8d0D000Ee44948FC98c9B98A4FA4921476f08B0d', decimals: 18, onchainSymbols: ['USD1'] },
    // เพิ่ม 2026-11-03 (PancakeSwap V3) — ผ่านการตรวจ symbol() + ราคาเส้นทางที่ดีที่สุด (V2/V3) เทียบ Binance แล้ว
    ETC: { name: 'Ethereum Classic', address: '0x3d6545b08693daE087E957cb1180ee38B9e3c25E', decimals: 18, onchainSymbols: ['ETC'] },
    AAVE: { name: 'Aave', address: '0xfb6115445Bff7b52FeB98650C87f44907E58f802', decimals: 18, onchainSymbols: ['AAVE'] },
    MANA: { name: 'Decentraland', address: '0x26433c8127d9b4e9B71Eaa15111DF99Ea2EeB2f8', decimals: 18, onchainSymbols: ['MANA'] },
    BCH: { name: 'Bitcoin Cash', address: '0x8fF795a6F4D97E7887C79beA79aba5cc76444aDf', decimals: 18, onchainSymbols: ['BCH'] },
    ZEC: { name: 'Zcash', address: '0x1Ba42e5193dfA8B03D15dd1B86a3113bbBEF8Eeb', decimals: 18, onchainSymbols: ['ZEC'] },
    FET: { name: 'Fetch.ai', address: '0x031b41e504677879370e9DBcF937283A8691Fa7f', decimals: 18, onchainSymbols: ['FET'] },
    PENDLE: { name: 'Pendle', address: '0xb3Ed0A426155B79B898849803E3B36552f7ED507', decimals: 18, onchainSymbols: ['PENDLE'] },
    ZRO: { name: 'LayerZero', address: '0x6985884C4392D348587B19cb9eAAf157F13271cd', decimals: 18, onchainSymbols: ['ZRO'] },
    LISTA: { name: 'Lista DAO', address: '0xFceB31A79F71AC9CBDCF853519c1b12D379EdC46', decimals: 18, onchainSymbols: ['LISTA'] },
    BONK: { name: 'Bonk', address: '0xA697e272a73744b343528C3Bc4702F2565b2F422', decimals: 5, onchainSymbols: ['Bonk', 'BONK'] },
    FDUSD: { name: 'First Digital USD', address: '0xc5f0f7b66764F6ec8C8Dff7BA683102295E16409', decimals: 18, onchainSymbols: ['FDUSD'] },
    LAZIO: { name: 'FC Lazio Fan Token', address: '0x77d547256A2cD95F32F67aE0313E450Ac200648d', decimals: 8, onchainSymbols: ['LAZIO'] },
    MUBARAK: { name: 'Mubarak', address: '0x5C85D6C6825aB4032337F11Ee92a72DF936b46F6', decimals: 18, onchainSymbols: ['mubarak', 'MUBARAK'] },
    FORM: { name: 'Four (FORM)', address: '0x5b73A93b4E5e4f1FD27D8b3F8C97D69908b5E284', decimals: 18, onchainSymbols: ['FORM'] },
    COOKIE: { name: 'Cookie DAO', address: '0xC0041EF357B183448B235a8Ea73Ce4E4eC8c265F', decimals: 18, onchainSymbols: ['COOKIE'] },
};

// ABI ขั้นต่ำสำหรับตรวจ token ก่อนเทรด
const VERIFY_ABI = [
    'function symbol() view returns (string)',
    'function decimals() view returns (uint8)',
];

// Cache ผลตรวจ on-chain ต่อ session — address บน mainnet ไม่เปลี่ยนระหว่างใช้งาน
const verifiedCache = new Map();

/**
 * คืน entry จาก registry (ไม่ตรวจ on-chain) — ใช้เช็คว่า symbol นี้เทรดได้ไหม
 */
export function getBscTradeToken(symbol) {
    return BSC_TRADE_TOKENS[String(symbol || '').toUpperCase()] || null;
}

/**
 * คืนข้อมูล token ที่ "ตรวจกับ on-chain แล้ว" สำหรับใช้เทรดจริง
 * - อ่าน symbol() + decimals() จาก contract บน BSC
 * - symbol ต้องตรงกับ onchainSymbols ไม่งั้น throw (fail-closed)
 * - decimals ใช้ค่า on-chain เสมอ (ไม่เชื่อค่า static)
 * @throws Error ข้อความพร้อมแสดงต่อผู้ใช้ได้ (มี isFriendly)
 */
export async function getVerifiedTradeToken(symbol) {
    const key = String(symbol || '').toUpperCase();
    const entry = BSC_TRADE_TOKENS[key];

    if (!entry) {
        throw friendly('swapError.tokenNotTradable', { symbol: key });
    }

    // เหรียญ native (BNB) ไม่มี contract ให้ตรวจ
    if (entry.native) {
        return { symbol: key, name: entry.name, address: entry.address, decimals: entry.decimals, native: true };
    }

    if (verifiedCache.has(key)) return verifiedCache.get(key);

    let onchainSymbol;
    let onchainDecimals;
    try {
        // โหนดหลักล่ม → ลองโหนดสำรอง (ยังเป็นการอ่านจากเชนจริง ไม่ใช่ค่า static)
        [onchainSymbol, onchainDecimals] = await withBscReadFallback((provider) => {
            const contract = new Contract(entry.address, VERIFY_ABI, provider);
            return Promise.all([contract.symbol(), contract.decimals()]);
        });
    } catch {
        // อ่านจากเชนไม่ได้ = ตรวจไม่ได้ = ไม่ให้เทรด (อย่า fallback ไปค่า static)
        throw friendly('swapError.tokenVerifyUnavailable');
    }

    const accepted = (entry.onchainSymbols || [key]).map(s => s.toUpperCase());
    if (!accepted.includes(String(onchainSymbol).toUpperCase())) {
        throw friendly('swapError.tokenVerifyFailed', { symbol: key });
    }

    const verified = {
        symbol: key,
        name: entry.name,
        address: entry.address,
        decimals: Number(onchainDecimals),
        native: false,
    };
    verifiedCache.set(key, verified);
    return verified;
}
