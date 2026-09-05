<script setup>
/**
 * TPIX TRADE - Trading Dashboard Page
 * กระดานเทรด 4 คอลัมน์ ที่ผู้ใช้จัดผังการ์ดเองได้
 *   ซ้าย  = รายการคู่เทรด + AI TRADE
 *   กลาง  = กราฟ + ฟอร์มซื้อขาย
 *   ขวา   = สมุดคำสั่ง
 *   ท้าย  = คำสั่งของฉัน + เทรดล่าสุด
 *
 * แต่ละคอลัมน์ประกอบด้วย "แถว" และแถวหนึ่งวางการ์ดเคียงกันได้ 2 ใบ (บนจอ xl ขึ้นไป)
 * เช่น กราฟเต็มความกว้างด้านบน แล้วใต้กราฟแบ่งเป็นฟอร์มซื้อขาย | สมุดคำสั่ง
 * ต่ำกว่า xl แถวยุบเป็น display:contents แล้วการ์ดเรียงลงมาทีละใบตามคลาส order-*
 *
 * โหมด "พอดีหน้าจอ": แถวยืด/หดแบ่งความสูงที่เหลือกันเอง (ตาม CARD_FLEX)
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
import RowSplitter from '@/Components/Trading/RowSplitter.vue';
import RowResizer from '@/Components/Trading/RowResizer.vue';
import PageArt from '@/Components/PageArt.vue';
import { useBinanceData } from '@/Composables/useBinanceData';
import { useSwap } from '@/Composables/useSwap';
import { useTpixDex, toDexAddress } from '@/Composables/useTpixDex';
import { loadDexConfig, isDexConfigured } from '@/Config/dexContracts';
import { useTradingFee } from '@/Composables/useTradingFee';
import { useWalletStore } from '@/Stores/walletStore';
import { useWalletBalance } from '@/Composables/useWalletBalance';
import { useTradeLayout, COLUMNS } from '@/Composables/useTradeLayout';
import { useDragAutoScroll } from '@/Composables/useDragAutoScroll';
import { useAiBot } from '@/Composables/useAiBot';
import { useMyTrades } from '@/Composables/useMyTrades';
import { playTradeSound, playErrorSound } from '@/Composables/useSounds';
import { getBscTradeToken, getVerifiedTradeToken } from '@/Config/bscTradeTokens';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';
import { showToast } from '@/Composables/useToasts';

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

/*
 * ข้อมูลคู่จากเซิร์ฟเวอร์ (/api/v1/market/pairs) — บอกว่าคู่นี้อยู่เชนไหน ที่อยู่โทเคนอะไร
 * คู่บนเชน TPIX ไม่ได้มาจากทะเบียน JS แต่มาจากพูลจริงบน TPIXDEXFactory (dex:sync สร้างให้)
 * จึงต้องถามเซิร์ฟเวอร์ ไม่ใช่เดาจากชื่อคู่
 */
const pairMeta = ref(null);
const pairMetaLoaded = ref(false);

async function loadPairMeta() {
    try {
        const { data } = await axios.get('/api/v1/market/pairs');
        const wanted = props.pair.toUpperCase();
        pairMeta.value = (data?.data || []).find((p) => String(p.symbol).toUpperCase() === wanted) || null;
    } catch {
        pairMeta.value = null;
    } finally {
        pairMetaLoaded.value = true;
    }
}

// คู่ที่อยู่บนเชน TPIX (ไม่ว่าจะ deploy DEX แล้วหรือยัง)
const isTpixChainPair = computed(() =>
    Number(pairMeta.value?.network_chain_id) === TPIX_CHAIN_ID || (isTPIXPair.value && !pairMeta.value)
);

// คู่ที่เทรดจริงบน TPIX DEX ได้ — พูลมีอยู่จริง + เซิร์ฟเวอร์ยืนยันว่าสัญญา DEX มีโค้ดบนเชน
const isDexPair = computed(() =>
    Number(pairMeta.value?.network_chain_id) === TPIX_CHAIN_ID
    && pairMeta.value?.execution_mode === 'onchain'
    && isDexConfigured()
);

// เชนที่ไม้จะลงจริง — ส่งให้ฟอร์มขอใบเสนอราคาค่าบริการให้ตรงเชน
const tradeChainId = computed(() => (isDexPair.value ? TPIX_CHAIN_ID : BSC_CHAIN_ID));

// โหมดของ TradeForm:
//  onchain  = market order execute จริง (PancakeSwap บน BSC หรือพูล TPIX DEX บนเชน TPIX)
//  disabled = คู่บนเชน TPIX ที่ DEX ยังไม่ deploy หรือคู่ที่ไม่มี token บน BSC — เห็นไว้ก่อน กดไม่ได้
const tradeFormMode = computed(() => ((isBscTradable.value || isDexPair.value) ? 'onchain' : 'disabled'));

/** โทเคนของคู่บน TPIX DEX ในรูปที่ useTpixDex ใช้ — สร้างจากข้อมูลเซิร์ฟเวอร์ ไม่เดา */
function dexToken(side) {
    const m = pairMeta.value;
    if (!m) return null;
    const isBase = side === 'base';
    const address = toDexAddress(isBase ? m.base_address : m.quote_address);
    return {
        symbol: isBase ? baseSymbol.value : quoteSymbol.value,
        address,
        decimals: Number(isBase ? m.base_decimals : m.quote_decimals) || 18,
        native: address === 'native',
    };
}

// ── ผังการ์ด ────────────────────────────────────────────────────────────────
const layout = useTradeLayout();

/*
 * ป้ายไม้บนกราฟ — ทั้งของบอทและที่เราวางเอง
 *
 * เจ้าของสั่งไว้ตรงๆ ว่า "เปิดบอทแล้วต้องเห็นไม้ที่บอทวางในกราฟ" และ
 * "เราวางเองตรงไหนก็ต้องเห็น" — กราฟที่ไม่มีป้ายทำให้ผู้ใช้ตรวจสอบบอทไม่ได้เลย
 * ว่ามันเข้าไม้ตรงจุดที่ควรเข้าไหม ได้แต่เชื่อตัวเลขสรุปอย่างเดียว
 *
 * ไม้ของบอทมาจากพอร์ตทดลอง (โหมดจริงยังเป็นสัญญาณรอยืนยัน ยังไม่มีไม้จริง)
 */
