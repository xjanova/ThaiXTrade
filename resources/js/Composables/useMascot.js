/**
 * TPIX TRADE — สถานะของน้อง TPIX ที่คอมโพเนนต์อื่นต้องรู้
 *
 * - active: น้องอยู่บนจอจริง (โหลด 3D สำเร็จและไม่ได้ถูกซ่อน)
 *   → หน้าต่างแชทลอยซ่อนปุ่มเปิดของตัวเอง ใช้น้องเป็นทางเข้าแชทแทน และคุยด้วยบุคลิกของน้อง
 * - hidden: ผู้ใช้กดซ่อนน้อง (จำไว้ในเครื่อง)
 * - supported: false เมื่อเครื่องนี้แสดง 3D ไม่ได้ → ปุ่มทัวร์เปิดหน้าต่างแชทแทน
 * - tourRequests: ปุ่ม "ทัวร์กับน้อง TPIX" บนหน้าแรกเพิ่มตัวนับนี้ คอมโพเนนต์น้องคอยฟัง
 *
 * Developed by Xman Studio
 */

import { ref } from 'vue';

const STORAGE_KEY = 'tpix_mascot_hidden';

function readHidden() {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function writeHidden(on) {
    try {
        if (on) localStorage.setItem(STORAGE_KEY, '1');
        else localStorage.removeItem(STORAGE_KEY);
    } catch {
        // โหมดส่วนตัว/บล็อกที่เก็บข้อมูล — ซ่อนได้เฉพาะรอบนี้
    }
}

const hidden = ref(typeof window === 'undefined' ? false : readHidden());
const active = ref(false);
const supported = ref(true);
const tourRequests = ref(0);

export function useMascot() {
    return {
        hidden,
        active,
        supported,
        tourRequests,
        hide() {
            hidden.value = true;
            active.value = false;
            writeHidden(true);
        },
        show() {
            hidden.value = false;
            writeHidden(false);
        },
        requestTour() {
            hidden.value = false;
            writeHidden(false);
            tourRequests.value += 1;
        },
    };
}
