/**
 * TPIX TRADE — ตัวช่วยของสะพาน TPIX (เชน 4289) ⇄ wTPIX (BSC) (ฟังก์ชันล้วน ทดสอบได้โดยไม่ต้องมีกระเป๋า)
 *
 * กติกาเงินเดียวกับสะพาน USDT: ทุกจำนวนเป็น BigInt wei — ห้ามผ่าน Number/float
 * (1 TPIX = 1e18 wei เกิน Number.MAX_SAFE_INTEGER ตั้งแต่ 0.009 TPIX)
 *
 * Developed by Xman Studio
 */

/** TPIX (เนทีฟ) และ wTPIX ใช้ 18 ทศนิยมทั้งคู่ — ไม่มีการแปลงหน่วยข้ามเชน ไม่มีฝุ่น */
export const TPIX_DECIMALS = 18;

/** TPIXBridgeVault — lock() ไม่มีพารามิเตอร์: ผู้รับบน BSC = ที่อยู่ที่ล็อกเสมอ */
export const WTPIX_VAULT_ABI = ['function lock() payable'];

/** wTPIX (ERC20Burnable) — burn() เผาของตัวเองได้ทันที ไม่ต้อง approve · ผู้รับบนเชน TPIX = ที่อยู่ผู้เผา */
export const WTPIX_TOKEN_ABI = [
    'function balanceOf(address owner) view returns (uint256)',
    'function burn(uint256 amount)',
];

/** สถานะที่จบแล้ว — เลิกถามเซิร์ฟเวอร์ */
export const WTPIX_TERMINAL_STATES = ['minted', 'released', 'rejected', 'failed', 'not_a_lock', 'not_a_burn', 'ignored', 'below_fee', 'unsupported_chain'];

/**
 * ข้อความจากช่องกรอก → BigInt wei — null ถ้าไม่ใช่ตัวเลขที่รับได้ (ทศนิยมได้ไม่เกิน 18 ตำแหน่ง)
 */
export function parseTpixAmount(input) {
    const text = String(input ?? '').trim().replace(/,/g, '');
    if (!/^\d+(\.\d+)?$/.test(text)) return null;

    const [whole, frac = ''] = text.split('.');
    if (frac.length > TPIX_DECIMALS || whole.length > 15) return null;

    return BigInt(whole) * 10n ** 18n + BigInt(frac.padEnd(TPIX_DECIMALS, '0') || '0');
}

/** BigInt wei → ข้อความ (ตัดศูนย์ท้าย ไม่มีคอมมา) */
export function formatTpix(wei) {
    const value = BigInt(wei ?? 0n);
    const negative = value < 0n;
    const abs = negative ? -value : value;
    const whole = abs / 10n ** 18n;
    const frac = (abs % 10n ** 18n).toString().padStart(TPIX_DECIMALS, '0').replace(/0+$/, '');

    return `${negative ? '-' : ''}${whole}${frac ? `.${frac}` : ''}`;
}

/** ข้อความ TPIX จาก API ('100000') → BigInt wei · อ่านไม่ออก = null */
export function tpixTextToWei(text) {
    if (text === null || text === undefined || text === '') return null;

    return parseTpixAmount(text);
}

/** ยอดที่ได้จริงอีกฝั่ง = จำนวน − ค่าธรรมเนียม (ไม่ติดลบ) */
export function receiveWei(amountWei, feeText) {
    const fee = tpixTextToWei(feeText) ?? 0n;
    const amount = BigInt(amountWei ?? 0n);

    return amount > fee ? amount - fee : 0n;
}

/**
 * ตรวจยอดกับวงเงิน (ฝั่งเว็บ) + เพดานบนสัญญา.
 *
 * @param {bigint|null} wei
 * @param {'to_bsc'|'to_tpix'} direction
 * @param {object} info ผลจาก /api/v1/bridge/info?asset=tpix
 * @returns {{ error: string|null, params?: object, warning: string|null, warningParams?: object }}
 *   error   = ห้ามกด (ต่ำกว่าขั้นต่ำ = ไม่ถูกทำ · เกิน maxLock บนสัญญา = ธุรกรรม revert)
 *   warning = ทำได้ แต่จะรอทีมงานตรวจ (ช้า)
 */
