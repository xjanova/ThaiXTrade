<script setup>
/**
 * TPIX TRADE - Swap Page
 * DEX token swap with real Web3 integration
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getCoinLogo } from '@/utils/cryptoLogos';
import { useWalletStore } from '@/Stores/walletStore';
import { useSwap } from '@/Composables/useSwap';
import { getTxUrl, BSC_CHAIN_CONFIG } from '@/utils/web3';
import WalletModal from '@/Components/Wallet/WalletModal.vue';

const walletStore = useWalletStore();
const swap = useSwap();

// Token lists (BSC mainnet addresses)
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
const tokenSelectorMode = ref('from');
const isLoading = ref(false);
const showWalletModal = ref(false);

// Quote data
const currentQuote = ref(null);

// Computed
const isWalletConnected = computed(() => walletStore.isConnected);
const isOnBSC = computed(() => walletStore.isBSC);

const feeRate = computed(() => currentQuote.value?.feeRate ?? 0.3);
const feeAmount = computed(() => {
    if (currentQuote.value) return currentQuote.value.feeAmount.toFixed(6);
    const amount = parseFloat(fromAmount.value) || 0;
    return (amount * feeRate.value / 100).toFixed(6);
});
const exchangeRate = computed(() => currentQuote.value?.exchangeRate ?? null);
const priceImpact = computed(() => currentQuote.value?.priceImpact?.toFixed(2) ?? '0');
const minimumReceived = computed(() => {
    if (currentQuote.value) return currentQuote.value.minimumReceived.toFixed(6);
    const amount = parseFloat(toAmount.value) || 0;
    return (amount * (1 - slippage.value / 100)).toFixed(6);
});

const needsApproval = ref(false);

// Watch fromAmount to get real quote
let quoteTimeout = null;
watch(fromAmount, (val) => {
    if (quoteTimeout) clearTimeout(quoteTimeout);
    if (!val || parseFloat(val) <= 0) {
        toAmount.value = '';
        currentQuote.value = null;
        needsApproval.value = false;
        return;
    }

    isLoading.value = true;
    quoteTimeout = setTimeout(async () => {
        try {
            const quote = await swap.getQuote(fromToken.value, toToken.value, parseFloat(val));
            if (quote) {
                currentQuote.value = quote;
                toAmount.value = quote.netOutput.toFixed(6);

                // Check if approval is needed
                if (isWalletConnected.value) {
                    needsApproval.value = !(await swap.checkAllowance(
                        fromToken.value.address,
                        val,
                        fromToken.value.decimals,
                    ));
                }
            } else {
                toAmount.value = '';
                currentQuote.value = null;
            }
        } catch (err) {
            console.warn('Quote error:', err.message);
            toAmount.value = '';
            currentQuote.value = null;
        } finally {
            isLoading.value = false;
        }
    }, 600);
});

// Watch wallet connection to refresh balances
watch(() => walletStore.isConnected, async (connected) => {
    if (connected) {
        await refreshBalances();
    } else {
        popularTokens.value.forEach(t => t.balance = '0.00');
    }
});

// Watch token changes to refresh quote
watch([fromToken, toToken], () => {
    fromAmount.value = '';
    toAmount.value = '';
    currentQuote.value = null;
    needsApproval.value = false;
});

const swapTokens = () => {
    const temp = fromToken.value;
    fromToken.value = toToken.value;
    toToken.value = temp;
    fromAmount.value = '';
    toAmount.value = '';
    currentQuote.value = null;
    needsApproval.value = false;
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
    currentQuote.value = null;
};

const slippageOptions = [0.1, 0.5, 1.0, 3.0];

// Fetch real token balances
async function refreshBalances() {
    if (!walletStore.isConnected) return;
    for (const token of popularTokens.value) {
        try {
            const balance = await swap.getBalance(token.address);
            token.balance = parseFloat(balance).toFixed(4);
        } catch {
            token.balance = '0.00';
        }
    }
}

// Handle approval
async function handleApprove() {
    try {
        await swap.approveToken(fromToken.value.address);
        needsApproval.value = false;
    } catch (err) {
        // Error is already set in swap composable
    }
}

// Execute the swap
async function executeSwap() {
    if (!fromAmount.value || !toAmount.value || !currentQuote.value) return;

    if (!isWalletConnected.value) {
        showWalletModal.value = true;
        return;
    }

    if (!isOnBSC.value) {
        try {
            await walletStore.switchChain();
        } catch {
            return;
        }
    }

    try {
        const result = await swap.executeSwap(
            fromToken.value,
            toToken.value,
            parseFloat(fromAmount.value),
            currentQuote.value,
            slippage.value,
        );

        // Refresh balances after swap
        await refreshBalances();

        // Reset form
        fromAmount.value = '';
        toAmount.value = '';
        currentQuote.value = null;
    } catch (err) {
        // Error is already set in swap composable
    }
}

// Set max amount from balance
function setMaxAmount() {
    const bal = parseFloat(fromToken.value.balance);
    if (bal > 0) {
        // Leave a little BNB for gas if swapping native token
        if (fromToken.value.symbol === 'BNB') {
            fromAmount.value = Math.max(bal - 0.005, 0).toString();
        } else {
            fromAmount.value = bal.toString();
        }
    }
}

// Load balances on mount if wallet is connected
onMounted(async () => {
    if (walletStore.isConnected) {
        await refreshBalances();
    }
});
</script>

<template>
    <Head title="Swap" />

    <AppLayout :hide-sidebar="true">
        <div class="flex items-center justify-center min-h-[calc(100vh-160px)] px-4 py-6">
            <div class="w-full max-w-md">

                <!-- Network Warning -->
                <div v-if="isWalletConnected && !isOnBSC" class="mb-4 p-3 rounded-xl bg-yellow-500/10 border border-yellow-500/30 text-yellow-400 text-sm flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <span>Please switch to BSC network.</span>
                    <button @click="walletStore.switchChain()" class="ml-auto px-3 py-1 rounded-lg bg-yellow-500/20 hover:bg-yellow-500/30 text-yellow-300 text-xs font-medium transition-colors">
                        Switch
                    </button>
                </div>

                <!-- Main Swap Card -->
                <div class="relative">
                    <!-- Glow effect behind card -->
                    <div class="absolute -inset-1 bg-gradient-to-r from-accent-500/20 via-primary-500/20 to-warm-500/20 rounded-3xl blur-xl opacity-60"></div>

                    <div class="relative glass-brand p-5 rounded-2xl">
                        <!-- Header -->
                        <div class="flex items-center justify-between mb-5">
                            <h2 class="text-lg font-bold text-white">Swap</h2>
                            <button
                                @click="showSlippageSettings = !showSlippageSettings"
                                class="p-2 rounded-xl text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                                :class="{ 'text-primary-400 bg-primary-500/10': showSlippageSettings }"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </button>
                        </div>

                        <!-- Slippage Settings -->
                        <Transition
                            enter-active-class="transition ease-out duration-200"
                            enter-from-class="opacity-0 -translate-y-2"
                            enter-to-class="opacity-100 translate-y-0"
                            leave-active-class="transition ease-in duration-150"
                            leave-from-class="opacity-100 translate-y-0"
                            leave-to-class="opacity-0 -translate-y-2"
                        >
                            <div v-if="showSlippageSettings" class="mb-4 p-3 rounded-xl bg-dark-800/60 border border-white/5">
                                <p class="text-xs font-medium text-dark-400 mb-2">Slippage Tolerance</p>
                                <div class="flex items-center gap-2">
                                    <button
                                        v-for="opt in slippageOptions"
                                        :key="opt"
                                        @click="slippage = opt"
                                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
                                        :class="slippage === opt ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30' : 'bg-dark-700/50 text-dark-400 hover:text-white border border-transparent'"
                                    >
                                        {{ opt }}%
                                    </button>
                                    <div class="flex items-center gap-1 ml-auto">
                                        <input
                                            v-model.number="slippage"
                                            type="number"
                                            step="0.1"
                                            min="0.01"
                                            max="50"
                                            class="w-14 bg-dark-700/50 border border-dark-600 rounded-lg px-2 py-1.5 text-xs text-white text-center focus:outline-none focus:border-primary-500"
                                        />
                                        <span class="text-xs text-dark-400">%</span>
                                    </div>
                                </div>
                            </div>
                        </Transition>

                        <!-- From Token -->
                        <div class="rounded-xl bg-dark-800/40 border border-white/5 p-4 hover:border-white/10 transition-colors">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs text-dark-400">You pay</span>
                                <button
                                    v-if="isWalletConnected && parseFloat(fromToken.balance) > 0"
                                    @click="setMaxAmount"
                                    class="text-xs text-primary-400 hover:text-primary-300 transition-colors"
                                >
                                    Balance: {{ fromToken.balance }} <span class="font-semibold">MAX</span>
                                </button>
                                <span v-else class="text-xs text-dark-500">Balance: {{ fromToken.balance }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="fromAmount"
                                    type="number"
                                    placeholder="0.0"
                                    step="any"
                                    min="0"
                                    class="flex-1 bg-transparent text-2xl font-semibold text-white placeholder-dark-600 focus:outline-none min-w-0"
                                />
                                <button
                                    @click="openTokenSelector('from')"
                                    class="flex items-center gap-2 px-3 py-2 rounded-xl bg-dark-700/60 hover:bg-dark-600/60 border border-white/5 hover:border-white/10 transition-all flex-shrink-0"
                                >
                                    <div class="w-6 h-6 rounded-full overflow-hidden bg-dark-600">
                                        <img v-if="getCoinLogo(fromToken.symbol)" :src="getCoinLogo(fromToken.symbol)" :alt="fromToken.symbol" class="w-full h-full object-cover" />
                                        <span v-else class="flex items-center justify-center w-full h-full text-xs text-white font-bold">{{ fromToken.symbol.charAt(0) }}</span>
                                    </div>
                                    <span class="font-semibold text-white text-sm">{{ fromToken.symbol }}</span>
                                    <svg class="w-3.5 h-3.5 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Swap Direction Button -->
                        <div class="flex justify-center -my-2.5 relative z-10">
                            <button
                                @click="swapTokens"
                                class="w-9 h-9 rounded-full bg-dark-800 border-4 border-dark-950/80 flex items-center justify-center text-dark-400 hover:text-primary-400 hover:border-primary-500/30 transition-all hover:rotate-180 duration-300"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                                </svg>
                            </button>
                        </div>

                        <!-- To Token -->
                        <div class="rounded-xl bg-dark-800/40 border border-white/5 p-4 hover:border-white/10 transition-colors">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs text-dark-400">You receive</span>
                                <span class="text-xs text-dark-500">Balance: {{ toToken.balance }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="flex-1 text-2xl font-semibold min-w-0 truncate" :class="isLoading || swap.isLoadingQuote.value ? 'text-dark-500 animate-pulse' : toAmount ? 'text-white' : 'text-dark-600'">
                                    {{ (isLoading || swap.isLoadingQuote.value) ? 'Loading...' : (toAmount || '0.0') }}
                                </div>
                                <button
                                    @click="openTokenSelector('to')"
                                    class="flex items-center gap-2 px-3 py-2 rounded-xl bg-dark-700/60 hover:bg-dark-600/60 border border-white/5 hover:border-white/10 transition-all flex-shrink-0"
                                >
                                    <div class="w-6 h-6 rounded-full overflow-hidden bg-dark-600">
                                        <img v-if="getCoinLogo(toToken.symbol)" :src="getCoinLogo(toToken.symbol)" :alt="toToken.symbol" class="w-full h-full object-cover" />
                                        <span v-else class="flex items-center justify-center w-full h-full text-xs text-white font-bold">{{ toToken.symbol.charAt(0) }}</span>
                                    </div>
                                    <span class="font-semibold text-white text-sm">{{ toToken.symbol }}</span>
                                    <svg class="w-3.5 h-3.5 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Exchange Rate (inline) -->
                        <div v-if="exchangeRate" class="mt-3 flex items-center justify-center text-xs text-dark-400">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                            </svg>
                            1 {{ fromToken.symbol }} = {{ exchangeRate.toFixed(4) }} {{ toToken.symbol }}
                        </div>

                        <!-- Error -->
                        <div v-if="swap.error.value" class="mt-3 p-3 rounded-xl bg-trading-red/10 border border-trading-red/20 text-trading-red text-sm flex items-center gap-2">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ swap.error.value }}
                        </div>

                        <!-- Success -->
                        <div v-if="swap.txHash.value && swap.txStatus.value === 'confirmed'" class="mt-3 p-3 rounded-xl bg-trading-green/10 border border-trading-green/20 text-trading-green text-sm">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <span class="font-medium">Swap confirmed!</span>
                            </div>
                            <a :href="getTxUrl(swap.txHash.value)" target="_blank" rel="noopener" class="text-xs underline mt-1 block text-trading-green/70 hover:text-trading-green">
                                View on BscScan
                            </a>
                        </div>

                        <!-- Details Accordion -->
                        <div v-if="fromAmount && parseFloat(fromAmount) > 0 && currentQuote" class="mt-3 rounded-xl bg-dark-800/30 border border-white/5 overflow-hidden">
                            <div class="p-3 space-y-2 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-dark-400">Platform Fee <span class="text-accent-400">({{ feeRate }}%)</span></span>
                                    <span class="text-dark-300 font-mono">{{ feeAmount }} {{ fromToken.symbol }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-dark-400">Price Impact</span>
                                    <span :class="parseFloat(priceImpact) > 3 ? 'text-trading-red' : parseFloat(priceImpact) > 1 ? 'text-yellow-400' : 'text-trading-green'" class="font-mono">
                                        {{ priceImpact }}%
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-dark-400">Slippage</span>
                                    <span class="text-dark-300 font-mono">{{ slippage }}%</span>
                                </div>
                                <div class="border-t border-white/5 pt-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-dark-300">Min. Received</span>
                                        <span class="text-white font-semibold font-mono">{{ minimumReceived }} {{ toToken.symbol }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Button -->
                        <button
                            v-if="!isWalletConnected"
                            class="w-full mt-4 btn-brand py-3.5 text-base"
                            @click="showWalletModal = true"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            Connect Wallet
                        </button>

                        <button
                            v-else-if="needsApproval && fromAmount && currentQuote"
                            :disabled="swap.isApproving.value"
                            class="w-full mt-4 py-3.5 text-base rounded-xl font-semibold transition-all disabled:opacity-40 bg-accent-500/20 text-accent-400 border border-accent-500/30 hover:bg-accent-500/30"
                            @click="handleApprove"
                        >
                            {{ swap.isApproving.value ? 'Approving...' : `Approve ${fromToken.symbol}` }}
                        </button>

                        <button
                            v-else
                            :disabled="!fromAmount || !toAmount || isLoading || swap.isExecuting.value || swap.isLoadingQuote.value"
                            class="w-full mt-4 btn-primary py-3.5 text-base disabled:opacity-40"
                            @click="executeSwap"
                        >
                            {{ swap.isExecuting.value ? 'Swapping...' : (isLoading || swap.isLoadingQuote.value) ? 'Fetching Quote...' : 'Swap' }}
                        </button>

                        <!-- Powered by -->
                        <div class="mt-3 flex items-center justify-center gap-1.5 text-[10px] text-dark-500">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            PancakeSwap V2 on BSC
                        </div>
                    </div>
                </div>

                <!-- Route Info -->
                <div class="mt-4 p-3 rounded-xl bg-dark-900/60 border border-white/5">
                    <div class="flex items-center gap-3 text-xs">
                        <div class="flex items-center gap-1.5">
                            <div class="w-4 h-4 rounded-full overflow-hidden bg-dark-700">
                                <img v-if="getCoinLogo(fromToken.symbol)" :src="getCoinLogo(fromToken.symbol)" class="w-full h-full object-cover" />
                            </div>
                            <span class="text-dark-300 font-medium">{{ fromToken.symbol }}</span>
                        </div>
                        <div class="flex-1 border-t border-dashed border-dark-700 relative">
                            <div class="absolute inset-x-0 -top-2 flex justify-center">
                                <span class="px-1.5 bg-dark-950 text-[10px] text-primary-400/70">PancakeSwap V2</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <div class="w-4 h-4 rounded-full overflow-hidden bg-dark-700">
                                <img v-if="getCoinLogo(toToken.symbol)" :src="getCoinLogo(toToken.symbol)" class="w-full h-full object-cover" />
                            </div>
                            <span class="text-dark-300 font-medium">{{ toToken.symbol }}</span>
                        </div>
                    </div>
                    <div class="mt-2 flex items-center gap-3 text-[10px] text-dark-500">
                        <span class="flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full" :class="isWalletConnected ? 'bg-trading-green animate-pulse' : 'bg-dark-600'"></span>
                            {{ isWalletConnected ? 'Connected' : 'Not connected' }}
                        </span>
                        <span>BSC Mainnet</span>
                        <span>~{{ feeRate }}% fee</span>
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
                <div class="glass w-full max-w-sm p-5 animate-scale-in">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-white">Select Token</h3>
                        <button @click="showTokenSelector = false" class="p-1 rounded-lg hover:bg-white/10 text-dark-400 hover:text-white transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Popular tags -->
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        <button
                            v-for="token in popularTokens.slice(0, 5)"
                            :key="'tag-' + token.symbol"
                            @click="selectToken(token)"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-dark-800/50 border border-white/5 hover:border-white/10 text-xs text-dark-300 hover:text-white transition-all"
                        >
                            <div class="w-4 h-4 rounded-full overflow-hidden bg-dark-600">
                                <img v-if="getCoinLogo(token.symbol)" :src="getCoinLogo(token.symbol)" class="w-full h-full object-cover" />
                            </div>
                            {{ token.symbol }}
                        </button>
                    </div>

                    <div class="border-t border-white/5 pt-3">
                        <div class="space-y-0.5 max-h-[320px] overflow-y-auto">
                            <button
                                v-for="token in popularTokens"
                                :key="token.symbol"
                                @click="selectToken(token)"
                                class="w-full flex items-center gap-3 p-2.5 rounded-xl hover:bg-white/5 transition-colors text-left"
                                :class="{
                                    'opacity-30 pointer-events-none': (tokenSelectorMode === 'from' && token.symbol === fromToken.symbol) || (tokenSelectorMode === 'to' && token.symbol === toToken.symbol),
                                }"
                            >
                                <div class="w-8 h-8 rounded-full overflow-hidden bg-dark-700 flex-shrink-0">
                                    <img v-if="getCoinLogo(token.symbol)" :src="getCoinLogo(token.symbol)" :alt="token.symbol" class="w-full h-full object-cover" />
                                    <span v-else class="flex items-center justify-center w-full h-full text-xs text-white font-bold">{{ token.symbol.charAt(0) }}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-white text-sm">{{ token.symbol }}</p>
                                    <p class="text-xs text-dark-500 truncate">{{ token.name }}</p>
                                </div>
                                <span class="text-xs text-dark-400 font-mono">{{ token.balance }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Wallet Modal -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <WalletModal
                v-if="showWalletModal && !isWalletConnected"
                @close="showWalletModal = false"
                @connected="showWalletModal = false"
            />
        </Transition>
    </AppLayout>
</template>
