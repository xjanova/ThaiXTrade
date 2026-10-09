/**
 * TPIX TRADE — ตั้งค่าเปิด/ปิดผู้ช่วย AI (แชทน้อง TPIX) บนหน้าเทรด
 *
 * เจ้าของสั่ง: "หน้าเว็บเทรดตั้งค่าเปิดปิดผู้ช่วยเอไอได้"
 *
 * ปุ่มแชทลอยกินมุมขวาล่างของกระดานเทรดพอดี (ทับปุ่มซื้อ/ขายบนมือถือ
 * และทับสมุดคำสั่งบนจอกว้างในโหมดพอดีหน้าจอ) จึงต้องปิดได้เฉพาะหน้าเทรด
 * หน้าอื่นยังมีผู้ช่วยเหมือนเดิม
 *
 * จำไว้ต่อเครื่อง (localStorage) — ค่าปริยายเปิด ผู้ใช้เดิมเห็นเหมือนเดิมจนกว่าจะกดปิดเอง
 * ที่ตั้งค่ามี 3 จุดที่ใช้ค่าเดียวกันนี้: เมนู "ปรับผัง" บนหน้าเทรด (จอกว้าง) ·
 * หัวหน้าต่างแชทบนหน้าเทรด (ทุกขนาดจอ) · หน้าตั้งค่า (ทุกขนาดจอ — ทางเปิดคืนบนมือถือ)
 *
 * Developed by Xman Studio
 */

import { ref, watch } from 'vue';

export const ASSISTANT_TRADE_KEY = 'tpix.trade.assistant';

/** /trade และ /trade/BTC-USDT — ไม่รวมหน้าอื่นที่บังเอิญขึ้นต้นด้วยคำเดียวกัน */
export function isTradeUrl(url) {
    return /^\/trade(?:[/?#]|$)/.test(String(url || ''));
}

function readStored() {
    try {
        return localStorage.getItem(ASSISTANT_TRADE_KEY) !== '0';
    } catch {
        // โหมดส่วนตัว/บล็อกที่เก็บข้อมูล — เปิดไว้ตามค่าปริยาย
        return true;
    }
}

const showOnTrade = ref(typeof window === 'undefined' ? true : readStored());

watch(showOnTrade, (value) => {
    try {
        localStorage.setItem(ASSISTANT_TRADE_KEY, value ? '1' : '0');
    } catch {
        // เก็บไม่ได้ก็ยังมีผลในหน้านี้ แค่ไม่ถูกจำไว้
    }
});

// เปิดหลายแท็บ: ปิดในแท็บหนึ่งแล้วแท็บอื่นต้องตามด้วย ไม่งั้นผู้ใช้เห็นปุ่มโผล่กลับมาเอง
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (event) => {
        if (event.key === ASSISTANT_TRADE_KEY) showOnTrade.value = readStored();
    });
}

export function useAssistantPref() {
    return {
        showOnTrade,
        setShowOnTrade(value) {
            showOnTrade.value = !!value;
        },
    };
}

/** สำหรับเทสต์เท่านั้น */
export function __reloadAssistantPref() {
    showOnTrade.value = readStored();
}
