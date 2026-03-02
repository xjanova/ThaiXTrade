<script setup>
/**
 * TPIX TRADE - Settings Page
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';

const walletStore = useWalletStore();
const isConnected = computed(() => walletStore.isConnected);

const slippageTolerance = ref('0.5');
const txDeadline = ref('20');
const gasOption = ref('standard');

const slippageOptions = ['0.1', '0.5', '1.0'];
const gasOptions = [
    { id: 'low', label: 'Low', description: 'Slower, cheaper' },
    { id: 'standard', label: 'Standard', description: 'Balanced' },
    { id: 'fast', label: 'Fast', description: 'Faster, more expensive' },
];
</script>

<template>
    <Head title="Settings" />

    <AppLayout>
        <div class="max-w-3xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">Settings</h1>
                <p class="text-dark-400">Customize your trading experience.</p>
            </div>

            <!-- Transaction Settings -->
            <div class="glass-dark rounded-2xl p-6 mb-6">
                <h2 class="text-lg font-semibold text-white mb-6">Transaction Settings</h2>

                <!-- Slippage Tolerance -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-dark-300 mb-3">Slippage Tolerance</label>
                    <div class="flex items-center gap-3">
                        <button
                            v-for="option in slippageOptions"
                            :key="option"
                            @click="slippageTolerance = option"
                            :class="[
                                'px-4 py-2 rounded-xl text-sm font-medium transition-all',
                                slippageTolerance === option
                                    ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30'
                                    : 'glass-sm text-dark-400 hover:text-white'
                            ]"
                        >
                            {{ option }}%
                        </button>
                        <div class="relative flex-1">
                            <input
                                v-model="slippageTolerance"
                                type="text"
                                class="w-full px-4 py-2 rounded-xl glass-sm border border-white/10 bg-transparent text-white text-right focus:outline-none focus:border-primary-500/50"
                            />
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-dark-400">%</span>
                        </div>
                    </div>
                    <p class="text-xs text-dark-500 mt-2">Your transaction will revert if the price changes unfavorably by more than this percentage.</p>
                </div>

                <!-- Transaction Deadline -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-dark-300 mb-3">Transaction Deadline</label>
                    <div class="relative w-48">
                        <input
                            v-model="txDeadline"
                            type="text"
                            class="w-full px-4 py-2 rounded-xl glass-sm border border-white/10 bg-transparent text-white focus:outline-none focus:border-primary-500/50"
                        />
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-dark-400 text-sm">minutes</span>
                    </div>
                    <p class="text-xs text-dark-500 mt-2">Your transaction will revert if it is pending for more than this period of time.</p>
                </div>

                <!-- Gas Price -->
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-3">Gas Price</label>
                    <div class="grid grid-cols-3 gap-3">
                        <button
                            v-for="option in gasOptions"
                            :key="option.id"
                            @click="gasOption = option.id"
                            :class="[
                                'p-3 rounded-xl text-center transition-all',
                                gasOption === option.id
                                    ? 'bg-primary-500/20 border border-primary-500/30'
                                    : 'glass-sm border border-white/5 hover:border-white/10'
                            ]"
                        >
                            <p :class="['font-medium text-sm', gasOption === option.id ? 'text-primary-400' : 'text-white']">
                                {{ option.label }}
                            </p>
                            <p class="text-xs text-dark-400 mt-1">{{ option.description }}</p>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Wallet Info -->
            <div class="glass-dark rounded-2xl p-6">
                <h2 class="text-lg font-semibold text-white mb-6">Wallet</h2>

                <div v-if="isConnected" class="space-y-4">
                    <div class="flex items-center justify-between p-4 rounded-xl bg-white/5">
                        <div>
                            <p class="text-sm text-dark-400">Connected Address</p>
                            <p class="font-mono text-white">{{ walletStore.address }}</p>
                        </div>
                        <div class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></div>
                    </div>
                    <div class="flex items-center justify-between p-4 rounded-xl bg-white/5">
                        <div>
                            <p class="text-sm text-dark-400">Network</p>
                            <p class="text-white">{{ walletStore.isBSC ? 'BNB Smart Chain' : `Chain ID: ${walletStore.chainId}` }}</p>
                        </div>
                    </div>
                    <button
                        @click="walletStore.disconnect()"
                        class="w-full py-3 rounded-xl text-trading-red border border-trading-red/20 hover:bg-trading-red/10 transition-colors font-medium"
                    >
                        Disconnect Wallet
                    </button>
                </div>

                <div v-else class="text-center py-8">
                    <p class="text-dark-400 mb-2">No wallet connected</p>
                    <p class="text-sm text-dark-500">Connect your wallet using the button in the navigation bar.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
