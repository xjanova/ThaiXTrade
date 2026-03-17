/**
 * TPIX TRADE - Token Sale Store (Pinia)
 * ระบบจัดการข้อมูลการขายเหรียญ TPIX (ICO/IDO)
 * เก็บ state ของรอบขาย, phases, สถิติ, รายการซื้อ
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

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

    // phase ที่ active อยู่ตอนนี้ (จาก phases ของ sale)
    const activePhase = computed(() => {
        if (!sale.value?.phases) return null;
        return sale.value.phases.find(p => p.status === 'active') || null;
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
            console.error('[TokenSale] getPreview error:', err);
            return null;
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
        submitPurchase,
        loadAll,
        reset,
    };
});
