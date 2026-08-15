/**
 * TPIX TRADE - BSC Trade Token Registry
 * แผนที่เหรียญ major (คู่เทรดในหน้า Trade) → token address บน BSC
 * ใช้กับ market order ที่ execute จริงผ่าน PancakeSwap V2
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
import { NATIVE_TOKEN_ADDRESS, getBscReadProvider } from '@/utils/web3';

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
        const err = new Error(`${key} is not tradable on BSC yet.`);
        err.isFriendly = true;
        throw err;
    }

    // เหรียญ native (BNB) ไม่มี contract ให้ตรวจ
    if (entry.native) {
        return { symbol: key, name: entry.name, address: entry.address, decimals: entry.decimals, native: true };
    }

    if (verifiedCache.has(key)) return verifiedCache.get(key);

    let onchainSymbol;
    let onchainDecimals;
    try {
        const contract = new Contract(entry.address, VERIFY_ABI, getBscReadProvider());
        [onchainSymbol, onchainDecimals] = await Promise.all([
            contract.symbol(),
            contract.decimals(),
        ]);
    } catch {
        // อ่านจากเชนไม่ได้ = ตรวจไม่ได้ = ไม่ให้เทรด (อย่า fallback ไปค่า static)
        const err = new Error('Unable to verify token on BSC. Please try again.');
        err.isFriendly = true;
        throw err;
    }

    const accepted = (entry.onchainSymbols || [key]).map(s => s.toUpperCase());
    if (!accepted.includes(String(onchainSymbol).toUpperCase())) {
        const err = new Error(`Token verification failed for ${key}. Trading blocked for safety.`);
        err.isFriendly = true;
        throw err;
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