export function checkWtpixAmount(wei, direction, info) {
    if (wei === null || wei === undefined || wei <= 0n) return { error: 'bridge.wtpix.errAmountInvalid', warning: null };

    const side = direction === 'to_bsc' ? 'lock' : 'burn';
    const limits = info?.limits ?? {};
    const fee = tpixTextToWei(limits[`${side}_fee`]) ?? 0n;

    if (wei <= fee) return { error: 'bridge.wtpix.errFeeTooHigh', params: { fee: limits[`${side}_fee`] }, warning: null };

    // ขั้นต่ำ = ค่าที่มากกว่าระหว่างวงเงินเว็บกับ minLock บนสัญญา (ล็อกต่ำกว่า minLock = revert)
    const minWeb = tpixTextToWei(limits[`min_${side}`]) ?? 0n;
    const minChain = direction === 'to_bsc' ? (tpixTextToWei(info?.vault?.min_lock) ?? 0n) : 0n;
    const min = minWeb > minChain ? minWeb : minChain;
    if (wei < min) return { error: 'bridge.wtpix.errBelowMin', params: { min: formatTpix(min) }, warning: null };

    // ล็อกเกิน maxLock บนสัญญา = revert แน่นอน ห้ามส่ง
    const maxLock = direction === 'to_bsc' ? tpixTextToWei(info?.vault?.max_lock) : null;
    if (maxLock !== null && wei > maxLock) return { error: 'bridge.wtpix.errAboveContractMax', params: { max: formatTpix(maxLock) }, warning: null };

    /*
     * ยอดที่สัญญาปลายทางต้องจ่ายเกิน maxPerTx = ทำครั้งเดียวไม่ได้ (depositId ใช้ได้ครั้งเดียว แบ่งจ่ายไม่ได้)
     * → เหรียญถูกล็อก/เผาไปแล้วแต่ค้างรอเจ้าของขยายเพดาน จึงห้ามส่งตั้งแต่ต้น
     *   ล็อก → minter บน BSC · เผา → คลังบนเชน TPIX
     * เทียบ "ยอดที่ได้รับ" (หักค่าธรรมเนียมแล้ว) แบบเดียวกับเซิร์ฟเวอร์ — เพดานที่บอกผู้ใช้จึงบวกค่าธรรมเนียมกลับ
     */
    const destMax = tpixTextToWei(direction === 'to_bsc' ? info?.minter?.max_per_tx : info?.vault?.max_per_tx);
    if (destMax !== null && wei - fee > destMax) {
        return { error: 'bridge.wtpix.errAboveContractMax', params: { max: formatTpix(destMax + fee) }, warning: null };
    }

    const maxWeb = tpixTextToWei(limits[`max_${side}_per_tx`]);
    if (maxWeb !== null && maxWeb > 0n && wei > maxWeb) {
        return { error: null, warning: 'bridge.wtpix.warnAboveMax', warningParams: { max: limits[`max_${side}_per_tx`] } };
    }

    return { error: null, warning: null };
}

/** สถานะ → ป้าย + โทนสี (ข้อความแยกจากสะพาน USDT — "minted" ของที่นี่คือ wTPIX บน BSC ไม่ใช่ USDT) */
export function wtpixStateBadge(state) {
    const tone = {
        minted: 'success', released: 'success',
        confirming: 'info', queued: 'info', minting: 'info', releasing: 'info', pending: 'info',
        review: 'warning', awaiting_liquidity: 'warning', unknown: 'warning',
        rejected: 'danger', failed: 'danger', not_found: 'danger', not_a_lock: 'danger', not_a_burn: 'danger', unsupported_chain: 'danger',
        ignored: 'muted', below_fee: 'muted',
    }[state] ?? 'muted';

    return { labelKey: `bridge.wtpix.state.${state}`, tone, done: WTPIX_TERMINAL_STATES.includes(state) };
}

// ── รายการที่กำลังติดตาม (localStorage — สะดวกต่อผู้ใช้เท่านั้น แหล่งความจริงคือเชน) ─────────

const TRACK_KEY = 'tpix.wtpix-bridge.tracked.v1';
const TRACK_LIMIT = 20;

export function loadWtpixTracked(storage = globalThis.localStorage) {
    try {
        const parsed = JSON.parse(storage?.getItem(TRACK_KEY) || '[]');
        return Array.isArray(parsed)
            ? parsed.filter((e) => e && /^0x[0-9a-fA-F]{64}$/.test(e.txHash) && ['to_bsc', 'to_tpix'].includes(e.direction) && Number.isInteger(e.chainId))
            : [];
    } catch {
        return [];
    }
}

export function saveWtpixTracked(list, storage = globalThis.localStorage) {
    try {
        storage?.setItem(TRACK_KEY, JSON.stringify(list.slice(0, TRACK_LIMIT)));
    } catch {
        /* เขียนไม่ได้ก็แค่ไม่จำ — ตัวสแกนอ่านจากเชนเองอยู่แล้ว */
    }
}

/** เพิ่มรายการ (ซ้ำ = ย้ายขึ้นบนสุด ไม่เพิ่มแถวใหม่ — กดเบิ้ล/ติดตามซ้ำไม่ทำให้รายการงอก) */
export function addWtpixTracked(list, entry) {
    const key = (e) => `${e.direction}:${e.chainId}:${String(e.txHash).toLowerCase()}`;
    const next = { ...entry, txHash: String(entry.txHash).toLowerCase(), addedAt: entry.addedAt ?? Date.now() };

    return [next, ...list.filter((e) => key(e) !== key(next))].slice(0, TRACK_LIMIT);
}
