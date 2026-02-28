<script setup>
/**
 * TPIX TRADE - Swap Page
 * DEX token swap with fee transparency
 * Developed by Xman Studio
 */

import { ref, computed, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getCoinLogo } from '@/utils/cryptoLogos';

// Token lists
const popularTokens = ref([
    { symbol: 'BNB', name: 'BNB', address: '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE', decimals: 18, balance: '0.00' },
    { symbol: 'USDT', name: 'Tether', address: '0x55d398326f99059fF775485246999027B3197955', decimals: 18, balance: '0.00' },
    { symbol: 'USDC', name: 'USD Coin', address: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', decimals: 18, balance: '0.00' },
    { symbol: 'ETH', name: 'Ethereum', address: '0x2170Ed0880ac9A755fd29B2688956BD959F933F8', decimals: 18, balance: '0.00' },
    { symbol: 'BTC', name: 'Bitcoin', address: '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c', decimals: 18, balance: '0.00' },
    { symbol: 'SOL', name: 'Solana', address: '0x570A5D26f7765Ecb712C0924E4De545B89fD43dF', decimals: 18, balance: '0.00' },
    { symbol: 'DOGE', name: 'Dogecoin', address: '0xbA2aE424d960c26247Dd6c32edC70B295c744C43', decimals: 8, balance: '0.00' },
    { symbol: 'CAKE', name: 'PancakeSwap', address: '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82', decimals: 18, balance: '0.00' },
]);

// Swap state
const fromToken = ref(popularTokens.value[0]);
const toToken = ref(popularTokens.value[1]);
const fromAmount = ref('');
const toAmount = ref('');
const slippage = ref(0.5);
const showSlippageSettings = ref(false);
const showTokenSelector = ref(false);
const tokenSelectorMode = ref('from'); // 'from' or 'to'
const isLoading = ref(false);
const isWalletConnected = ref(false);

// Fee info
const feeRate = ref(0.3);
const feeAmount = computed(() => {
    const amount = parseFloat(fromAmount.value) || 0;
    return (amount * feeRate.value / 100).toFixed(6);
});
const netAmount = computed(() => {
    const amount = parseFloat(fromAmount.value) || 0;
    const fee = parseFloat(feeAmount.value) || 0;
    return (amount - fee).toFixed(6);
});

// Price info
const exchangeRate = ref(null);
const priceImpact = ref(0);
const minimumReceived = computed(() => {
    const amount = parseFloat(toAmount.value) || 0;
    return (amount * (1 - slippage.value / 100)).toFixed(6);
});

// Watch fromAmount to simulate quote
let quoteTimeout = null;
watch(fromAmount, (val) => {
    if (quoteTimeout) clearTimeout(quoteTimeout);
    if (!val || parseFloat(val) <= 0) {
        toAmount.value = '';
        exchangeRate.value = null;
        priceImpact.value = 0;
        return;
    }
    isLoading.value = true;
    quoteTimeout = setTimeout(() => {
        // Simulate API quote (in production: call /api/v1/swap/quote)
        const mockRate = getMockRate();
        const net = parseFloat(val) * (1 - feeRate.value / 100);
        toAmount.value = (net * mockRate).toFixed(6);
        exchangeRate.value = mockRate;
        priceImpact.value = Math.min(parseFloat(val) * 0.001, 5).toFixed(2);
        isLoading.value = false;
    }, 500);
});

const getMockRate = () => {
    const rates = {
        'BNB-USDT': 567.89,
        'USDT-BNB': 1 / 567.89,
        'BNB-USDC': 567.50,
        'BNB-ETH': 0.1645,
        'ETH-USDT': 3456.78,
        'BTC-USDT': 67234.50,
        'SOL-USDT': 178.90,
    };
    const key = `${fromToken.value.symbol}-${toToken.value.symbol}`;
    return rates[key] || 1.0;
};

const swapTokens = () => {
    const temp = fromToken.value;
    fromToken.value = toToken.value;
    toToken.value = temp;
    fromAmount.value = '';
    toAmount.value = '';
};

const openTokenSelector = (mode) => {
    tokenSelectorMode.value = mode;
    showTokenSelector.value = true;
};

const selectToken = (token) => {
    if (tokenSelectorMode.value === 'from') {
        if (token.symbol === toToken.value.symbol) swapTokens();
        else fromToken.value = token;
    } else {
        if (token.symbol === fromToken.value.symbol) swapTokens();
        else toToken.value = token;
    }
    showTokenSelector.value = false;
    fromAmount.value = '';
    toAmount.value = '';
};

const slippageOptions = [0.1, 0.5, 1.0, 3.0];

const executeSwap = () => {
    if (!fromAmount.value || !toAmount.value) return;
    // In production: trigger wallet transaction via ethers.js
    alert('Swap feature coming soon! Connect your wallet to start trading.');
};
</script>

<template>
    <Head title="Swap" />

    <AppLayout>
        <div class="max-w-lg mx-auto py-8">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">Swap</h1>
                <p class="text-dark-400">Trade tokens instantly with the best rates</p>
            </div>

            <!-- Swap Card -->
            <div class="glass-brand p-6 relative">
                <!-- Settings Button -->
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-white">Swap Tokens</h2>
                    <button
                        @click="showSlippageSettings = !showSlippageSettings"
                        class="p-2 rounded-xl text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </button>
                </div>

                <!-- Slippage Settings -->
                <div v-if="showSlippageSettings" class="mb-4 p-4 rounded-xl bg-dark-800/50 border border-white/5">
                    <p class="text-sm font-medium text-dark-300 mb-3">Slippage Tolerance</p>
                    <div class="flex items-center gap-2">
                        <button
                            v-for="opt in slippageOptions"
                            :key="opt"
                            @click="slippage = opt"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all"
                            :class="slippage === opt ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30' : 'bg-dark-700 text-dark-400 hover:text-white border border-transparent'"
                        >
                            {{ opt }}%
                        </button>
                        <div class="flex items-center gap-1">
                            <input
                                v-model.number="slippage"
                                type="number"
                                step="0.1"
                                min="0.01"
                                max="50"
                                class="w-16 bg-dark-700 border border-dark-600 rounded-lg px-2 py-1.5 text-sm text-white text-center focus:outline-none focus:border-primary-500"
                            />
                            <span class="text-sm text-dark-400">%</span>
                        </div>
                    </div>
                </div>

                <!-- From Token -->
                <div class="bg-dark-800/50 border border-dark-700 rounded-xl p-4 mb-2">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm text-dark-400">From</span>
                        <span class="text-xs text-dark-500">Balance: {{ fromToken.balance }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <input
                            v-model="fromAmount"
                            type="number"
                            placeholder="0.0"
                            step="any"
                            min="0"
                            class="flex-1 bg-transparent text-2xl font-semibold text-white placeholder-dark-600 focus:outline-none"
                        />
                        <button
                            @click="openTokenSelector('from')"
                            class="flex items-center gap-2 px-3 py-2 rounded-xl bg-dark-700 hover:bg-dark-600 border border-dark-600 transition-colors"
                        >
                            <div class="w-6 h-6 rounded-full overflow-hidden bg-dark-600">
                                <img v-if="getCoinLogo(fromToken.symbol)" :src="getCoinLogo(fromToken.symbol)" :alt="fromToken.symbol" class="w-full h-full object-cover" />
                                <span v-else class="flex items-center justify-center w-full h-full text-xs text-white font-bold">{{ fromToken.symbol.charAt(0) }}</span>
                            </div>
                            <span class="font-semibold text-white">{{ fromToken.symbol }}</span>
                            <svg class="w-4 h-4 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Swap Direction Button -->
                <div class="flex justify-center -my-3 relative z-10">
                    <button
                        @click="swapTokens"
                        class="w-10 h-10 rounded-full bg-gradient-to-br from-accent-500 via-primary-500 to-warm-500 flex items-center justify-center shadow-glow hover:shadow-glow-lg transition-all hover:scale-110"
                    >
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                        </svg>
                    </button>
                </div>

                <!-- To Token -->
                <div class="bg-dark-800/50 border border-dark-700 rounded-xl p-4 mt-2">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm text-dark-400">To (estimated)</span>
                        <span class="text-xs text-dark-500">Balance: {{ toToken.balance }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex-1 text-2xl font-semibold" :class="isLoading ? 'text-dark-500 animate-pulse' : toAmount ? 'text-white' : 'text-dark-600'">
                            {{ isLoading ? 'Loading...' : (toAmount || '0.0') }}
                        </div>
                        <button
                            @click="openTokenSelector('to')"
                            class="flex items-center gap-2 px-3 py-2 rounded-xl bg-dark-700 hover:bg-dark-600 border border-dark-600 transition-colors"
                        >
                            <div class="w-6 h-6 rounded-full overflow-hidden bg-dark-600">
                                <img v-if="getCoinLogo(toToken.symbol)" :src="getCoinLogo(toToken.symbol)" :alt="toToken.symbol" class="w-full h-full object-cover" />
                                <span v-else class="flex items-center justify-center w-full h-full text-xs text-white font-bold">{{ toToken.symbol.charAt(0) }}</span>
                            </div>
                            <span class="font-semibold text-white">{{ toToken.symbol }}</span>
                            <svg class="w-4 h-4 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Fee Breakdown -->
                <div v-if="fromAmount && parseFloat(fromAmount) > 0" class="mt-4 p-4 rounded-xl bg-dark-800/30 border border-white/5 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-dark-400">Exchange Rate</span>
                        <span class="text-white font-mono">
                            1 {{ fromToken.symbol }} = {{ exchangeRate ? exchangeRate.toFixed(4) : '...' }} {{ toToken.symbol }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-dark-400 flex items-center gap-1">
                            Platform Fee
                            <span class="text-xs text-accent-400">({{ feeRate }}%)</span>
                        </span>
                        <span class="text-warm-400 font-mono">{{ feeAmount }} {{ fromToken.symbol }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-dark-400">Price Impact</span>
                        <span :class="parseFloat(priceImpact) > 3 ? 'text-trading-red' : parseFloat(priceImpact) > 1 ? 'text-yellow-400' : 'text-trading-green'" class="font-mono">
                            {{ priceImpact }}%
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-dark-400">Slippage Tolerance</span>
                        <span class="text-dark-300 font-mono">{{ slippage }}%</span>
                    </div>
                    <div class="border-t border-white/5 pt-2 mt-2">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-dark-300 font-medium">Minimum Received</span>
                            <span class="text-white font-semibold font-mono">{{ minimumReceived }} {{ toToken.symbol }}</span>
                        </div>
                    </div>
                </div>

                <!-- Swap / Connect Button -->
                <button
                    v-if="!isWalletConnected"
                    class="w-full mt-4 btn-brand py-4 text-lg"
                    @click="executeSwap"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    Connect Wallet to Swap
                </button>
                <button
                    v-else
                    :disabled="!fromAmount || !toAmount || isLoading"
                    class="w-full mt-4 btn-primary py-4 text-lg disabled:opacity-40"
                    @click="executeSwap"
                >
                    {{ isLoading ? 'Fetching Quote...' : 'Swap' }}
                </button>

                <!-- Powered by badge -->
                <div class="mt-4 flex items-center justify-center gap-2 text-xs text-dark-500">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    Secured by TPIX Router Smart Contract
                </div>
            </div>

            <!-- Route Info -->
            <div class="mt-6 glass-dark p-4 rounded-xl">
                <div class="flex items-center gap-2 mb-3">
                    <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <span class="text-sm font-semibold text-white">Routing</span>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <div class="flex items-center gap-1.5">
                        <div class="w-5 h-5 rounded-full overflow-hidden bg-dark-700">
                            <img v-if="getCoinLogo(fromToken.symbol)" :src="getCoinLogo(fromToken.symbol)" class="w-full h-full object-cover" />
                        </div>
                        <span class="text-white font-medium">{{ fromToken.symbol }}</span>
                    </div>
                    <div class="flex-1 border-t border-dashed border-dark-600 relative">
                        <div class="absolute inset-x-0 -top-2.5 flex justify-center">
                            <span class="px-2 bg-dark-900 text-xs text-accent-400">TPIX Router</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-5 h-5 rounded-full overflow-hidden bg-dark-700">
                            <img v-if="getCoinLogo(toToken.symbol)" :src="getCoinLogo(toToken.symbol)" class="w-full h-full object-cover" />
                        </div>
                        <span class="text-white font-medium">{{ toToken.symbol }}</span>
                    </div>
                </div>
                <div class="mt-3 flex items-center gap-4 text-xs text-dark-500">
                    <span class="flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-trading-green"></span>
                        PancakeSwap V2
                    </span>
                    <span>BSC Network</span>
                    <span>~{{ feeRate }}% platform fee</span>
                </div>
            </div>

            <!-- Fee Transparency Banner -->
            <div class="mt-6 glass-dark p-4 rounded-xl border border-accent-500/10">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-accent-500/20 to-primary-500/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-white mb-1">Fee Transparency</h4>
                        <p class="text-xs text-dark-400 leading-relaxed">
                            TPIX TRADE charges a {{ feeRate }}% platform fee on each swap, deducted from the input token before routing through the DEX.
                            All fees are collected on-chain via our audited TPIX Router smart contract for full transparency.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Token Selector Modal -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="showTokenSelector" class="fixed inset-0 bg-dark-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showTokenSelector = false">
                <div class="glass w-full max-w-md p-6 animate-scale-in">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-white">Select Token</h3>
                        <button @click="showTokenSelector = false" class="p-1 rounded-lg hover:bg-white/10 text-dark-400 hover:text-white transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Search -->
                    <input
                        type="text"
                        placeholder="Search by name or address..."
                        class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 mb-4"
                    />

                    <!-- Token List -->
                    <div class="space-y-1 max-h-[400px] overflow-y-auto">
                        <button
                            v-for="token in popularTokens"
                            :key="token.symbol"
                            @click="selectToken(token)"
                            class="w-full flex items-center gap-3 p-3 rounded-xl hover:bg-white/5 transition-colors text-left"
                            :class="{
                                'opacity-40 cursor-not-allowed': (tokenSelectorMode === 'from' && token.symbol === fromToken.symbol) || (tokenSelectorMode === 'to' && token.symbol === toToken.symbol),
                            }"
                        >
                            <div class="w-8 h-8 rounded-full overflow-hidden bg-dark-700 flex-shrink-0">
                                <img v-if="getCoinLogo(token.symbol)" :src="getCoinLogo(token.symbol)" :alt="token.symbol" class="w-full h-full object-cover" />
                                <span v-else class="flex items-center justify-center w-full h-full text-xs text-white font-bold">{{ token.symbol.charAt(0) }}</span>
                            </div>
                            <div class="flex-1">
                                <p class="font-semibold text-white">{{ token.symbol }}</p>
                                <p class="text-xs text-dark-400">{{ token.name }}</p>
                            </div>
                            <span class="text-sm text-dark-400 font-mono">{{ token.balance }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </AppLayout>
</template>
