/**
 * TPIX TRADE — คำสั่งตั้งราคา (limit order) ของคู่ที่เทรดบนเชนจริง
 *
 * AMM (PancakeSwap / TPIX DEX) ไม่มีสมุดคำสั่ง — คำสั่งตั้งราคาจึงเป็น "คำสั่งรอราคา":
 *   1. เก็บไว้ที่เซิร์ฟเวอร์ (เห็นตรงกันทุกอุปกรณ์ · ยกเลิกได้ · หมดอายุเอง)
 *   2. หน้าเทรดเฝ้าราคา พอถึงก็แจ้งให้เจ้าของกดยืนยัน
 *   3. ส่งเป็นไม้ราคาตลาดที่ minOut = "ได้รับอย่างน้อย" ของคำสั่ง — ราคาแย่กว่าที่ตั้ง = revert บนเชน
 *   4. เซิร์ฟเวอร์ปิดคำสั่งเมื่อเห็นไม้ลงเชนจริง
 * เราไม่ถือเหรียญและไม่เซ็นแทนผู้ใช้ — จึงต้องมีคนกดยืนยันตอนราคาถึง (เปิดหน้าเทรด/แอปไว้)
 *
 * Developed by Xman Studio
 */
import { ref } from 'vue';
import axios from 'axios';

/*
 * ส่วนเผื่อค่าธรรมเนียม — ราคาตลาด (ticker) ไม่ใช่ราคาที่ได้จริง
 * ไม้บนเชนเสียค่าธรรมเนียมพูล (~0.25–0.3%) + ค่าธรรมเนียมแพลตฟอร์ม (0.3% บน BSC)
 * แจ้งว่า "ถึงราคา" เมื่อราคาตลาดดีกว่าราคาที่ตั้งเกินส่วนเผื่อนี้ — ไม่งั้นกดยืนยันแล้วเจอ "ราคายังไม่ถึง"
 * ตัวตัดสินจริงคือราคาจาก router ตอนกดยืนยัน + minOut บนเชน ตัวเลขนี้แค่กันแจ้งเตือนเก้อ
 */
export const LIMIT_COST_MARGIN = 0.006;

/** คิวแจ้งเซิร์ฟเวอร์ว่า "ยืนยันในกระเป๋าแล้ว" ที่ยังส่งไม่ถึง — แท็บปิด/เน็ตหลุดแล้วต้องส่งต่อได้ */
export const SUBMIT_QUEUE_KEY = 'tpix.limitOrderSubmits';

