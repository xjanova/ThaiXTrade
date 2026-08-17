/**
 * TPIX TRADE — แถบแจ้งเตือนแบบลอย (toast)
 *
 * เจ้าของสั่ง: "แถบคำเตือนควรเป็นแถบที่แสดงซ้อนไว้ 10 วินาทีแล้วหายไป หรือกดปิดได้"
 *
 * ทำไมต้องมีตัวกลาง: ก่อนหน้านี้แต่ละหน้าทำแถบแจ้งเตือนของตัวเอง ทำให้อายุ
 * ไม่เท่ากัน (บางที่ 4 วิ) บางอันปิดเองไม่ได้ และที่แย่สุดคือบางอันกินพื้นที่
 * ในผังจริงจนดันการ์ดเลื่อนลง แทนที่จะลอยทับอยู่ข้างบน
 *
 * ⚠️ ใช้กับ "ข้อความชั่วคราว" เท่านั้น
 *    อะไรที่เป็น "สถานะ" (เช่นแถบบอกว่าคู่นี้เทรดออนเชนได้) ห้ามทำเป็น toast
 *    เพราะสถานะต้องอยู่ให้เห็นตลอดเวลาที่มันยังเป็นจริง ไม่ใช่หายไปใน 10 วิ
 *
 * เก็บ state ระดับโมดูล — ทุกหน้าเห็นคิวเดียวกัน ไม่ต้องส่ง prop ข้ามคอมโพเนนต์
 *
 * Developed by Xman Studio
 */
import { ref } from 'vue';

/** อายุเริ่มต้นตามที่เจ้าของกำหนด */
export const DEFAULT_TOAST_MS = 10000;

const toasts = ref([]);
const timers = new Map();
let nextId = 0;

function clearTimer(id) {
    const timer = timers.get(id);

    if (timer) {
        clearTimeout(timer);
        timers.delete(id);
    }
}

/** ปิดข้อความหนึ่งอัน (ผู้ใช้กดปิด หรือหมดเวลาเอง) */
export function dismissToast(id) {
    clearTimer(id);
    toasts.value = toasts.value.filter(t => t.id !== id);
}

/**
 * แสดงข้อความลอย
 *
 * @param {object} options
 * @param {string} options.text      ข้อความ
 * @param {string} [options.type]    success | error | info | pending
 * @param {number} [options.duration] มิลลิวินาที (0 = ค้างไว้จนกดปิด)
 * @param {object} [options.link]    { href, label } ลิงก์ท้ายข้อความ เช่นดู tx
 * @returns {number} id ไว้ปิดเองทีหลัง
 */
export function showToast({ text, type = 'info', duration = DEFAULT_TOAST_MS, link = null }) {
    const id = ++nextId;

    toasts.value = [...toasts.value, { id, text, type, link }];

    // duration = 0 สำหรับงานที่ยังไม่จบ (เช่นกำลังรอ tx) — ต้องปิดเองด้วย dismissToast
    if (duration > 0) {
        timers.set(id, setTimeout(() => dismissToast(id), duration));
    }

    return id;
}

/** แทนที่ข้อความเดิมด้วยอันใหม่ — ใช้ตอนงานเดียวเปลี่ยนสถานะ (กำลังส่ง → สำเร็จ) */
export function replaceToast(id, options) {
    dismissToast(id);

    return showToast(options);
}

export function useToasts() {
    return { toasts, showToast, dismissToast, replaceToast };
}
