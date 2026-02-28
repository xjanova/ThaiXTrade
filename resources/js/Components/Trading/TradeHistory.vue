<script setup>
/**
 * TPIX TRADE - Trade History Component
 * Display completed trades
 * Developed by Xman Studio
 */

import { ref } from 'vue';

const trades = ref([
    {
        id: 1,
        pair: 'BTC/USDT',
        side: 'buy',
        price: '67,234.50',
        amount: '0.0523',
        total: '3,516.36',
        fee: '3.52',
        time: '2024-01-15 14:32:45'
    },
    {
        id: 2,
        pair: 'ETH/USDT',
        side: 'sell',
        price: '3,456.78',
        amount: '1.2000',
        total: '4,148.14',
        fee: '4.15',
        time: '2024-01-15 13:45:20'
    },
    {
        id: 3,
        pair: 'BNB/USDT',
        side: 'buy',
        price: '567.89',
        amount: '5.0000',
        total: '2,839.45',
        fee: '2.84',
        time: '2024-01-15 12:30:15'
    },
    {
        id: 4,
        pair: 'SOL/USDT',
        side: 'buy',
        price: '178.90',
        amount: '10.0000',
        total: '1,789.00',
        fee: '1.79',
        time: '2024-01-15 11:15:45'
    },
    {
        id: 5,
        pair: 'BTC/USDT',
        side: 'sell',
        price: '67,890.00',
        amount: '0.1000',
        total: '6,789.00',
        fee: '6.79',
        time: '2024-01-15 10:20:30'
    },
]);
</script>

<template>
    <div>
        <!-- Filters -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-4">
                <select class="bg-dark-800 border-dark-600 rounded-lg text-sm text-dark-300 py-2 px-3 focus:ring-primary-500 focus:border-primary-500">
                    <option value="all">All Pairs</option>
                    <option value="btc">BTC/USDT</option>
                    <option value="eth">ETH/USDT</option>
                </select>
                <select class="bg-dark-800 border-dark-600 rounded-lg text-sm text-dark-300 py-2 px-3 focus:ring-primary-500 focus:border-primary-500">
                    <option value="all">All Sides</option>
                    <option value="buy">Buy</option>
                    <option value="sell">Sell</option>
                </select>
            </div>
            <button class="text-sm text-primary-400 hover:text-primary-300 transition-colors">
                Export CSV
            </button>
        </div>

        <!-- Trades Table -->
        <div class="overflow-x-auto">
            <table class="trading-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Pair</th>
                        <th>Side</th>
                        <th>Price</th>
                        <th>Amount</th>
                        <th>Total</th>
                        <th>Fee</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="trade in trades" :key="trade.id">
                        <td class="font-mono text-dark-400">{{ trade.time }}</td>
                        <td class="font-medium text-white">{{ trade.pair }}</td>
                        <td>
                            <span
                                :class="[
                                    'px-2 py-1 rounded text-xs font-medium',
                                    trade.side === 'buy' ? 'bg-trading-green/20 text-trading-green' : 'bg-trading-red/20 text-trading-red'
                                ]"
                            >
                                {{ trade.side.toUpperCase() }}
                            </span>
                        </td>
                        <td class="font-mono text-white">${{ trade.price }}</td>
                        <td class="font-mono text-dark-300">{{ trade.amount }}</td>
                        <td class="font-mono text-white">${{ trade.total }}</td>
                        <td class="font-mono text-dark-400">${{ trade.fee }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="flex items-center justify-between mt-4 pt-4 border-t border-white/5">
            <p class="text-sm text-dark-400">Showing 1-5 of 156 trades</p>
            <div class="flex items-center gap-2">
                <button class="p-2 rounded-lg bg-dark-800 text-dark-400 hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <button class="px-3 py-1.5 rounded-lg bg-primary-500 text-white text-sm font-medium">1</button>
                <button class="px-3 py-1.5 rounded-lg bg-dark-800 text-dark-400 hover:text-white text-sm font-medium transition-colors">2</button>
                <button class="px-3 py-1.5 rounded-lg bg-dark-800 text-dark-400 hover:text-white text-sm font-medium transition-colors">3</button>
                <span class="text-dark-400">...</span>
                <button class="px-3 py-1.5 rounded-lg bg-dark-800 text-dark-400 hover:text-white text-sm font-medium transition-colors">32</button>
                <button class="p-2 rounded-lg bg-dark-800 text-dark-400 hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>
