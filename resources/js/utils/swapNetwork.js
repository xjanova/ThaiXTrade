/**
 * TPIX TRADE — เครือข่ายของหน้า Swap (BSC / TPIX Chain)
 *
 * หน้า /swap สวอปได้สองทาง:
 *  - BSC        → PancakeSwap ผ่าน useSwap (ทางเดิม ผูก chain id 56 ตรง ๆ)
 *  - TPIX Chain → พูล TPIX DEX ผ่าน useTpixDex (ทางเดียวกับ /trade)
 *
 * ไฟล์นี้เก็บเฉพาะตรรกะล้วน (ไม่แตะกระเป๋า/เครือข่าย) ให้เทสต์ได้ตรง ๆ:
 * เลือกเครือข่ายแบบ fail-closed · สร้างรายการเหรียญจากคู่บน DEX · เพดานผลกระทบราคา
 *
 * Developed by Xman Studio
 */

import { BSC_CHAIN_ID, TPIX_CHAIN_CONFIG } from '@/utils/web3';
import { toDexAddress } from '@/Composables/useTpixDex';

export const SWAP_NETWORKS = Object.freeze({ BSC: 'bsc', TPIX: 'tpix' });

/** chain id ของ TPIX Chain (4289) — มาจาก config เชน ไม่ใช่เชนปริยายของระบบ */
export const TPIX_CHAIN_ID = TPIX_CHAIN_CONFIG.chainIdNum;

/** ไม้ที่ดันราคาพูลเกินเท่านี้ (%) ห้ามส่ง — ค่าเดียวกับ executeDexMarketOrder ใน Trade.vue */
export const PRICE_IMPACT_BLOCK_PCT = 10;

/** decimals ที่ยอมรับได้ของ ERC-20 — นอกช่วงนี้ถือว่าข้อมูลเสีย */
const MAX_TOKEN_DECIMALS = 36;

const ADDRESS_RE = /^0x[0-9a-fA-F]{40}$/;

/**
 * chain id ของเครือข่ายที่เลือก
 *
 * ⚠️ BSC ต้องเป็น 56 ตรง ๆ เสมอ — ห้ามใช้ getDefaultChainId()
 *    เชนปริยายของระบบพลิกเป็น TPIX แล้ว ถ้าใช้แทนกัน ทางสวอป PancakeSwap จะไปผิดเชน
 */
export function chainIdForNetwork(network) {
    return network === SWAP_NETWORKS.TPIX ? TPIX_CHAIN_ID : BSC_CHAIN_ID;
}

/**
 * เครือข่ายที่หน้า Swap ใช้จริง — fail-closed
 *
 *  - DEX ยังไม่พร้อม (เซิร์ฟเวอร์ไม่ยืนยัน ready / ที่อยู่ไม่ครบ) → BSC เสมอ ไม่ว่าผู้ใช้จะเลือกอะไร
 *  - ผู้ใช้เลือกเองแล้ว → ตามที่เลือก
 *  - ยังไม่เลือก → TPIX ก็ต่อเมื่อมีเหรียญให้สวอปอย่างน้อย 2 ตัว (ไม่พาผู้ใช้ไปเจอหน้าว่าง)
 *
 * @param {{ requested?: 'bsc'|'tpix'|null, dexReady?: boolean, tpixTokenCount?: number }} state
 * @returns {'bsc'|'tpix'}
 */
export function resolveSwapNetwork({ requested = null, dexReady = false, tpixTokenCount = 0 } = {}) {
    if (dexReady !== true) return SWAP_NETWORKS.BSC;
    if (requested === SWAP_NETWORKS.BSC) return SWAP_NETWORKS.BSC;
    if (requested === SWAP_NETWORKS.TPIX) return SWAP_NETWORKS.TPIX;
    return Number(tpixTokenCount) >= 2 ? SWAP_NETWORKS.TPIX : SWAP_NETWORKS.BSC;
}

/** decimals ที่ใช้ได้ หรือ null — ห้ามเดา 18 ให้เอง */
function validDecimals(value) {
    if (value === null || value === undefined || value === '' || typeof value === 'boolean') return null;
    const n = Number(value);
    return Number.isInteger(n) && n >= 0 && n <= MAX_TOKEN_DECIMALS ? n : null;
}

/**
 * คู่นี้เป็นคู่บน TPIX DEX ที่ส่งไม้ได้จริงไหม
 *
 * รับได้สองรูป:
 *  - /api/v1/dex/pairs   → chain_id = chain id จริง (4289) · กรองเฉพาะ onchain มาแล้ว
 *  - /api/v1/market/pairs → network_chain_id = chain id จริง · chain_id คือ PK ของตาราง (ห้ามใช้)
 */
function isTpixDexPair(pair) {
    if (!pair || typeof pair !== 'object') return false;
    if (pair.is_active !== true) return false;
    if (pair.execution_mode !== undefined && pair.execution_mode !== 'onchain') return false;

    const chain = pair.network_chain_id !== undefined ? pair.network_chain_id : pair.chain_id;
    return Number(chain) === TPIX_CHAIN_ID;
}

