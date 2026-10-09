/**
 * TPIX TRADE — useMyTrades
 * ประวัติไม้ที่ "ผู้ใช้วางเอง" (ไม่ใช่ของบอท)
 *
 * เป็น singleton ระดับโมดูล — ตารางประวัติเทรดกับป้ายบนกราฟใช้ชุดเดียวกัน
 * ยิง endpoint ครั้งเดียวแล้วทั้งสองที่เห็นตรงกันเสมอ ไม่ใช่ต่างคนต่างโหลด
 * แล้วกราฟขึ้นไม้ที่ตารางยังไม่มี (หรือกลับกัน) ซึ่งผู้ใช้จะไม่เชื่อทั้งสองอัน
 *
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import axios from 'axios';
import { useWalletStore } from '@/Stores/walletStore';
import { flushPendingSwapRecords } from '@/Composables/useSwap';

/** รายการดิบจาก API — ผู้เรียกแต่ละที่ค่อยแปลงเป็นรูปแบบที่ตัวเองใช้ */
const trades = ref([]);
const isLoading = ref(false);
/**
 * true เมื่อ backend ตอบ 403 (กระเป๋ายังไม่เซ็นยืนยัน / ลายเซ็นหมดอายุ 4 ชม.)
 *
 * เดิม 403 ถูกกลืนเป็น "ยังไม่มีประวัติ" — ผู้ใช้ที่เพิ่งเทรดไปเห็นตารางว่างแล้วคิดว่าไม้หาย
 * ตารางต้องบอกตรงๆ ว่าต้องเซ็นยืนยันก่อน พร้อมปุ่มให้กด
 */
const needsVerification = ref(false);
/** โหลดไม่สำเร็จด้วยเหตุอื่น (เน็ต/เซิร์ฟเวอร์) — ต่างจาก "ยังไม่มีไม้" */
const loadFailed = ref(false);

let inFlight = null;
let loadedFor = null;

export function useMyTrades() {
    const walletStore = useWalletStore();

    /**
     * โหลดประวัติของกระเป๋าปัจจุบัน.
     *
     * @param {boolean} force ยิงใหม่แม้โหลดของกระเป๋านี้ไปแล้ว (ใช้หลังเทรดเสร็จ)
     */
    async function load(force = false) {
        const address = walletStore.address;

        if (!address) {
            trades.value = [];
            loadedFor = null;
            needsVerification.value = false;
            loadFailed.value = false;
            return [];
        }

        if (!force && loadedFor === address) return trades.value;

        // กันยิงซ้อน — หลายคอมโพเนนต์เรียกพร้อมกันตอนหน้าโหลดเสร็จ
        // (ยกเว้นคำขอที่ค้างอยู่เป็นของกระเป๋าใบก่อน — ผลนั้นใช้ไม่ได้แล้ว)
        if (inFlight && inFlight.address === address) return inFlight;

        // สลับกระเป๋า: ห้ามโชว์ไม้ของใบก่อนระหว่างรอ
        if (loadedFor !== address) trades.value = [];

        isLoading.value = true;
        // ส่งบันทึกไม้ที่ค้างในเครื่องก่อน (สวอปสำเร็จแต่บันทึกล้ม) แล้วค่อยอ่านประวัติ
        // → หลังผู้ใช้เซ็นยืนยันกระเป๋าใหม่ ไม้ที่เคยหายจะกลับมาในรอบโหลดนี้เลย
        const request = flushPendingSwapRecords(address)
            .catch(() => 0)
            .then(() => axios.get('/api/v1/trading/history', { params: { wallet_address: address } }))
            .then(({ data }) => {
                if (walletStore.address !== address) return trades.value;   // สลับกระเป๋าระหว่างรอ
                trades.value = data?.success ? (data.data ?? []) : [];
                loadedFor = address;
                needsVerification.value = false;
                loadFailed.value = false;
                return trades.value;
            })
            .catch((err) => {
                if (walletStore.address !== address) return trades.value;
                trades.value = [];
                // 403 = ยังไม่ได้เซ็นยืนยันกระเป๋า — ตารางต้องบอกให้เซ็น ไม่ใช่โชว์ว่าว่าง
                needsVerification.value = err?.response?.status === 403;
                loadFailed.value = !needsVerification.value;
                return [];
            })
            .finally(() => {
                if (inFlight === request) {
                    isLoading.value = false;
                    inFlight = null;
                }
            });

        request.address = address;
        inFlight = request;

        return request;
    }

    /**
     * ไม้ของคู่เทรดหนึ่ง แปลงเป็นป้ายสำหรับกราฟ.
     *
     * เวลาเป็น "วินาที" ตามที่ lightweight-charts ใช้ (API ส่ง ISO string มา)
     * และเทียบชื่อคู่แบบไม่สนใจว่าใช้ `-` หรือ `/` เพราะสองฝั่งเขียนไม่เหมือนกัน
     */
    function markersFor(pair) {
        const wanted = String(pair || '').replace('-', '/').toUpperCase();

        return trades.value
            .filter((t) => String(t.pair || '').replace('-', '/').toUpperCase() === wanted)
            .map((t) => {
                const seconds = Math.floor(new Date(t.created_at).getTime() / 1000);
                const price = Number(t.price);

                return {
                    time: seconds,
                    side: String(t.side || '').toLowerCase(),
                    price: Number.isFinite(price) ? price : null,
                    source: 'mine',
                };
            })
            .filter((m) => Number.isFinite(m.time) && (m.side === 'buy' || m.side === 'sell'));
    }

    return { trades, isLoading, needsVerification, loadFailed, load, markersFor };
}
