<script setup>
/**
 * TPIX TRADE - Trading Dashboard Page
 * กระดานเทรด 3 คอลัมน์ ที่ผู้ใช้จัดผังการ์ดเองได้
 *   ซ้าย  = รายการคู่เทรด + AI TRADE
 *   กลาง  = กราฟ + ฟอร์มซื้อขาย + คำสั่งของฉัน
 *   ขวา   = สมุดคำสั่ง + เทรดล่าสุด
 *
 * โหมด "พอดีหน้าจอ": การ์ดยืด/หดแบ่งความสูงที่เหลือกันเอง (ตาม CARD_FLEX)
 * โดยมีความสูงต่ำสุดของแต่ละใบ ถ้าใส่ไม่ลงจริงๆ คอลัมน์จะเลื่อนแทนการบีบจนอ่านไม่ออก
 *
 * ข้อมูล: คู่ TPIX ใช้ order book ภายใน · คู่อื่นใช้ Binance + execute บน PancakeSwap
 * Developed by Xman Studio
 */

import { ref, computed, watch, nextTick, onMounted, onUnmounted, useTemplateRef } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TradingChart from '@/Components/Trading/TradingChart.vue';
import OrderBook from '@/Components/Trading/OrderBook.vue';
import TradeForm from '@/Components/Trading/TradeForm.vue';
import RecentTrades from '@/Components/Trading/RecentTrades.vue';
import OpenOrders from '@/Components/Trading/OpenOrders.vue';
import TradeHistory from '@/Components/Trading/TradeHistory.vue';
import PairSelector from '@/Components/Trading/PairSelector.vue';
import MarketListPanel from '@/Components/Trading/MarketListPanel.vue';
import AiTradeCard from '@/Components/Trading/AiTradeCard.vue';
import DraggableCard from '@/Components/Trading/DraggableCard.vue';
import PageArt from '@/Components/PageArt.vue';
import { useBinanceData } from '@/Composables/useBinanceData';
import { useSwap } from '@/Composables/useSwap';
import { useWalletStore } from '@/Stores/walletStore';
import { useWalletBalance } from '@/Composables/useWalletBalance';
import { useTradeLayout, COLUMNS, TRADE_CARDS, CHART_HEIGHTS } from '@/Composables/useTradeLayout';
import { playTradeSound, playErrorSound } from '@/Composables/useSounds';
import { getBscTradeToken, getVerifiedTradeToken } from '@/Config/bscTradeTokens';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

// การเทรดจริงทั้งหมดรันบน BSC (PancakeSwap) จนกว่า DEX บน TPIX Chain จะพร้อม
const BSC_CHAIN_ID = 56;
const TPIX_CHAIN_ID = 4289;