/** ฝั่งหนึ่งของคู่ → เหรียญในรายการ หรือ null ถ้าข้อมูลไม่ครบ */
function tokenFromSide(pair, side) {
    const rawAddress = pair[`${side}_address`];
    // ที่อยู่ว่าง/ผิดรูปต้องไม่กลายเป็น native — toDexAddress('') = native จึงต้องตรวจก่อน
    if (typeof rawAddress !== 'string' || !ADDRESS_RE.test(rawAddress)) return null;

    const address = toDexAddress(rawAddress);
    const native = address === 'native';
    const symbol = String(pair[`${side}_asset`] || '').trim();

    // TPIX native: decimals มาจาก config ของเชน (เหรียญหลักของเชน ไม่ใช่สัญญา ERC-20)
    const decimals = native
        ? TPIX_CHAIN_CONFIG.nativeCurrency.decimals
        : validDecimals(pair[`${side}_decimals`]);
    if (decimals === null) return null;
    if (!native && !symbol) return null;

    return {
        symbol: native ? TPIX_CHAIN_CONFIG.nativeCurrency.symbol : symbol,
        name: native ? TPIX_CHAIN_CONFIG.nativeCurrency.name : symbol,
        address,
        decimals,
        native,
        logo: pair[`${side}_logo`] || null,
    };
}

/**
 * รายการเหรียญของโหมด TPIX Chain — สร้างจากคู่บน DEX ที่เปิดอยู่เท่านั้น
 *
 *  - TPIX native (0x0 ในฐานข้อมูล = WTPIX ในพูล) ขึ้นก่อน แล้วตามลำดับที่เซิร์ฟเวอร์เรียงมา
 *  - คู่ที่ข้อมูลฝั่งใดฝั่งหนึ่งไม่ครบ (ที่อยู่/decimals) ทิ้งทั้งคู่
 *  - ที่อยู่เดียวกันแต่ decimals ไม่ตรงกันในสองคู่ = ข้อมูลขัดกัน ทิ้งเหรียญนั้น (ไม่เดาว่าตัวไหนถูก)
 *
 * @param {Array<object>} pairs ข้อมูลจาก /api/v1/dex/pairs (หรือ /api/v1/market/pairs)
 * @returns {Array<{symbol,name,address,decimals,native,logo,balance,balanceRaw}>}
 */
export function buildTpixTokenList(pairs) {
    const byAddress = new Map();
    const conflicted = new Set();

    for (const pair of Array.isArray(pairs) ? pairs : []) {
        if (!isTpixDexPair(pair)) continue;

        const base = tokenFromSide(pair, 'base');
        const quote = tokenFromSide(pair, 'quote');
        if (!base || !quote || base.address === quote.address) continue;

        for (const token of [base, quote]) {
            const seen = byAddress.get(token.address);
            if (!seen) {
                byAddress.set(token.address, token);
            } else if (seen.decimals !== token.decimals) {
                conflicted.add(token.address);
            } else if (!seen.logo && token.logo) {
                seen.logo = token.logo;
            }
        }
    }

    const tokens = [...byAddress.values()].filter((t) => !conflicted.has(t.address));
    tokens.sort((a, b) => Number(b.native) - Number(a.native));

    // balanceRaw = null แปลว่า "ยังไม่รู้ยอด" — ต่างจาก '0' ที่อ่านได้แล้วว่าศูนย์
    return tokens.map((t) => ({ ...t, balance: '0.00', balanceRaw: null }));
}

/** เหรียญเดียวกันไหม — เทียบที่อยู่ ไม่ใช่สัญลักษณ์ (สัญลักษณ์ซ้ำกันได้) */
export function sameToken(a, b) {
    if (!a || !b) return false;
    return String(a.address || '').toLowerCase() === String(b.address || '').toLowerCase();
}

/**
 * คู่เริ่มต้นของรายการ: TPIX → USDT (ถ้ามี) ไม่งั้น TPIX → เหรียญแรกที่ไม่ใช่ TPIX
 * @returns {{from, to}|null} null = มีเหรียญไม่ถึง 2 ตัว สวอปไม่ได้
 */
export function defaultTokenPair(tokens) {
    const list = Array.isArray(tokens) ? tokens : [];
    if (list.length < 2) return null;

    const from = list.find((t) => t.native) || list[0];
    const others = list.filter((t) => !sameToken(t, from));
    const to = others.find((t) => String(t.symbol).toUpperCase() === 'USDT') || others[0];

    return to ? { from, to } : null;
}

/**
 * ไม้นี้ต้องถูกบล็อกเพราะผลกระทบราคาไหม — fail-closed
 * ไม่รู้ค่า (null/NaN) = บล็อก เพราะไม่รู้ว่าพูลรับไม้ขนาดนี้ได้หรือเปล่า
 */
export function isPriceImpactBlocked(impact) {
    if (impact === null || impact === undefined || impact === '') return true;
    const n = Number(impact);
    if (!Number.isFinite(n)) return true;
    return n > PRICE_IMPACT_BLOCK_PCT;
}

/**
 * จำนวนที่กรอกเกินยอดคงเหลือไหม
 * ยอดที่ยังไม่รู้ (null/undefined) = ไม่ตัดสิน — ให้เชนเป็นคนปฏิเสธ ไม่บล็อกผู้ใช้จากข้อมูลที่ไม่มี
 */
export function exceedsBalance(amountText, balanceRaw) {
    const amount = parseFloat(amountText);
    if (!(amount > 0)) return false;
    if (balanceRaw === null || balanceRaw === undefined || balanceRaw === '') return false;
    const balance = parseFloat(balanceRaw);
    if (!Number.isFinite(balance)) return false;
    return amount > balance;
}
