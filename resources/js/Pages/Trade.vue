<script setup>
/**
 * TPIX TRADE - Trading Dashboard Page
 * Main trading interface with real-time data:
 * - TPIX pairs: internal order book (real trades)
 * - Other pairs: Binance data + PancakeSwap execution
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

// Detect if this is a TPIX pair (uses internal order book, not Binance)
const isTPIXPair = computed(() => props.pair.toUpperCase().startsWith('TPIX'));

// Real market data from Binance (for non-TPIX pairs)
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

// TPIX internal data
const tpixPrice = ref(null);
let tpixRefreshInterval = null;

/**
 * Fetch all TPIX data: price, order book, recent trades.
 */
async function fetchTpixData() {
    try {
        const [priceRes, bookRes, tradesRes] = await Promise.all([
            axios.get('/api/v1/tpix/price'),
            axios.get('/api/v1/tpix/orderbook'),
            axios.get('/api/v1/tpix/trades'),
        ]);

        // Price & ticker
        if (priceRes.data.success) {
            const p = priceRes.data.data;
            tpixPrice.value = p;
            ticker.value = {
                price: p.price,
                lastPrice: p.price,
                change: p.change_24h,
                priceChange: p.change_24h,
                priceChangePercent: p.change_24h,
                changePercent: p.change_24h,
                high: p.high_24h,
                highPrice: p.high_24h,
                low: p.low_24h,
                lowPrice: p.low_24h,
                volume: p.volume_24h,
            };
        }

        // Order book
        if (bookRes.data.success) {
            const book = bookRes.data.data;
            asks.value = (book.asks || []).map(a => [a.price, a.amount]);
            bids.value = (book.bids || []).map(b => [b.price, b.amount]);
        }

        // Recent trades
        if (tradesRes.data.success) {
            trades.value = (tradesRes.data.data || []).map(t => ({
                price: t.price,
                qty: t.amount,
                quoteQty: t.total,
                time: t.time,
                isBuyerMaker: t.side === 'sell',
            }));
        }

        isLoading.value = false;
    } catch {
        isLoading.value = false;
    }
}

const walletStore = useWalletStore();
const { balances, fetchBalances } = useWalletBalance();
const swap = useSwap();

const activeTab = ref('openOrders');
const selectedPrice = ref(null);
const orderStatus = ref(null);
const orderMessage = ref('');

const tabs = [
    { id: 'openOrders', label: 'Open Orders', count: 0 },
    { id: 'history', label: 'Trade History', count: null },
    { id: 'funds', label: 'Funds', count: null },
];

/**
 * Handle order submission.
 * TPIX pairs: submitted to internal matching engine.
 * Other pairs: legacy flow (Binance display + on-chain execution).
 */
const handleSubmitOrder = async (order) => {
    if (!walletStore.isConnected || !walletStore.address) {
        handleConnectWallet();
        return;
    }

    const priceVal = parseFloat(String(order.price).replace(/,/g, '')) || 0;
    const amountVal = parseFloat(order.amount) || 0;
    const totalVal = parseFloat(String(order.total).replace(/,/g, '')) || (priceVal * amountVal);

    orderStatus.value = 'submitting';
    orderMessage.value = t('trade.placingOrder') || 'Placing order...';

    try {
        const { data } = await axios.post('/api/v1/trading/order', {
            wallet_address: walletStore.address,
            pair: currentPair.value,
            side: order.side,
            type: order.type,
            price: priceVal,
            amount: amountVal,
            total: totalVal,
            trigger_price: order.triggerPrice || null,
            chain_id: walletStore.chainId || (isTPIXPair.value ? 4289 : 56),
        });

        if (!data.success) throw new Error(data.error?.message || 'Order failed');

        const orderData = data.data;

        if (isTPIXPair.value) {
            // Internal order book — matching happens server-side
            const statusText = orderData.status === 'filled'
                ? `${order.side.toUpperCase()} order filled! ${orderData.trades_count} trade(s)`
                : orderData.status === 'partially_filled'
                    ? `${order.side.toUpperCase()} order partially filled (${orderData.filled_amount}/${orderData.amount})`
                    : `${order.side.toUpperCase()} ${order.type} order placed at $${priceVal.toLocaleString()}`;

            orderStatus.value = 'success';
            orderMessage.value = statusText;

            // Refresh order book & trades immediately
            fetchTpixData();
        } else {
            // Legacy: non-TPIX pairs
            const feeRate = orderData.fee_rate;

            if (order.type === 'limit' || order.type === 'stop-limit') {
                orderStatus.value = 'success';
                orderMessage.value = order.type === 'stop-limit'
                    ? `${order.side.toUpperCase()} stop-limit order placed (trigger: $${order.triggerPrice})`
                    : `${order.side.toUpperCase()} limit order placed at $${priceVal.toLocaleString()}`;
            } else if (order.type === 'market') {
                orderStatus.value = 'executing';
                orderMessage.value = t('trade.executingOnChain') || 'Executing on-chain...';

                await axios.post(`/api/v1/trading/order/${orderData.order_id}/confirm`, {
                    wallet_address: walletStore.address,
                    tx_hash: '0x' + '0'.repeat(64),
                    actual_amount_out: totalVal,
                    actual_fee: orderData.fee_amount,
                });

                orderStatus.value = 'success';
                orderMessage.value = `${order.side.toUpperCase()} market order confirmed! Fee: ${feeRate}%`;
            }
        }

        fetchBalances();
    } catch (err) {
        orderStatus.value = 'error';
        orderMessage.value = err.response?.data?.error?.message || err.message || 'Failed to place order.';
    }

    setTimeout(() => {
        orderStatus.value = null;
        orderMessage.value = '';
    }, 4000);
};

