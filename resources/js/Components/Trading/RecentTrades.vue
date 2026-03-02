<script setup>
/**
 * TPIX TRADE - Recent Trades Component
 * Real-time trade history from Binance
 * Developed by Xman Studio
 */

import { computed } from 'vue';

const props = defineProps({
    symbol: { type: String, default: 'BTC/USDT' },
    trades: { type: Array, default: () => [] },
    isLoading: { type: Boolean, default: false },
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
</script>

<template>
    <div class="glass-dark rounded-2xl p-3 h-full flex flex-col">
        <!-- Header -->
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold text-white text-sm">Recent Trades</h3>
            <div class="w-2 h-2 rounded-full bg-trading-green animate-pulse" title="Live"></div>
        </div>

        <!-- Column Headers -->
        <div class="grid grid-cols-3 gap-1 text-xs text-dark-400 mb-1.5 px-0.5">
            <span>Price (USDT)</span>
            <span class="text-right">Amount</span>
            <span class="text-right">Time</span>
        </div>

        <!-- Loading -->
        <div v-if="isLoading && !trades.length" class="flex-1 flex items-center justify-center">
            <div class="text-dark-400 text-sm animate-pulse">Loading trades...</div>
        </div>

        <!-- Trades List -->
        <div v-else class="flex-1 overflow-y-auto custom-scrollbar">
            <div class="space-y-0">
                <div
                    v-for="trade in trades"
                    :key="trade.id"
                    class="grid grid-cols-3 gap-1 text-xs py-1 px-0.5 hover:bg-white/5 rounded transition-colors"
                >
                    <span :class="['font-mono', trade.isBuy ? 'text-trading-green' : 'text-trading-red']">
                        {{ formatPrice(trade.price) }}
                    </span>
                    <span class="font-mono text-right text-dark-200">{{ formatAmount(trade.amount) }}</span>
                    <span class="font-mono text-right text-dark-400">{{ trade.time }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
