/**
 * TPIX TRADE — ค่าตั้งค่าธุรกรรมที่ผู้ใช้บันทึกไว้ในหน้าตั้งค่า (/settings)
 *
 * ⚠️ เดิมหน้าตั้งค่าบันทึก slippage / ระยะเวลาจำกัดธุรกรรม ลงเครื่องอย่างเดียว
 *    แต่ไม่มีใครอ่านไปใช้เลย — ฟอร์มเทรดใช้ค่าจากเซิร์ฟเวอร์ หน้า Swap ตั้ง 0.5% ตายตัว
 *    และ router ใช้กำหนดเวลา 20 นาทีตายตัว ผู้ใช้ตั้งเท่าไรก็ไม่มีผล
 *
 * ค่าในเครื่องแก้มือได้ จึงตรวจขอบเขตทุกครั้งที่อ่าน — ค่าเสีย = ใช้ค่าปริยาย
 *
 * Developed by Xman Studio
 */

export const TRADE_SETTINGS_KEY = 'tpix_trade_settings';

/** slippage ที่ยอมรับได้ (%) — นอกช่วงนี้คือพิมพ์ผิด ไม่ใช่ความตั้งใจ */
export const SLIPPAGE_MIN = 0.01;
export const SLIPPAGE_MAX = 50;

/** ระยะเวลาจำกัดธุรกรรม (นาที) */
export const DEADLINE_DEFAULT_MIN = 20;
export const DEADLINE_MIN = 1;
export const DEADLINE_MAX = 180;

export function readTradeSettings() {
    try {
        const parsed = JSON.parse(localStorage.getItem(TRADE_SETTINGS_KEY) || 'null');
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch {
        return null;
    }
}

/** slippage ที่ใช้ได้จริง หรือ null ถ้าไม่ได้ตั้ง/ค่าเสีย */
export function parseSlippage(value) {
    const num = Number(String(value ?? '').replace('%', '').trim());
    if (!Number.isFinite(num) || num < SLIPPAGE_MIN || num > SLIPPAGE_MAX) return null;

    return num;
}

/**
 * slippage ที่ผู้ใช้ตั้งไว้ในหน้าตั้งค่า (%)
 * null = ไม่เคยตั้ง → ผู้เรียกใช้ค่าปริยายของตัวเอง (เช่นฟอร์มเทรดใช้ค่าจากเซิร์ฟเวอร์)
 */
export function preferredSlippage() {
    return parseSlippage(readTradeSettings()?.slippageTolerance);
}

/** ระยะเวลาจำกัดธุรกรรมเป็นนาที ที่ตรวจขอบเขตแล้ว (ไม่เคยตั้ง = 20) */
export function parseDeadlineMinutes(value) {
    const num = Number(String(value ?? '').trim());
    if (!Number.isFinite(num) || num <= 0) return DEADLINE_DEFAULT_MIN;

    return Math.min(DEADLINE_MAX, Math.max(DEADLINE_MIN, Math.round(num)));
}

/** ระยะเวลาจำกัดธุรกรรมเป็นวินาที — ใช้คำนวณ deadline ที่ส่งเข้า router */
export function txDeadlineSeconds() {
    return parseDeadlineMinutes(readTradeSettings()?.txDeadline) * 60;
}