const { t } = useTranslation();
const props = defineProps({
    pair: {
        type: String,
        default: 'BTC-USDT',
    },
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

// ── ผังการ์ด ────────────────────────────────────────────────────────────────
const layout = useTradeLayout();
const showLayoutMenu = ref(false);
const isChartFullscreen = ref(false);

const board = useTemplateRef('board');
const boardHeight = ref(0);

/** จอเล็กกว่า xl วางการ์ดเรียงลงมา — ลาก DnD ด้วยนิ้วไม่เสถียร จึงล็อกไว้ */
const isNarrow = ref(false);

/** โหมดจัดการ์ดให้พอดีหน้าจอ (เฉพาะจอกว้างที่มีที่ให้จัดจริง) */
const packed = computed(() => layout.fitScreen.value && !isNarrow.value);

/**
 * ความสูงของกระดาน = ที่เหลือจากขอบล่างของแถบหัวถึงก้นหน้าจอ
 * วัดจากของจริงแทนการคำนวณจากความสูงของ NavBar/ticker/แบนเนอร์
 * เพราะแบนเนอร์โฆษณาและแถบแจ้งเตือนโผล่/หายได้ตลอด
 */
/**
 * เกณฑ์จอกว้าง — ใช้ matchMedia ไม่ใช่ window.innerWidth ใน event `resize`
 * เพราะ resize ไม่ถูกส่งเสมอไป (แท็บพื้นหลัง/เปลี่ยนขนาดผ่านเครื่องมือ) แล้ว
 * โหมดพอดีจอจะค้างเป็น true บนมือถือ → กริดถูกล็อกความสูงทั้งที่คอลัมน์เป็น
 * `display:contents` ทำให้การ์ดยุบเหลือ 0
 */
let wideQuery = null;

function syncBreakpoint() {
    if (typeof window === 'undefined') return;
    wideQuery = wideQuery || window.matchMedia('(min-width: 1280px)');
    isNarrow.value = !wideQuery.matches;
}

function measureBoard() {
    syncBreakpoint();

    if (!board.value || typeof window === 'undefined') return;
    // rect.top เป็นระยะจากขอบบนของ viewport อยู่แล้ว จึงลบออกจากความสูงจอได้ตรงๆ
    // (ในโหมดพอดีจอหน้าไม่เลื่อน ค่านี้จึงคงที่)
    const viewportTop = board.value.getBoundingClientRect().top;
    boardHeight.value = Math.max(520, window.innerHeight - viewportTop - 14);
}

const boardStyle = computed(() =>
    packed.value && boardHeight.value ? { height: `${boardHeight.value}px` } : {}
);

/**
 * ตำแหน่งของแต่ละคอลัมน์ในแต่ละขนาดจอ
 *
 *   lg (2 ช่อง) : กราฟ | สมุดคำสั่ง  →  คู่เทรด(กว้าง 2)  →  คำสั่ง/ประวัติ(กว้าง 2)
 *   xl (4 ช่อง) : คู่เทรด | กราฟ | สมุดคำสั่ง | คำสั่ง+ประวัติ
 *
 * ที่ให้คอลัมน์ 4 เริ่มที่ xl ไม่ใช่ lg เพราะต่ำกว่านั้นกราฟจะเหลือแคบเกินจนเสียรูป
 */
const columnClass = {
    left: 'lg:order-3 lg:col-span-2 xl:order-1 xl:col-span-1',
    center: 'lg:order-1 xl:order-2',
    right: 'lg:order-2 xl:order-3',
    far: 'lg:order-4 lg:col-span-2 xl:order-4 xl:col-span-1',
};

const hideableCards = computed(() =>
    TRADE_CARDS.filter(c => !c.essential).map(c => ({ id: c.id, label: t(c.titleKey) }))
);

/** ความสูงของการ์ดหนึ่งใบ — โหมดพอดีจอใช้ flex, โหมดเลื่อนหน้าใช้ px */
function styleFor(cardId) {
    if (cardId === 'chart' && isChartFullscreen.value) return {};
    return layout.cardStyle(cardId, packed.value);
}

function onColumnDrop(column) {
    if (!layout.draggingId.value) return;
    layout.dropOnColumn(column);
    layout.endDrag();
}

function closeLayoutMenu(e) {
    if (!e.target.closest('.layout-menu')) showLayoutMenu.value = false;
}

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
 * รวมความลึกของ order book ให้อยู่รูปแบบเดียวกับที่ OrderBook ใช้
 * (เดิมฝั่ง TPIX ส่งเป็น array [price, amount] แต่คอมโพเนนต์อ่าน .price/.amount
 *  ทำให้สมุดคำสั่งของคู่ TPIX ว่างเปล่าทั้งที่ API คืนข้อมูลมาแล้ว)
 */
function normalizeDepth(rows) {
    const parsed = (rows || []).map(r => ({
        price: parseFloat(r.price ?? r[0]) || 0,
        amount: parseFloat(r.amount ?? r[1]) || 0,
    })).filter(r => r.price > 0 && r.amount > 0);

    const maxTotal = Math.max(...parsed.map(r => r.price * r.amount), 1);

    return parsed.map(r => ({
        ...r,
        total: r.price * r.amount,
        depth: Math.min(100, ((r.price * r.amount) / maxTotal) * 100),
    }));
}

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

        // Order book — asks เรียงจากราคาต่ำสุด, bids เรียงจากราคาสูงสุด (เหมือนฟีด Binance)
        if (bookRes.data.success) {
            const bookData = bookRes.data.data;
            asks.value = normalizeDepth(bookData.asks).sort((a, b) => a.price - b.price);
            bids.value = normalizeDepth(bookData.bids).sort((a, b) => b.price - a.price);
        }

        // Recent trades
        if (tradesRes.data.success) {
            trades.value = (tradesRes.data.data || []).map((tr, i) => ({
                id: tr.id ?? `${tr.time}-${i}`,
                price: parseFloat(tr.price) || 0,
                amount: parseFloat(tr.amount) || 0,
                time: new Date(tr.time).toLocaleTimeString('en-US', { hour12: false }),
                isBuy: tr.side === 'buy',
            }));
        }

        isLoading.value = false;
        dataError.value = null;
    } catch {
        isLoading.value = false;
        dataError.value = t('trade.notice.dataError');
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
let priceNonce = 0;

const tabs = [
    { id: 'openOrders', key: 'trade.tabs.openOrders' },
    { id: 'history', key: 'trade.tabs.history' },
    { id: 'funds', key: 'trade.tabs.funds' },
];

/**
 * รับราคาที่ผู้ใช้คลิกจากสมุดคำสั่ง/เทรดล่าสุด แล้วส่งต่อให้ฟอร์ม
 * ต้องแนบ nonce เพราะคลิก "ราคาเดิมซ้ำ" จะไม่ทำให้ watcher ของฟอร์มทำงาน
 * ถ้าเทียบแค่ค่าราคา (อาการเดิม: กดแล้วเงียบ ต้องไปคลิกราคาอื่นก่อน)
 */
function handleSelectPrice(payload) {
    const picked = typeof payload === 'object' && payload !== null ? payload : { price: payload };
    selectedPrice.value = { ...picked, nonce: ++priceNonce };
}

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
        showOrderError(t('trade.status.limitSoon'));
        return;
    }

    const amountVal = parseFloat(order.amount) || 0;
    const totalVal = parseFloat(String(order.total).replace(/,/g, '')) || 0;
    // buy จ่าย quote token (USDT) ตามช่อง Total, sell จ่าย base token ตามช่อง Amount
    const inputAmount = order.side === 'buy' ? totalVal : amountVal;
    if (inputAmount <= 0) {
        showOrderError(t('trade.enterAmount'));
        return;
    }

    isSubmitting.value = true;
    orderStatus.value = 'executing';
    orderMessage.value = t('trade.status.preparing');
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
            orderMessage.value = t('trade.status.switchingChain');
            try {
                await walletStore.switchChain(BSC_CHAIN_ID);
            } catch {
                throw friendly(t('trade.status.switchCancelled'));
            }
            if (walletStore.chainId !== BSC_CHAIN_ID) {
                throw friendly(t('trade.status.switchToBsc'));
            }
        }

        // 3) Quote จริงจาก PancakeSwap router (รวมค่าธรรมเนียมแพลตฟอร์มแล้ว)
        const quote = await swap.getQuote(fromTok, toTok, inputAmount);
        if (!quote) {
            throw friendly(swap.error.value || t('trade.status.noLiquidity'));
        }

        // 4) กันราคา on-chain เพี้ยนจากราคาตลาด (สภาพคล่องบาง/pool ผิดปกติ) — เกิน 10% ไม่ส่ง
        const marketPrice = parseFloat(ticker.value?.lastPrice || ticker.value?.price || 0);
        if (marketPrice > 0 && quote.netOutput > 0) {
            const effPrice = order.side === 'buy'
                ? quote.amountIn / quote.netOutput   // จ่าย USDT ต่อ 1 base
                : quote.netOutput / quote.amountIn;  // ได้ USDT ต่อ 1 base
            const deviation = Math.abs(effPrice - marketPrice) / marketPrice;
            if (deviation > 0.10) {
                throw friendly(t('trade.status.deviation'));
            }
        }

        // 5) Approve router ถ้า allowance ไม่พอ (เฉพาะ token ไม่ใช่ BNB)
        if (!fromTok.native) {
            const hasAllowance = await swap.checkAllowance(fromTok.address, inputAmount, fromTok.decimals);
            if (!hasAllowance) {
                orderMessage.value = t('trade.status.approving', { symbol: fromSym });
                const approveTx = await swap.approveToken(fromTok.address);
                await approveTx.wait();
            }
        }

        // 6) ส่ง swap จริง — executeSwap เก็บ platform fee + บันทึก backend ให้แล้ว
        //    slippage ที่ผู้ใช้เลือกในฟอร์มมีผลจริงกับ minOut ที่ส่งเข้า router
        orderMessage.value = order.side === 'buy' ? t('trade.status.confirmBuy') : t('trade.status.confirmSell');
        const slippage = Number.isFinite(Number(order.slippage)) && order.slippage !== null
            ? Number(order.slippage)
            : quote.slippage;
        const result = await swap.executeSwap(fromTok, toTok, inputAmount, quote, slippage);

        orderStatus.value = 'success';
        orderMessage.value = order.side === 'buy'
            ? t('trade.status.bought', { amount: fmtQty(quote.netOutput), symbol: toSym })
            : t('trade.status.sold', { amount: fmtQty(inputAmount), from: fromSym, out: fmtQty(quote.netOutput), to: toSym });
        orderTxUrl.value = result?.url || null;

        playTradeSound();
        fetchBscFormBalances();
        fetchBalances();
    } catch (err) {
        orderStatus.value = 'error';
        // error ที่ตั้งใจ throw เองมีข้อความพร้อมแสดง — นอกนั้น useSwap map ให้แล้ว
        orderMessage.value = err?.isFriendly ? err.message : (swap.error.value || t('trade.status.failed'));
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

    if (amountVal <= 0) {
        showOrderError(t('trade.enterAmount'));
        return;
    }

    if (order.type !== 'market' && priceVal <= 0) {
        showOrderError(t('trade.enterPrice'));
        return;
    }

    const totalVal = parseFloat(String(order.total).replace(/,/g, '')) || (priceVal * amountVal);
    const sideLabel = order.side === 'buy' ? t('trade.form.buy') : t('trade.form.sell');

    isSubmitting.value = true;
    orderStatus.value = 'submitting';
    orderMessage.value = t('trade.status.placing');

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30s timeout

        // ส่งเชนของคู่ที่เทรดจริง ไม่ใช่ค่าตายตัว — คู่ major ถูกย้ายไปอยู่บน BSC แล้ว
        // (backend map chainId → chains.chain_id_hex เพื่อหาแถวคู่เทรด ส่งผิดเชนจะหาไม่เจอ)
        const { data } = await axios.post('/api/v1/trading/order', {
            wallet_address: walletStore.address,
            pair: currentPair.value,
            side: order.side,
            type: order.type,
            price: priceVal,
            amount: amountVal,
            total: totalVal,
            trigger_price: order.triggerPrice || null,
            chain_id: isBscTradable.value ? BSC_CHAIN_ID : TPIX_CHAIN_ID,
        }, { signal: controller.signal });

        clearTimeout(timeoutId);

        if (!data.success) throw new Error(data.error?.message || t('trade.status.failed'));

        const orderData = data.data;

        orderStatus.value = 'success';
        orderMessage.value = orderData.status === 'filled'
            ? t('trade.status.orderFilled', { side: sideLabel, count: orderData.trades_count })
            : orderData.status === 'partially_filled'
                ? t('trade.status.orderPartial', { side: sideLabel, filled: orderData.filled_amount, amount: orderData.amount })
                : t('trade.status.orderPlaced', {
                    side: sideLabel,
                    type: order.type === 'market' ? t('trade.form.marketType') : t('trade.form.limit'),
                    price: priceVal.toLocaleString(),
                });

        // Refresh order book & trades ทันที (เฉพาะ TPIX pair ที่ใช้ internal data feed)
        if (isTPIXPair.value) {
            fetchTpixData();
        }

        playTradeSound();
        fetchBalances();
    } catch (err) {
        orderStatus.value = 'error';
        if (err.name === 'AbortError' || err.code === 'ERR_CANCELED') {
            orderMessage.value = t('trade.status.timeout');
        } else {
            orderMessage.value = err.response?.data?.error?.message || err.message || t('trade.status.failed');
        }
        playErrorSound();
    } finally {
        isSubmitting.value = false;
    }

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        orderStatus.value = null;
        orderMessage.value = '';
    }, 4000);
};

