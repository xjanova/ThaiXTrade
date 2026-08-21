/**
 * TPIX TRADE - Token Sale Store (Pinia)
 * ระบบจัดการข้อมูลการขายเหรียญ TPIX (ICO/IDO)
 * เก็บ state ของรอบขาย, phases, สถิติ, รายการซื้อ
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';
import { isPhaseOpen, isPhaseStale } from '@/utils/salePhase';

export const useTokenSaleStore = defineStore('tokenSale', () => {
    // === State ===

    // ข้อมูลรอบขายที่ active อยู่
    const sale = ref(null);

    // สถิติรวมของรอบขาย (total sold, raised, buyers)
    const stats = ref(null);

    // รายการซื้อของ wallet ที่เชื่อมต่ออยู่
    const purchases = ref([]);

    // ตาราง vesting ของ wallet ที่เชื่อมต่ออยู่
    const vestingSchedule = ref([]);

    // สถานะ loading ต่างๆ
    const isLoadingSale = ref(false);
    const isLoadingStats = ref(false);
    const isLoadingPurchases = ref(false);
    const isLoadingVesting = ref(false);
    const isPurchasing = ref(false);

    // ข้อผิดพลาด
    const error = ref(null);

    // === Computed ===

    // phase ที่เปิดขายอยู่ตอนนี้ (status active + อยู่ในช่วงวันที่กำหนด)
    const activePhase = computed(() => {
        if (!sale.value?.phases) return null;
        return sale.value.phases.find(p => isPhaseOpen(p)) || null;
    });

    /**
     * เฟสที่ "ป้ายบอกว่า active แต่จริงๆ ปิดไปแล้ว" — ใช้แสดงข้อความอธิบายผู้ใช้
     * ไม่ใช่เอาไว้ให้ซื้อ
     */
    const staleActivePhase = computed(() => {
        if (!sale.value?.phases) return null;
        return sale.value.phases.find(p => isPhaseStale(p)) || null;
    });

    // phases ทั้งหมดของรอบขาย
    const phases = computed(() => sale.value?.phases || []);

    // เปอร์เซ็นต์ขายไปแล้วทั้งหมด
    const percentSold = computed(() => {
        if (!sale.value) return 0;
        return sale.value.percent_sold || 0;
    });

    // จำนวน TPIX ที่เหลือ
    const totalRemaining = computed(() => {
        if (!sale.value) return 0;
        return sale.value.total_supply - sale.value.total_sold;
    });

    // เวลาสิ้นสุดรอบขาย (สำหรับ countdown)
    const endsAt = computed(() => {
        if (!sale.value?.ends_at) return null;
        return new Date(sale.value.ends_at);
    });

    // ยอดซื้อรวมของ wallet นี้
    const totalPurchased = computed(() => {
        return purchases.value.reduce((sum, p) => sum + (p.tpix_amount || 0), 0);
    });

    // ยอดที่ claim ได้แล้ว
    const totalClaimable = computed(() => {
        return vestingSchedule.value.reduce((sum, v) => sum + (v.claimable || 0), 0);
    });

    // === Actions ===

    /**
     * โหลดข้อมูลรอบขายที่ active พร้อม phases
     * ใช้ตอนเข้าหน้า Token Sale
     */
    async function fetchSale() {
        isLoadingSale.value = true;
        error.value = null;

        try {
            const { data } = await axios.get('/api/v1/token-sale');
            if (data.success) {
                sale.value = data.data;
            }
        } catch (err) {
            error.value = 'ไม่สามารถโหลดข้อมูลรอบขายได้';
            console.error('[TokenSale] fetchSale error:', err);
        } finally {
            isLoadingSale.value = false;
        }
    }

    /**
     * โหลดสถิติรอบขาย (total sold, raised, buyers count)
     */
    async function fetchStats() {
        isLoadingStats.value = true;

        try {
            const { data } = await axios.get('/api/v1/token-sale/stats');
            if (data.success) {
                stats.value = data.data;
            }
        } catch (err) {
            console.error('[TokenSale] fetchStats error:', err);
        } finally {
            isLoadingStats.value = false;
        }
    }

    /**
     * โหลดรายการซื้อของ wallet ที่ระบุ
     * @param {string} walletAddress - 0x... wallet address
     */
    async function fetchPurchases(walletAddress) {
        if (!walletAddress) return;
        isLoadingPurchases.value = true;

        try {
            const { data } = await axios.get(`/api/v1/token-sale/purchases/${walletAddress}`);
            if (data.success) {
                purchases.value = data.data;
            }
        } catch (err) {
            console.error('[TokenSale] fetchPurchases error:', err);
        } finally {
            isLoadingPurchases.value = false;
        }
    }

    /**
     * โหลดตาราง vesting ของ wallet
     * @param {string} walletAddress - 0x... wallet address
     */
    async function fetchVesting(walletAddress) {
        if (!walletAddress) return;
        isLoadingVesting.value = true;

        try {
            const { data } = await axios.get(`/api/v1/token-sale/vesting/${walletAddress}`);
            if (data.success) {
                vestingSchedule.value = data.data;
            }
        } catch (err) {
            console.error('[TokenSale] fetchVesting error:', err);
        } finally {
            isLoadingVesting.value = false;
        }
    }

    /**
     * คำนวณ preview ก่อนซื้อ (จำนวน TPIX ที่จะได้)
     * @param {number} phaseId - ID ของ phase ที่จะซื้อ
     * @param {string} currency - สกุลเงินที่จ่าย (BNB, USDT, BUSD)
     * @param {number} amount - จำนวนเงินที่จ่าย
     * @returns {object|null} ข้อมูล preview
     */
    async function getPreview(phaseId, currency, amount) {
        try {
            const { data } = await axios.post('/api/v1/token-sale/preview', {
                phase_id: phaseId,
                currency,
                amount,
            });
            if (data.success) {
                return data.data;
            }
            return null;
        } catch (err) {
            /*
             * แยก "รอบขายปิด" (409 PHASE_CLOSED) ออกจากความล้มเหลวอื่นให้ชัด
             *
             * เดิมกลืน error ทุกชนิดแล้วคืน null เฉยๆ หน้าเว็บจึงไม่รู้ว่าเพราะอะไร
             * — ปุ่มซื้อยังกดได้ ผู้ใช้กดจ่ายเงินต่อได้ทั้งที่ซื้อไม่ได้แน่ๆ
             * ตัวนี้จึงโยนต่อเฉพาะกรณีที่ต้อง "หยุดผู้ใช้ก่อนเสียเงิน"
             */
            const apiError = err?.response?.data?.error;

            if (apiError?.code === 'PHASE_CLOSED') {
                const closed = new Error(apiError.message || 'รอบขายนี้ปิดแล้ว');
                closed.code = 'PHASE_CLOSED';
                closed.userMessage = apiError.message;
                throw closed;
            }

            console.error('[TokenSale] getPreview error:', err);
            return null;
        }
    }

    /**
     * ตรวจล่วงหน้าก่อนจ่ายเงินจริง — ผ่านด่านเดียวกับตอนซื้อ (กระเป๋า + KYC)
     *
     * ต่างจาก getPreview() ตรงที่ปลายทางนี้ "ไม่สาธารณะ" จึงตรวจได้ด้วยว่า
     * เซสชันกระเป๋าของผู้ใช้ยังใช้งานได้อยู่จริงไหม ซึ่งเป็นด่านที่เคยไปโผล่
     * ตอนยื่น tx_hash คือหลังจากเงินออกจากกระเป๋าไปแล้ว
     *
     * @returns {{ok: true, data: object} | {ok: false, message: string}}
     */
    async function precheckPurchase(payload) {
        try {
            const { data } = await axios.post('/api/v1/token-sale/precheck', payload);

            return data.success
                ? { ok: true, data: data.data }
                : { ok: false, message: data.error?.message || 'ตรวจสอบไม่ผ่าน' };
        } catch (err) {
            const apiError = err?.response?.data?.error;

            /*
             * 403 = เซสชันกระเป๋าหมดอายุ ต้องเชื่อมใหม่ก่อน
             * นี่คือเคสที่เคยทำให้เงินหาย — ผู้ใช้เปิดหน้าค้างไว้ข้ามวัน
             * แล้วกดซื้อ เงินออกจริง แต่หลังบ้านปฏิเสธเพราะยืนยันตัวตนไม่ผ่าน
             */
            if (err?.response?.status === 403) {
                return {
                    ok: false,
                    message: apiError?.message || 'เซสชันกระเป๋าหมดอายุ กรุณาเชื่อมต่อกระเป๋าใหม่อีกครั้งก่อนซื้อ',
                };
            }

            return {
                ok: false,
                message: apiError?.message || 'ตรวจสอบก่อนซื้อไม่สำเร็จ กรุณาลองใหม่อีกครั้ง',
            };
        }
    }

    /**
     * สั่งซื้อโดยโอนเงินเข้าบัญชี — คืนรหัสอ้างอิงให้ผู้ซื้อไปใส่ตอนโอน
     *
     * ยังไม่นับเป็นยอดขายจนกว่าทีมงานจะยืนยันว่าเงินเข้าจริง
     *
     * @returns {{ok: true, data: object} | {ok: false, message: string}}
     */
    async function createBankOrder(payload) {
        try {
            const { data } = await axios.post('/api/v1/token-sale/bank-order', payload);

            return data.success
                ? { ok: true, data: data.data }
                : { ok: false, message: data.error?.message || 'สร้างคำสั่งซื้อไม่สำเร็จ' };
        } catch (err) {
            const apiError = err?.response?.data?.error;

            if (err?.response?.status === 403) {
                return {
                    ok: false,
                    message: apiError?.message || 'เซสชันกระเป๋าหมดอายุ กรุณาเชื่อมต่อกระเป๋าใหม่อีกครั้ง',
                };
            }

            return { ok: false, message: apiError?.message || 'สร้างคำสั่งซื้อไม่สำเร็จ กรุณาลองใหม่' };
        }
    }

    /**
     * เริ่มชำระด้วยบัตรผ่าน Stripe — คืน URL ให้พาผู้ใช้ไปหน้าชำระเงิน
     *
     * @returns {{ok: true, url: string} | {ok: false, message: string}}
     */
    async function createStripeCheckout(payload) {
        try {
            const { data } = await axios.post('/api/v1/token-sale/stripe/checkout', payload);

            return data.success && data.data?.url
                ? { ok: true, url: data.data.url }
                : { ok: false, message: data.error?.message || 'เริ่มการชำระเงินไม่สำเร็จ' };
        } catch (err) {
            const apiError = err?.response?.data?.error;

            if (err?.response?.status === 403) {
                return {
                    ok: false,
                    message: apiError?.message || 'เซสชันกระเป๋าหมดอายุ กรุณาเชื่อมต่อกระเป๋าใหม่อีกครั้ง',
                };
            }

            return { ok: false, message: apiError?.message || 'เริ่มการชำระเงินไม่สำเร็จ กรุณาลองใหม่' };
        }
    }

    /**
     * ส่งคำสั่งซื้อเหรียญ TPIX
     * ผู้ใช้ต้องจ่ายเงินบน BSC ก่อน แล้วส่ง tx_hash มาให้ verify
     * @param {object} purchaseData - { wallet_address, phase_id, currency, amount, tx_hash }
     * @returns {object} ข้อมูล transaction ที่สร้าง
     */
    async function submitPurchase(purchaseData) {
        isPurchasing.value = true;
        error.value = null;

        try {
            const { data } = await axios.post('/api/v1/token-sale/purchase', purchaseData);
            if (data.success) {
                // อัปเดตข้อมูล sale และ stats หลังซื้อสำเร็จ
                await Promise.all([
                    fetchSale(),
                    fetchStats(),
                    fetchPurchases(purchaseData.wallet_address),
                ]);
                return data.data;
            }
            throw new Error('Purchase failed');
        } catch (err) {
            const msg = err.response?.data?.error?.message || err.message || 'การซื้อล้มเหลว';
            error.value = msg;
            throw new Error(msg);
        } finally {
            isPurchasing.value = false;
        }
    }

    /**
     * โหลดข้อมูลทั้งหมดของหน้า Token Sale
     * ใช้ตอนเข้าหน้าครั้งแรก
     * @param {string|null} walletAddress - wallet ที่เชื่อมต่ออยู่ (ถ้ามี)
     */
    async function loadAll(walletAddress = null) {
        const promises = [fetchSale(), fetchStats()];
        if (walletAddress) {
            promises.push(fetchPurchases(walletAddress));
            promises.push(fetchVesting(walletAddress));
        }
        await Promise.all(promises);
    }

    /**
     * รีเซ็ต state ทั้งหมด
     */
    function reset() {
        sale.value = null;
        stats.value = null;
        purchases.value = [];
        vestingSchedule.value = [];
        error.value = null;
    }

    return {
        // State
        sale,
        stats,
        purchases,
        vestingSchedule,
        isLoadingSale,
        isLoadingStats,
        isLoadingPurchases,
        isLoadingVesting,
        isPurchasing,
        error,
        // Computed
        activePhase,
        staleActivePhase,
        isPhaseOpen,
        phases,
        percentSold,
        totalRemaining,
        endsAt,
        totalPurchased,
        totalClaimable,
        // Actions
        fetchSale,
        fetchStats,
        fetchPurchases,
        fetchVesting,
        getPreview,
        precheckPurchase,
        createBankOrder,
        createStripeCheckout,
        submitPurchase,
        loadAll,
        reset,
    };
});