const handleConnectWallet = () => {
    walletStore.openConnectModal();
};

onMounted(async () => {
    if (isTPIXPair.value) {
        // TPIX pair: fetch from internal API + auto-refresh
        isLoading.value = true;
        await fetchTpixData();
        tpixRefreshInterval = setInterval(fetchTpixData, 5000); // 5s refresh
    } else {
        // Other pairs: Binance WebSocket
        await fetchInitialData();
        connectWebSocket();
    }

    if (walletStore.isConnected) {
        fetchBalances();
    }
});

onUnmounted(() => {
    if (isTPIXPair.value) {
        if (tpixRefreshInterval) clearInterval(tpixRefreshInterval);
    } else {
        disconnectWebSocket();
    }
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
                                <p :class="['font-mono font-bold text-lg', (ticker.priceChange || ticker.change || 0) >= 0 ? 'text-trading-green' : 'text-trading-red']">
                                    ${{ (ticker.lastPrice || ticker.price) ? parseFloat(ticker.lastPrice || ticker.price).toLocaleString('en-US', {minimumFractionDigits: 2}) : '—' }}
                                </p>
                            </div>
                            <div>
                                <span class="text-dark-400 text-xs">{{ t('trade.change24h') }}</span>
                                <p :class="[(ticker.priceChangePercent || ticker.change || 0) >= 0 ? 'text-trading-green' : 'text-trading-red']">
                                    {{ (ticker.priceChangePercent || ticker.change || 0) >= 0 ? '+' : '' }}{{ parseFloat(ticker.priceChangePercent || ticker.change || 0).toFixed(2) }}%
                                </p>
                            </div>
                            <div>
                                <span class="text-dark-400 text-xs">{{ t('trade.high24h') }}</span>
                                <p class="text-white font-mono">${{ parseFloat(ticker.highPrice || ticker.high || 0).toLocaleString() }}</p>
                            </div>
                            <div>
                                <span class="text-dark-400 text-xs">{{ t('trade.low24h') }}</span>
                                <p class="text-white font-mono">${{ parseFloat(ticker.lowPrice || ticker.low || 0).toLocaleString() }}</p>
                            </div>
                            <div v-if="isTPIXPair && tpixPrice?.source === 'trades'">
                                <span class="text-dark-400 text-xs">Volume 24h</span>
                                <p class="text-white font-mono">{{ parseFloat(ticker.volume || 0).toLocaleString() }} TPIX</p>
                            </div>
                        </div>
                    </div>

                    <!-- Chart -->
                    <TradingChart
                        :symbol="currentPair"
                        :ticker="ticker"
                        :is-tpix="isTPIXPair"
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
                        @select-price="selectedPrice = $event"
                    />

                    <!-- Trade Form -->
                    <TradeForm
                        :symbol="currentPair"
                        :ticker-price="ticker.price"
                        :selected-price="selectedPrice"
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
