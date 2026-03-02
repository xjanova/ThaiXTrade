<script setup>
/**
 * TPIX TRADE - Order Book Component
 * Real-time order book display with data from Binance
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';

const props = defineProps({
    symbol: { type: String, default: 'BTC/USDT' },
    asks: { type: Array, default: () => [] },
    bids: { type: Array, default: () => [] },
    tickerPrice: { type: Number, default: 0 },
    isLoading: { type: Boolean, default: false },
});

const viewMode = ref('both'); // 'both', 'bids', 'asks'

const spreadAmount = computed(() => {
    const lowestAsk = props.asks.length ? props.asks[props.asks.length - 1]?.price : 0;
    const highestBid = props.bids.length ? props.bids[0]?.price : 0;
    if (!lowestAsk || !highestBid) return '0.00';
    return (lowestAsk - highestBid).toFixed(2);
});

const spreadPercent = computed(() => {
    const lowestAsk = props.asks.length ? props.asks[props.asks.length - 1]?.price : 0;
    const highestBid = props.bids.length ? props.bids[0]?.price : 0;
    if (!lowestAsk || !highestBid) return '0.000';
    return ((lowestAsk - highestBid) / highestBid * 100).toFixed(3);
});

const formatPrice = (price) => {
    if (price >= 1000) return price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (price >= 1) return price.toFixed(2);
    if (price >= 0.01) return price.toFixed(4);
    return price.toFixed(8);
};

const formatAmount = (amount) => {
    if (amount >= 1) return amount.toFixed(4);
    return amount.toFixed(6);
};

const priceIsUp = computed(() => {
    if (!props.bids.length) return true;
    return props.tickerPrice >= props.bids[0]?.price;
});
</script>

<template>
    <div class="glass-dark rounded-2xl h-full flex flex-col">
        <!-- Header -->
        <div class="flex items-center justify-between p-3 border-b border-white/5">
            <h3 class="font-semibold text-white text-sm">Order Book</h3>
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-1 p-0.5 rounded-lg bg-dark-800">
                    <button
                        @click="viewMode = 'both'"
                        :class="['p-1 rounded transition-all', viewMode === 'both' ? 'bg-dark-600' : 'hover:bg-dark-700']"
                    >
                        <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor">
                            <rect x="2" y="2" width="12" height="5" rx="1" class="text-trading-red"/>
                            <rect x="2" y="9" width="12" height="5" rx="1" class="text-trading-green"/>
                        </svg>
                    </button>
                    <button
                        @click="viewMode = 'bids'"
                        :class="['p-1 rounded transition-all', viewMode === 'bids' ? 'bg-dark-600' : 'hover:bg-dark-700']"
                    >
                        <svg class="w-3.5 h-3.5 text-trading-green" viewBox="0 0 16 16" fill="currentColor">
                            <rect x="2" y="2" width="12" height="12" rx="1"/>
                        </svg>
                    </button>
                    <button
                        @click="viewMode = 'asks'"
                        :class="['p-1 rounded transition-all', viewMode === 'asks' ? 'bg-dark-600' : 'hover:bg-dark-700']"
                    >
                        <svg class="w-3.5 h-3.5 text-trading-red" viewBox="0 0 16 16" fill="currentColor">
                            <rect x="2" y="2" width="12" height="12" rx="1"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Column Headers -->
        <div class="grid grid-cols-3 gap-1 px-3 py-1.5 text-xs font-medium text-dark-400 border-b border-white/5">
            <span>Price (USDT)</span>
            <span class="text-right">Amount</span>
            <span class="text-right">Total</span>
        </div>

        <!-- Loading State -->
        <div v-if="isLoading && !asks.length" class="flex-1 flex items-center justify-center">
            <div class="text-dark-400 text-sm animate-pulse">Loading order book...</div>
        </div>

        <!-- Order Book Content -->
        <div v-else class="flex-1 overflow-hidden flex flex-col">
            <!-- Asks (Sell Orders) -->
            <div v-if="viewMode !== 'bids'" :class="['overflow-y-auto', 'flex-1']">
                <div class="flex flex-col-reverse">
                    <div
                        v-for="(ask, index) in asks"
                        :key="`ask-${index}`"
                        class="relative grid grid-cols-3 gap-1 px-3 py-1 text-xs hover:bg-white/5 cursor-pointer transition-colors"
                    >
                        <div class="absolute right-0 top-0 h-full bg-trading-red/10" :style="{ width: `${ask.depth}%` }"></div>
                        <span class="text-trading-red font-mono relative z-10">${{ formatPrice(ask.price) }}</span>
                        <span class="text-right text-white font-mono relative z-10">{{ formatAmount(ask.amount) }}</span>
                        <span class="text-right text-dark-400 font-mono relative z-10">{{ ask.total.toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</span>
                    </div>
                </div>
            </div>

            <!-- Spread -->
            <div class="px-3 py-2 border-y border-white/5 bg-dark-800/50">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-1.5">
                        <span :class="['text-base font-bold font-mono', priceIsUp ? 'text-trading-green' : 'text-trading-red']">
                            ${{ formatPrice(tickerPrice) }}
                        </span>
                        <svg v-if="priceIsUp" class="w-3.5 h-3.5 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                        </svg>
                        <svg v-else class="w-3.5 h-3.5 text-trading-red" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                    </div>
                    <div class="text-right">
                        <span class="text-xs text-dark-400">Spread </span>
                        <span class="text-xs font-mono text-white">${{ spreadAmount }}</span>
                        <span class="text-xs text-dark-400 ml-1">({{ spreadPercent }}%)</span>
                    </div>
                </div>
            </div>

            <!-- Bids (Buy Orders) -->
            <div v-if="viewMode !== 'asks'" :class="['overflow-y-auto', 'flex-1']">
                <div
                    v-for="(bid, index) in bids"
                    :key="`bid-${index}`"
                    class="relative grid grid-cols-3 gap-1 px-3 py-1 text-xs hover:bg-white/5 cursor-pointer transition-colors"
                >
                    <div class="absolute left-0 top-0 h-full bg-trading-green/10" :style="{ width: `${bid.depth}%` }"></div>
                    <span class="text-trading-green font-mono relative z-10">${{ formatPrice(bid.price) }}</span>
                    <span class="text-right text-white font-mono relative z-10">{{ formatAmount(bid.amount) }}</span>
                    <span class="text-right text-dark-400 font-mono relative z-10">{{ bid.total.toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
