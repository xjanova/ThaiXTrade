<script setup>
/**
 * TPIX TRADE - Trade History Component
 * Display real completed trades from backend API
 * Developed by Xman Studio
 */

import { ref, onMounted, computed } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import { getTxUrl } from '@/utils/web3';
import axios from 'axios';

const walletStore = useWalletStore();
const trades = ref([]);
const isLoading = ref(false);

const isConnected = computed(() => walletStore.isConnected);

async function fetchHistory() {
    if (!walletStore.address) {
        trades.value = [];
        return;
    }

    isLoading.value = true;
    try {
        const { data } = await axios.get('/api/v1/trading/history', {
            params: { wallet_address: walletStore.address },
        });
        if (data.success) {
            trades.value = data.data.map(t => ({
                id: t.id,
                pair: t.pair,
                side: t.side,
                price: t.price || '0',
                amount: t.amount,
                total: t.total || '0',
                fee: t.fee || '0',
                txHash: t.tx_hash,
                time: new Date(t.created_at).toLocaleString(),
            }));
        }
    } catch (err) {
        trades.value = [];
    } finally {
        isLoading.value = false;
    }
}

onMounted(() => {
    fetchHistory();
});
</script>

<template>
    <div>
        <!-- Not Connected -->
        <div v-if="!isConnected" class="py-12 text-center">
            <svg class="w-12 h-12 mx-auto text-dark-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-dark-400">Connect wallet to view your trade history</p>
        </div>

        <div v-else>
            <!-- Filters -->
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-4">
                    <select class="bg-dark-800 border-dark-600 rounded-lg text-sm text-dark-300 py-2 px-3 focus:ring-primary-500 focus:border-primary-500">
                        <option value="all">All Pairs</option>
                    </select>
                    <select class="bg-dark-800 border-dark-600 rounded-lg text-sm text-dark-300 py-2 px-3 focus:ring-primary-500 focus:border-primary-500">
                        <option value="all">All Sides</option>
                        <option value="buy">Buy</option>
                        <option value="sell">Sell</option>
                    </select>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="isLoading" class="py-8 text-center text-dark-400">
                <div class="animate-pulse">Loading trade history...</div>
            </div>

            <!-- Trades Table -->
            <div v-else class="overflow-x-auto">
                <table class="trading-table" v-if="trades.length > 0">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Pair</th>
                            <th>Side</th>
                            <th>Price</th>
                            <th>Amount</th>
                            <th>Total</th>
                            <th>Fee</th>
                            <th>Tx</th>
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
                            <td>
                                <a v-if="trade.txHash"
                                    :href="getTxUrl(trade.txHash)"
                                    target="_blank" rel="noopener"
                                    class="text-primary-400 hover:text-primary-300 text-xs font-mono underline">
                                    {{ trade.txHash.slice(0, 8) }}...
                                </a>
                                <span v-else class="text-dark-600 text-xs">-</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Empty State -->
                <div v-else class="py-12 text-center">
                    <svg class="w-12 h-12 mx-auto text-dark-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-dark-400">No trade history yet</p>
                    <p class="text-xs text-dark-500 mt-1">Your completed trades will appear here.</p>
                </div>
            </div>
        </div>
    </div>
</template>
