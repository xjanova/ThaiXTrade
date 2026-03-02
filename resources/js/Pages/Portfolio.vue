<script setup>
/**
 * TPIX TRADE - Portfolio Page
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';

const walletStore = useWalletStore();
const isConnected = computed(() => walletStore.isConnected);
const shortAddress = computed(() => walletStore.shortAddress);

const totalBalance = ref('$0.00');
const change24h = ref('+0.00%');

const holdings = ref([
    { symbol: 'BNB', name: 'BNB', balance: '0.00', value: '$0.00', change: '0.00%', isUp: true },
]);
</script>

<template>
    <Head title="Portfolio" />

    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">Portfolio</h1>
                <p class="text-dark-400">Track your assets and performance across all chains.</p>
            </div>

            <!-- Not Connected State -->
            <div v-if="!isConnected" class="flex flex-col items-center justify-center py-20">
                <div class="w-24 h-24 rounded-3xl bg-gradient-to-br from-accent-500/15 via-primary-500/15 to-warm-500/10 flex items-center justify-center mb-6">
                    <svg class="w-12 h-12 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-white mb-3">Connect Your Wallet</h2>
                <p class="text-dark-400 text-center max-w-md mb-6">
                    Connect your wallet to view your portfolio, track balances, and monitor your trading performance.
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
                            <p class="text-sm text-dark-400 mb-1">Total Balance</p>
                            <p class="text-4xl font-bold text-white">{{ totalBalance }}</p>
                            <p class="text-sm text-dark-500 mt-1">{{ shortAddress }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="glass-sm rounded-xl px-4 py-3 text-center">
                                <p class="text-xs text-dark-400 mb-1">24h Change</p>
                                <p class="text-lg font-semibold text-trading-green">{{ change24h }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Holdings Table -->
                <div class="glass-dark rounded-2xl overflow-hidden">
                    <div class="p-4 border-b border-white/5">
                        <h3 class="text-lg font-semibold text-white">Holdings</h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-white/5 text-dark-400 text-sm">
                                    <th class="text-left p-4">Asset</th>
                                    <th class="text-right p-4">Balance</th>
                                    <th class="text-right p-4">Value</th>
                                    <th class="text-right p-4">24h Change</th>
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
                                            <div class="w-8 h-8 rounded-full bg-dark-800 flex items-center justify-center">
                                                <span class="text-sm font-bold text-white">{{ token.symbol.charAt(0) }}</span>
                                            </div>
                                            <div>
                                                <p class="font-medium text-white">{{ token.symbol }}</p>
                                                <p class="text-xs text-dark-400">{{ token.name }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-4 text-right font-mono text-white">{{ token.balance }}</td>
                                    <td class="p-4 text-right font-mono text-white">{{ token.value }}</td>
                                    <td class="p-4 text-right">
                                        <span :class="token.isUp ? 'text-trading-green' : 'text-trading-red'" class="font-medium">
                                            {{ token.change }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-8 text-center text-dark-400">
                        <p class="text-sm">Portfolio tracking with real-time balances coming soon.</p>
                        <p class="text-xs text-dark-500 mt-1">Connect your wallet to see your BNB balance on BSC.</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