/** รูปเดียวของชื่อคู่ — API ใช้ทั้ง `BTC-USDT` และ `BTC/USDT` */
export function limitPairKey(pair) {
    return String(pair || '').trim().replace(/\//g, '-').toUpperCase();
}

/**
 * ราคาตลาดถึงราคาที่ตั้งแล้วหรือยัง (เผื่อค่าธรรมเนียมแล้ว)
 * ซื้อ: ราคาตลาดต้อง "ต่ำกว่า" ราคาที่ตั้ง · ขาย: ต้อง "สูงกว่า"
 */
export function isLimitPriceReached(order, marketPrice, margin = LIMIT_COST_MARGIN) {
    const limit = Number(order?.price);
    const market = Number(marketPrice);
    if (!(limit > 0) || !(market > 0)) return false;

    return order.side === 'buy'
        ? market * (1 + margin) <= limit
        : market >= limit * (1 + margin);
}

function readQueue() {
    try {
        const rows = JSON.parse(localStorage.getItem(SUBMIT_QUEUE_KEY) || '[]');
        return Array.isArray(rows) ? rows : [];
    } catch {
        return [];
    }
}

function writeQueue(rows) {
    try {
        if (rows.length) localStorage.setItem(SUBMIT_QUEUE_KEY, JSON.stringify(rows.slice(-50)));
        else localStorage.removeItem(SUBMIT_QUEUE_KEY);
    } catch {
        // โหมดส่วนตัว/พื้นที่เต็ม — ไม่มีคิวก็ยังทำงานได้ (เซิร์ฟเวอร์เปิดคำสั่งคืนเองเมื่อไม่เห็นไม้)
    }
}

/** รหัสข้อผิดพลาดของ LimitOrderController ที่ข้อความพร้อมแสดง (ภาษาไทยจากเซิร์ฟเวอร์) */
const SERVER_MESSAGE_CODES = new Set([
    'PAIR_NOT_TRADABLE',
    'TOO_MANY_OPEN_ORDERS',
    'INVALID_AMOUNT',
    'ORDER_NOT_OPEN',
]);

export function useLimitOrders() {
    /** คำสั่งที่ยังเปิดอยู่ของกระเป๋าปัจจุบัน (รอราคา + ยืนยันแล้วรอผล) */
    const orders = ref([]);
    const isLoading = ref(false);
    /** 403 = ยังไม่ได้เซ็นยืนยันกระเป๋า — หน้าเทรดต้องไม่เฝ้าราคาด้วยรายการว่างแล้วทำเหมือนไม่มีคำสั่ง */
    const needsVerification = ref(false);

    let loadSeq = 0;

    async function load(wallet) {
        const seq = ++loadSeq;

        if (!wallet) {
            orders.value = [];
            needsVerification.value = false;
            return;
        }

        isLoading.value = true;
        try {
            await flushSubmits(wallet);
            const { data } = await axios.get('/api/v1/trading/limit-orders', { params: { wallet_address: wallet } });
            if (seq !== loadSeq) return;   // สลับกระเป๋า/มีคำขอใหม่กว่า — ทิ้งผลนี้

            orders.value = data?.success && Array.isArray(data.data) ? data.data : [];
            needsVerification.value = false;
        } catch (err) {
            if (seq !== loadSeq) return;
            orders.value = [];
            needsVerification.value = err?.response?.status === 403;
        } finally {
            if (seq === loadSeq) isLoading.value = false;
        }
    }

    /**
     * สร้างคำสั่ง — ไม่ throw คืนผลให้ผู้เรียกตัดสินใจ
     * @returns {Promise<{ok: true, order: object} | {ok: false, reason: 'verify'|'kyc'|'server'|'failed', message?: string}>}
     */
    async function place(wallet, payload) {
        try {
            const { data } = await axios.post('/api/v1/trading/limit-orders', { wallet_address: wallet, ...payload });
            if (!data?.success || !data.data) return { ok: false, reason: 'failed' };

            orders.value = [data.data, ...orders.value];

            return { ok: true, order: data.data };
        } catch (err) {
            const status = err?.response?.status;
            const code = err?.response?.data?.error?.code;

            if (SERVER_MESSAGE_CODES.has(code)) {
                return { ok: false, reason: 'server', message: err.response.data.error.message };
            }
            if (status === 403 && /KYC/i.test(String(code || ''))) return { ok: false, reason: 'kyc' };
            if (status === 403) return { ok: false, reason: 'verify' };

            // ห้ามโชว์ข้อความดิบ — log ไว้ดูเองพอ
            console.warn('Place limit order failed:', status ?? err?.message);

            return { ok: false, reason: 'failed' };
        }
    }

    /**
     * เจ้าของยืนยันในกระเป๋าแล้ว — ผูกคำสั่งกับธุรกรรม
     *
     * เข้าคิวในเครื่อง "ก่อน" ยิง: ธุรกรรมออกจากกระเป๋าไปแล้ว ถ้าคำขอนี้หลุด (เน็ต/ปิดแท็บ)
     * คำสั่งจะยังขึ้น "รอราคา" แล้วชวนให้ยืนยันซ้ำ = ไม้ลงสองรอบ
     */
    async function markSubmitted(wallet, id, txHash) {
        if (!wallet || !id || !txHash) return false;

        const key = String(wallet).toLowerCase();
        writeQueue([...readQueue().filter(r => r.id !== id), { wallet: key, id, txHash }]);

        // หน้าจอต้องเลิกชวนให้ยืนยันทันที ไม่รอเซิร์ฟเวอร์ตอบ
        orders.value = orders.value.map(o => (o.id === id ? { ...o, status: 'submitted', tx_hash: txHash } : o));

        return flushSubmits(wallet);
    }

    /** ส่งคิวที่ค้างของกระเป๋านี้ — เรียกตอนโหลดรายการทุกครั้ง */
    async function flushSubmits(wallet) {
        const key = String(wallet || '').toLowerCase();
        const mine = readQueue().filter(r => r.wallet === key);
        if (!mine.length) return true;

        let allSent = true;
        for (const row of mine) {
            try {
                const { data } = await axios.post(`/api/v1/trading/limit-orders/${encodeURIComponent(row.id)}/submitted`, {
                    wallet_address: key,
                    tx_hash: row.txHash,
                });
                writeQueue(readQueue().filter(r => r.id !== row.id));
                if (data?.data) {
                    orders.value = orders.value
                        .map(o => (o.id === row.id ? data.data : o))
                        .filter(o => o.status === 'open' || o.status === 'submitted');
                }
            } catch (err) {
                const status = err?.response?.status;
                // 404/409/422 = เซิร์ฟเวอร์ตัดสินแล้วว่าไม่รับ (ปิดไปแล้ว/hash ถูกใช้) — ยิงซ้ำก็ได้คำตอบเดิม
                if (status === 404 || status === 409 || status === 422) {
                    writeQueue(readQueue().filter(r => r.id !== row.id));
                } else {
                    allSent = false;   // เน็ตหลุด / 403 / 5xx — เก็บไว้ส่งรอบหน้า
                }
            }
        }

        return allSent;
    }

    /** คำสั่งนี้มีธุรกรรมที่ส่งแล้วแต่ยังแจ้งเซิร์ฟเวอร์ไม่ถึงไหม — ห้ามชวนยืนยันซ้ำ */
    function hasPendingSubmit(id) {
        return readQueue().some(r => r.id === id);
    }

    function reset() {
        ++loadSeq;
        orders.value = [];
        needsVerification.value = false;
        isLoading.value = false;
    }

    return {
        orders,
        isLoading,
        needsVerification,
        load,
        place,
        markSubmitted,
        flushSubmits,
        hasPendingSubmit,
        reset,
    };
}
