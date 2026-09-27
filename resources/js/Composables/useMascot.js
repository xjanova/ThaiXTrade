/**
 * TPIX TRADE — สถานะของน้อง TPIX ที่คอมโพเนนต์อื่นต้องรู้
 *
 * - active: น้องอยู่บนจอจริง (โหลด 3D สำเร็จและไม่ได้ถูกซ่อน)
 *   → หน้าต่างแชทลอยซ่อนปุ่มเปิดของตัวเอง ใช้น้องเป็นทางเข้าแชทแทน และคุยด้วยบุคลิกของน้อง
 * - hidden: ผู้ใช้กดซ่อนน้อง — ซ่อนแค่ระหว่างดูหน้านี้รอบนี้ ไม่จำไว้ในเครื่อง
 *   (เจ้าของสั่ง: "ถ้ากลับมาหน้า 3D ควรกลับมาเองทันที") → เข้าหน้าแรก 3D ใหม่เมื่อไหร่ น้องกลับมาเอง
 * - present: หน้านี้มีตัวน้อง (หน้าแรก 3D) — ซ่อนอยู่ก็ต้องมีปุ่ม "เรียกน้องกลับมา" ให้กดได้ตลอด
 *   (เจ้าของสั่ง: "ตัวอวาต้าไม่มีหนทางกลับมาได้ย่อแล้ว ต้องมีทางเรียกเธอกลับมาได้ตลอด")
 * - recallRequests: ผู้ใช้เรียกน้องกลับมา → น้องโบกมือทักว่ากลับมาแล้ว
 * - supported: false เมื่อเครื่องนี้แสดง 3D ไม่ได้ → ปุ่มทัวร์เปิดหน้าต่างแชทแทน
 * - tourRequests: ปุ่ม "ทัวร์กับน้อง TPIX" บนหน้าแรกเพิ่มตัวนับนี้ คอมโพเนนต์น้องคอยฟัง
 *
 * Developed by Xman Studio
 */

import { ref } from 'vue';

// เวอร์ชันก่อนจำค่าซ่อนไว้ถาวร — ล้างทิ้ง คนที่เคยซ่อนไว้จะได้เห็นน้องอีก
try {
    if (typeof window !== 'undefined') localStorage.removeItem('tpix_mascot_hidden');
} catch {
    // บล็อกที่เก็บข้อมูล — ไม่มีอะไรให้ล้าง
}

const hidden = ref(false);
const active = ref(false);
const present = ref(false);
const supported = ref(true);
const tourRequests = ref(0);
const recallRequests = ref(0);

export function useMascot() {
    return {
        hidden,
        active,
        present,
        supported,
        tourRequests,
        recallRequests,
        hide() {
            hidden.value = true;
            active.value = false;
        },
        show() {
            hidden.value = false;
        },
        /** ปุ่ม "เรียกน้องกลับมา" — แสดงตัว + ทักทาย */
        recall() {
            hidden.value = false;
            recallRequests.value += 1;
        },
        requestTour() {
            hidden.value = false;
            tourRequests.value += 1;
        },
    };
}
