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

/** รายการดิบจาก API — ผู้เรียกแต่ละที่ค่อยแปลงเป็นรูปแบบที่ตัวเองใช้ */
const trades = ref([]);
const isLoading = ref(false);

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
            return [];
        }

        if (!force && loadedFor === address) return trades.value;

        // กันยิงซ้อน — หลายคอมโพเนนต์เรียกพร้อมกันตอนหน้าโหลดเสร็จ
        if (inFlight) return inFlight;

        isLoading.value = true;
        inFlight = axios
            .get('/api/v1/trading/history', { params: { wallet_address: address } })
            .then(({ data }) => {
                trades.value = data?.success ? (data.data ?? []) : [];
                loadedFor = address;
                return trades.value;
            })
            .catch(() => {
                // 403 = ยังไม่ได้เซ็นยืนยันกระเป๋า ไม่ใช่ error ที่ต้องเด้งเตือน
                trades.value = [];
                return [];
            })
            .finally(() => {
                isLoading.value = false;
                inFlight = null;
            });

        return inFlight;
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

    return { trades, isLoading, load, markersFor };
}