const bot = useAiBot();
const myTrades = useMyTrades();

/*
 * โหมดบอท — เจ้าของสั่ง "การเข้าไม้ ออกไม้ ต้องแสดงชัดเจนในเส้นกราฟเมื่อเปิดโหมดบอท"
 *
 * เปิด = ป้ายของบอทใหญ่ขึ้นพร้อมข้อความ + เส้นต้นทุน/SL/TP ของไม้ที่บอทถืออยู่
 * จำไว้ต่อเครื่อง (localStorage) เปิดครั้งเดียวไม่ต้องเปิดใหม่ทุกครั้งที่เข้าหน้า
 * ค่าปริยายเปิด — คนที่ไม่มีบอทไม่เห็นอะไรต่างอยู่แล้ว (ไม่มีไม้ของบอทให้วาด)
 *
 * โหมดบอทไม่แตะฟอร์มซื้อ/ขาย — ผู้ใช้ยังวางไม้เองได้ตามปกติ บอทใช้พอร์ต/กระเป๋าของมันเอง
 */
const BOT_MODE_KEY = 'tpix.trade.botMode';
const botMode = ref((() => {
    try {
        const stored = localStorage.getItem(BOT_MODE_KEY);
        return stored === null ? true : stored === '1';
    } catch (_) {
        return true;
    }
})());
watch(botMode, (value) => {
    try { localStorage.setItem(BOT_MODE_KEY, value ? '1' : '0'); } catch (_) { /* โหมดส่วนตัว */ }
});

/** ข้อความบนป้าย — ซื้อบอกเงินที่ใช้ ขายบอกกำไร/ขาดทุนที่ปิดได้ */
function botMarkerLabel(trade) {
    const side = String(trade.side || '').toLowerCase();
    const pnl = Number(trade.realized_pnl);

    if (side === 'sell' && Number.isFinite(pnl)) {
        return `${t('aiTrade.botSell')} ${pnl >= 0 ? '+' : ''}${pnl.toFixed(2)}`;
    }

    const usd = Number(trade.gross_value);
    const word = t(side === 'buy' ? 'aiTrade.botBuy' : 'aiTrade.botSell');

    return Number.isFinite(usd) && usd > 0 ? `${word} $${usd.toFixed(0)}` : word;
}

const botMarkers = computed(() => {
    const wanted = currentPair.value.toUpperCase();

    /*
     * แหล่งหลักคือ /ai-bot/trades ของคู่นี้ (ทุกโหมด ครบ 300 ไม้ล่าสุด)
     * ระหว่างที่ยังโหลดไม่เสร็จหรือเป็นของคู่เก่า ใช้ไม้จากพอร์ตทดลองไปพลางก่อน
     */
    const loaded = bot.trades.value;
    const source = loaded?.pair?.toUpperCase() === wanted
        ? loaded.items
        : (bot.demo.value?.trades ?? []);

    return source
        .filter(t => String(t.pair || '').replace('-', '/').toUpperCase() === wanted)
        .map(t => ({
            time: Math.floor(new Date(t.created_at).getTime() / 1000),
            side: String(t.side || '').toLowerCase(),
            price: Number(t.price),
            source: 'bot',
            label: botMarkerLabel(t),
        }))
        .filter(m => Number.isFinite(m.time));
});

/**
 * เส้นราคาของไม้ที่บอทถืออยู่ในคู่นี้ — ต้นทุนเฉลี่ย + SL/TP ที่คิดจากกรอบเสี่ยงของบอท
 *
 * SL/TP คำนวณจาก entry × (1 ∓ %) เหมือนที่ BotRunner ใช้ตัดสินจริง จึงเป็นเส้นที่บอท
 * จะลงมือเมื่อราคาแตะ ไม่ใช่ตัวเลขประมาณ
 */
const botPriceLines = computed(() => {
    const wanted = currentPair.value.toUpperCase();
    const lines = [];

    for (const item of (bot.bots.value ?? [])) {
        if (String(item.pair || '').replace('-', '/').toUpperCase() !== wanted || !item.position) continue;

        const entry = Number(item.position.entry_price);
        if (!Number.isFinite(entry) || entry <= 0) continue;

        const sl = Number(item.risk?.stop_loss_pct);
        const tp = Number(item.risk?.take_profit_pct);

        lines.push({ price: entry, color: '#38bdf8', style: 'dashed', title: `${t('aiTrade.botEntryLine')} · ${item.name}` });
        if (sl > 0) lines.push({ price: entry * (1 - sl / 100), color: '#FF1744', style: 'dotted', title: t('aiTrade.botSlLine') });
        if (tp > 0) lines.push({ price: entry * (1 + tp / 100), color: '#00C853', style: 'dotted', title: t('aiTrade.botTpLine') });
    }

    return lines;
});

const chartMarkers = computed(() => [
    ...botMarkers.value,
    ...myTrades.markersFor(currentPair.value),
]);
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

/**
 * ความสูงต่ำสุดของกระดาน — ต่ำกว่านี้การ์ดเหลือแค่หัวจนอ่านอะไรไม่ได้
 *
 * ⚠️ ห้ามตั้งสูงกว่านี้ (เดิม 520): บนจอเตี้ยที่เหลือที่จริงแค่ 480px การบังคับ 520
 *    ทำให้กระดานสูงเกินจอ หน้าเลื่อนได้ → ผู้ใช้เห็นเป็น "โหมดพอดีหน้าจอไม่พอดี"
 *    คอลัมน์มี overflow-y-auto อยู่แล้ว ให้คอลัมน์เลื่อนข้างในดีกว่าให้ทั้งหน้าเลื่อน
 */
