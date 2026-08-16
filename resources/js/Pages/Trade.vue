<script setup>
/**
 * TPIX TRADE - Trading Dashboard Page
 * Main trading interface with real-time data:
 * - TPIX pairs: internal order book (real trades)
 * - Other pairs: Binance data + PancakeSwap execution
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TradingChart from '@/Components/Trading/TradingChart.vue';
import OrderBook from '@/Components/Trading/OrderBook.vue';
import TradeForm from '@/Components/Trading/TradeForm.vue';
import RecentTrades from '@/Components/Trading/RecentTrades.vue';
import OpenOrders from '@/Components/Trading/OpenOrders.vue';
import TradeHistory from '@/Components/Trading/TradeHistory.vue';
import PairSelector from '@/Components/Trading/PairSelector.vue';
import PageArt from '@/Components/PageArt.vue';
import { useBinanceData } from '@/Composables/useBinanceData';
import { useSwap } from '@/Composables/useSwap';
import { useWalletStore } from '@/Stores/walletStore';
import { useWalletBalance } from '@/Composables/useWalletBalance';
import { playTradeSound, playErrorSound, playNotificationSound } from '@/Composables/useSounds';
import { getBscTradeToken, getVerifiedTradeToken } from '@/Config/bscTradeTokens';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

// การเทรดจริงทั้งหมดรันบน BSC (PancakeSwap) จนกว่า DEX บน TPIX Chain จะพร้อม
const BSC_CHAIN_ID = 56;

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

const baseSymbol = computed(() => currentPair.value.split('/')[0] || '');
const quoteSymbol = computed(() => currentPair.value.split('/')[1] || '');

// คู่ที่เทรดจริงบน BSC ได้ — ทั้ง base และ quote ต้องมีใน registry (เช็ค address แล้ว)
const isBscTradable = computed(() =>
    !isTPIXPair.value
    && !!getBscTradeToken(baseSymbol.value)
    && !!getBscTradeToken(quoteSymbol.value)
);

// โหมดของ TradeForm:
//  onchain  = market order execute จริงผ่าน PancakeSwap บน BSC
//  disabled = TPIX pair (รอเชน TPIX) หรือคู่ที่ไม่มี token บน BSC — เห็นไว้ก่อน กดไม่ได้
const tradeFormMode = computed(() => (isBscTradable.value ? 'onchain' : 'disabled'));

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

// ── เทรดจริงบน BSC (market order → PancakeSwap) ─────────────────────────────

// ยอดคงเหลือของ base/quote อ่านตรงจาก BSC RPC — ถูกต้องเสมอไม่ว่า wallet อยู่เชนไหน
const bscFormBalances = ref([]);

async function fetchBscFormBalances() {
    if (!walletStore.isConnected || !isBscTradable.value) {
        bscFormBalances.value = [];
        return;
    }
    try {
        const baseTok = getBscTradeToken(baseSymbol.value);
        const quoteTok = getBscTradeToken(quoteSymbol.value);
        const [baseBal, quoteBal] = await Promise.all([
            swap.getBalance(baseTok.address),
            swap.getBalance(quoteTok.address),
        ]);
        bscFormBalances.value = [
            { symbol: baseSymbol.value, balance: baseBal },
            { symbol: quoteSymbol.value, balance: quoteBal },
        ];
    } catch {
        // อ่านไม่ได้ก็คงค่าเดิมไว้ — ฟอร์มยังใช้งานได้ (executeSwap เช็คยอดจริงอีกที)
    }
}

// ฟอร์มโหมด onchain ใช้ยอดจาก BSC, โหมดอื่นใช้ยอดจาก wallet chain ปัจจุบัน (เดิม)
const formBalances = computed(() =>
    tradeFormMode.value === 'onchain' ? bscFormBalances.value : balances.value
);

// จัดรูปตัวเลขจำนวนเหรียญสำหรับแสดงผล
function fmtQty(n) {
    const num = Number(n) || 0;
    return num.toLocaleString('en-US', { maximumFractionDigits: num >= 1 ? 4 : 8 });
}

function friendly(message) {
    const err = new Error(message);
    err.isFriendly = true;
    return err;
}

// Preview ราคาจริงจาก router (debounce 400ms) — แสดงใน TradeForm ก่อนกดยืนยัน
const marketPreview = ref(null);
let previewTimer = null;
let previewSeq = 0;

function handleFormChange(form) {
    if (tradeFormMode.value !== 'onchain' || form.type !== 'market') {
        marketPreview.value = null;
        return;
    }
    const amountNum = parseFloat(form.amount) || 0;
    const totalNum = parseFloat(String(form.total).replace(/,/g, '')) || 0;
    // buy ใช้ยอด quote (USDT) เป็น input จริง, sell ใช้ยอด base
    const inputAmount = form.side === 'buy' ? totalNum : amountNum;
    if (inputAmount <= 0) {
        marketPreview.value = null;
        return;
    }
    marketPreview.value = { loading: true };
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => refreshMarketPreview(form, inputAmount), 400);
}

async function refreshMarketPreview(form, inputAmount) {
    const seq = ++previewSeq;
    try {
        const fromSym = form.side === 'buy' ? quoteSymbol.value : baseSymbol.value;
        const toSym = form.side === 'buy' ? baseSymbol.value : quoteSymbol.value;
        // ใช้ token ที่ตรวจ decimals จาก on-chain แล้ว — กัน preview เพี้ยนถ้าค่า static ผิด
        const [fromTok, toTok] = await Promise.all([
            getVerifiedTradeToken(fromSym),
            getVerifiedTradeToken(toSym),
        ]);
        const q = await swap.getQuote(fromTok, toTok, inputAmount);
        if (seq !== previewSeq) return; // มี request ใหม่กว่าแล้ว — ทิ้งผลนี้
        if (!q) {
            marketPreview.value = null;
            return;
        }
        marketPreview.value = {
            loading: false,
            receiveText: `${fmtQty(q.netOutput)} ${toSym}`,
            minReceivedText: `${fmtQty(q.minimumReceived)} ${toSym}`,
            feeText: `${fmtQty(q.feeAmount)} ${fromSym} (${q.feeRate}%)`,
        };
    } catch {
        if (seq === previewSeq) marketPreview.value = null;
    }
}

const activeTab = ref('openOrders');
const selectedPrice = ref(null);
const orderStatus = ref(null);
const orderMessage = ref('');
const orderTxUrl = ref(null); // ลิงก์ BscScan ของเทรดที่สำเร็จ (โหมด onchain)
const isSubmitting = ref(false); // ป้องกันกด submit ซ้ำ
let toastTimer = null;

const tabs = [
    { id: 'openOrders', label: 'Open Orders', count: 0 },
    { id: 'history', label: 'Trade History', count: null },
    { id: 'funds', label: 'Funds', count: null },
];

/**
 * Handle order submission — แยกตามโหมดของคู่เทรด:
 *  onchain  → market order execute จริงบน BSC ผ่าน PancakeSwap
 *  disabled → ฟอร์มถูกปิดอยู่แล้ว (TPIX รอเชน) — กันไว้อีกชั้น
 *  อื่นๆ    → internal order book (path เดิม ใช้เมื่อ TPIX Chain เปิด)
 */
