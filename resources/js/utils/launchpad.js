/**
 * TPIX TRADE — ตัวช่วยของหน้า Launchpad (ฟังก์ชันล้วน ไม่แตะกระเป๋า/เครือข่าย — เทสต์ได้ตรง ๆ)
 *
 * กฎเงิน: ยอดที่ต้องโอนมาจากเซิร์ฟเวอร์ (amount_units, 6 ทศนิยม) เสมอ แล้วขยายเป็นหน่วยของ USDT บนเชนต้นทาง
 * (BSC = 18 ทศนิยม) — ห้ามคำนวณจากช่องที่ผู้ใช้พิมพ์ซ้ำ ไม่งั้นยอดไม่ตรงคำสั่ง = ไม่ได้เหรียญ ได้ USDT_TPIX แทน
 *
 * Developed by Xman Studio
 */

/** รหัสข้อผิดพลาดจาก /api/v1/launchpad ที่มีคำแปล (launchpad.errors.*) — ที่ไม่รู้จักใช้ข้อความกลาง */
export const ERROR_CODES = [
    'RISK_NOT_ACCEPTED', 'INVALID_NONCE', 'INVALID_ISSUED_AT', 'SIGNATURE_EXPIRED', 'INVALID_SIGNATURE', 'OPEN_ORDER_EXISTS',
    'SIGNATURE_USED', 'ORDER_NOT_CANCELLABLE', 'LAUNCHPAD_UNAVAILABLE', 'SALE_NOT_OPEN', 'SALE_NOT_ACTIVE', 'BRIDGE_PAUSED',
    'AMOUNT_OUT_OF_RANGE', 'DAILY_LIMIT', 'CONTRACT_WALLET', 'KYC_REQUIRED', 'ZERO_OUTPUT', 'CHAIN_UNAVAILABLE', 'SALE_PAUSED',
    'SOLD_OUT', 'BELOW_MINIMUM', 'WALLET_LIMIT', 'INVALID_WALLET', 'INVALID_AMOUNT', 'SALE_NOT_FOUND', 'ORDER_NOT_FOUND', 'SERVER_ERROR',
];

/** สถานะคำสั่งซื้อ → โทนสี + จบแล้วหรือยัง (จบแล้ว = หยุดถามสถานะ) */
export const ORDER_STATUS = {
    awaiting_payment: { tone: 'info', done: false },
    paid: { tone: 'info', done: false },
    buying: { tone: 'info', done: false },
    delivered: { tone: 'success', done: true },
    refunding: { tone: 'warning', done: false },
    refunded: { tone: 'warning', done: true },
    expired: { tone: 'muted', done: true },
    cancelled: { tone: 'muted', done: true },
    needs_review: { tone: 'warning', done: false },
};

export const BADGE_LEVELS = ['ok', 'warn', 'danger', 'info', 'unknown'];

export const REPORT_REASONS = ['scam', 'impersonation', 'misleading', 'other'];

export const ERC20_TRANSFER_ABI = [
    'function transfer(address to, uint256 amount) returns (bool)',
    'function balanceOf(address owner) view returns (uint256)',
];

/** แปลง error ของ axios → { key, params, code, details } (คีย์แปลของหน้า ไม่ใช่ข้อความดิบจากเซิร์ฟเวอร์) */
export function apiError(err) {
    const body = err?.response?.data;
    const code = body?.error?.code;
    const details = body?.error?.details ?? {};

    if (code && ERROR_CODES.includes(code)) {
        return { key: `launchpad.errors.${code}`, params: details, code, details };
    }
    // ด่านตรวจข้อมูลของ Laravel (422 แบบ errors{}) — ช่องที่ส่งไปผิดรูปแบบ
    if (err?.response?.status === 422 && body?.errors) {
        return { key: 'launchpad.errors.INVALID_AMOUNT', params: {}, code: 'INVALID_AMOUNT', details: {} };
    }
    if (err?.response?.status === 429) {
        return { key: 'launchpad.errors.TOO_MANY', params: {}, code: 'TOO_MANY', details: {} };
    }

    return { key: 'launchpad.errors.SERVER_ERROR', params: {}, code: code ?? 'SERVER_ERROR', details };
}

/** ข้อผิดพลาดจากกระเป๋า → คีย์แปล */
export function walletErrorKey(err) {
    const code = err?.code ?? err?.info?.error?.code;
    const msg = String(err?.shortMessage || err?.reason || err?.message || '').toLowerCase();

    if (err?.message === 'SWITCH_FAILED') return 'launchpad.buy.switchFailed';
    if (err?.message === 'WALLET_CHANGED') return 'launchpad.buy.walletChanged';
    if (code === 4001 || code === 'ACTION_REJECTED' || msg.includes('user rejected') || msg.includes('user denied')) {
        return 'launchpad.buy.rejected';
    }
    if (msg.includes('insufficient')) return 'launchpad.buy.insufficient';

    return 'launchpad.buy.txFailed';
}

/**
 * ช่องจำนวน USDT → ข้อความที่ส่งให้เซิร์ฟเวอร์ ('12.5') หรือ null ถ้าผิดรูปแบบ
 * รับได้สูงสุด 6 ทศนิยม (USDT_TPIX) — เกินนั้นจะเป็นเศษที่สะพานตัดทิ้ง ยอดไม่ตรงคำสั่ง
 */
export function normalizeAmount(input) {
    const text = String(input ?? '').trim().replace(/,/g, '');
    if (!/^\d{1,12}(\.\d{1,6})?$/.test(text)) return null;
    if (/^0+(\.0+)?$/.test(text)) return null;

    return text.replace(/^0+(?=\d)/, '');
}