const MIN_BOARD_HEIGHT = 360;

function measureBoard() {
    syncBreakpoint();

    if (!board.value || typeof window === 'undefined') return;
    // rect.top เป็นระยะจากขอบบนของ viewport อยู่แล้ว จึงลบออกจากความสูงจอได้ตรงๆ
    // (ในโหมดพอดีจอหน้าไม่เลื่อน ค่านี้จึงคงที่)
    const viewportTop = board.value.getBoundingClientRect().top;
    boardHeight.value = Math.max(MIN_BOARD_HEIGHT, window.innerHeight - viewportTop - 14);
}

/**
 * ⭐ ต้นเหตุ "บางครั้งเปิดมาพอดี บางครั้งไม่พอดี"
 *
 * ความสูงกระดานคิดจากขอบบนของมัน ซึ่งถูกดันโดยทุกอย่างที่อยู่เหนือมัน —
 * โดยเฉพาะ <BannerAd> ใน AppLayout ที่ยิง API แล้วค่อย v-if โผล่มาทีหลัง
 * ถ้า API ตอบก่อน nextTick ก็พอดี ตอบช้ากว่านั้นก็เกินไปเท่าความสูงแบนเนอร์
 * และไม่มีอะไรวัดซ้ำ เพราะ window resize ไม่ยิง (ขนาดหน้าต่างไม่ได้เปลี่ยน)
 *
 * แก้ด้วยการเฝ้าดู "พี่ที่อยู่ก่อนหน้า" ของทุกชั้นตั้งแต่กระดานไล่ขึ้นไปถึง body
 * = เซตของกล่องที่ความสูงของมันกำหนดตำแหน่งบนสุดของกระดานพอดี
 * ไม่มีตัวไหนขึ้นกับความสูงของกระดานเอง จึงไม่เกิดลูปวัด→เปลี่ยน→วัด
 */
let topObserver = null;
let topMutations = null;

/**
 * ⚠️ ResizeObserver อย่างเดียวไม่พอ — และนี่คือกรณีของแบนเนอร์เป๊ะๆ
 *
 * ตอน v-if ยังเป็นเท็จ Vue วาง comment node ไว้แทน ไม่ใช่ element
 * previousElementSibling จึงข้ามมันไป = ตอน mount ไม่มีใครเฝ้าแบนเนอร์เลย
 * พอ API ตอบแล้วกล่องจริงโผล่มาแทน comment ก็ยังไม่มีใครเฝ้าอยู่ดี
 * ต้องมี MutationObserver คอยดูการเพิ่ม/ลบลูก แล้วผูกตัวเฝ้าขนาดใหม่ทุกครั้ง
 */
function attachTopObservers() {
    for (let node = board.value; node && node !== document.body; node = node.parentElement) {
        if (node.parentElement) topMutations?.observe(node.parentElement, { childList: true });

        for (let sib = node.previousElementSibling; sib; sib = sib.previousElementSibling) {
            topObserver?.observe(sib);
        }
    }
}

function observeAbove() {
    if (!board.value || typeof window === 'undefined') return;

    topObserver?.disconnect();
    topMutations?.disconnect();

    if (typeof ResizeObserver !== 'undefined') {
        topObserver = new ResizeObserver(() => measureBoard());
    }

    if (typeof MutationObserver !== 'undefined') {
        topMutations = new MutationObserver(() => {
            attachTopObservers();
            measureBoard();
        });
    }

    attachTopObservers();
}

const boardStyle = computed(() => {
    const style = packed.value && boardHeight.value ? { height: `${boardHeight.value}px` } : {};

    // ผังคอลัมน์ไดนามิกใช้เฉพาะจอกว้าง — ต่ำกว่านั้นคลาส Tailwind คุมอยู่แล้ว
    // (จอแคบ = คอลัมน์เดียว, lg = สองคอลัมน์)
    if (!isNarrow.value) {
        style.gridTemplateColumns = gridTemplate.value;
    }

    return style;
});

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

/**
 * คอลัมน์ที่มีการ์ดอยู่จริง — คอลัมน์ว่างจะไม่ถูก render จึงไม่กินพื้นที่
 *
 * นี่คือสิ่งที่ทำให้ผังกลายเป็น 4 → 3 → 2 คอลัมน์เองตามที่ผู้ใช้ลากวาง
 */
const filledColumns = computed(() => COLUMNS.filter(col => layout.visible.value[col].length > 0));

/**
 * ⭐ กับดักที่ต้องแก้: ยุบคอลัมน์ว่างทิ้งแล้ว "ที่วางการ์ด" ก็หายไปด้วย
 *    ผู้ใช้จะลากการ์ดกลับเข้าคอลัมน์นั้นไม่ได้อีกเลย = ผังพังถาวร
 *
 * แก้โดยระหว่างที่กำลังลากอยู่ ให้ render คอลัมน์ว่างกลับมาเป็นแถบบางๆ รับวาง
 * พอปล่อยมือแล้วคอลัมน์ที่ยังว่างก็ยุบหายไปเหมือนเดิม
 */
const isDragging = computed(() => !!layout.draggingId.value);

const renderedColumns = computed(() => (isDragging.value ? COLUMNS : filledColumns.value));

/** คอลัมน์ที่ถือกราฟอยู่ — รางนี้เป็นตัวยืด ส่วนคอลัมน์อื่นกว้างคงที่ */
const chartColumn = computed(() =>
    COLUMNS.find(col => layout.visible.value[col].includes('chart')) ?? filledColumns.value[0]
);

/**
 * grid template สร้างจากคอลัมน์ที่ render จริง
 *
 * เดิมเป็นค่าคงที่ 4 ราง ทำให้คอลัมน์ว่างยังกินพื้นที่ 248px เต็มความสูง
 * และรางยืด 1fr ผูกกับ "ชื่อคอลัมน์ center" ไม่ใช่การ์ดกราฟ — พอลากกราฟออกไป
 * กราฟจะไปอยู่ในรางแคบแล้วโดนบีบ ส่วนคอลัมน์ที่ไม่มีกราฟกลับได้ที่ว่างทั้งหมด
 */
