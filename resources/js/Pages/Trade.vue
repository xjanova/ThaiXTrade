<script setup>
/**
 * TPIX TRADE - Trading Dashboard Page
 * Main trading interface with real-time Binance data
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
import { useBinanceData } from '@/Composables/useBinanceData';
import { useWalletStore } from '@/Stores/walletStore';

const props = defineProps({
    pair: {
        type: String,
        default: 'BTC-USDT'
    }
});

const currentPair = computed(() => props.pair.replace('-', '/'));
const binanceSymbol = computed(() => props.pair.replace('-', ''));

// Real market data from Binance
const {
    ticker,
    asks,
    bids,
    trades,
    isLoading,
    fetchInitialData,
    connectWebSocket,
} = useBinanceData(() => binanceSymbol.value);

const walletStore = useWalletStore();
const activeTab = ref('openOrders');
const showWalletModal = ref(false);

const tabs = [
    { id: 'openOrders', label: 'Open Orders', count: 0 },
    { id: 'history', label: 'Trade History', count: null },
    { id: 'funds', label: 'Funds', count: null },
];

const handleSubmitOrder = (order) => {
    console.log('Order submitted:', order);
    // TODO: Execute via PancakeSwap using useSwap composable
};

const handleConnectWallet = () => {
    // Trigger the wallet modal in AppLayout
    showWalletModal.value = true;
};

onMounted(async () => {
    await fetchInitialData();
    connectWebSocket();
});
</script>

<template>
    <Head :title="`Trade ${currentPair}`" />

    <AppLayout :hide-sidebar="true">
        <div class="max-w-[1920px] mx-auto">
            <!-- Trading Layout: 3 columns -->
            <div class="grid grid-cols-12 gap-3">

                <!-- Left Column: Chart + Order Tabs -->
                <div class="col-span-12 xl:col-span-8 lg:col-span-7 space-y-3">
                    <!-- Chart -->
                    <TradingChart
                        :symbol="currentPair"
                        :ticker="ticker"
                        class="h-[520px]"
                    />

                    <!-- Order Tabs -->
                    <div class="glass-dark rounded-2xl overflow-hidden">
                        <div class="flex items-center border-b border-white/5">
                            <button
                                v-for="tab in tabs"
                                :key="tab.id"
                                @click="activeTab = tab.id"
                                :class="[
                                    'px-5 py-3 text-sm font-medium transition-all relative',
                                    activeTab === tab.id
                                        ? 'text-primary-400'
                                        : 'text-dark-400 hover:text-white'
                                ]"
                            >
                                <span>{{ tab.label }}</span>
                                <span
                                    v-if="tab.count"
                                    class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full bg-primary-500/20 text-primary-400"
                                >
                                    {{ tab.count }}
                                </span>
                                <div
                                    v-if="activeTab === tab.id"
                                    class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary-500"
                                ></div>
                            </button>
                        </div>
                        <div class="p-4">
                            <OpenOrders v-if="activeTab === 'openOrders'" />
                            <TradeHistory v-else-if="activeTab === 'history'" />
                            <div v-else class="py-6 text-center text-dark-400 text-sm">
                                <p>Connect wallet to view funds</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Order Book + Trade Form + Recent Trades -->
                <div class="col-span-12 xl:col-span-4 lg:col-span-5 space-y-3">
                    <!-- Order Book -->
                    <OrderBook
                        :symbol="currentPair"
                        :asks="asks"
                        :bids="bids"
                        :ticker-price="ticker.price"
                        :is-loading="isLoading"
                        class="h-[340px]"
                    />

                    <!-- Trade Form -->
                    <TradeForm
                        :symbol="currentPair"
                        :ticker-price="ticker.price"
                        :is-wallet-connected="walletStore.isConnected"
                        @submit-order="handleSubmitOrder"
                        @connect-wallet="handleConnectWallet"
                    />

                    <!-- Recent Trades -->
                    <RecentTrades
                        :symbol="currentPair"
                        :trades="trades"
                        :is-loading="isLoading"
                        class="h-[280px]"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