const handleConnectWallet = () => {
    walletStore.openConnectModal();
};

onMounted(async () => {
    measureBoard();
    wideQuery?.addEventListener('change', measureBoard);
    window.addEventListener('resize', measureBoard);
    document.addEventListener('click', closeLayoutMenu);

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
            dataError.value = t('trade.notice.connectError');
            isLoading.value = false;
        }
    }

    if (walletStore.isConnected) {
        fetchBalances();
        fetchBscFormBalances();
    }

    nextTick(measureBoard);
});

// เชื่อม wallet ทีหลัง / สลับ address → โหลดยอด BSC สำหรับฟอร์มเทรดใหม่
watch(() => walletStore.address, () => {
    fetchBscFormBalances();
});

// แถบแจ้งเตือนโผล่/หาย หรือสลับโหมด → ตำแหน่งบนสุดของกระดานขยับ ต้องวัดใหม่
watch([dataError, tradeFormMode, () => layout.fitScreen.value], () => {
    nextTick(measureBoard);
});

// เปลี่ยนคู่เทรด → ราคาที่เคยคลิกไว้เป็นของคู่เดิม ต้องล้างทิ้ง
watch(currentPair, () => {
    selectedPrice.value = null;
    isChartFullscreen.value = false;
});

onUnmounted(() => {
    if (isTPIXPair.value) {
        if (tpixRefreshInterval) clearInterval(tpixRefreshInterval);
    } else {
        disconnectWebSocket();
    }
    clearTimeout(previewTimer);
    clearTimeout(toastTimer);
    wideQuery?.removeEventListener('change', measureBoard);
    window.removeEventListener('resize', measureBoard);
    document.removeEventListener('click', closeLayoutMenu);
});
</script>