const gridTemplate = computed(() => renderedColumns.value.map((col) => {
    const rows = layout.visibleRows.value[col];

    // คอลัมน์ว่างระหว่างลาก = แถบบางๆ ให้เล็งวางได้ ไม่กินที่มาก
    if (!rows.length) return '96px';

    // เผื่อจากแถวที่ต้องการกว้างที่สุด — แถวคู่ต้องพอสำหรับการ์ดทั้งสองใบบวกช่องไฟ
    const min = layout.columnMinWidth(rows);

    // รางของกราฟเป็นตัวยืด แต่ห้ามหดต่ำกว่าที่แถวข้างในต้องการจริง
    // (เดิมเป็น minmax(0,1fr) — พอผู้ใช้แบ่งคอลัมน์ย่อยใต้กราฟ รางจะบี้จนการ์ดล้น)
    if (col === chartColumn.value) return `minmax(${min}px, 1fr)`;

    // ⚠️ เพดานต้องไม่ต่ำกว่า min: minmax(472px, 340px) จะทำให้เบราว์เซอร์ทิ้งค่า max
    //    เงียบๆ แล้วรางจะกว้างค้างที่ min โดยไม่มีใครรู้ว่าเพดานไม่ทำงาน
    const max = Math.max(min, Math.min(min + 80, 340));

    return `minmax(${min}px, ${max}px)`;
}).join(' '));

/**
 * แถวยุบเป็น display:contents ต่ำกว่า xl — การ์ดไหลเรียงลงมาทีละใบตามคลาส order-*
 * (จอแคบวางเคียงกันแล้วเหลือใบละ ~180px ซึ่งอ่านตัวเลขไม่ออก)
 */
const rowClass = ['contents xl:flex xl:flex-row xl:gap-3 xl:min-w-0 xl:min-h-0'];

/** ความสูงของแถว — บนจอกว้างแถวเป็นตัวถือความสูง ไม่ใช่การ์ด */
function rowStyleFor(row) {
    // ต่ำกว่า xl แถวเป็น display:contents สไตล์ไม่มีผล การ์ดคุมความสูงเอง
    return isNarrow.value ? {} : layout.rowStyle(row, packed.value);
}

/** สไตล์ของการ์ดหนึ่งใบ — บนจอกว้างคุมแค่ความกว้างในแถว, จอแคบคุมความสูง */
function styleFor(cardId, row = null) {
    if (cardId === 'chart' && isChartFullscreen.value) return {};
    if (!isNarrow.value && row) return layout.cardStyleInRow(cardId, row);

    return layout.cardStyle(cardId, packed.value);
}

/*
 * ลากไปค้างที่ขอบคอลัมน์แล้วให้มันเลื่อนเอง
 *
 * ผูกที่คอลัมน์ไม่ใช่ที่การ์ด เพราะ dragover ของการ์ดไม่ได้ stopPropagation
 * (มีแต่ drop ที่หยุด) เหตุการณ์จึงลอยขึ้นมาถึงคอลัมน์เสมอ — ผูกที่เดียวครอบคลุม
 * ทั้งตอนลากผ่านการ์ดและตอนลากผ่านที่ว่าง
 */
const autoScroll = useDragAutoScroll();

function onColumnDragOver(event) {
    if (!layout.draggingId.value) return;
    autoScroll.onDragOver(event, event.currentTarget);
}

