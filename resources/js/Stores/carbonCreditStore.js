/**
 * TPIX TRADE - Carbon Credit Store
 * Pinia store สำหรับระบบ Carbon Credit
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';

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
            const { data } = await axios.get('/api/v1/carbon-credits/projects');
            if (data.success) {
                projects.value = data.data;
            }
        } catch (e) {
            error.value = e.response?.data?.error?.message || e.message;
        } finally {
            isLoading.value = false;
        }
    }

    async function fetchStats() {
        try {
            const { data } = await axios.get('/api/v1/carbon-credits/stats');
            if (data.success) {
                stats.value = data.data;
            }
        } catch (e) {
            error.value = e.response?.data?.error?.message || e.message;
        }
    }

    async function fetchMyCredits(walletAddress) {
        if (!walletAddress) return;
        try {
            const { data } = await axios.get(`/api/v1/carbon-credits/my-credits/${walletAddress}`);
            if (data.success) {
                myCredits.value = data.data;
            }
        } catch (e) {
            error.value = e.response?.data?.error?.message || e.message;
        }
    }

    async function fetchMyRetirements(walletAddress) {
        if (!walletAddress) return;
        try {
            const { data } = await axios.get(`/api/v1/carbon-credits/my-retirements/${walletAddress}`);
            if (data.success) {
                myRetirements.value = data.data;
            }
        } catch (e) {
            error.value = e.response?.data?.error?.message || e.message;
        }
    }

    async function purchaseCredits(purchaseData) {
        isLoading.value = true;
        error.value = null;
        try {
            const { data } = await axios.post('/api/v1/carbon-credits/purchase', purchaseData);
            if (data.success) {
                myCredits.value.unshift(data.data);
                return data.data;
            }
            throw new Error(data.error?.message || 'Purchase failed');
        } catch (e) {
            error.value = e.response?.data?.error?.message || e.message;
            throw e;
        } finally {
            isLoading.value = false;
        }
    }

    async function retireCredits(retireData) {
        isLoading.value = true;
        error.value = null;
        try {
            const { data } = await axios.post('/api/v1/carbon-credits/retire', retireData);
            if (data.success) {
                myRetirements.value.unshift(data.data);
                return data.data;
            }
            throw new Error(data.error?.message || 'Retirement failed');
        } catch (e) {
            error.value = e.response?.data?.error?.message || e.message;
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