/** หน่วย 6 ทศนิยม (สตริงจากเซิร์ฟเวอร์) → BigInt หน่วยของ USDT บนเชนต้นทาง */
export function sourceUnits(amountUnits6, decimals) {
    const units = BigInt(String(amountUnits6));
    const diff = Number(decimals) - 6;
    if (diff >= 0) return units * 10n ** BigInt(diff);

    // เชนที่ทศนิยมน้อยกว่า 6 — ต้องหารลงตัวเท่านั้น ไม่งั้นยอดจะไม่ตรงคำสั่ง
    const base = 10n ** BigInt(-diff);
    if (units % base !== 0n) throw new Error('AMOUNT_NOT_REPRESENTABLE');
    return units / base;
}

/** ช่วงของหน้าขายตามเวลา: upcoming | live | ended */
export function saleState(sale, now = Date.now()) {
    const start = Date.parse(sale?.starts_at ?? '');
    const end = Date.parse(sale?.ends_at ?? '');
    if (Number.isNaN(start) || Number.isNaN(end)) return 'ended';
    if (now < start) return 'upcoming';
    if (now >= end) return 'ended';
    return 'live';
}

/** เวลาที่เหลือ → { d, h, m, s } (ไม่ติดลบ) */
export function timeLeft(iso, now = Date.now()) {
    const target = Date.parse(iso ?? '');
    let secs = Number.isNaN(target) ? 0 : Math.max(0, Math.floor((target - now) / 1000));
    const d = Math.floor(secs / 86400); secs -= d * 86400;
    const h = Math.floor(secs / 3600); secs -= h * 3600;
    const m = Math.floor(secs / 60);
    return { d, h, m, s: secs - m * 60 };
}

/**
 * ตัวเลขทศนิยมยาวเป็นสตริง ('100000000000.5') → ใส่คอมมา ตัดทศนิยมเหลือ maxFrac (ปัดลง — ไม่แสดงเกินที่ได้จริง)
 * ไม่ผ่าน Number — เลข 1e29 หน่วยเหรียญเกินความแม่นของ float
 */
export function formatDecimal(value, maxFrac = 4) {
    const text = String(value ?? '').trim();
    if (!/^-?\d+(\.\d+)?$/.test(text)) return '—';
    const negative = text.startsWith('-');
    const [whole, frac = ''] = text.replace('-', '').split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const cut = frac.slice(0, maxFrac).replace(/0+$/, '');
    return `${negative ? '-' : ''}${grouped}${cut ? `.${cut}` : ''}`;
}

/** BigInt/สตริงหน่วยเล็กสุด → สตริงทศนิยม (ไม่มีคอมมา) */
export function unitsToDecimal(units, decimals) {
    if (units === null || units === undefined || units === '') return null;
    const value = BigInt(String(units));
    const base = 10n ** BigInt(decimals);
    const frac = (value % base).toString().padStart(Number(decimals), '0').replace(/0+$/, '');
    return `${value / base}${frac ? `.${frac}` : ''}`;
}

/** สัดส่วนขายไปแล้ว (0–100, ทศนิยม 2) จากหน่วยเล็กสุด */
export function soldPercent(soldUnits, capUnits) {
    try {
        const sold = BigInt(String(soldUnits ?? '0'));
        const cap = BigInt(String(capUnits ?? '0'));
        if (cap <= 0n) return 0;
        const bps = (sold * 10000n) / cap;
        return Math.min(100, Number(bps) / 100);
    } catch {
        return 0;
    }
}

/** เหรียญโดยประมาณจากช่องที่พิมพ์ (ก่อน prepare) — ตัวเลขจริงคือ quote.tokens ที่เซิร์ฟเวอร์ตอบ */
export function estimateTokens(amountText, tokensPerUsdt) {
    const amount = normalizeAmount(amountText);
    if (!amount || !/^\d+(\.\d+)?$/.test(String(tokensPerUsdt ?? ''))) return null;
    // คูณแบบ fixed-point 6 ตำแหน่ง ด้วย BigInt
    const toFixed = (s, places) => {
        const [w, f = ''] = String(s).split('.');
        return BigInt(w) * 10n ** BigInt(places) + BigInt((f.padEnd(places, '0').slice(0, places)) || '0');
    };
    const product = toFixed(amount, 6) * toFixed(tokensPerUsdt, 18); // scale 24
    return unitsToDecimal(product, 24);
}

/** ปุ่ม/ลิงก์ explorer */
export function explorerUrl(base, kind, value) {
    if (!base || !value) return null;
    return `${String(base).replace(/\/+$/, '')}/${kind}/${value}`;
}

export function shortAddr(value) {
    return value ? `${value.slice(0, 8)}…${value.slice(-6)}` : '—';
}

// ── จำ tx ที่ส่งไปแล้วของแต่ละคำสั่ง (ปิดหน้า/รีเฟรชแล้วไม่ส่งซ้ำ) ───────────────
const TX_KEY = 'tpix_launchpad_payments';

export function loadPayments(storage = globalThis.localStorage) {
    try {
        const parsed = JSON.parse(storage?.getItem(TX_KEY) ?? '{}');
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
}

export function rememberPayment(orderId, txHash, storage = globalThis.localStorage) {
    const all = loadPayments(storage);
    all[orderId] = { txHash, at: Date.now() };
    // เก็บแค่ 30 รายการล่าสุด
    const trimmed = Object.fromEntries(Object.entries(all).sort((a, b) => b[1].at - a[1].at).slice(0, 30));
    try {
        storage?.setItem(TX_KEY, JSON.stringify(trimmed));
    } catch { /* โหมดส่วนตัว/เต็ม — แค่ไม่จำ */ }
    return trimmed;
}
