/**
 * TPIX TRADE — ฟีเจอร์ไหนเปิดใช้ได้จริงแล้วบ้าง
 *
 * เซิร์ฟเวอร์ปฏิเสธ swap ด้วย 503 เมื่อยังไม่ได้ตั้ง fee_collector_wallet และปฏิเสธ
 * bridge เมื่อ address ของสัญญายังว่าง — แต่หน้าเว็บไม่เคยถามก่อน ผู้ใช้จึงกรอกยอด
 * เลือกเหรียญ กดปุ่ม แล้วเจอ error ทุกครั้ง สิ่งที่ผู้ใช้สรุปคือ "เว็บพัง"
 * ไม่ใช่ "เจ้าของเว็บยังตั้งค่าไม่เสร็จ"
 *
 * ตัวนี้ถามครั้งเดียวแล้วแชร์ผลให้ทุกหน้า (คำขอค้างอยู่ก็แชร์ ไม่ยิงซ้ำ)
 *
 * ⚠️ ถามไม่สำเร็จ = ปิดปุ่มไว้ก่อน ไม่ใช่เปิด
 *    เดาว่าเปิดแล้วปล่อยให้กด = ผู้ใช้เสียเวลากรอกฟอร์มจนจบเพื่อไปเจอ error
 *    ที่ปลายทางอยู่ดี เดาว่าปิดแล้วผิด = เห็นปุ่มลองใหม่ ซึ่งกู้คืนได้ในคลิกเดียว
 *
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import axios from 'axios';

// สถานะระดับโมดูล — หลายหน้าที่เปิดพร้อมกันใช้ผลเดียวกัน
const config = ref(null);
const isLoading = ref(false);
const loadFailed = ref(false);
let inFlight = null;

async function fetchConfig(force = false) {
    if (!force && config.value) return config.value;
    // คำขอที่ค้างอยู่มีแล้วก็รอตัวนั้น ไม่ยิงใหม่ — กันหลายหน้าเรียกพร้อมกัน
    if (inFlight) return inFlight;

    isLoading.value = true;
    loadFailed.value = false;

    inFlight = axios
        .get('/api/v1/fees')
        .then(({ data }) => {
            config.value = data;
            return data;
        })
        .catch(() => {
            loadFailed.value = true;
            return null;
        })
        .finally(() => {
            isLoading.value = false;
            inFlight = null;
        });

    return inFlight;
}

export function usePlatformReadiness() {
    /**
     * ยังไม่รู้ผล = ยังไม่พร้อม
     *
     * ระหว่างโหลดต้องปิดปุ่มไว้ก่อน ไม่ใช่เปิดแล้วค่อยปิดทีหลัง — เปิดไว้ก่อน
     * แปลว่ามีช่วงเวลาที่กดได้จริงแล้วพลาด และเป็นช่วงที่หน้าเพิ่งโหลดเสร็จพอดี
     * ซึ่งเป็นตอนที่คนกดมากที่สุด
     */
    const swapReady = computed(() => config.value?.swap?.enabled === true);
    const bridgeReady = computed(() => config.value?.bridge?.enabled === true);

    /** ข้อความบอกเหตุผลที่ยังกดไม่ได้ — null แปลว่าพร้อมใช้ */
    function reasonFor(feature) {
        if (isLoading.value || (!config.value && !loadFailed.value)) {
            return 'กำลังตรวจสอบสถานะบริการ...';
        }
        if (loadFailed.value) {
            return 'ตรวจสอบสถานะบริการไม่สำเร็จ — ลองใหม่อีกครั้ง';
        }
        if (config.value?.[feature]?.enabled === true) {
            return null;
        }

        return 'ยังไม่เปิดให้ใช้งาน — ผู้ดูแลระบบยังตั้งค่ากระเป๋าค่าธรรมเนียมไม่ครบ';
    }

    return {
        config,
        isLoading,
        loadFailed,
        swapReady,
        bridgeReady,
        reasonFor,
        load: fetchConfig,
        reload: () => fetchConfig(true),
    };
}
