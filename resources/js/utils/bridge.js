/**
 * TPIX TRADE — ตัวช่วยของหน้าสะพาน USDT (ฟังก์ชันล้วน ทดสอบได้โดยไม่ต้องมีกระเป๋า/เครือข่าย)
 *
 * กติกาเงิน: ทุกจำนวนคำนวณเป็น BigInt หน่วยเล็กสุด — ห้ามผ่าน Number/float
 * (0.1 + 0.2 ≠ 0.3 ในโลก float · และ 1 USDT บน BSC = 1e18 เกิน Number.MAX_SAFE_INTEGER)
 *
 * Developed by Xman Studio
 */

import { getAddress } from 'ethers';

/** USDT_TPIX ใช้ 6 ทศนิยม — ฝั่ง mint ตัดเศษที่เกินนี้ทิ้งอยู่แล้ว จึงไม่ให้กรอกเกินตั้งแต่หน้าจอ */
export const USDT_TPIX_DECIMALS = 6;

/** ABI ขั้นต่ำที่หน้าสะพานใช้ */
export const BRIDGE_ERC20_ABI = [
    'function balanceOf(address owner) view returns (uint256)',
    'function transfer(address to, uint256 amount) returns (bool)',
];

export const USDT_TPIX_ABI = [
    'function balanceOf(address owner) view returns (uint256)',
    'function bridgeBurn(uint256 amount, uint256 targetChainId, string targetAddress)',
];

/** สถานะที่จบแล้ว — เลิกถามเซิร์ฟเวอร์ (chain_paused ไม่จบ: เปิดเชนใหม่เมื่อไหร่ตัวสแกนไล่ต่อให้เอง) */
export const TERMINAL_STATES = ['minted', 'paid', 'rejected', 'failed', 'not_a_deposit', 'not_a_withdrawal', 'ignored', 'below_fee', 'unsupported_chain', 'chain_disabled'];

/** สถานะที่เงินอยู่ใน vault แล้วแต่จะไม่ถูก mint อัตโนมัติ — ต้องบอกผู้ใช้ว่าติดต่อขอคืนได้ */
export const REFUND_STATES = ['chain_disabled', 'ignored'];

/**
 * ข้อความจากช่องกรอก → BigInt หน่วยเล็กสุดของเหรียญ — null ถ้าไม่ใช่ตัวเลขที่รับได้.
 *
 * จำกัดทศนิยมไม่เกิน 6 ตำแหน่งเสมอ (แม้เหรียญต้นทางจะมี 18) เพราะ USDT_TPIX มี 6
 * ตัวเลขที่เกินจะกลายเป็นฝุ่นค้างใน vault ซึ่งผู้ใช้ไม่ได้คืน
 */
export function parseAmountToUnits(input, decimals) {
    const text = String(input ?? '').trim().replace(/,/g, '');
    if (!/^\d+(\.\d+)?$/.test(text)) return null;

    const [whole, frac = ''] = text.split('.');
    if (frac.length > Math.min(USDT_TPIX_DECIMALS, decimals)) return null;

    const units = BigInt(whole) * 10n ** BigInt(decimals) + BigInt((frac || '0').padEnd(decimals, '0').slice(0, decimals) || '0');

    return units;
}

/** BigInt หน่วยเล็กสุด → ข้อความ (ตัดศูนย์ท้าย ไม่มีคอมมา) */
export function formatUnitsPlain(units, decimals) {
    const value = BigInt(units);
    const negative = value < 0n;
    const abs = negative ? -value : value;
    const base = 10n ** BigInt(decimals);
    const whole = abs / base;
    const frac = (abs % base).toString().padStart(decimals, '0').replace(/0+$/, '');

    return `${negative ? '-' : ''}${whole}${frac ? `.${frac}` : ''}`;
}

/** ข้อความ USDT (เช่น '0.3' จาก API) → BigInt 6 ทศนิยม — ค่าที่อ่านไม่ออกถือเป็น 0 */
export function usdtToUnits(text) {
    return parseAmountToUnits(text, USDT_TPIX_DECIMALS) ?? 0n;
}

/**
 * ยอดที่ได้รับจริงเมื่อถอน = จำนวน − ค่าธรรมเนียม (หน่วย 6 ทศนิยม) — ไม่ติดลบ
 */
export function withdrawReceiveUnits(amountUnits, feeText) {
    const fee = usdtToUnits(feeText);
    const amount = BigInt(amountUnits ?? 0n);

    return amount > fee ? amount - fee : 0n;
}

/**
 * ตรวจยอดฝากกับวงเงินจากเซิร์ฟเวอร์ (หน่วย 6 ทศนิยม).
 *
 * @param {bigint|null} units
 * @param {object} limits  limits จาก /api/v1/bridge/info
 * @param {string|null} effectiveMax  deposit_max_per_tx จาก info = min(วงเงินเว็บ, maxPerTx บนสัญญา)
 * @returns {{ error: string|null, params?: object, warning: string|null, warningParams?: object }}
 *   error = ห้ามกดส่ง: ต่ำกว่าขั้นต่ำ (ไม่ถูก mint) · เกินต่อครั้ง (mint ไม่ได้เลยไม่ว่าใครอนุมัติ — เงินค้าง vault)
 */