function onColumnDrop(column) {
    autoScroll.stop();
    if (!layout.draggingId.value) return;
    layout.dropOnColumn(column);
    layout.endDrag();
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

/*
 * แถบเตือนเดิมกินพื้นที่ในผังจริง ทำให้การ์ดทั้งกระดานเลื่อนลงทุกครั้งที่มี error
 * และ measureBoard ต้องคำนวณความสูงใหม่ตาม — เปลี่ยนเป็นข้อความลอยแทน
 * อยู่ 10 วินาทีแล้วหายเอง หรือกดปิดได้ทันที
 */
watch(dataError, (text) => {
    if (text) showToast({ text, type: 'error' });
});

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
 * ฟีดของคู่บน TPIX DEX — ราคากลางจากพูล, ความลึกสังเคราะห์, สวอปล่าสุด
 * (รูปแบบเดียวกับ fetchTpixData เพื่อให้การ์ดทุกใบใช้ต่อได้โดยไม่ต้องรู้ว่ามาจากไหน)
 */
let dexRefreshInterval = null;
const dexKlinesUrl = computed(() => (isDexPair.value ? `/api/v1/dex/klines/${props.pair}` : ''));

async function fetchDexData() {
    const symbol = props.pair;
    try {
        const [tickerRes, bookRes, tradesRes] = await Promise.all([
            axios.get(`/api/v1/dex/ticker/${symbol}`).catch(() => ({ data: { success: false } })),
            axios.get(`/api/v1/dex/orderbook/${symbol}`).catch(() => ({ data: { success: false } })),
            axios.get(`/api/v1/dex/trades/${symbol}`).catch(() => ({ data: { success: false } })),
        ]);

        if (tickerRes.data.success) {
            const p = tickerRes.data.data;
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
                reserveBase: p.reserve_base,
                reserveQuote: p.reserve_quote,
                hasLiquidity: p.has_liquidity,
            };
        }

        if (bookRes.data.success) {
            const bookData = bookRes.data.data;
            asks.value = normalizeDepth(bookData.asks).sort((a, b) => a.price - b.price);
            bids.value = normalizeDepth(bookData.bids).sort((a, b) => b.price - a.price);
        }

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
const dex = useTpixDex();
const tradingFee = useTradingFee();

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

// ยอดคงเหลือของคู่บน TPIX DEX — อ่านตรงจาก RPC ของเชน TPIX (ถูกต้องไม่ว่ากระเป๋าอยู่เชนไหน)
const dexFormBalances = ref([]);

async function fetchDexFormBalances() {
    if (!walletStore.isConnected || !isDexPair.value) {
        dexFormBalances.value = [];
        return;
    }
    try {
        const baseTok = dexToken('base');
        const quoteTok = dexToken('quote');
        if (!baseTok || !quoteTok) return;
        const [baseBal, quoteBal] = await Promise.all([
            dex.getBalance(baseTok.address),
            dex.getBalance(quoteTok.address),
        ]);
        dexFormBalances.value = [
            { symbol: baseSymbol.value, balance: baseBal },
            { symbol: quoteSymbol.value, balance: quoteBal },
        ];
    } catch {
        // อ่านไม่ได้ก็คงค่าเดิมไว้ — executeSwap เช็คยอดจริงอีกที
    }
}

// ฟอร์มโหมด onchain ใช้ยอดจากเชนที่เทรดจริง (BSC หรือ TPIX), โหมดอื่นใช้ยอดจาก wallet chain ปัจจุบัน (เดิม)
const formBalances = computed(() => {
    if (tradeFormMode.value !== 'onchain') return balances.value;
    return isDexPair.value ? dexFormBalances.value : bscFormBalances.value;
});

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
        let q;
        if (isDexPair.value) {
            const fromTok = dexToken(form.side === 'buy' ? 'quote' : 'base');
            const toTok = dexToken(form.side === 'buy' ? 'base' : 'quote');
            q = await dex.getTradeQuote(fromTok, toTok, inputAmount, Number(form.slippage) || 0.5);
        } else {
            // ใช้ token ที่ตรวจ decimals จาก on-chain แล้ว — กัน preview เพี้ยนถ้าค่า static ผิด
            const [fromTok, toTok] = await Promise.all([
                getVerifiedTradeToken(fromSym),
                getVerifiedTradeToken(toSym),
            ]);
            q = await swap.getQuote(fromTok, toTok, inputAmount);
        }
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
        if (isDexPair.value) {
            await executeDexMarketOrder(order);
        } else {
            await executeBscMarketOrder(order);
        }
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

    // ใบอนุญาตวางไม้ที่ขอไว้ — ต้องคืนเงินถ้าไม้ไม่ได้ลง (ดู finally)
    let ticket = null;

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

        /*
         * 6) ขอใบอนุญาตวางไม้ — ค่าบริการถูกเก็บตรงนี้
         *
         * ⚠️ ต้องขอตรงนี้ ไม่ใช่ตั้งแต่ต้น
         *    ขั้น 1-5 ล้มด้วยเหตุที่ไม่เกี่ยวกับค่าบริการได้ (ไม่มีสภาพคล่อง ·
         *    ราคาเพี้ยน · ผู้ใช้กดยกเลิกตอนสลับเชนหรือ approve) เก็บเงินก่อน
         *    แล้วคืนวนไปมาทุกครั้งไม่มีประโยชน์กับใคร
         *
         * ⚠️ ได้ใบแล้วต้องปิดบัญชีให้จบทุกทาง — สำเร็จ = ใช้ตั๋ว · ล้ม = คืนเงิน
         *    ปล่อยค้างเมื่อไหร่ = ผู้ใช้เสีย TPIX โดยไม่ได้อะไรเลย
         */
        const feeQuoteEnabled = tradingFee.currentQuote.value?.enabled === true;
        const orderValueUsd = Number(order.orderValueUsd) > 0 ? Number(order.orderValueUsd) : totalVal;

        if (feeQuoteEnabled && orderValueUsd > 0) {
            orderMessage.value = t('trade.status.preparing');
            ticket = await tradingFee.issueTicket({
                wallet: walletStore.address,
                pair: currentPair.value,
                side: order.side,
                orderValueUsd,
                chainId: BSC_CHAIN_ID,
                method: order.feeMethod === 'onchain' ? 'onchain' : 'tpix_credit',
            });

            // ไม่ได้ใบ = วางไม้ไม่ได้ ตามที่เจ้าของกำหนด — บอกเหตุผลตรงๆ
            if (!ticket) {
                throw friendly(tradingFee.error.value || 'ขอใบอนุญาตวางไม้ไม่สำเร็จ');
            }
        }

        // 7) ส่ง swap จริง — executeSwap เก็บ platform fee + บันทึก backend ให้แล้ว
        //    slippage ที่ผู้ใช้เลือกในฟอร์มมีผลจริงกับ minOut ที่ส่งเข้า router
        orderMessage.value = order.side === 'buy' ? t('trade.status.confirmBuy') : t('trade.status.confirmSell');
        const slippage = Number.isFinite(Number(order.slippage)) && order.slippage !== null
            ? Number(order.slippage)
            : quote.slippage;
        const result = await swap.executeSwap(fromTok, toTok, inputAmount, quote, slippage);

        // ไม้ลงจริงแล้ว — ปิดใบอนุญาต (ตั้งค่า null กัน finally คืนเงินซ้ำ)
        if (ticket) {
            await tradingFee.consumeTicket(walletStore.address, ticket.uuid, result?.hash || null);
            ticket = null;
        }

        orderStatus.value = 'success';
        orderMessage.value = order.side === 'buy'
            ? t('trade.status.bought', { amount: fmtQty(quote.netOutput), symbol: toSym })
            : t('trade.status.sold', { amount: fmtQty(inputAmount), from: fromSym, out: fmtQty(quote.netOutput), to: toSym });
        orderTxUrl.value = result?.url || null;

        playTradeSound();
        fetchBscFormBalances();
        fetchBalances();
        myTrades.load(true);   // ไม้ที่เพิ่งวางต้องโผล่บนกราฟทันที
    } catch (err) {
        orderStatus.value = 'error';
        // error ที่ตั้งใจ throw เองมีข้อความพร้อมแสดง — นอกนั้น useSwap map ให้แล้ว
        orderMessage.value = err?.isFriendly ? err.message : (swap.error.value || t('trade.status.failed'));
        playErrorSound();
    } finally {
        /*
         * ⚠️ ใบอนุญาตที่ยังค้าง = เงินที่หักไปแล้วแต่ไม่มีไม้ลง ต้องคืนทุกกรณี
         *
         * อยู่ใน finally ไม่ใช่ catch เพราะทางที่ออกจาก try ได้มีมากกว่าการ throw
         * (ผู้ใช้กดยกเลิกในกระเป๋าเป็นเคสที่เกิดบ่อยที่สุด และมาถึงตรงนี้เสมอ)
         *
         * ตัวเก็บกวาดฝั่งเซิร์ฟเวอร์คืนให้อยู่แล้วเมื่อตั๋วหมดอายุ — ตรงนี้คือคืนทันที
         * ไม่ให้ผู้ใช้ต้องรอเห็นยอดหายไป 15 นาทีแล้วค่อยกลับมา
         */
        if (ticket) {
            await tradingFee.refundTicket(walletStore.address, ticket.uuid, 'ไม้ไม่ได้ลง');
            ticket = null;
        }

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
 * Market order เทรดจริงบนเชน TPIX ผ่านพูล TPIX DEX
 * ลำดับ: สลับเชนเป็น TPIX → quote จริงจาก router → กันไม้ที่ดันราคาพูลเกินไป
 * → approve (ถ้าจำเป็น) → ใบอนุญาตวางไม้ → swap → refresh ยอด
 *
 * ต่างจาก BSC: ค่าธรรมเนียม 0.3% อยู่ในพูลแล้ว ไม่มีธุรกรรมโอนค่าธรรมเนียมแยก
 * และไม่เทียบกับราคา Binance เพราะพูลนี้เองคือตลาดของคู่นี้
 */
async function executeDexMarketOrder(order) {
    if (order.type !== 'market') {
        showOrderError(t('trade.status.limitSoon'));
        return;
    }

    const amountVal = parseFloat(order.amount) || 0;
    const totalVal = parseFloat(String(order.total).replace(/,/g, '')) || 0;
    const inputAmount = order.side === 'buy' ? totalVal : amountVal;
    if (inputAmount <= 0) {
        showOrderError(t('trade.enterAmount'));
        return;
    }

    const fromTok = dexToken(order.side === 'buy' ? 'quote' : 'base');
    const toTok = dexToken(order.side === 'buy' ? 'base' : 'quote');
    if (!fromTok || !toTok) {
        showOrderError(t('trade.status.failed'));
        return;
    }

    isSubmitting.value = true;
    orderStatus.value = 'executing';
    orderMessage.value = t('trade.status.preparing');
    orderTxUrl.value = null;

    let ticket = null;

    try {
        // 1) กระเป๋าต้องอยู่บนเชน TPIX — สลับให้ (ผู้ใช้กดยืนยันในกระเป๋า)
        if (Number(walletStore.chainId) !== TPIX_CHAIN_ID) {
            orderMessage.value = t('trade.status.switchingChainTpix');
            try {
                await walletStore.switchChain(TPIX_CHAIN_ID);
            } catch {
                throw friendly(t('trade.status.switchCancelledTpix'));
            }
            if (Number(walletStore.chainId) !== TPIX_CHAIN_ID) {
                throw friendly(t('trade.status.switchToTpix'));
            }
        }

        // 2) Quote จริงจาก router ของพูล
        const slippage = Number.isFinite(Number(order.slippage)) && order.slippage !== null
            ? Number(order.slippage)
            : 0.5;
        const quote = await dex.getTradeQuote(fromTok, toTok, inputAmount, slippage);
        if (!quote) {
            throw friendly(dex.error.value || t('trade.status.noLiquidity'));
        }

        // 3) กันไม้ที่ดันราคาพูลเกิน 10% — พูลบางเกินกว่าจะรับไม้ขนาดนี้
        if (quote.priceImpact > 10) {
            throw friendly(t('trade.status.priceImpact', { impact: quote.priceImpact.toFixed(1) }));
        }

        // 4) Approve router ถ้า allowance ไม่พอ (เฉพาะโทเคน ไม่ใช่ TPIX)
        if (!fromTok.native) {
            const needs = await dex.needsApproval(fromTok.address, inputAmount, fromTok.decimals);
            if (needs) {
                orderMessage.value = t('trade.status.approving', { symbol: fromTok.symbol });
                const ok = await dex.approveToken(fromTok.address, inputAmount, fromTok.decimals);
                if (!ok) throw friendly(dex.error.value || t('trade.status.failed'));
            }
        }

        // 5) ใบอนุญาตวางไม้ — ค่าบริการถูกเก็บตรงนี้ (คืนใน finally ถ้าไม้ไม่ได้ลง)
        const feeQuoteEnabled = tradingFee.currentQuote.value?.enabled === true;
        const orderValueUsd = Number(order.orderValueUsd) > 0 ? Number(order.orderValueUsd) : totalVal;
        if (feeQuoteEnabled && orderValueUsd > 0) {
            orderMessage.value = t('trade.status.preparing');
            ticket = await tradingFee.issueTicket({
                wallet: walletStore.address,
                pair: currentPair.value,
                side: order.side,
                orderValueUsd,
                chainId: TPIX_CHAIN_ID,
                method: order.feeMethod === 'onchain' ? 'onchain' : 'tpix_credit',
            });
            if (!ticket) {
                throw friendly(tradingFee.error.value || 'ขอใบอนุญาตวางไม้ไม่สำเร็จ');
            }
        }

        // 6) ส่ง swap จริง — บันทึกประวัติฝั่งเซิร์ฟเวอร์ให้แล้ว
        orderMessage.value = order.side === 'buy' ? t('trade.status.confirmBuy') : t('trade.status.confirmSell');
        const result = await dex.executeTradeSwap(fromTok, toTok, inputAmount, quote, slippage);

        if (ticket) {
            await tradingFee.consumeTicket(walletStore.address, ticket.uuid, result?.hash || null);
            ticket = null;
        }

        orderStatus.value = 'success';
        orderMessage.value = order.side === 'buy'
            ? t('trade.status.boughtTpix', { amount: fmtQty(quote.netOutput), symbol: toTok.symbol })
            : t('trade.status.sold', { amount: fmtQty(inputAmount), from: fromTok.symbol, out: fmtQty(quote.netOutput), to: toTok.symbol });
        orderTxUrl.value = result?.url || null;

        playTradeSound();
        fetchDexFormBalances();
        fetchBalances();
        fetchDexData();
        myTrades.load(true);
    } catch (err) {
        orderStatus.value = 'error';
        orderMessage.value = err?.isFriendly ? err.message : (dex.error.value || t('trade.status.failed'));
        playErrorSound();
    } finally {
        if (ticket) {
            await tradingFee.refundTicket(walletStore.address, ticket.uuid, 'ไม้ไม่ได้ลง');
            ticket = null;
        }

        isSubmitting.value = false;
        clearTimeout(toastTimer);
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
        myTrades.load(true);   // ไม้ที่เพิ่งวางต้องโผล่บนกราฟทันที
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

/** เลือกฟีดตามชนิดคู่ — TPIX DEX / TPIX ภายใน (ก่อน DEX) / Binance */
async function startFeeds() {
    stopFeeds();
    isLoading.value = true;
    if (isDexPair.value) {
        await fetchDexData();
        dexRefreshInterval = setInterval(fetchDexData, 5000);
    } else if (isTPIXPair.value) {
        // TPIX pair: fetch from internal API + auto-refresh
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
}

function stopFeeds() {
    if (dexRefreshInterval) clearInterval(dexRefreshInterval);
    if (tpixRefreshInterval) clearInterval(tpixRefreshInterval);
    dexRefreshInterval = null;
    tpixRefreshInterval = null;
    disconnectWebSocket();
}

onMounted(async () => {
    measureBoard();
    observeAbove();
    wideQuery?.addEventListener('change', measureBoard);
    window.addEventListener('resize', measureBoard);
    // ฟอนต์ไทยโหลดเสร็จทีหลัง แล้วความสูงของแถบหัวเปลี่ยน — วัดใหม่อีกรอบ
    document.fonts?.ready.then(measureBoard).catch(() => {});

    // ต้องรู้ก่อนว่าคู่นี้อยู่เชนไหนและ DEX พร้อมไหม ถึงจะเลือกฟีดได้ถูก
    await Promise.all([loadDexConfig(), loadPairMeta()]);
    await startFeeds();

    if (walletStore.isConnected) {
        fetchBalances();
        fetchBscFormBalances();
        fetchDexFormBalances();
        loadChartMarkerSources();
    }

    startMarkerRefresh();
    nextTick(measureBoard);
});

/**
 * โหลดที่มาของป้ายบนกราฟ — ไม้ของบอท (พอร์ตทดลอง) + ไม้ที่เราวางเอง.
 *
 * ทั้งสองตัวเงียบเมื่อกระเป๋ายังไม่ยืนยัน (403) จึงไม่ต้องดักอะไรเพิ่มตรงนี้
 */
function loadChartMarkerSources() {
    bot.loadDemo();
    bot.loadTrades(currentPair.value);
    myTrades.load();
}

/*
 * บอทคลาวด์เข้าไม้เองที่ฝั่งเซิร์ฟเวอร์ หน้าเว็บจึงไม่มีทางรู้ว่ามีไม้ใหม่
 * ถ้าไม่ถามเป็นระยะ — ป้ายบนกราฟจะค้างจนกว่าผู้ใช้จะรีเฟรชหน้าเอง
 *
 * 60 วิพอสำหรับรอบบอทที่เร็วที่สุด (VIP 1 นาที) และไม่ถี่จนกวนเซิร์ฟเวอร์
 */
let markerRefreshInterval = null;

function startMarkerRefresh() {
    stopMarkerRefresh();
    markerRefreshInterval = setInterval(() => {
        if (walletStore.isConnected) loadChartMarkerSources();
    }, 60_000);
}

function stopMarkerRefresh() {
    if (markerRefreshInterval) clearInterval(markerRefreshInterval);
    markerRefreshInterval = null;
}

// เชื่อม wallet ทีหลัง / สลับ address → โหลดยอด BSC สำหรับฟอร์มเทรดใหม่
watch(() => walletStore.address, () => {
    fetchBscFormBalances();
    fetchDexFormBalances();
    // ป้ายบนกราฟผูกกับกระเป๋า — สลับกระเป๋าแล้วต้องไม่ค้างไม้ของคนก่อน
    loadChartMarkerSources();
});

// แถบแจ้งเตือนโผล่/หาย หรือสลับโหมด → ตำแหน่งบนสุดของกระดานขยับ ต้องวัดใหม่
watch([dataError, tradeFormMode, () => layout.fitScreen.value], () => {
    nextTick(measureBoard);
});

// เปลี่ยนคู่เทรด → ราคาที่เคยคลิกไว้เป็นของคู่เดิม ต้องล้างทิ้ง
watch(currentPair, async () => {
    selectedPrice.value = null;
    isChartFullscreen.value = false;
    // คู่ใหม่อาจอยู่คนละเชน — โหลดข้อมูลคู่ใหม่แล้วสลับฟีดให้ตรง
    await loadPairMeta();
    await startFeeds();
    fetchDexFormBalances();
    // ไม้ของบอทผูกกับคู่ — สลับคู่แล้วต้องโหลดชุดใหม่ ไม่งั้นป้ายของคู่เก่าค้างจนรอบรีเฟรชถัดไป
    bot.loadTrades(currentPair.value);
});

onUnmounted(() => {
    stopFeeds();
    clearTimeout(previewTimer);
    clearTimeout(toastTimer);
    wideQuery?.removeEventListener('change', measureBoard);
    window.removeEventListener('resize', measureBoard);
    topObserver?.disconnect();
    topMutations?.disconnect();
    topObserver = null;
    topMutations = null;
    stopMarkerRefresh();
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

            <!-- แถบบอกโหมดเทรด: on-chain จริงบน BSC หรือรอ TPIX Chain -->
            <div v-if="tradeFormMode === 'onchain'" class="relative mb-3 px-3 py-2 rounded-xl bg-primary-500/10 border border-primary-500/20 text-primary-300 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <span>{{ t(isDexPair ? 'trade.notice.onchainTpix' : 'trade.notice.onchain') }}</span>
            </div>
            <div v-else-if="isTpixChainPair && pairMetaLoaded" class="relative mb-3 px-3 py-2 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ t('trade.notice.tpixDexPending') }}</span>
            </div>

            <!-- ── ผัง 4 คอลัมน์ ────────────────────────────────────────────────
                 การ์ดทุกใบเขียนครั้งเดียวใน loop ของคอลัมน์ — ย้ายไปคอลัมน์ไหน
                 ก็ render ที่นั่น ไม่ต้องเขียนซ้ำ 3 ชุดให้หลุดกันภายหลัง -->
            <div
                ref="board"
                class="relative grid gap-3 grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] trade-board"
                :class="packed ? 'items-stretch' : 'items-start'"
                :style="boardStyle"
            >
                <div
                    v-for="col in renderedColumns"
                    :key="col"
                    :class="[
                        'contents lg:flex lg:flex-col lg:gap-3 lg:min-w-0',
                        packed && 'lg:min-h-0 lg:overflow-y-auto lg:overflow-x-hidden custom-scrollbar',
                        columnClass[col],
                    ]"
                    @dragover.prevent="onColumnDragOver"
                    @drop="onColumnDrop(col)"
                >
                    <!--
                        ⭐ ที่วางสำหรับคอลัมน์ว่าง — โผล่เฉพาะตอนกำลังลาก

                        ถ้าไม่มีอันนี้ พอผู้ใช้ลากการ์ดใบสุดท้ายออกจากคอลัมน์ คอลัมน์นั้น
                        จะยุบหายไปพร้อมกับ "ที่รับวาง" ของมัน แล้วจะเอาการ์ดกลับเข้าไป
                        ไม่ได้อีกเลย — ผังพังถาวรโดยที่ผู้ใช้ไม่ได้ทำอะไรผิด
                    -->
                    <div
                        v-if="isDragging && !layout.visible.value[col].length"
                        class="hidden lg:flex items-center justify-center rounded-2xl border-2 border-dashed border-primary-500/40 bg-primary-500/[0.04] min-h-[160px] text-[10px] text-primary-300/70 text-center px-1 leading-tight"
                    >
                        {{ t('trade.layout.dropHere') }}
                    </div>

                    <!--
                        แถว = ชั้นกลางระหว่างคอลัมน์กับการ์ด

                        data-trade-row ให้ RowResizer หากล่องของทุกแถวในคอลัมน์เจอ
                        เพื่อวัดความสูงจริงตอนเริ่มลาก (querySelectorAll ที่พ่อของมัน)
                    -->
                    <template
                        v-for="(row, rowIndex) in layout.visibleRows.value[col]"
                        :key="row.join('+')"
                    >
                        <div data-trade-row :class="rowClass" :style="rowStyleFor(row)">
                            <template v-for="(cardId, cardIndex) in row" :key="cardId">
                                <!-- เส้นแบ่งซ้าย/ขวา — คั่นระหว่างการ์ดสองใบในแถวเดียวกัน -->
                                <RowSplitter v-if="cardIndex > 0" :row="row" />

                        <!-- คู่เทรด -->
                        <DraggableCard
                            v-if="cardId === 'market'"
                            card-id="market"
                            :locked="isNarrow"
                            :class="layout.stackClass('market')"
                            :style="styleFor('market', row)"
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
                            :style="styleFor('chart', row)"
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
                                :is-tpix="isTPIXPair && !isDexPair"
                                :klines-url="dexKlinesUrl"
                                :markers="chartMarkers"
                                :price-lines="botPriceLines"
                                :bot-mode="botMode"
                                class="flex-1 relative z-10"
                                @update:bot-mode="botMode = $event"
                            />
                        </DraggableCard>

                        <!-- ฟอร์มซื้อ/ขาย -->
                        <DraggableCard
                            v-else-if="cardId === 'form'"
                            card-id="form"
                            :locked="isNarrow"
                            :class="layout.stackClass('form')"
                            :style="styleFor('form', row)"
                            body-class="overflow-y-auto custom-scrollbar"
                        >
                            <TradeForm
                                :symbol="currentPair"
                                :ticker-price="ticker?.price || 0"
                                :selected-price="selectedPrice"
                                :is-wallet-connected="walletStore.isConnected"
                                :wallet-address="walletStore.address"
                                :is-submitting="isSubmitting"
                                :balances="formBalances"
                                :mode="tradeFormMode"
                                :chain-id="tradeChainId"
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
                            :style="styleFor('orders', row)"
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
                            :style="styleFor('book', row)"
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
                            :style="styleFor('ai', row)"
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
                            :style="styleFor('trades', row)"
                        >
                            <RecentTrades
                                :symbol="currentPair"
                                :trades="trades"
                                :is-loading="isLoading"
                                @select-price="handleSelectPrice"
                            />
                        </DraggableCard>
                            </template>
                        </div>

                        <!--
                            เส้นแบ่งบน/ล่าง — มีเฉพาะโหมดพอดีหน้าจอ
                            โหมดเลื่อนหน้าความสูงมาจากค่าคงที่ของการ์ดกับปุ่มเลือกขนาดกราฟ
                            ลากตรงนี้จะไม่มีความสูงให้สองแถวแย่งกัน
                        -->
                        <RowResizer
                            v-if="packed && rowIndex < layout.visibleRows.value[col].length - 1"
                            :rows="layout.visibleRows.value[col]"
                            :index="rowIndex"
                        />
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
