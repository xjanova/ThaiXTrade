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
import { playTradeSound, playErrorSound, playNotificationSound } from '@/Composables/useSounds';
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

// Connection error state
const dataError = ref(null);

/**
 * Fetch all TPIX data: price, order book, recent trades.
 */
async function fetchTpixData() {
    try {
        const [priceRes, bookRes, tradesRes] = await Promise.all([
            axios.get('/api/v1/tpix/price').catch(() => ({ data: { success: false } })),
            axios.get('/api/v1/tpix/orderbook').catch(() => ({ data: { success: false } })),
            axios.get('/api/v1/tpix/trades').catch(() => ({ data: { success: false } })),
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
        dataError.value = null;
    } catch {
        isLoading.value = false;
        dataError.value = 'Failed to load market data. Retrying...';
    }
}

const walletStore = useWalletStore();
const { balances, fetchBalances } = useWalletBalance();
const swap = useSwap();

const activeTab = ref('openOrders');
const selectedPrice = ref(null);
const orderStatus = ref(null);
const orderMessage = ref('');
const isSubmitting = ref(false); // ป้องกันกด submit ซ้ำ

const tabs = [
    { id: 'openOrders', label: 'Open Orders', count: 0 },
    { id: 'history', label: 'Trade History', count: null },
    { id: 'funds', label: 'Funds', count: null },
];

/**
 * Handle order submission.
 * TPIX pairs: submitted to internal matching engine.
 * Other pairs: legacy flow (Binance display + on-chain execution).
 * ป้องกันกดซ้ำ (debounce) + timeout
 */
const handleSubmitOrder = async (order) => {
    // ป้องกันกด submit ซ้ำ (rapid tap)
    if (isSubmitting.value) return;

    if (!walletStore.isConnected || !walletStore.address) {
        handleConnectWallet();
        return;
    }

    const priceVal = parseFloat(String(order.price).replace(/,/g, '')) || 0;
    const amountVal = parseFloat(order.amount) || 0;

    // Validate ก่อน submit
    if (amountVal <= 0) {
        orderStatus.value = 'error';
        orderMessage.value = t('trade.enterAmount') || 'Please enter an amount.';
        playErrorSound();
        setTimeout(() => { orderStatus.value = null; orderMessage.value = ''; }, 3000);
        return;
    }

    if (order.type !== 'market' && priceVal <= 0) {
        orderStatus.value = 'error';
        orderMessage.value = t('trade.enterPrice') || 'Please enter a price.';
        playErrorSound();
        setTimeout(() => { orderStatus.value = null; orderMessage.value = ''; }, 3000);
        return;
    }

    const totalVal = parseFloat(String(order.total).replace(/,/g, '')) || (priceVal * amountVal);

    isSubmitting.value = true;
    orderStatus.value = 'submitting';
    orderMessage.value = t('trade.placingOrder') || 'Placing order...';

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30s timeout

        // TPIX pairs ต้องใช้ TPIX Chain (4289) เสมอ ไม่ว่า wallet จะอยู่เชนไหน
        // ถ้าอยู่เชนผิด backend จะ reject พร้อมข้อความบอกให้สลับเชน
        const chainIdToSend = isTPIXPair.value
            ? 4289
            : (walletStore.chainId || 56);

        const { data } = await axios.post('/api/v1/trading/order', {
            wallet_address: walletStore.address,
            pair: currentPair.value,
            side: order.side,
            type: order.type,
            price: priceVal,
            amount: amountVal,
            total: totalVal,
            trigger_price: order.triggerPrice || null,
            chain_id: chainIdToSend,
        }, { signal: controller.signal });

        clearTimeout(timeoutId);

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

                const confirmController = new AbortController();
                const confirmTimeout = setTimeout(() => confirmController.abort(), 30000);

                await axios.post(`/api/v1/trading/order/${orderData.order_id}/confirm`, {
                    wallet_address: walletStore.address,
                    tx_hash: '0x' + '0'.repeat(64),
                    actual_amount_out: totalVal,
                    actual_fee: orderData.fee_amount,
                }, { signal: confirmController.signal });

                clearTimeout(confirmTimeout);

                orderStatus.value = 'success';
                orderMessage.value = `${order.side.toUpperCase()} market order confirmed! Fee: ${feeRate}%`;
            }
        }

        // เล่นเสียง trade สำเร็จ
        playTradeSound();
        fetchBalances();
    } catch (err) {
        orderStatus.value = 'error';
        if (err.name === 'AbortError' || err.code === 'ERR_CANCELED') {
            orderMessage.value = 'Order timed out. Please try again.';
        } else {
            orderMessage.value = err.response?.data?.error?.message || err.message || 'Failed to place order.';
        }
        playErrorSound();
    } finally {
        isSubmitting.value = false;
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
        try {
            await fetchInitialData();
            connectWebSocket();
        } catch {
            dataError.value = 'Failed to connect to market data. Please refresh.';
            isLoading.value = false;
        }
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
            <Transition
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 translate-y-[-12px]"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition-all duration-200 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 translate-y-[-12px]"
            >
                <div
                    v-if="orderStatus"
                    :class="[
                        'fixed top-4 right-4 z-50 px-5 py-3.5 rounded-xl shadow-lg text-sm font-medium flex items-center gap-3',
                        orderStatus === 'success' ? 'bg-trading-green/90 text-white' :
                        orderStatus === 'error' ? 'bg-trading-red/90 text-white' :
                        'bg-primary-500/90 text-white'
                    ]"
                >
                    <!-- Spinner for submitting/executing -->
                    <div v-if="orderStatus === 'submitting' || orderStatus === 'executing'" class="spinner !w-4 !h-4 !border-white/30 !border-t-white"></div>
                    <!-- Check for success -->
                    <svg v-else-if="orderStatus === 'success'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <!-- X for error -->
                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    <span>{{ orderStatus === 'submitting' ? 'Placing order...' : orderMessage }}</span>
                </div>
            </Transition>

            <!-- Data Error Banner -->
            <div v-if="dataError" class="mb-3 p-3 rounded-xl bg-trading-red/10 border border-trading-red/30 text-trading-red text-sm flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                {{ dataError }}
            </div>

            <!-- Trading Layout: 3 columns -->
            <div class="grid grid-cols-12 gap-3">

                <!-- Left Column: Pair Selector + Chart + Order Tabs -->
                <div class="col-span-12 xl:col-span-8 lg:col-span-7 space-y-3">
                    <!-- Pair Selector + Ticker Info -->
                    <div class="flex items-center gap-4 flex-wrap">
                        <PairSelector :currentPair="currentPair" />
                        <div v-if="ticker && ticker.price" class="flex items-center gap-6 text-sm">
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
                            <div class="hidden sm:block">
                                <span class="text-dark-400 text-xs">{{ t('trade.high24h') }}</span>
                                <p class="text-white font-mono">${{ parseFloat(ticker.highPrice || ticker.high || 0).toLocaleString() }}</p>
                            </div>
                            <div class="hidden sm:block">
                                <span class="text-dark-400 text-xs">{{ t('trade.low24h') }}</span>
                                <p class="text-white font-mono">${{ parseFloat(ticker.lowPrice || ticker.low || 0).toLocaleString() }}</p>
                            </div>
                            <div v-if="isTPIXPair && tpixPrice?.source === 'trades'" class="hidden md:block">
                                <span class="text-dark-400 text-xs">Volume 24h</span>
                                <p class="text-white font-mono">{{ parseFloat(ticker.volume || 0).toLocaleString() }} TPIX</p>
                            </div>
                        </div>
                        <!-- Loading skeleton for ticker -->
                        <div v-else-if="isLoading" class="flex items-center gap-6">
                            <div class="space-y-1">
                                <div class="skeleton w-8 h-3"></div>
                                <div class="skeleton w-24 h-6"></div>
                            </div>
                            <div class="space-y-1">
                                <div class="skeleton w-12 h-3"></div>
                                <div class="skeleton w-16 h-4"></div>
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
                                <div v-else-if="walletStore.isConnected" class="py-8">
                                    <svg class="w-8 h-8 mx-auto text-dark-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 12V8H6a2 2 0 01-2-2c0-1.1.9-2 2-2h12v4m2 0v8a2 2 0 01-2 2H6a2 2 0 01-2-2V6"/>
                                    </svg>
                                    <p class="text-dark-500">No balances found</p>
                                </div>
                                <div v-else class="py-8">
                                    <svg class="w-8 h-8 mx-auto text-dark-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                    </svg>
                                    <p class="text-dark-500 mb-3">Connect wallet to view funds</p>
                                    <button @click="handleConnectWallet" class="btn-primary text-sm px-6 py-2">
                                        Connect Wallet
                                    </button>
                                </div>
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
                        :ticker-price="ticker?.price || 0"
                        :is-loading="isLoading"
                        class="h-[340px]"
                        @select-price="selectedPrice = $event"
                    />

                    <!-- Trade Form -->
                    <TradeForm
                        :symbol="currentPair"
                        :ticker-price="ticker?.price || 0"
                        :selected-price="selectedPrice"
                        :is-wallet-connected="walletStore.isConnected"
                        :is-submitting="isSubmitting"
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
