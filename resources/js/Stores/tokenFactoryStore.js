/**
 * TPIX TRADE - Token Factory Store
 * Pinia store สำหรับระบบ Token Factory
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useTokenFactoryStore = defineStore('tokenFactory', () => {
    const tokens = ref([]);
    const myTokens = ref([]);
    const isLoading = ref(false);
    const error = ref(null);

    async function fetchTokens() {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch('/api/v1/token-factory');
            const data = await res.json();
            if (data.success) {
                tokens.value = data.data;
            }
        } catch (e) {
            error.value = e.message;
        } finally {
            isLoading.value = false;
        }
    }

    async function fetchMyTokens(walletAddress) {
        if (!walletAddress) return;
        isLoading.value = true;
        try {
            const res = await fetch(`/api/v1/token-factory/my-tokens?wallet_address=${walletAddress}`, {
                headers: { 'X-Wallet-Address': walletAddress },
            });
            const data = await res.json();
            if (data.success) {
                myTokens.value = data.data;
            }
        } catch (e) {
            error.value = e.message;
        } finally {
            isLoading.value = false;
        }
    }

    async function createToken(tokenData) {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch('/api/v1/token-factory/create', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Wallet-Address': tokenData.creator_address,
                },
                body: JSON.stringify(tokenData),
            });
            const data = await res.json();
            if (data.success) {
                myTokens.value.unshift(data.data);
                return data.data;
            }
            throw new Error(data.error?.message || 'Failed to create token');
        } catch (e) {
            error.value = e.message;
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
