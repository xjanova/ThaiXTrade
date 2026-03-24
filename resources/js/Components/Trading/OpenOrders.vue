<script setup>
/**
 * TPIX TRADE - Open Orders Component
 * Display real active orders from backend API
 * Developed by Xman Studio
 */

import { ref, onMounted, computed } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import axios from 'axios';

const walletStore = useWalletStore();
const orders = ref([]);
const isLoading = ref(false);

const isConnected = computed(() => walletStore.isConnected);

async function fetchOrders() {
    if (!walletStore.address) {
        orders.value = [];
        return;
    }

    isLoading.value = true;
    try {
        const { data } = await axios.get('/api/v1/trading/orders', {
            params: { wallet_address: walletStore.address },
        });
        if (data.success) {
            orders.value = data.data.map(o => ({
                id: o.id,
                pair: o.pair,
                type: o.type,
                side: o.side,
                price: o.price || '0',
                amount: o.amount,
                filled: '0%',
                total: (parseFloat(o.price || 0) * parseFloat(o.amount)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                time: new Date(o.created_at).toLocaleString(),
            }));
        }
    } catch (err) {
        // Silent - no orders found
        orders.value = [];
    } finally {
        isLoading.value = false;
    }
}

const cancelOrder = async (orderId) => {
    try {
        await axios.delete(`/api/v1/trading/order/${orderId}`, {
            data: { wallet_address: walletStore.address },
        });
        orders.value = orders.value.filter(o => o.id !== orderId);
    } catch (err) {
        console.warn('Failed to cancel order:', err.message);
    }
};

const cancelAll = async () => {
    for (const order of orders.value) {
        await cancelOrder(order.id);
    }
};

onMounted(() => {
    fetchOrders();
});
</script>

<template>
    <div>
        <!-- Not Connected -->
        <div v-if="!isConnected" class="py-12 text-center">
            <svg class="w-12 h-12 mx-auto text-dark-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            <p class="text-dark-400">Connect wallet to view your open orders</p>
        </div>

        <div v-else>
            <!-- Actions Bar -->
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-dark-400 cursor-pointer">
                        <input type="checkbox" class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500">
                        <span>Hide Other Pairs</span>
                    </label>
                </div>
                <button
                    v-if="orders.length > 0"
                    @click="cancelAll"
                    class="text-sm text-trading-red hover:text-trading-red-light transition-colors"
                >
                    Cancel All
                </button>
            </div>

            <!-- Loading -->
            <div v-if="isLoading" class="py-8 text-center text-dark-400">
                <div class="animate-pulse">Loading orders...</div>
            </div>

            <!-- Orders Table -->
            <div v-else class="overflow-x-auto">
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
    </div>
</template>
