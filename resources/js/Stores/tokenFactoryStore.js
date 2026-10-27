/**
 * TPIX TRADE - Token Factory Store
 * Pinia store สำหรับระบบ Token Factory
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';

export const useTokenFactoryStore = defineStore('tokenFactory', () => {
    const tokens = ref([]);
    const myTokens = ref([]);
    const isLoading = ref(false);
    const error = ref(null);

    async function fetchTokens() {
        isLoading.value = true;
        error.value = null;
        try {
            const { data } = await axios.get('/api/v1/token-factory');
            if (data.success) {
                tokens.value = data.data;
            }
        } catch (e) {
            error.value = e.response?.data?.error?.message || e.message;
        } finally {
            isLoading.value = false;
        }
    }

    async function fetchMyTokens(walletAddress) {
        if (!walletAddress) return;
        isLoading.value = true;
        try {
            const { data } = await axios.get(`/api/v1/token-factory/my-tokens`, {
                params: { wallet_address: walletAddress },
            });
            if (data.success) {
                myTokens.value = data.data;
            }
        } catch (e) {
            error.value = e.response?.data?.error?.message || e.message;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * ขอใบเสนอราคาค่าธรรมเนียม — เซิร์ฟเวอร์ตรวจฟอร์ม/นโยบายครบแล้วล็อกยอด
     * @returns {Promise<{quote_id: string, amount: string, amount_wei: string, fee_wallet: string, chain_id: number, is_free: boolean, expires_at: string}>}
     */
    async function requestQuote(payload) {
        const { data } = await axios.post('/api/v1/token-factory/quote', payload);
        if (!data.success) throw new Error(data.error?.message || 'ขอใบเสนอราคาไม่สำเร็จ');
        return data.data;
    }

    /**
     * ถามสถานะธุรกรรมค่าธรรมเนียม — 202 = ยังรอบล็อกยืนยัน (ไม่ใช่ error)
     * @returns {Promise<{confirmed: boolean, confirmations: number, required: number, message?: string}>}
     */
    async function checkFeeStatus(payload) {
        const res = await axios.post('/api/v1/token-factory/fee-status', payload);
        if (res.data?.success) {
            return { confirmed: true, ...res.data.data };
        }
        if (res.data?.error?.code === 'FEE_PENDING') {
            return {
                confirmed: false,
                confirmations: res.data.error.confirmations || 0,
                required: res.data.error.required || 0,
                message: res.data.error.message,
            };
        }
        throw new Error(res.data?.error?.message || 'ตรวจสถานะค่าธรรมเนียมไม่สำเร็จ');
    }

    /** ใบเสนอราคาค่ายืนยันซอร์สของเหรียญที่ deploy แล้ว (ผูกกับเหรียญและกระเป๋าเจ้าของ) */
    async function requestSourceVerifyQuote(tokenId, walletAddress) {
        const { data } = await axios.post(`/api/v1/token-factory/${tokenId}/source-verify/quote`, { wallet_address: walletAddress });
        if (!data.success) throw new Error(data.error?.message || 'ขอใบเสนอราคาไม่สำเร็จ');
        return data.data;
    }

    /** ส่ง tx ค่ายืนยันซอร์ส — เซิร์ฟเวอร์ตรวจบนเชนแล้วสั่ง explorer ยืนยัน */
    async function purchaseSourceVerify(tokenId, payload) {
        const res = await axios.post(`/api/v1/token-factory/${tokenId}/source-verify`, payload);
        if (!res.data?.success) {
            const err = new Error(res.data?.error?.message || 'ชำระค่ายืนยันซอร์สไม่สำเร็จ');
            err.code = res.data?.error?.code;
            throw err;
        }
        const i = myTokens.value.findIndex((t) => t.id === res.data.data?.id);
        if (i >= 0) myTokens.value[i] = res.data.data;
        return res.data.data;
    }

    async function createToken(tokenData) {
        isLoading.value = true;
        error.value = null;
        try {
            const { data } = await axios.post('/api/v1/token-factory/create', tokenData);
            if (data.success) {
                myTokens.value.unshift(data.data);
                return data.data;
            }
            const err = new Error(data.error?.message || 'Failed to create token');
            err.code = data.error?.code;
            throw err;
        } catch (e) {
            error.value = e.response?.data?.error?.message || e.message;
            throw e;
        } finally {
            isLoading.value = false;
        }
    }

    return {
        tokens,
        myTokens,
        isLoading,
        error,
        fetchTokens,
        fetchMyTokens,
        requestQuote,
        checkFeeStatus,
        requestSourceVerifyQuote,
        purchaseSourceVerify,
        createToken,
    };
});
