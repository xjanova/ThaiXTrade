<script setup>
/**
 * TPIX TRADE - Portfolio Page
 * Real wallet balance from blockchain RPC
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useWalletBalance } from '@/Composables/useWalletBalance';
import { getCoinLogo } from '@/utils/cryptoLogos';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();
const walletStore = useWalletStore();
const { balances, isLoading, fetchBalances } = useWalletBalance();

const isConnected = computed(() => walletStore.isConnected);
const shortAddress = computed(() => walletStore.shortAddress);

const holdings = computed(() => {
    return balances.value.map(b => ({
        symbol: b.symbol,
        name: b.name,
        balance: parseFloat(b.balance).toFixed(6),
        logo: b.logo,
        isNative: b.is_native,
    }));
});

const hasHoldings = computed(() => holdings.value.length > 0);

onMounted(() => {
    if (isConnected.value) {
        fetchBalances();
    }
});

watch(isConnected, (connected) => {
    if (connected) {
        fetchBalances();
    }
});
</script>

<template>
    <Head title="Portfolio" />

    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">{{ t('portfolio.title') }}</h1>
                <p class="text-dark-400">Track your assets and performance across all chains.</p>
            </div>

            <!-- Not Connected State -->
            <div v-if="!isConnected" class="flex flex-col items-center justify-center py-20">
                <div class="w-24 h-24 rounded-3xl bg-gradient-to-br from-accent-500/15 via-primary-500/15 to-warm-500/10 flex items-center justify-center mb-6">
                    <svg class="w-12 h-12 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-white mb-3">{{ t('wallet.connect') }}</h2>
                <p class="text-dark-400 text-center max-w-md mb-6">
                    {{ t('portfolio.connectToView') }}
                </p>
                <p class="text-sm text-dark-500">
                    Use the "Connect Wallet" button in the navigation bar to get started.
                </p>
            </div>

            <!-- Connected State -->
            <div v-else>
                <!-- Balance Overview -->
                <div class="glass-dark rounded-2xl p-6 mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <p class="text-sm text-dark-400 mb-1">Wallet Address</p>
                            <p class="text-lg font-mono text-white">{{ shortAddress }}</p>
                            <p class="text-sm text-dark-500 mt-1">
                                Chain: {{ walletStore.isBSC ? 'BNB Smart Chain' : `Chain ID: ${walletStore.chainId}` }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button
                                @click="fetchBalances"
                                class="glass-sm rounded-xl px-4 py-3 text-sm text-primary-400 hover:text-primary-300 transition-colors"
                            >
                                Refresh
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Loading -->
                <div v-if="isLoading" class="glass-dark rounded-2xl p-12 text-center">
                    <div class="animate-pulse text-dark-400">Fetching balances from blockchain...</div>
                </div>

                <!-- Holdings Table -->
                <div v-else class="glass-dark rounded-2xl overflow-hidden">
                    <div class="p-4 border-b border-white/5">
                        <h3 class="text-lg font-semibold text-white">{{ t('portfolio.assets') }}</h3>
                    </div>

                    <div v-if="hasHoldings" class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-white/5 text-dark-400 text-sm">
                                    <th class="text-left p-4">Asset</th>
                                    <th class="text-right p-4">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="token in holdings"
                                    :key="token.symbol"
                                    class="border-b border-white/5 hover:bg-white/5 transition-colors"
                                >
                                    <td class="p-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full overflow-hidden bg-dark-800 flex items-center justify-center">
                                                <img v-if="getCoinLogo(token.symbol)" :src="getCoinLogo(token.symbol)" :alt="token.symbol" class="w-7 h-7" />
                                                <span v-else class="text-sm font-bold text-white">{{ token.symbol.charAt(0) }}</span>
                                            </div>
                                            <div>
                                                <p class="font-medium text-white">{{ token.symbol }}</p>
                                                <p class="text-xs text-dark-400">{{ token.name }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-4 text-right font-mono text-white">{{ token.balance }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="p-8 text-center text-dark-400">
                        <svg class="w-12 h-12 mx-auto text-dark-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 12H4"/>
                        </svg>
                        <p class="text-sm">No tokens found in this wallet on the current chain.</p>
                        <p class="text-xs text-dark-500 mt-1">Make sure you're connected to BNB Smart Chain.</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