export function checkDepositAmount(units, limits, effectiveMax = null) {
    if (units === null || units === undefined) return { error: 'bridge.errAmountInvalid', warning: null };
    if (units <= 0n) return { error: 'bridge.errAmountInvalid', warning: null };

    const min = usdtToUnits(limits?.min_deposit ?? '1');
    if (units < min) return { error: 'bridge.errBelowMin', params: { min: limits?.min_deposit ?? '1' }, warning: null };

    // เดิมเป็นแค่คำเตือน "รอตรวจ" — แต่ยอดที่เกิน maxPerTx บนสัญญา mint ไม่ได้แม้แอดมินอนุมัติ ต้องห้ามตั้งแต่หน้าจอ
    const maxText = effectiveMax ?? limits?.max_deposit_per_tx ?? '0';
    const max = usdtToUnits(maxText);
    if (max > 0n && units > max) {
        return { error: 'bridge.errAboveMaxBlocked', params: { max: maxText }, warning: null };
    }

    return { error: null, warning: null };
}

/** ยอดที่ได้รับจริงเมื่อฝาก = จำนวน − ค่าธรรมเนียมฝาก (หน่วย 6 ทศนิยม) — ไม่ติดลบ */
export function depositReceiveUnits(units6, feeText) {
    const fee = usdtToUnits(feeText ?? '0');
    const amount = BigInt(units6 ?? 0n);

    return amount > fee ? amount - fee : 0n;
}

/**
 * ที่อยู่ปลายทางใช้ได้ไหม — ตัวพิมพ์ผสมต้องตรง checksum (EIP-55) ไม่งั้นแปลว่าพิมพ์ผิด
 * (เซิร์ฟเวอร์ตรวจแบบเดียวกัน แล้วปัดเป็นรอตรวจ — กันตั้งแต่หน้าจอดีกว่าให้เงินไปค้าง)
 */
export function isValidEvmAddress(address) {
    const text = String(address ?? '').trim();
    if (!/^0x[0-9a-fA-F]{40}$/.test(text)) return false;
    if (/^0x0{40}$/.test(text)) return false;

    const body = text.slice(2);
    if (body === body.toLowerCase() || body === body.toUpperCase()) return true;

    try {
        return getAddress(text) === text;
    } catch {
        return false;
    }
}

/** สถานะ → ป้าย + โทนสี ให้ทุกที่ในหน้าแสดงเหมือนกัน */
export function stateBadge(state) {
    const tone = {
        minted: 'success', paid: 'success',
        confirming: 'info', queued: 'info', minting: 'info', sending: 'info', pending: 'info',
        review: 'warning', awaiting_liquidity: 'warning', unknown: 'warning',
        rejected: 'danger', failed: 'danger', not_found: 'danger', not_a_deposit: 'danger',
        not_a_withdrawal: 'danger', unsupported_chain: 'danger', ignored: 'muted', below_fee: 'muted',
        chain_disabled: 'danger', chain_paused: 'warning',
    }[state] ?? 'muted';

    return { labelKey: `bridge.state.${state}`, tone, done: TERMINAL_STATES.includes(state) };
}

export function explorerTx(explorer, hash) {
    return explorer && hash ? `${String(explorer).replace(/\/+$/, '')}/tx/${hash}` : null;
}

export function explorerTokenHolder(explorer, token, holder) {
    return explorer && token && holder ? `${String(explorer).replace(/\/+$/, '')}/token/${token}?a=${holder}` : null;
}

export function explorerAddress(explorer, address) {
    return explorer && address ? `${String(explorer).replace(/\/+$/, '')}/address/${address}` : null;
}

// ── รายการที่กำลังติดตาม (localStorage — สะดวกต่อผู้ใช้เท่านั้น แหล่งความจริงคือเชน) ─────────

const TRACK_KEY = 'tpix.bridge.tracked.v2';
const TRACK_LIMIT = 20;

/** อ่านรายการที่ติดตาม — โหมดส่วนตัว/ถูกบล็อก storage = รายการว่าง ไม่พัง */
export function loadTracked(storage = globalThis.localStorage) {
    try {
        const parsed = JSON.parse(storage?.getItem(TRACK_KEY) || '[]');
        return Array.isArray(parsed)
            ? parsed.filter((e) => e && /^0x[0-9a-fA-F]{64}$/.test(e.txHash) && ['deposit', 'withdraw'].includes(e.kind))
            : [];
    } catch {
        return [];
    }
}

export function saveTracked(list, storage = globalThis.localStorage) {
    try {
        storage?.setItem(TRACK_KEY, JSON.stringify(list.slice(0, TRACK_LIMIT)));
    } catch {
        /* เขียนไม่ได้ก็แค่ไม่จำ — เงินไม่ได้หายไปไหน ตัวสแกนอ่านจากเชนเอง */
    }
}

/** เพิ่มรายการ (ซ้ำ = ย้ายขึ้นบนสุด ไม่เพิ่มแถวใหม่) */
export function addTracked(list, entry) {
    const key = (e) => `${e.kind}:${e.chainId}:${String(e.txHash).toLowerCase()}`;
    const next = { ...entry, txHash: String(entry.txHash).toLowerCase(), addedAt: entry.addedAt ?? Date.now() };

    return [next, ...list.filter((e) => key(e) !== key(next))].slice(0, TRACK_LIMIT);
}

/** แปลง error ของกระเป๋า/ethers เป็นคีย์ข้อความ — ห้ามโชว์ exception ดิบให้ผู้ใช้ */
export function friendlyWalletError(err) {
    const code = err?.code ?? err?.info?.error?.code;
    const msg = String(err?.shortMessage || err?.reason || err?.message || '').toLowerCase();

    if (code === 4001 || code === 'ACTION_REJECTED' || msg.includes('user rejected') || msg.includes('user denied')) {
        return 'bridge.rejectedByUser';
    }
    if (msg.includes('insufficient')) return 'bridge.errInsufficient';

    return 'bridge.errTxFailed';
}
