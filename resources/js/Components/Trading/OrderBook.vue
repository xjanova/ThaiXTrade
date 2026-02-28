<script setup>
/**
 * TPIX TRADE - Order Book Component
 * Real-time order book display
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';

const props = defineProps({
    symbol: {
        type: String,
        default: 'BTC/USDT'
    }
});

const precision = ref(2);
const viewMode = ref('both'); // 'both', 'bids', 'asks'

// Mock order book data
const asks = ref([
    { price: '67,280.50', amount: '0.2345', total: '15,777.43', depth: 15 },
    { price: '67,275.00', amount: '0.5678', total: '38,193.81', depth: 25 },
    { price: '67,270.00', amount: '0.8901', total: '59,889.27', depth: 40 },
    { price: '67,265.00', amount: '1.2345', total: '83,041.59', depth: 55 },
    { price: '67,260.00', amount: '0.4567', total: '30,723.64', depth: 30 },
    { price: '67,255.00', amount: '0.7890', total: '53,084.20', depth: 45 },
    { price: '67,250.00', amount: '1.5678', total: '105,434.85', depth: 70 },
    { price: '67,245.00', amount: '0.3456', total: '23,239.47', depth: 20 },
]);

const bids = ref([
    { price: '67,234.50', amount: '0.3456', total: '23,235.85', depth: 20 },
    { price: '67,230.00', amount: '0.6789', total: '45,648.75', depth: 35 },
    { price: '67,225.00', amount: '1.0123', total: '68,052.18', depth: 50 },
    { price: '67,220.00', amount: '0.4567', total: '30,699.67', depth: 25 },
    { price: '67,215.00', amount: '0.8901', total: '59,828.57', depth: 45 },
    { price: '67,210.00', amount: '1.3456', total: '90,445.98', depth: 60 },
    { price: '67,205.00', amount: '0.5678', total: '38,157.76', depth: 30 },
    { price: '67,200.00', amount: '2.1234', total: '142,692.48', depth: 85 },
]);

const spreadAmount = computed(() => {
    const askPrice = parseFloat(asks.value[asks.value.length - 1]?.price.replace(',', '') || 0);
    const bidPrice = parseFloat(bids.value[0]?.price.replace(',', '') || 0);
    return (askPrice - bidPrice).toFixed(2);
});

const spreadPercent = computed(() => {
    const askPrice = parseFloat(asks.value[asks.value.length - 1]?.price.replace(',', '') || 0);
    const bidPrice = parseFloat(bids.value[0]?.price.replace(',', '') || 0);
    return ((askPrice - bidPrice) / bidPrice * 100).toFixed(3);
});
</script>

<template>
    <div class="glass-dark rounded-2xl h-full flex flex-col">
        <!-- Header -->
        <div class="flex items-center justify-between p-4 border-b border-white/5">
            <h3 class="font-semibold text-white">Order Book</h3>
            <div class="flex items-center gap-2">
                <!-- View Mode -->
                <div class="flex items-center gap-1 p-1 rounded-lg bg-dark-800">
                    <button
                        @click="viewMode = 'both'"
                        :class="['p-1.5 rounded transition-all', viewMode === 'both' ? 'bg-dark-600' : 'hover:bg-dark-700']"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor">
                            <rect x="2" y="2" width="12" height="5" rx="1" class="text-trading-red"/>
                            <rect x="2" y="9" width="12" height="5" rx="1" class="text-trading-green"/>
                        </svg>
                    </button>
                    <button
                        @click="viewMode = 'bids'"
                        :class="['p-1.5 rounded transition-all', viewMode === 'bids' ? 'bg-dark-600' : 'hover:bg-dark-700']"
                    >
                        <svg class="w-4 h-4 text-trading-green" viewBox="0 0 16 16" fill="currentColor">
                            <rect x="2" y="2" width="12" height="12" rx="1"/>
                        </svg>
                    </button>
                    <button
                        @click="viewMode = 'asks'"
                        :class="['p-1.5 rounded transition-all', viewMode === 'asks' ? 'bg-dark-600' : 'hover:bg-dark-700']"
                    >
                        <svg class="w-4 h-4 text-trading-red" viewBox="0 0 16 16" fill="currentColor">
                            <rect x="2" y="2" width="12" height="12" rx="1"/>
                        </svg>
                    </button>
                </div>

                <!-- Precision -->
                <select
                    v-model="precision"
                    class="bg-dark-800 border-0 rounded-lg text-xs text-dark-300 py-1.5 px-2 focus:ring-1 focus:ring-primary-500"
                >
                    <option :value="0">0</option>
                    <option :value="1">0.1</option>
                    <option :value="2">0.01</option>
                </select>
            </div>
        </div>

        <!-- Column Headers -->
        <div class="grid grid-cols-3 gap-2 px-4 py-2 text-xs font-medium text-dark-400 border-b border-white/5">
            <span>Price (USDT)</span>
            <span class="text-right">Amount (BTC)</span>
            <span class="text-right">Total</span>
        </div>

        <!-- Order Book Content -->
        <div class="flex-1 overflow-hidden flex flex-col">
            <!-- Asks (Sell Orders) -->
            <div
                v-if="viewMode !== 'bids'"
                :class="['overflow-y-auto', viewMode === 'both' ? 'flex-1' : 'flex-1']"
            >
                <div class="flex flex-col-reverse">
                    <div
                        v-for="(ask, index) in asks"
                        :key="`ask-${index}`"
                        class="relative grid grid-cols-3 gap-2 px-4 py-1.5 text-sm hover:bg-white/5 cursor-pointer transition-colors"
                    >
                        <!-- Depth Background -->
                        <div
                            class="absolute right-0 top-0 h-full bg-trading-red/10"
                            :style="{ width: `${ask.depth}%` }"
                        ></div>

                        <span class="text-trading-red font-mono relative z-10">${{ ask.price }}</span>
                        <span class="text-right text-white font-mono relative z-10">{{ ask.amount }}</span>
                        <span class="text-right text-dark-400 font-mono relative z-10">{{ ask.total }}</span>
                    </div>
                </div>
            </div>

            <!-- Spread -->
            <div class="px-4 py-3 border-y border-white/5 bg-dark-800/50">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-lg font-bold font-mono text-trading-green">$67,234.50</span>
                        <svg class="w-4 h-4 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                        </svg>
                    </div>
                    <div class="text-right">
                        <span class="text-xs text-dark-400">Spread: </span>
                        <span class="text-xs font-mono text-white">${{ spreadAmount }}</span>
                        <span class="text-xs text-dark-400 ml-1">({{ spreadPercent }}%)</span>
                    </div>
                </div>
            </div>

            <!-- Bids (Buy Orders) -->
            <div
                v-if="viewMode !== 'asks'"
                :class="['overflow-y-auto', viewMode === 'both' ? 'flex-1' : 'flex-1']"
            >
                <div
                    v-for="(bid, index) in bids"
                    :key="`bid-${index}`"
                    class="relative grid grid-cols-3 gap-2 px-4 py-1.5 text-sm hover:bg-white/5 cursor-pointer transition-colors"
                >
                    <!-- Depth Background -->
                    <div
                        class="absolute left-0 top-0 h-full bg-trading-green/10"
                        :style="{ width: `${bid.depth}%` }"
                    ></div>

                    <span class="text-trading-green font-mono relative z-10">${{ bid.price }}</span>
                    <span class="text-right text-white font-mono relative z-10">{{ bid.amount }}</span>
                    <span class="text-right text-dark-400 font-mono relative z-10">{{ bid.total }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
