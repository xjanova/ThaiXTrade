<script setup>
/**
 * ThaiXTrade - Trading Dashboard Page
 * Main trading interface
 * Developed by Xman Studio
 */

import { ref, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TradingChart from '@/Components/Trading/TradingChart.vue';
import OrderBook from '@/Components/Trading/OrderBook.vue';
import TradeForm from '@/Components/Trading/TradeForm.vue';
import RecentTrades from '@/Components/Trading/RecentTrades.vue';
import OpenOrders from '@/Components/Trading/OpenOrders.vue';
import TradeHistory from '@/Components/Trading/TradeHistory.vue';

const props = defineProps({
    pair: {
        type: String,
        default: 'BTC-USDT'
    }
});

const currentPair = computed(() => props.pair.replace('-', '/'));
const activeTab = ref('openOrders');

const tabs = [
    { id: 'openOrders', label: 'Open Orders', count: 3 },
    { id: 'history', label: 'Trade History', count: null },
    { id: 'funds', label: 'Funds', count: null },
];

// Mock wallet balance
const balance = ref({
    base: { symbol: 'BTC', amount: '0.5678' },
    quote: { symbol: 'USDT', amount: '15,234.50' }
});

const handleSubmitOrder = (order) => {
    console.log('Order submitted:', order);
    // TODO: Implement order submission via Web3
};
</script>

<template>
    <Head :title="`Trade ${currentPair}`" />

    <AppLayout>
        <div class="max-w-[1920px] mx-auto">
            <!-- Trading Layout -->
            <div class="grid grid-cols-12 gap-4">
                <!-- Left Column: Chart & Orders -->
                <div class="col-span-12 xl:col-span-8 space-y-4">
                    <!-- Chart -->
                    <TradingChart :symbol="currentPair" class="h-[500px]" />

                    <!-- Order Tabs -->
                    <div class="glass-dark rounded-2xl overflow-hidden">
                        <!-- Tab Navigation -->
                        <div class="flex items-center border-b border-white/5">
                            <button
                                v-for="tab in tabs"
                                :key="tab.id"
                                @click="activeTab = tab.id"
                                :class="[
                                    'px-6 py-4 text-sm font-medium transition-all relative',
                                    activeTab === tab.id
                                        ? 'text-primary-400'
                                        : 'text-dark-400 hover:text-white'
                                ]"
                            >
                                <span>{{ tab.label }}</span>
                                <span
                                    v-if="tab.count"
                                    class="ml-2 px-2 py-0.5 text-xs rounded-full bg-primary-500/20 text-primary-400"
                                >
                                    {{ tab.count }}
                                </span>
                                <div
                                    v-if="activeTab === tab.id"
                                    class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary-500"
                                ></div>
                            </button>
                        </div>

                        <!-- Tab Content -->
                        <div class="p-4">
                            <OpenOrders v-if="activeTab === 'openOrders'" />
                            <TradeHistory v-else-if="activeTab === 'history'" />
                            <div v-else class="py-8 text-center text-dark-400">
                                <p>Connect wallet to view funds</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Order Book, Trade Form, Recent Trades -->
                <div class="col-span-12 xl:col-span-4 space-y-4">
                    <!-- Order Book -->
                    <OrderBook :symbol="currentPair" class="h-[380px]" />

                    <!-- Trade Form -->
                    <TradeForm
                        :symbol="currentPair"
                        :balance="balance"
                        @submit-order="handleSubmitOrder"
                    />

                    <!-- Recent Trades -->
                    <RecentTrades :symbol="currentPair" class="h-[300px]" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
