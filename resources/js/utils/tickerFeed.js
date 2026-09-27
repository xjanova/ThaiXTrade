/**
 * TPIX TRADE — ดึงราคาทุกคู่ (/api/v1/market/tickers) ร่วมกันทั้งหน้า
 *
 * แถบราคาวิ่งด้านบน + หน้าแรก + ตัวเลือกคู่เทรด ต่างคนต่างยิงคำขอเดียวกันพร้อมกันตอนเปิดหน้า
 * → ขอพร้อมกันหลายที่ = ยิงครั้งเดียวแล้วใช้คำตอบร่วมกัน · ได้มาไม่เกิน MAX_AGE_MS ใช้ซ้ำได้เลย
 * ตัวรีเฟรชอัตโนมัติ (ทุก 15 วิ) ยังได้ราคาใหม่เสมอเพราะอายุเกิน MAX_AGE_MS แล้ว
 *
 * คืนค่าเป็น response ของ axios เหมือนเดิม — ที่เรียกใช้เปลี่ยนแค่บรรทัดเดียว
 *
 * Developed by Xman Studio
 */
import axios from 'axios';

export const MAX_AGE_MS = 5000;

let inflight = null;
let cached = null; // { at, res }

export function getTickers() {
    if (cached && Date.now() - cached.at < MAX_AGE_MS) return Promise.resolve(cached.res);
    if (inflight) return inflight;
    inflight = axios
        .get('/api/v1/market/tickers')
        .then((res) => {
            // เก็บเฉพาะคำตอบที่ใช้ได้ — คำตอบเสียต้องให้รอบถัดไปลองใหม่ทันที
            if (res?.data?.success) cached = { at: Date.now(), res };
            return res;
        })
        .finally(() => {
            inflight = null;
        });
    return inflight;
}

/** สำหรับเทสต์เท่านั้น */
export function __resetTickerFeed() {
    inflight = null;
    cached = null;
}