const handleSubmitOrder = async (order) => {
    // ป้องกันกด submit ซ้ำ (rapid tap)
    if (isSubmitting.value) return;

    if (!walletStore.isConnected || !walletStore.address) {
        handleConnectWallet();
        return;
    }

    if (tradeFormMode.value === 'disabled') return;

    if (tradeFormMode.value === 'onchain') {
        await executeBscMarketOrder(order);
        return;
    }

    await submitInternalOrder(order);
};

/**
 * แสดง toast error สั้นๆ ของฟอร์มเทรด
 */
function showOrderError(message) {
    orderStatus.value = 'error';
    orderMessage.value = message;
    playErrorSound();
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { orderStatus.value = null; orderMessage.value = ''; }, 4000);
}

/**
 * Market order เทรดจริงบน BSC ผ่าน PancakeSwap
 * ลำดับ: ตรวจ token on-chain → สลับเชนเป็น BSC → quote จริง → กันราคาเพี้ยน
 * → approve (ถ้าจำเป็น) → swap → refresh ยอด
 */
async function executeBscMarketOrder(order) {
    if (order.type !== 'market') {
        showOrderError('Limit orders open with TPIX Chain launch — use Market for now.');
        return;
    }

    const amountVal = parseFloat(order.amount) || 0;
    const totalVal = parseFloat(String(order.total).replace(/,/g, '')) || 0;
    // buy จ่าย quote token (USDT) ตามช่อง Total, sell จ่าย base token ตามช่อง Amount
    const inputAmount = order.side === 'buy' ? totalVal : amountVal;
    if (inputAmount <= 0) {
        showOrderError(t('trade.enterAmount') || 'Please enter an amount.');
        return;
    }

    isSubmitting.value = true;
    orderStatus.value = 'executing';
    orderMessage.value = 'Preparing on-chain trade…';
    orderTxUrl.value = null;

    try {
        // 1) ตรวจ token address กับ on-chain (symbol + decimals) — ไม่ผ่าน = ไม่เทรด
        const fromSym = order.side === 'buy' ? quoteSymbol.value : baseSymbol.value;
        const toSym = order.side === 'buy' ? baseSymbol.value : quoteSymbol.value;
        const [fromTok, toTok] = await Promise.all([
            getVerifiedTradeToken(fromSym),
            getVerifiedTradeToken(toSym),
        ]);

        // 2) การเทรดรันบน BSC เท่านั้น — สลับ wallet ให้ (ผู้ใช้กดยืนยันใน wallet)
        if (walletStore.chainId !== BSC_CHAIN_ID) {
            orderMessage.value = 'Switching wallet to BSC…';
            try {
                await walletStore.switchChain(BSC_CHAIN_ID);
            } catch {
                throw friendly('Network switch was cancelled — trading runs on BSC.');
            }
            if (walletStore.chainId !== BSC_CHAIN_ID) {
                throw friendly('Please switch your wallet to BSC to trade.');
            }
        }

        // 3) Quote จริงจาก PancakeSwap router (รวมค่าธรรมเนียมแพลตฟอร์มแล้ว)
        const quote = await swap.getQuote(fromTok, toTok, inputAmount);
        if (!quote) {
            throw friendly(swap.error.value || 'No liquidity available for this pair.');
        }

        // 4) กันราคา on-chain เพี้ยนจากราคาตลาด (สภาพคล่องบาง/pool ผิดปกติ) — เกิน 10% ไม่ส่ง
        const marketPrice = parseFloat(ticker.value?.lastPrice || ticker.value?.price || 0);
        if (marketPrice > 0 && quote.netOutput > 0) {
            const effPrice = order.side === 'buy'
                ? quote.amountIn / quote.netOutput   // จ่าย USDT ต่อ 1 base
                : quote.netOutput / quote.amountIn;  // ได้ USDT ต่อ 1 base
            const deviation = Math.abs(effPrice - marketPrice) / marketPrice;
            if (deviation > 0.10) {
                throw friendly('On-chain price differs too much from market price. Try a smaller amount or use Swap.');
            }
        }

        // 5) Approve router ถ้า allowance ไม่พอ (เฉพาะ token ไม่ใช่ BNB)
        if (!fromTok.native) {
            const hasAllowance = await swap.checkAllowance(fromTok.address, inputAmount, fromTok.decimals);
            if (!hasAllowance) {
                orderMessage.value = `Approving ${fromSym} in your wallet…`;
                const approveTx = await swap.approveToken(fromTok.address);
                await approveTx.wait();
            }
        }

        // 6) ส่ง swap จริง — executeSwap เก็บ platform fee + บันทึก backend ให้แล้ว
        orderMessage.value = `Confirm the ${order.side} order in your wallet…`;
        const result = await swap.executeSwap(fromTok, toTok, inputAmount, quote, quote.slippage);

        orderStatus.value = 'success';
        orderMessage.value = order.side === 'buy'
            ? `Bought ≈ ${fmtQty(quote.netOutput)} ${toSym} on BSC`
            : `Sold ${fmtQty(inputAmount)} ${fromSym} for ≈ ${fmtQty(quote.netOutput)} ${toSym}`;
        orderTxUrl.value = result?.url || null;

        playTradeSound();
        fetchBscFormBalances();
        fetchBalances();
    } catch (err) {
        orderStatus.value = 'error';
        // error ที่ตั้งใจ throw เองมีข้อความพร้อมแสดง — นอกนั้น useSwap map ให้แล้ว
        orderMessage.value = err?.isFriendly ? err.message : (swap.error.value || 'Trade failed. Please try again.');
        playErrorSound();
    } finally {
        isSubmitting.value = false;
        clearTimeout(toastTimer);
        // สำเร็จค้าง toast ไว้นานขึ้นให้กดดู tx ได้
        const holdMs = orderStatus.value === 'success' && orderTxUrl.value ? 10000 : 4000;
        toastTimer = setTimeout(() => {
            orderStatus.value = null;
            orderMessage.value = '';
            orderTxUrl.value = null;
        }, holdMs);
    }
}

