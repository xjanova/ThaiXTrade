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

    async function createToken(tokenData) {
        isLoading.value = true;
        error.value = null;
        try {
            const { data } = await axios.post('/api/v1/token-factory/create', tokenData);
            if (data.success) {
                myTokens.value.unshift(data.data);
                return data.data;
            }
            throw new Error(data.error?.message || 'Failed to create token');
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
        createToken,
    };
});
