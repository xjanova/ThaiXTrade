/**
 * TPIX TRADE - useWalletBalance Composable
 * Fetches real wallet balances from both blockchain (ethers.js)
 * and backend API, combining results for best coverage.
 * Developed by Xman Studio
 */

import { ref, watch } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import { getTokenBalance, NATIVE_TOKEN_ADDRESS } from '@/utils/web3';
import axios from 'axios';

export function useWalletBalance() {
    const balances = ref([]);
    const totalBalanceUsd = ref(0);
    const isLoading = ref(false);
    const error = ref(null);

    const walletStore = useWalletStore();

    /**
     * Fetch balances from backend API (which calls blockchain RPC).
     */
    async function fetchBalances() {
        if (!walletStore.isConnected || !walletStore.address) {
            balances.value = [];
            totalBalanceUsd.value = 0;
            return;
        }

        isLoading.value = true;
        error.value = null;

        try {
            const chainId = walletStore.chainId || 56;

            const { data } = await axios.get('/api/v1/wallet/balances', {
                params: {
                    wallet_address: walletStore.address,
                    chain_id: chainId,
                },
            });

            if (data.success) {
                balances.value = data.data.balances || [];
            }
        } catch (err) {
            console.warn('Backend balance fetch failed, falling back to ethers.js:', err.message);
            // Fallback: fetch native balance directly from ethers.js
            await fetchNativeBalanceDirect();
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Fallback: fetch native balance using ethers.js directly.
     */
    async function fetchNativeBalanceDirect() {
        if (!walletStore.provider || !walletStore.address) return;

        try {
            const balance = await getTokenBalance(
                NATIVE_TOKEN_ADDRESS,
                walletStore.address,
                walletStore.provider,
            );

            balances.value = [{
                token_address: NATIVE_TOKEN_ADDRESS,
                symbol: 'BNB',
                name: 'BNB',
                decimals: 18,
                balance: balance,
                is_native: true,
            }];
        } catch (err) {
            error.value = 'Failed to fetch wallet balance.';
        }
    }

    /**
     * Get a specific token balance using ethers.js.
     */
    async function getBalance(tokenAddress) {
        if (!walletStore.provider || !walletStore.address) return '0';

        try {
            return await getTokenBalance(tokenAddress, walletStore.address, walletStore.provider);
        } catch {
            return '0';
        }
    }

    // Auto-fetch when wallet connects or address changes
    watch(
        () => walletStore.address,
        (newAddr) => {
            if (newAddr) {
                fetchBalances();
            } else {
                balances.value = [];
                totalBalanceUsd.value = 0;
            }
        },
    );

    return {
        balances,
        totalBalanceUsd,
        isLoading,
        error,
        fetchBalances,
        getBalance,
    };
}