/**
 * Internal order book (path เดิม) — ใช้เมื่อ TPIX Chain เปิดเทรด
 * ป้องกันกดซ้ำ (debounce) + timeout
 */
const submitInternalOrder = async (order) => {
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

        // Trade เป็น index/proxy — ราคาดึง realtime จาก Binance, balance off-chain
        // ทุกคู่เทรด register บน TPIX chain (4289) — ส่ง chain_id ตายตัว
        // wallet จะอยู่เชนไหนก็ได้ (สำหรับ Bridge/Send/Receive); การเทรดไม่ผูกกับ wallet chain
        const { data } = await axios.post('/api/v1/trading/order', {
            wallet_address: walletStore.address,
            pair: currentPair.value,
            side: order.side,
            type: order.type,
            price: priceVal,
            amount: amountVal,
            total: totalVal,
            trigger_price: order.triggerPrice || null,
            chain_id: 4289,
        }, { signal: controller.signal });

        clearTimeout(timeoutId);

        if (!data.success) throw new Error(data.error?.message || 'Order failed');

        const orderData = data.data;

        // ทุก pair ที่ admin ตั้งใน trading_pairs เข้า internal order book ฝั่ง backend
        // เหมือนกันหมด — ไม่มี on-chain execution ในเส้นทางนี้ จึงไม่มีการยิง confirm
        // ด้วย tx_hash (path เดิมยิง tx ปลอม 0x000...0 แล้วยัง 404 เพราะ order
        // เป็น internal ไม่ใช่ Transaction — ลบทิ้งแล้ว)
        const statusText = orderData.status === 'filled'
            ? `${order.side.toUpperCase()} order filled! ${orderData.trades_count} trade(s)`
            : orderData.status === 'partially_filled'
                ? `${order.side.toUpperCase()} order partially filled (${orderData.filled_amount}/${orderData.amount})`
                : order.type === 'stop-limit'
                    ? `${order.side.toUpperCase()} stop-limit order placed (trigger: $${order.triggerPrice})`
                    : `${order.side.toUpperCase()} ${order.type} order placed at $${priceVal.toLocaleString()}`;

        orderStatus.value = 'success';
        orderMessage.value = statusText;

        // Refresh order book & trades ทันที (เฉพาะ TPIX pair ที่ใช้ internal data feed)
        if (isTPIXPair.value) {
            fetchTpixData();
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
        fetchBscFormBalances();
    }
});

