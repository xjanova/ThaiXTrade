/**
 * TPIX TRADE - Carbon Credit Store
 * Pinia store สำหรับระบบ Carbon Credit
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useCarbonCreditStore = defineStore('carbonCredit', () => {
    const projects = ref([]);
    const myCredits = ref([]);
    const myRetirements = ref([]);
    const stats = ref(null);
    const isLoading = ref(false);
    const error = ref(null);

    async function fetchProjects() {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch('/api/v1/carbon-credits/projects');
            const data = await res.json();
            if (data.success) {
                projects.value = data.data;
            }
        } catch (e) {
            error.value = e.message;
        } finally {
            isLoading.value = false;
        }
    }

    async function fetchStats() {
        try {
            const res = await fetch('/api/v1/carbon-credits/stats');
            const data = await res.json();
            if (data.success) {
                stats.value = data.data;
            }
        } catch (e) {
            error.value = e.message;
        }
    }

    async function fetchMyCredits(walletAddress) {
        if (!walletAddress) return;
        try {
            const res = await fetch(`/api/v1/carbon-credits/my-credits/${walletAddress}`, {
                headers: { 'X-Wallet-Address': walletAddress },
            });
            const data = await res.json();
            if (data.success) {
                myCredits.value = data.data;
            }
        } catch (e) {
            error.value = e.message;
        }
    }

    async function fetchMyRetirements(walletAddress) {
        if (!walletAddress) return;
        try {
            const res = await fetch(`/api/v1/carbon-credits/my-retirements/${walletAddress}`, {
                headers: { 'X-Wallet-Address': walletAddress },
            });
            const data = await res.json();
            if (data.success) {
                myRetirements.value = data.data;
            }
        } catch (e) {
            error.value = e.message;
        }
    }

    async function purchaseCredits(purchaseData) {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch('/api/v1/carbon-credits/purchase', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Wallet-Address': purchaseData.wallet_address,
                },
                body: JSON.stringify(purchaseData),
            });
            const data = await res.json();
            if (data.success) {
                myCredits.value.unshift(data.data);
                return data.data;
            }
            throw new Error(data.error?.message || 'Purchase failed');
        } catch (e) {
            error.value = e.message;
            throw e;
        } finally {
            isLoading.value = false;
        }
    }

    async function retireCredits(retireData) {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch('/api/v1/carbon-credits/retire', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Wallet-Address': retireData.wallet_address,
                },
                body: JSON.stringify(retireData),
            });
            const data = await res.json();
            if (data.success) {
                myRetirements.value.unshift(data.data);
                return data.data;
            }
            throw new Error(data.error?.message || 'Retirement failed');
        } catch (e) {
            error.value = e.message;
            throw e;
        } finally {
            isLoading.value = false;
        }
    }

    async function loadAll(walletAddress) {
        await Promise.all([
            fetchProjects(),
            fetchStats(),
            ...(walletAddress ? [fetchMyCredits(walletAddress), fetchMyRetirements(walletAddress)] : []),
        ]);
    }

    return {
        projects,
        myCredits,
        myRetirements,
        stats,
        isLoading,
        error,
        fetchProjects,
        fetchStats,
        fetchMyCredits,
        fetchMyRetirements,
        purchaseCredits,
        retireCredits,
        loadAll,
    };
});
