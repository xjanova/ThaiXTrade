<script setup>
/**
 * TPIX TRADE - Trading Dashboard Page
 * Main trading interface with real-time Binance data
 * and real order submission via PancakeSwap
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TradingChart from '@/Components/Trading/TradingChart.vue';
import OrderBook from '@/Components/Trading/OrderBook.vue';
import TradeForm from '@/Components/Trading/TradeForm.vue';
import RecentTrades from '@/Components/Trading/RecentTrades.vue';
import OpenOrders from '@/Components/Trading/OpenOrders.vue';
import TradeHistory from '@/Components/Trading/TradeHistory.vue';
import PairSelector from '@/Components/Trading/PairSelector.vue';
import { useBinanceData } from '@/Composables/useBinanceData';
import { useSwap } from '@/Composables/useSwap';
import { useWalletStore } from '@/Stores/walletStore';
import { useWalletBalance } from '@/Composables/useWalletBalance';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();
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
    disconnectWebSocket,
} = useBinanceData(() => binanceSymbol.value);

const walletStore = useWalletStore();
const { balances, fetchBalances } = useWalletBalance();
const { error: swapError } = useSwap();

const activeTab = ref('openOrders');
const showWalletModal = ref(false);
const orderStatus = ref(null); // 'submitting', 'success', 'error'
const orderMessage = ref('');

const tabs = [
    { id: 'openOrders', label: 'Open Orders', count: 0 },
    { id: 'history', label: 'Trade History', count: null },
    { id: 'funds', label: 'Funds', count: null },
];

const handleSubmitOrder = async (order) => {
    if (!walletStore.isConnected || !walletStore.address) {
        handleConnectWallet();
        return;
    }

    orderStatus.value = 'submitting';
    orderMessage.value = '';

    try {
        const { data } = await axios.post('/api/v1/trading/order', {
            wallet_address: walletStore.address,
            pair: currentPair.value,
            side: order.side,
            type: order.type,
            price: parseFloat(String(order.price).replace(/,/g, '')) || 0,
            amount: parseFloat(order.amount) || 0,
            chain_id: walletStore.chainId || 56,
        });

        if (data.success) {
            orderStatus.value = 'success';
            orderMessage.value = `${order.side.toUpperCase()} order placed successfully!`;
            // Refresh balances and orders
            fetchBalances();
        }
    } catch (err) {
        orderStatus.value = 'error';
        orderMessage.value = err.response?.data?.error?.message || 'Failed to place order.';
    }

    // Auto-clear status after 3 seconds
    setTimeout(() => {
        orderStatus.value = null;
        orderMessage.value = '';
    }, 3000);
};

const handleConnectWallet = () => {
    showWalletModal.value = true;
};

onMounted(async () => {
    await fetchInitialData();
    connectWebSocket();

    if (walletStore.isConnected) {
        fetchBalances();
    }
});

// Clean up WebSocket on page navigation (safety net for composable cleanup)
onUnmounted(() => {
    disconnectWebSocket();
});
</script>

<template>
    <Head :title="`Trade ${currentPair}`" />

    <AppLayout :hide-sidebar="true">
        <div class="max-w-[1920px] mx-auto">
            <!-- Order Status Toast -->
            <div
                v-if="orderStatus"
                :class="[
                    'fixed top-4 right-4 z-50 px-4 py-3 rounded-xl shadow-lg text-sm font-medium transition-all',
                    orderStatus === 'success' ? 'bg-trading-green/90 text-white' :
                    orderStatus === 'error' ? 'bg-trading-red/90 text-white' :
                    'bg-primary-500/90 text-white'
                ]"
            >
                {{ orderStatus === 'submitting' ? 'Placing order...' : orderMessage }}
            </div>

            <!-- Trading Layout: 3 columns -->
            <div class="grid grid-cols-12 gap-3">

                <!-- Left Column: Pair Selector + Chart + Order Tabs -->
                <div class="col-span-12 xl:col-span-8 lg:col-span-7 space-y-3">
                    <!-- Pair Selector + Ticker Info -->
                    <div class="flex items-center gap-4">
                        <PairSelector :currentPair="currentPair" />
                        <div v-if="ticker" class="flex items-center gap-6 text-sm">
                            <div>
                                <span class="text-dark-400 text-xs">{{ t('trade.price') }}</span>
                                <p :class="['font-mono font-bold text-lg', ticker.priceChange >= 0 ? 'text-trading-green' : 'text-trading-red']">
                                    ${{ ticker.lastPrice ? parseFloat(ticker.lastPrice).toLocaleString('en-US', {minimumFractionDigits: 2}) : '—' }}
                                </p>
                            </div>
                            <div>
                                <span class="text-dark-400 text-xs">{{ t('trade.change24h') }}</span>
                                <p :class="[ticker.priceChangePercent >= 0 ? 'text-trading-green' : 'text-trading-red']">
                                    {{ ticker.priceChangePercent >= 0 ? '+' : '' }}{{ parseFloat(ticker.priceChangePercent || 0).toFixed(2) }}%
                                </p>
                            </div>
                            <div>
                                <span class="text-dark-400 text-xs">{{ t('trade.high24h') }}</span>
                                <p class="text-white font-mono">${{ parseFloat(ticker.highPrice || 0).toLocaleString() }}</p>
                            </div>
                            <div>
                                <span class="text-dark-400 text-xs">{{ t('trade.low24h') }}</span>
                                <p class="text-white font-mono">${{ parseFloat(ticker.lowPrice || 0).toLocaleString() }}</p>
                            </div>
                        </div>
                    </div>

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
                                <div v-if="walletStore.isConnected && balances.length > 0">
                                    <div v-for="bal in balances" :key="bal.token_address" class="flex items-center justify-between py-2 border-b border-white/5">
                                        <span class="text-white font-medium">{{ bal.symbol }}</span>
                                        <span class="font-mono text-white">{{ parseFloat(bal.balance).toFixed(6) }}</span>
                                    </div>
                                </div>
                                <p v-else>Connect wallet to view funds</p>
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
                        :balances="balances"
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
