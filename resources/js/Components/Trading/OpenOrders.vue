<script setup>
/**
 * ThaiXTrade - Open Orders Component
 * Display active orders
 * Developed by Xman Studio
 */

import { ref } from 'vue';

const orders = ref([
    {
        id: 1,
        pair: 'BTC/USDT',
        type: 'Limit',
        side: 'buy',
        price: '65,000.00',
        amount: '0.1000',
        filled: '0%',
        total: '6,500.00',
        time: '2024-01-15 14:32:45'
    },
    {
        id: 2,
        pair: 'ETH/USDT',
        type: 'Limit',
        side: 'sell',
        price: '3,600.00',
        amount: '2.5000',
        filled: '40%',
        total: '9,000.00',
        time: '2024-01-15 13:20:10'
    },
    {
        id: 3,
        pair: 'BTC/USDT',
        type: 'Stop Limit',
        side: 'buy',
        price: '68,500.00',
        amount: '0.0500',
        filled: '0%',
        total: '3,425.00',
        time: '2024-01-15 12:15:30'
    },
]);

const cancelOrder = (orderId) => {
    orders.value = orders.value.filter(o => o.id !== orderId);
};

const cancelAll = () => {
    orders.value = [];
};
</script>

<template>
    <div>
        <!-- Actions Bar -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2 text-sm text-dark-400 cursor-pointer">
                    <input type="checkbox" class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500">
                    <span>Hide Other Pairs</span>
                </label>
            </div>
            <button
                @click="cancelAll"
                class="text-sm text-trading-red hover:text-trading-red-light transition-colors"
            >
                Cancel All
            </button>
        </div>

        <!-- Orders Table -->
        <div class="overflow-x-auto">
            <table class="trading-table" v-if="orders.length > 0">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Pair</th>
                        <th>Type</th>
                        <th>Side</th>
                        <th>Price</th>
                        <th>Amount</th>
                        <th>Filled</th>
                        <th>Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="order in orders" :key="order.id">
                        <td class="font-mono text-dark-400">{{ order.time }}</td>
                        <td class="font-medium text-white">{{ order.pair }}</td>
                        <td class="text-dark-300">{{ order.type }}</td>
                        <td>
                            <span
                                :class="[
                                    'px-2 py-1 rounded text-xs font-medium',
                                    order.side === 'buy' ? 'bg-trading-green/20 text-trading-green' : 'bg-trading-red/20 text-trading-red'
                                ]"
                            >
                                {{ order.side.toUpperCase() }}
                            </span>
                        </td>
                        <td class="font-mono text-white">${{ order.price }}</td>
                        <td class="font-mono text-dark-300">{{ order.amount }}</td>
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="w-16 h-1.5 bg-dark-700 rounded-full overflow-hidden">
                                    <div
                                        class="h-full bg-primary-500 rounded-full"
                                        :style="{ width: order.filled }"
                                    ></div>
                                </div>
                                <span class="text-xs text-dark-400">{{ order.filled }}</span>
                            </div>
                        </td>
                        <td class="font-mono text-white">${{ order.total }}</td>
                        <td>
                            <button
                                @click="cancelOrder(order.id)"
                                class="text-trading-red hover:text-trading-red-light transition-colors"
                            >
                                Cancel
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Empty State -->
            <div v-else class="py-12 text-center">
                <svg class="w-12 h-12 mx-auto text-dark-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <p class="text-dark-400">No open orders</p>
            </div>
        </div>
    </div>
</template>