<template>
    <Head :title="`Trade ${currentPair}`" />

    <AppLayout :hide-sidebar="true" :hide-ticker="true">
        <div class="max-w-[1920px] mx-auto">
            <!-- บรรยากาศพื้นหลังหน้าเทรด — จางมากเพื่อไม่แย่งสายตาจากตัวเลข -->
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
                        'fixed top-4 right-4 z-[70] max-w-[92vw] px-5 py-3.5 rounded-xl shadow-lg text-sm font-medium flex items-center gap-3',
                        orderStatus === 'success' ? 'bg-trading-green/90 text-white' :
                        orderStatus === 'error' ? 'bg-trading-red/90 text-white' :
                        'bg-primary-500/90 text-white'
                    ]"
                >
                    <div v-if="orderStatus === 'submitting' || orderStatus === 'executing'" class="spinner !w-4 !h-4 !border-white/30 !border-t-white"></div>
                    <svg v-else-if="orderStatus === 'success'" class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <svg v-else class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    <span>{{ orderMessage }}</span>
                    <a
                        v-if="orderStatus === 'success' && orderTxUrl"
                        :href="orderTxUrl"
                        target="_blank"
                        rel="noopener"
                        class="underline font-semibold whitespace-nowrap hover:opacity-80"
                    >
                        {{ t('trade.status.viewTx') }} ↗
                    </a>
                </div>
            </Transition>

            <!-- Data Error Banner -->
            <div v-if="dataError" class="relative mb-3 p-3 rounded-xl bg-trading-red/10 border border-trading-red/30 text-trading-red text-sm flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>
                {{ dataError }}
            </div>

            <!-- แถบเครื่องมือจัดผัง (แถวบางๆ ชิดขวา)
                 เอาออกไปแล้ว 2 อย่างเพราะซ้ำกับที่อื่นและกินพื้นที่แนวตั้ง:
                 - ตัวเลือกคู่เทรด → เลือกจากการ์ด "คู่เทรด" ในคอลัมน์ซ้ายได้อยู่แล้ว
                 - แผงราคา/24h    → หัวกราฟแสดงคู่+ราคา+เปอร์เซ็นต์ และย้าย high/low/vol ไปไว้ที่นั่น
                 ยังต้องมี z-30 เพราะ backdrop-blur สร้าง stacking context
                 และห้าม overflow-hidden ไม่งั้นเมนูจัดผังโดนตัด -->
            <div class="relative z-30 flex items-center justify-end gap-2 mb-3">
                <!-- เครื่องมือจัดผัง -->
                <div class="relative ml-auto flex items-center gap-2 layout-menu">
                    <!-- ขนาดกราฟ — มีผลเฉพาะโหมดเลื่อนหน้า (โหมดพอดีจอกราฟยืดเอง) -->
                    <div v-if="!packed" class="hidden md:flex items-center gap-0.5 p-0.5 rounded-lg bg-dark-800/80">
                        <button
                            v-for="h in CHART_HEIGHTS"
                            :key="h.id"
                            type="button"
                            :title="`${t('trade.layout.chartSize')}: ${t(h.labelKey)}`"
                            :class="['px-2 py-1 rounded-md text-[10px] font-medium transition-colors',
                                layout.chartHeight.value === h.id ? 'bg-dark-600 text-white' : 'text-dark-400 hover:text-white']"
                            @click="layout.chartHeight.value = h.id"
                        >
                            {{ t(h.labelKey) }}
                        </button>
                    </div>

                    <button
                        type="button"
                        class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-dark-800/80 border border-white/5 text-[11px] text-dark-300 hover:text-white hover:border-primary-500/40 transition-colors"
                        @click="showLayoutMenu = !showLayoutMenu"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h10M4 18h7" />
                        </svg>
                        {{ t('trade.layout.customize') }}
                    </button>

                    <!-- เมนูจัดผัง -->
                    <div
                        v-if="showLayoutMenu"
                        class="absolute right-0 top-full mt-2 w-64 z-50 rounded-xl border border-white/10 bg-dark-800/95 backdrop-blur-xl shadow-2xl p-3"
                    >
                        <p class="text-[11px] text-dark-400 mb-2 leading-relaxed">
                            {{ t('trade.layout.hint') }}
                            <span v-if="isNarrow" class="block text-amber-400 mt-1">{{ t('trade.layout.wideOnly') }}</span>
                        </p>

                        <label class="flex items-start gap-2 py-1.5 cursor-pointer border-y border-white/5 mb-2">
                            <input
                                type="checkbox"
                                class="mt-0.5 rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500 w-3.5 h-3.5"
                                :checked="layout.fitScreen.value"
                                @change="layout.fitScreen.value = !layout.fitScreen.value"
                            >
                            <span class="min-w-0">
                                <span class="block text-xs text-white">{{ t('trade.layout.fitScreen') }}</span>
                                <span class="block text-[10px] text-dark-500 leading-snug">{{ t('trade.layout.fitScreenHint') }}</span>
                            </span>
                        </label>

                        <p class="text-[10px] text-dark-500 mb-1.5">{{ t('trade.layout.showCards') }}</p>
                        <label
                            v-for="card in hideableCards"
                            :key="card.id"
                            class="flex items-center gap-2 py-1 cursor-pointer text-xs text-dark-300 hover:text-white"
                        >
                            <input
                                type="checkbox"
                                class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500 w-3.5 h-3.5"
                                :checked="!layout.hidden.value.includes(card.id)"
                                @change="layout.toggleHidden(card.id)"
                            >
                            {{ card.label }}
                        </label>

                        <button
                            type="button"
                            class="w-full mt-2.5 py-1.5 rounded-lg bg-white/5 text-[11px] text-dark-300 hover:text-white hover:bg-white/10 transition-colors"
                            @click="layout.reset()"
                        >
                            {{ t('trade.layout.reset') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- แถบบอกโหมดเทรด: on-chain จริงบน BSC หรือรอ TPIX Chain -->
            <div v-if="tradeFormMode === 'onchain'" class="relative mb-3 px-3 py-2 rounded-xl bg-primary-500/10 border border-primary-500/20 text-primary-300 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <span>{{ t('trade.notice.onchain') }}</span>
            </div>
            <div v-else-if="isTPIXPair" class="relative mb-3 px-3 py-2 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ t('trade.notice.tpixSoon') }}</span>
            </div>

            <!-- ── ผัง 4 คอลัมน์ ────────────────────────────────────────────────
                 การ์ดทุกใบเขียนครั้งเดียวใน loop ของคอลัมน์ — ย้ายไปคอลัมน์ไหน
                 ก็ render ที่นั่น ไม่ต้องเขียนซ้ำ 3 ชุดให้หลุดกันภายหลัง -->
            <div
                ref="board"
                class="relative grid gap-3 grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] xl:grid-cols-[248px_minmax(0,1fr)_284px_236px] 2xl:grid-cols-[268px_minmax(0,1fr)_312px_260px]"
                :class="packed ? 'items-stretch' : 'items-start'"
                :style="boardStyle"
            >
                <div
                    v-for="col in COLUMNS"
                    :key="col"
                    :class="[
                        'contents lg:flex lg:flex-col lg:gap-3 lg:min-w-0',
                        packed && 'lg:min-h-0 lg:overflow-y-auto lg:overflow-x-hidden custom-scrollbar',
                        columnClass[col],
                    ]"
                    @dragover.prevent
                    @drop="onColumnDrop(col)"
                >
                    <template v-for="cardId in layout.visible.value[col]" :key="cardId">
                        <!-- คู่เทรด -->
                        <DraggableCard
                            v-if="cardId === 'market'"
                            card-id="market"
                            :locked="isNarrow"
                            :class="layout.stackClass('market')"
                            :style="styleFor('market')"
                        >
                            <MarketListPanel :current-pair="currentPair" />
                        </DraggableCard>

                        <!-- กราฟ
                             เต็มจอต้องเป็น `!fixed` — DraggableCard มี `relative` เป็นคลาสคงที่
                             และ Tailwind วาง .relative ไว้หลัง .fixed ในไฟล์ output
                             คลาสที่ specificity เท่ากันจึงแพ้ให้ตัวที่มาทีหลังเสมอ -->
                        <DraggableCard
                            v-else-if="cardId === 'chart'"
                            card-id="chart"
                            :locked="isNarrow"
                            :class="[layout.stackClass('chart'), isChartFullscreen ? '!fixed inset-3 z-[55]' : '']"
                            :style="styleFor('chart')"
                        >
                            <template #actions>
                                <button
                                    type="button"
                                    class="p-1 rounded text-dark-500 hover:text-white hover:bg-white/5 transition-colors"
                                    :title="isChartFullscreen ? t('trade.layout.exitFullscreen') : t('trade.layout.fullscreen')"
                                    :aria-label="isChartFullscreen ? t('trade.layout.exitFullscreen') : t('trade.layout.fullscreen')"
                                    @click="isChartFullscreen = !isChartFullscreen"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path v-if="!isChartFullscreen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4" />
                                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 4v5H4M15 4v5h5M15 20v-5h5M9 20v-5H4" />
                                    </svg>
                                </button>
                            </template>

                            <!-- ฉากหลังกราฟจางๆ — วางก่อนกราฟ และกราฟต้องเป็น positioned
                                 ไม่งั้นเลเยอร์ absolute นี้จะทับกราฟทั้งใบ -->
                            <PageArt art="chart-backdrop" :opacity="11" fade="radial" />

                            <TradingChart
                                :symbol="currentPair"
                                :ticker="ticker"
                                :is-tpix="isTPIXPair"
                                class="flex-1 relative z-10"
                            />
                        </DraggableCard>

                        <!-- ฟอร์มซื้อ/ขาย -->
                        <DraggableCard
                            v-else-if="cardId === 'form'"
                            card-id="form"
                            :locked="isNarrow"
                            :class="layout.stackClass('form')"
                            :style="styleFor('form')"
                            body-class="overflow-y-auto custom-scrollbar"
                        >
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
                        </DraggableCard>

                        <!-- คำสั่งของฉัน -->
                        <DraggableCard
                            v-else-if="cardId === 'orders'"
                            card-id="orders"
                            :locked="isNarrow"
                            :class="layout.stackClass('orders')"
                            :style="styleFor('orders')"
                        >
                            <template #actions>
                                <div class="flex items-center gap-0.5">
                                    <button
                                        v-for="tab in tabs"
                                        :key="tab.id"
                                        type="button"
                                        :class="['px-2 py-1 rounded-md text-[11px] font-medium transition-colors whitespace-nowrap',
                                            activeTab === tab.id ? 'bg-primary-500/15 text-primary-300' : 'text-dark-400 hover:text-white']"
                                        @click="activeTab = tab.id"
                                    >
                                        {{ t(tab.key) }}
                                    </button>
                                </div>
                            </template>

                            <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar p-4">
                                <OpenOrders v-if="activeTab === 'openOrders'" />
                                <TradeHistory v-else-if="activeTab === 'history'" />
                                <div v-else class="text-center text-dark-400 text-sm">
                                    <div v-if="walletStore.isConnected && balances.length > 0">
                                        <div v-for="bal in balances" :key="bal.token_address" class="flex items-center justify-between py-2 border-b border-white/5">
                                            <span class="text-white font-medium">{{ bal.symbol }}</span>
                                            <span class="font-mono text-white">{{ parseFloat(bal.balance).toFixed(6) }}</span>
                                        </div>
                                    </div>
                                    <div v-else-if="walletStore.isConnected" class="py-8">
                                        <svg class="w-8 h-8 mx-auto text-dark-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 12V8H6a2 2 0 01-2-2c0-1.1.9-2 2-2h12v4m2 0v8a2 2 0 01-2 2H6a2 2 0 01-2-2V6" />
                                        </svg>
                                        <p class="text-dark-500">{{ t('trade.tabs.noBalances') }}</p>
                                    </div>
                                    <div v-else class="py-8">
                                        <svg class="w-8 h-8 mx-auto text-dark-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                        </svg>
                                        <p class="text-dark-500 mb-3">{{ t('trade.tabs.connectForFunds') }}</p>
                                        <button type="button" class="btn-primary text-sm px-6 py-2" @click="handleConnectWallet">
                                            {{ t('trade.form.connectWallet') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </DraggableCard>

                        <!-- สมุดคำสั่ง -->
                        <DraggableCard
                            v-else-if="cardId === 'book'"
                            card-id="book"
                            :locked="isNarrow"
                            :class="layout.stackClass('book')"
                            :style="styleFor('book')"
                        >
                            <OrderBook
                                :symbol="currentPair"
                                :asks="asks"
                                :bids="bids"
                                :ticker-price="ticker?.price || 0"
                                :is-loading="isLoading"
                                @select-price="handleSelectPrice"
                            />
                        </DraggableCard>

                        <!-- AI TRADE -->
                        <DraggableCard
                            v-else-if="cardId === 'ai'"
                            card-id="ai"
                            :locked="isNarrow"
                            :class="layout.stackClass('ai')"
                            :style="styleFor('ai')"
                            :art-opacity="10"
                        >
                            <template #actions>
                                <Link
                                    :href="`/ai-trade?pair=${encodeURIComponent(currentPair)}`"
                                    class="px-2 py-0.5 rounded-md text-[10px] font-medium text-primary-400 hover:text-primary-300 hover:bg-white/5 transition-colors"
                                >
                                    {{ t('trade.layout.settings') }}
                                </Link>
                            </template>

                            <AiTradeCard :pair="currentPair" />
                        </DraggableCard>

                        <!-- เทรดล่าสุด -->
                        <DraggableCard
                            v-else-if="cardId === 'trades'"
                            card-id="trades"
                            :locked="isNarrow"
                            :class="layout.stackClass('trades')"
                            :style="styleFor('trades')"
                        >
                            <RecentTrades
                                :symbol="currentPair"
                                :trades="trades"
                                :is-loading="isLoading"
                                @select-price="handleSelectPrice"
                            />
                        </DraggableCard>
                    </template>

                    <!-- พื้นที่รับการ์ดตอนคอลัมน์ว่าง -->
                    <div
                        v-if="layout.draggingId.value && layout.visible.value[col].length === 0"
                        class="h-24 rounded-2xl border-2 border-dashed border-primary-500/40 flex items-center justify-center text-[11px] text-primary-300"
                    >
                        {{ t('trade.layout.dropHere') }}
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
