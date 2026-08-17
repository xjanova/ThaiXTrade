/**
 * TPIX TRADE — อุปกรณ์ที่ใช้อยู่ลากของด้วยเมาส์ได้ไหม
 *
 * HTML5 drag-and-drop (dragstart/dragover/drop) ไม่ทำงานกับการแตะเลย — ไม่ใช่ว่า
 * ทำงานได้ไม่ดี แต่คือไม่ยิงอีเวนต์ออกมาเลย เบราว์เซอร์บนมือถือ/แท็บเล็ตตีความ
 * การลากนิ้วเป็นการเลื่อนหน้าจอเสมอ
 *
 * เดิมหน้าเทรดตัดสินจากความกว้างจออย่างเดียว (< 1280px = ล็อก) แท็บเล็ตจอใหญ่
 * อย่าง iPad Pro แนวนอน (1366px) จึงหลุดเกณฑ์มา แล้วโชว์ที่จับลากที่กดยังไงก็ไม่ขยับ
 *
 * ⚠️ ใช้ `pointer: coarse` ไม่ใช่ `any-pointer: coarse`
 *    โน้ตบุ๊กจอสัมผัสมีทั้งนิ้วและทัชแพด — `any-pointer` จะจับได้ด้วยแล้วปิด
 *    ความสามารถทิ้งทั้งที่เมาส์ใช้ลากได้ปกติ ส่วน `pointer` ดูตัวชี้หลักเท่านั้น
 *
 * Developed by Xman Studio
 */

import { ref, readonly } from 'vue';

const QUERY = '(pointer: fine)';

// ref เดียวใช้ร่วมทั้งแอพ — การ์ดในหน้าเทรดมีหลายใบ ไม่ควรผูก listener ใบละตัว
const hasFinePointer = ref(true);

let query = null;

function sync() {
    if (query) hasFinePointer.value = query.matches;
}

function ensureWatching() {
    // ระหว่าง SSR ไม่มี window — ถือว่าเป็นเมาส์ไว้ก่อน แล้ว client จะแก้ให้เองตอน mount
    if (query || typeof window === 'undefined' || !window.matchMedia) return;

    query = window.matchMedia(QUERY);
    sync();

    // ไม่ต้องถอด listener: ผูกกับ media query ระดับแอพที่อยู่ตลอดอายุหน้า
    // และมีตัวเดียวเสมอ ไม่ใช่ตัวใหม่ต่อคอมโพเนนต์
    query.addEventListener?.('change', sync);
}

export function usePointerCapability() {
    ensureWatching();

    return {
        /** true = ชี้ด้วยเมาส์/ปากกาได้แม่นยำ → ลากวางแบบ HTML5 ใช้ได้ */
        hasFinePointer: readonly(hasFinePointer),
    };
}
