<script setup>
/**
 * TPIX TRADE - Recent Trades Component
 * Real-time trade history
 * Developed by Xman Studio
 */

import { ref } from 'vue';

const props = defineProps({
    symbol: {
        type: String,
        default: 'BTC/USDT'
    }
});

// Mock trades data
const trades = ref([
    { id: 1, price: '67,234.50', amount: '0.0523', time: '14:32:45', isBuy: true },
    { id: 2, price: '67,234.00', amount: '0.1200', time: '14:32:44', isBuy: true },
    { id: 3, price: '67,233.50', amount: '0.0890', time: '14:32:43', isBuy: false },
    { id: 4, price: '67,235.00', amount: '0.2100', time: '14:32:42', isBuy: true },
    { id: 5, price: '67,234.50', amount: '0.0456', time: '14:32:41', isBuy: false },
    { id: 6, price: '67,233.00', amount: '0.1567', time: '14:32:40', isBuy: false },
    { id: 7, price: '67,234.00', amount: '0.0789', time: '14:32:39', isBuy: true },
    { id: 8, price: '67,235.50', amount: '0.3210', time: '14:32:38', isBuy: true },
    { id: 9, price: '67,234.50', amount: '0.0654', time: '14:32:37', isBuy: false },
    { id: 10, price: '67,233.50', amount: '0.1890', time: '14:32:36', isBuy: true },
    { id: 11, price: '67,234.00', amount: '0.0432', time: '14:32:35', isBuy: true },
    { id: 12, price: '67,235.00', amount: '0.2567', time: '14:32:34', isBuy: false },
]);
</script>

<template>
    <div class="glass-dark rounded-2xl p-4 h-full flex flex-col">
        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-white">Recent Trades</h3>
            <button class="text-dark-400 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
            </button>
        </div>

        <!-- Column Headers -->
        <div class="grid grid-cols-3 gap-2 text-xs text-dark-400 mb-2 px-1">
            <span>Price (USDT)</span>
            <span class="text-right">Amount (BTC)</span>
            <span class="text-right">Time</span>
        </div>

        <!-- Trades List -->
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <div class="space-y-0.5">
                <div
                    v-for="trade in trades"
                    :key="trade.id"
                    class="grid grid-cols-3 gap-2 text-sm py-1.5 px-1 hover:bg-white/5 rounded transition-colors"
                >
                    <span :class="['font-mono', trade.isBuy ? 'text-trading-green' : 'text-trading-red']">
                        {{ trade.price }}
                    </span>
                    <span class="font-mono text-right text-dark-200">{{ trade.amount }}</span>
                    <span class="font-mono text-right text-dark-400">{{ trade.time }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