// เชื่อม wallet ทีหลัง / สลับ address → โหลดยอด BSC สำหรับฟอร์มเทรดใหม่
watch(() => walletStore.address, () => {
    fetchBscFormBalances();
});

onUnmounted(() => {
    if (isTPIXPair.value) {
        if (tpixRefreshInterval) clearInterval(tpixRefreshInterval);
    } else {
        disconnectWebSocket();
    }
    clearTimeout(previewTimer);
    clearTimeout(toastTimer);
});
</script>

<template>
    <Head :title="`Trade ${currentPair}`" />

    <AppLayout :hide-sidebar="true">
        <div class="max-w-[1920px] mx-auto">
            <!-- บรรยากาศพื้นหลังหน้าเทรด — จางมากเพื่อไม่แย่งสายตาจากตัวเลข
                 วางเป็นตัวแรกสุด แล้วให้ทุก block ถัดไปเป็น relative เพื่อลอยอยู่เหนือมัน -->
            <div class="fixed inset-0 pointer-events-none overflow-hidden" aria-hidden="true">
                <img src="/images/art/trade-desk.webp" alt="" loading="eager" fetchpriority="low" decoding="async"
                    class="w-full h-full object-cover opacity-[0.13]" />
                <div class="absolute inset-0 bg-gradient-to-b from-dark-950/70 via-dark-950/85 to-dark-950"></div>
            </div>

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
                    <!-- ลิงก์ดูธุรกรรมจริงบน BscScan (เฉพาะเทรด on-chain สำเร็จ) -->
                    <a
                        v-if="orderStatus === 'success' && orderTxUrl"
                        :href="orderTxUrl"
                        target="_blank"
                        rel="noopener"
                        class="underline font-semibold whitespace-nowrap hover:opacity-80"
                    >
                        View tx ↗
                    </a>
                </div>
            </Transition>

            <!-- Data Error Banner -->
            <div v-if="dataError" class="relative mb-3 p-3 rounded-xl bg-trading-red/10 border border-trading-red/30 text-trading-red text-sm flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                {{ dataError }}
            </div>

            <!-- แถบบอกโหมดเทรด: on-chain จริงบน BSC หรือรอ TPIX Chain -->
            <div v-if="tradeFormMode === 'onchain'" class="relative mb-3 px-3 py-2 rounded-xl bg-primary-500/10 border border-primary-500/20 text-primary-300 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <span>Market orders execute for real on BSC via PancakeSwap — tokens settle in your wallet. Limit orders open with TPIX Chain.</span>
            </div>
            <div v-else-if="isTPIXPair" class="relative mb-3 px-3 py-2 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>TPIX/USDT trading opens with TPIX Chain launch — coming soon.</span>
            </div>

            <!-- Trading Layout: 3 columns -->
            <div class="relative grid grid-cols-12 gap-3">

                <!-- Left Column: Pair Selector + Chart + Order Tabs -->
                <div class="col-span-12 xl:col-span-8 lg:col-span-7 space-y-3">
                    <!-- Pair Selector + Ticker Info
                         ห้ามใส่ overflow-hidden ที่กล่องนี้ — dropdown ของ PairSelector จะโดนตัด
                         (PageArt คลิปตัวเองอยู่แล้ว จึงไม่กระทบ) -->
                    <div class="relative flex items-center gap-4 flex-wrap rounded-2xl border border-white/5 bg-dark-900/40 backdrop-blur-md px-4 py-3">
                        <PageArt art="hero-trade" :opacity="22" fade="edges" rounded="rounded-2xl" position="center" loading="eager" />
                        <PairSelector class="relative" :currentPair="currentPair" />
                        <div v-if="ticker && ticker.price" class="relative flex items-center gap-6 text-sm">
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
                        <div v-else-if="isLoading" class="relative flex items-center gap-6">
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
                        :balances="formBalances"
                        :mode="tradeFormMode"
                        :market-preview="marketPreview"
                        @submit-order="handleSubmitOrder"
                        @connect-wallet="handleConnectWallet"
                        @form-change="handleFormChange"
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
