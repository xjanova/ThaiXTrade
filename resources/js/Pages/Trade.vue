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
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TradingChart from '@/Components/Trading/TradingChart.vue';
import OrderBook from '@/Components/Trading/OrderBook.vue';
import TradeForm from '@/Components/Trading/TradeForm.vue';
import RecentTrades from '@/Components/Trading/RecentTrades.vue';
import OpenOrders from '@/Components/Trading/OpenOrders.vue';
import TradeHistory from '@/Components/Trading/TradeHistory.vue';
import MarketListPanel from '@/Components/Trading/MarketListPanel.vue';
import AiTradeCard from '@/Components/Trading/AiTradeCard.vue';
import DraggableCard from '@/Components/Trading/DraggableCard.vue';
import RowSplitter from '@/Components/Trading/RowSplitter.vue';
import RowResizer from '@/Components/Trading/RowResizer.vue';
import PageArt from '@/Components/PageArt.vue';
import { useBinanceData } from '@/Composables/useBinanceData';
import { useSwap, BSC_PRICE_IMPACT_BLOCK_PCT } from '@/Composables/useSwap';
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
import { showToast, dismissToast } from '@/Composables/useToasts';

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

/*
 * คู่ที่ "ทะเบียน JS" รู้จักบน BSC — ที่อยู่ + symbol ตรวจบนเชนได้ก่อนเทรด
 *
 * ⚠️ quote ต้องเป็น USDT ตามทะเบียนเท่านั้น
 *    เดิมรับทุกคู่ที่ทั้งสองเหรียญอยู่ในทะเบียน — /trade/ETH-BTC (พิมพ์ URL เอง) ก็เทรดได้
 *    แต่ช่อง Total ปัด 2 ตำแหน่ง = จ่าย BTC เกินที่ตั้งใจหลายเปอร์เซ็นต์
 */
const BSC_QUOTE_SYMBOL = 'USDT';
const bscRegistryPair = computed(() =>
    !isTPIXPair.value
    && quoteSymbol.value.toUpperCase() === BSC_QUOTE_SYMBOL
    && baseSymbol.value.toUpperCase() !== BSC_QUOTE_SYMBOL
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
// โหลดทะเบียนคู่ไม่สำเร็จ (ต่างจาก "ไม่มีคู่นี้") — ยังตัดสินไม่ได้ว่าเทรดได้ไหม
const pairMetaFailed = ref(false);
// ข้อมูลข้างบนเป็นของคู่ไหน — สลับคู่แล้วระหว่างโหลด pairMeta ยังเป็นของคู่เก่า
const pairMetaFor = ref('');

async function loadPairMeta() {
    const wanted = props.pair.toUpperCase();
    try {
        const { data } = await axios.get('/api/v1/market/pairs');
        pairMeta.value = (data?.data || []).find((p) => String(p.symbol).toUpperCase() === wanted) || null;
        pairMetaFailed.value = !data?.success;
    } catch {
        pairMeta.value = null;
        pairMetaFailed.value = true;
    } finally {
        pairMetaLoaded.value = true;
        pairMetaFor.value = wanted;
    }
}

/** ที่อยู่เหรียญเดียวกันไหม (เหรียญ native เก็บได้ทั้ง 0x000… และ 0xEeee…) */
const NATIVE_ADDRESSES = ['0x0000000000000000000000000000000000000000', '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'];
function sameTokenAddress(a, b) {
    const norm = (v) => {
        const lower = String(v || '').toLowerCase();
        return NATIVE_ADDRESSES.includes(lower) ? 'native' : lower;
    };
    return norm(a) !== '' && norm(a) === norm(b);
}

/*
 * คู่ที่ส่งเข้า PancakeSwap ได้จริง — เชนของคู่ต้องมาจากเซิร์ฟเวอร์ ไม่ใช่เดาจากชื่อ
 *
 * ⚠️ เดิมดูแค่ว่า symbol อยู่ในทะเบียน JS — แอดมินเพิ่มคู่ BTC-USDT บนเชนอื่น (1/137)
 *    หรือปิดคู่บน BSC ฉุกเฉิน หน้าเทรดก็ยังส่งเข้า PancakeSwap อยู่ดี
 *    ต้องเป็น: เชน 56 · execution_mode = onchain · ที่อยู่เหรียญตรงกับทะเบียน JS
 */
const isBscTradable = computed(() => {
    const m = pairMeta.value;
    if (!bscRegistryPair.value || !m) return false;
    if (Number(m.network_chain_id) !== BSC_CHAIN_ID || m.execution_mode !== 'onchain') return false;

    return sameTokenAddress(m.base_address, getBscTradeToken(baseSymbol.value)?.address)
        && sameTokenAddress(m.quote_address, getBscTradeToken(quoteSymbol.value)?.address);
});

/** ยังรอ (หรือโหลดไม่สำเร็จ) ทะเบียนคู่ของคู่ที่ทะเบียน JS รู้จัก — โชว์ฟอร์มไว้แต่ยังส่งไม่ได้ */
const bscPairPending = computed(() =>
    bscRegistryPair.value && !pairMeta.value && (!pairMetaLoaded.value || pairMetaFailed.value)
);

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

/*
 * ด่านยืนยันตัวตนของ "เทรด/สวอป" (แอดมินเปิด/ปิดได้) — สถานะมากับทุกหน้า (HandleInertiaRequests)
 * ต้องบอกก่อนกด: สวอปบน BSC ออกจากกระเป๋าตรง ด่านฝั่งเซิร์ฟเวอร์จึงไปโผล่ตอนบันทึกไม้
 * ซึ่งเงินออกไปแล้ว — ไม้จริงจะหายจากประวัติ
 */
const page = usePage();
const kycBlocked = computed(() => {
    const gate = page.props.kyc?.features?.trading;
    return !!gate?.required && !gate?.passed;
});

// โหมดของ TradeForm:
//  onchain  = market order execute จริง (PancakeSwap บน BSC หรือพูล TPIX DEX บนเชน TPIX)
//  disabled = คู่บนเชน TPIX ที่ DEX ยังไม่ deploy หรือคู่ที่ไม่มี token บน BSC — เห็นไว้ก่อน กดไม่ได้
// ระหว่างรอทะเบียนคู่ของคู่ BSC = onchain แต่ปุ่มปิด (tradeUnavailableReason) ไม่ให้ฟอร์มกระพริบเป็น "เร็วๆ นี้"
const tradeFormMode = computed(() => ((isBscTradable.value || isDexPair.value || bscPairPending.value) ? 'onchain' : 'disabled'));

/*
 * ป้ายบอกโหมดเทรดของคู่นี้ (เทรดบน BSC / บนเชน TPIX / ราคาอ้างอิง / รอ DEX)
 *
 * เจ้าของสั่ง (2026-10-28): ป้ายเตือนด้านบนต้องเป็นบอลลูนลอย หายเองหรือกดปิดได้
 * เดิมเป็นแถบในผัง กินความสูงกระดานตลอดเวลา — ตอนนี้ลอยผ่าน showToast 10 วิ
 * ฟอร์มเทรดยังบอกเหตุผลเองตอนกดไม่ได้ (tradeUnavailableReason) ป้ายหายไปก็ไม่เสียข้อมูล
 */
const pairNotice = computed(() => {
    if (!pairMetaLoaded.value || pairMetaFor.value !== props.pair.toUpperCase()) return null;
    if (tradeFormMode.value === 'onchain') {
        return { key: isDexPair.value ? 'trade.notice.onchainTpix' : 'trade.notice.onchain', type: 'info' };
    }
    if (isTpixChainPair.value) {
        // คู่ดัชนี (execution_mode = index) เป็นราคาอ้างอิงถาวร ไม่ใช่ "รอ DEX"
        return { key: pairMeta.value?.execution_mode === 'index' ? 'trade.notice.indexOnly' : 'trade.notice.tpixDexPending', type: 'warning' };
    }
    return null;
});

/**
 * โทเคนของคู่บน TPIX DEX ในรูปที่ useTpixDex ใช้ — สร้างจากข้อมูลเซิร์ฟเวอร์ ไม่เดา
 * ⚠️ เดิม decimals ที่ไม่มี/เป็น 0 ถูกเติมเป็น 18 ให้เอง (`|| 18`) — USDT 6 ตำแหน่งจะกลายเป็นจำนวนผิดล้านล้านเท่า
 *    decimals ใช้ไม่ได้ = null (ไม่มีโทเคน = ไม่มีราคา/ไม่ส่งไม้) · ก่อนส่งจริงตรวจกับบนเชนอีกชั้น (verifyTokenDecimals)
 */
function dexToken(side) {
    const m = pairMeta.value;
    if (!m) return null;
    const isBase = side === 'base';
    const address = toDexAddress(isBase ? m.base_address : m.quote_address);
    const raw = isBase ? m.base_decimals : m.quote_decimals;
    const decimals = raw === null || raw === undefined || raw === '' ? NaN : Number(raw);
    if (!Number.isInteger(decimals) || decimals < 0 || decimals > 36) return null;
    return {
        symbol: isBase ? baseSymbol.value : quoteSymbol.value,
        address,
        decimals,
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
// ความกว้างจริงของกระดาน ใช้ตัดสินว่าคอลัมน์ข้างขยายได้แค่ไหน
// อ่านจากกล่องจริง ไม่ใช่ innerWidth เพราะแถบข้าง/สกรอลบาร์กินที่ไปแล้ว
const boardWidth = ref(0);

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
    const rect = board.value.getBoundingClientRect();
    const viewportTop = rect.top;
    boardHeight.value = Math.max(MIN_BOARD_HEIGHT, window.innerHeight - viewportTop - 14);
    // วัดความกว้างในรอบเดียวกัน — ตัวสังเกตตัวเดิมยิงอยู่แล้วทุกครั้งที่ผังขยับ
    // จึงไม่ต้องตั้ง ResizeObserver อีกตัวมาซ้อนให้เสี่ยงลูปวัด-วาด-วัด
    boardWidth.value = Math.round(rect.width);
    nextTick(measureHandles);
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

/**
 * ── ด้ามลากปรับความกว้างคอลัมน์ (เฉพาะจอกว้าง) ──────────────────────────────
 *
 * ทำเป็น "ชั้นซ้อน" ที่วัดตำแหน่งจากคอลัมน์จริง ไม่ใช่แทรกเป็นลูกของกริด
 * เพราะคอลัมน์เป็นลูกโดยตรงของ grid — ถ้าแทรกด้ามเข้าไปอีกใบ มันจะกลายเป็น
 * ราง (track) เพิ่มขึ้นมา แล้ว gridTemplateColumns ที่คำนวณไว้จะเลื่อนผิดทั้งแถบ
 *
 * และไม่วางด้ามไว้ในคอลัมน์ด้วย เพราะโหมดพอดีจอทำให้คอลัมน์เป็นกล่องเลื่อน
 * (overflow-y-auto) ลูกที่ absolute จะเลื่อนหนีตามเนื้อหาไปด้วย
 */
const colEls = new Map();
const colHandles = ref([]);

function setColRef(col, el) {
    if (el) colEls.set(col, el);
    else colEls.delete(col);
}

/**
 * จับคู่ด้ามกับคอลัมน์ที่มันปรับ
 *
 * ⚠️ รอบแรกทำผิด: วางด้ามที่ขอบขวาของทุกคอลัมน์ยกเว้นใบสุดท้าย แล้วให้ด้าม
 * ปรับ "คอลัมน์ทางซ้าย" เสมอ ผลคือคอลัมน์ขวาสุดไม่มีด้ามเลย ปรับไม่ได้ถาวร
 * และด้ามที่ติดกราฟก็ไปพยายามปรับกราฟ ซึ่งเป็นรางยืดที่ปรับไม่ได้อยู่แล้ว
 *
 * กติกาใหม่: เดินจากซ้ายไปขวา แต่ละด้ามเลือก "คอลัมน์ที่ยังไม่มีใครดูแล"
 * โดยเอาฝั่งซ้ายก่อน ถ้าฝั่งซ้ายเป็นกราฟหรือมีคนดูแลแล้วค่อยเอาฝั่งขวา
 * ด้วยกติกานี้ทุกคอลัมน์ที่ปรับได้จะมีด้ามของตัวเองพอดีหนึ่งอัน
 *
 *   [คู่เทรด | กราฟ | สมุดคำสั่ง | ประวัติ]
 *      ด้าม1 ปรับคู่เทรด · ด้าม2 ปรับสมุดคำสั่ง · ด้าม3 ปรับประวัติ
 *
 * side บอกว่าด้ามอยู่ฝั่งไหนของคอลัมน์ที่มันปรับ ซึ่งกำหนดทิศทางการลาก:
 * ด้ามที่ขอบขวา ลากขวา = กว้างขึ้น · ด้ามที่ขอบซ้าย ลากขวา = แคบลง
 */
function measureHandles() {
    if (isNarrow.value || !board.value) {
        colHandles.value = [];
        return;
    }
    const base = board.value.getBoundingClientRect();
    const cols = renderedColumns.value;
    const chart = chartColumn.value;
    const taken = new Set();
    const out = [];

    for (let i = 0; i < cols.length - 1; i++) {
        const leftCol = cols[i];
        const rightCol = cols[i + 1];

        let col = null;
        let side = null;
        if (leftCol !== chart && !taken.has(leftCol)) {
            col = leftCol; side = 'right';   // ด้ามอยู่ขอบขวาของคอลัมน์นี้
        } else if (rightCol !== chart && !taken.has(rightCol)) {
            col = rightCol; side = 'left';   // ด้ามอยู่ขอบซ้ายของคอลัมน์นี้
        }
        if (!col) continue;

        const el = colEls.get(col);
        if (!el) continue;
        taken.add(col);

        const r = el.getBoundingClientRect();
        const edge = side === 'right' ? r.right : r.left;
        out.push({
            col,
            side,
            x: Math.round(edge - base.left + (side === 'right' ? 6 : -6)),
            width: Math.round(r.width),
        });
    }

    colHandles.value = out;
}

let resizing = null;

function startColumnResize(e, handle) {
    // คอลัมน์กราฟเป็นรางยืดที่กินที่เหลือ ตรึงเป็นพิกเซลแล้วจะมีที่ว่างค้างข้างขวา
    if (handle.col === chartColumn.value) return;
    const el = colEls.get(handle.col);
    if (!el) return;
    resizing = {
        col: handle.col,
        // ด้ามที่ขอบซ้ายต้องกลับทิศ: ลากขวา = ขอบซ้ายขยับเข้ามา = คอลัมน์แคบลง
        dir: handle.side === 'right' ? 1 : -1,
        startX: e.clientX,
        startW: el.getBoundingClientRect().width,
    };
    e.currentTarget.setPointerCapture?.(e.pointerId);
    e.preventDefault();
}

function onColumnResizeMove(e) {
    if (!resizing) return;
    layout.setColumnWidth(resizing.col, resizing.startW + (e.clientX - resizing.startX) * resizing.dir);
    // วัดใหม่ทันทีเพื่อให้ด้ามวิ่งตามนิ้ว ไม่ใช่กระตุกตามรอบ observer
    nextTick(measureHandles);
}

function endColumnResize() {
    if (!resizing) return;
    resizing = null;
    nextTick(measureHandles);
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
    extra: 'lg:order-5 lg:col-span-2 xl:order-5 xl:col-span-1',
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

/**
 * กระดานต้องกว้างเท่าไรถึงจะเสนอคอลัมน์ที่ 5
 *
 * ต่ำกว่านี้ 5 คอลัมน์จะเหลือใบละไม่ถึง 300px ซึ่งอ่านสมุดคำสั่งไม่ออก
 * จึงไม่เสนอให้ตอนลาก แต่ถ้าผู้ใช้เคยจัดไว้ตอนจอกว้างแล้วมาเปิดบนจอเล็ก
 * ยังต้อง render ตามปกติ ไม่งั้นการ์ดที่เขาย้ายไปจะหายไปเฉยๆ
 */
const WIDE_FOR_EXTRA = 1800;

const renderedColumns = computed(() => {
    if (!isDragging.value) return filledColumns.value;
    const roomy = boardWidth.value >= WIDE_FOR_EXTRA;
    return COLUMNS.filter(col =>
        col !== 'extra' || roomy || layout.visible.value.extra.length > 0
    );
});

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

    // ผู้ใช้ลากปรับความกว้างคอลัมน์นี้เองแล้ว — เคารพค่าที่ตั้งไว้
    const manual = layout.columnWidth.value[col];
    if (manual) return `${Math.max(min, manual)}px`;

    // เพดานยืดตามที่ว่างจริง: จอยิ่งกว้าง คอลัมน์ข้างยิ่งหายใจได้
    // เดิมตัน 340px เสมอ พอจอ 2560px กราฟเลยกินที่เหลือทั้งหมดคนเดียว
    // จนสมุดคำสั่งกับฟอร์มยังแคบเท่าจอโน้ตบุ๊ก ทั้งที่มีที่เหลือเฟือ
    //
    // ⭐ ตัวจำกัดจริงคือ "ส่วนเผื่อจาก min" ไม่ใช่เพดาน — วัดบนจอ 2400px แล้ว
    //    ได้ราง 300/280/280 ทั้งที่เพดานตั้งไว้ 460 เพราะ min+80 มาก่อนเสมอ
    //    ฉะนั้นต้องขยายส่วนเผื่อไปพร้อมกัน ไม่งั้นแก้เพดานอย่างเดียวไม่มีผลอะไร
    const wide = boardWidth.value;
    const slack = wide >= 2200 ? 240 : wide >= 1800 ? 160 : 80;
    const ceiling = wide >= 2200 ? 460 : wide >= 1800 ? 400 : 340;

    // ⚠️ เพดานต้องไม่ต่ำกว่า min: minmax(472px, 340px) จะทำให้เบราว์เซอร์ทิ้งค่า max
    //    เงียบๆ แล้วรางจะกว้างค้างที่ min โดยไม่มีใครรู้ว่าเพดานไม่ทำงาน
    const max = Math.max(min, Math.min(min + slack, ceiling));

    return `minmax(${min}px, ${max}px)`;
}).join(' '));

/**
 * แถวยุบเป็น display:contents ต่ำกว่า xl — การ์ดไหลเรียงลงมาทีละใบตามคลาส order-*
 * (จอแคบวางเคียงกันแล้วเหลือใบละ ~180px ซึ่งอ่านตัวเลขไม่ออก)
 */
const rowClass = ['contents xl:flex xl:flex-row xl:gap-3 xl:min-w-0 xl:min-h-0'];

/**
 * สัดส่วนย่อ min ของแถวในคอลัมน์ — ให้ทุกการ์ดอยู่ในจอเมื่อผู้ใช้ลากมารวมหลายใบ
 *
 * ตั้งเป็นตัวแปร CSS บนคอลัมน์แล้วให้ rowStyle เอาไปคูณ เพราะแถวไม่รู้ความสูง
 * คอลัมน์ของตัวเอง ส่วนหน้านี้รู้ (boardHeight) จึงเป็นคนบอก
 */
function columnStyle(col) {
    if (!packed.value || !boardHeight.value) return undefined;
    const scale = layout.columnMinScale(col, boardHeight.value);
    return scale < 1 ? { '--row-min-scale': String(scale) } : undefined;
}

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
    errorCode: binanceErrorCode,
    isLive: binanceLive,
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
// แท็บ Funds ต้องโชว์ยอดบน BSC ซึ่งเป็นเชนที่ไม้ลงจริง — เดิมใช้เชนที่กระเป๋าอยู่ตอนนั้น
// MetaMask เริ่มที่ Ethereum เลยเห็นยอด ETH ทั้งที่จะเทรดบน BSC (จนกว่าจะเทรดครั้งแรกแล้วสลับเชน)
const { balances, fetchBalances, needsVerification: fundsNeedVerify } = useWalletBalance({ chainId: BSC_CHAIN_ID });
const isVerifyingFunds = ref(false);

async function verifyForFunds() {
    if (isVerifyingFunds.value) return;   // กันป๊อปอัพขอลายเซ็นซ้อน
    isVerifyingFunds.value = true;
    try {
        const hasSigner = await walletStore.verifyOwnership();
        if (!hasSigner) {
            walletStore.openConnectModal();
            return;
        }
        await fetchBalances();
    } finally {
        isVerifyingFunds.value = false;
    }
}
const swap = useSwap();
const dex = useTpixDex();
const tradingFee = useTradingFee();

// ── เทรดจริงบน BSC (market order → PancakeSwap) ─────────────────────────────

// ยอดคงเหลือของ base/quote อ่านตรงจาก BSC RPC — ถูกต้องเสมอไม่ว่า wallet อยู่เชนไหน
const bscFormBalances = ref([]);
/*
 * เลขลำดับรอบอ่าน + กระเป๋าที่ยอดชุดนี้เป็นของ
 * เดิมสลับบัญชีแล้วคำขอของบัญชีเก่าที่ตอบช้ามาเขียนทับยอดของบัญชีใหม่ได้
 */
let bscBalanceSeq = 0;
let bscBalancesFor = null;

async function fetchBscFormBalances() {
    const seq = ++bscBalanceSeq;
    const owner = walletStore.address;
    if (!walletStore.isConnected || !owner || !isBscTradable.value) {
        bscFormBalances.value = [];
        bscBalancesFor = null;
        return;
    }
    // บัญชีใหม่ = ห้ามโชว์ยอดของบัญชีเดิมระหว่างรอ
    if (bscBalancesFor !== owner) bscFormBalances.value = [];
    try {
        const baseTok = getBscTradeToken(baseSymbol.value);
        const quoteTok = getBscTradeToken(quoteSymbol.value);
        const [baseBal, quoteBal] = await Promise.all([
            swap.getBalance(baseTok.address),
            swap.getBalance(quoteTok.address),
        ]);
        if (seq !== bscBalanceSeq || walletStore.address !== owner) return;
        bscFormBalances.value = [
            { symbol: baseSymbol.value, balance: baseBal },
            { symbol: quoteSymbol.value, balance: quoteBal },
        ];
        bscBalancesFor = owner;
    } catch {
        // อ่านไม่ได้ก็คงค่าเดิมไว้ — ฟอร์มยังใช้งานได้ (executeSwap เช็คยอดจริงบนเชนอีกที)
    }
}

/*
 * เหตุที่ปุ่มวางไม้ของคู่ BSC ยังกดไม่ได้ (ว่าง = กดได้) — ฟอร์มโชว์ข้อความนี้แทนปุ่มซื้อ/ขาย
 * คู่บน TPIX DEX มีด่านของตัวเอง ไม่ผ่านตรงนี้
 */
const tradeUnavailableReason = computed(() => {
    if (tradeFormMode.value !== 'onchain' || isDexPair.value) return '';
    if (bscPairPending.value) {
        return t(pairMetaLoaded.value ? 'trade.form.pairCheckFailed' : 'trade.form.pairChecking');
    }
    if (!isBscTradable.value) return t('trade.form.pairUnavailable');
    // กระเป๋าฝัง (TPIX Wallet) ใช้ได้เฉพาะเชน TPIX — สลับไป BSC ไม่ได้เลย บอกก่อนกด
    if (walletStore.isConnected && walletStore.isEmbedded) return t('trade.form.embeddedNoBsc');
    return '';
});

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

/**
 * ตัวเลขจากช่องกรอก — ตัดจุลภาคก่อนแปลงเสมอ
 *
 * ⚠️ เดิมใช้ parseFloat ตรงๆ: พิมพ์ "1,500" (เหรียญราคาจิ๋วอย่าง PEPE/SHIB) ได้ 1
 *    ฟอร์มคิด Total และค่าบริการจาก 1,500 แต่ขายจริงแค่ 1 เหรียญ
 */
function num(value) {
    return parseFloat(String(value ?? '').replace(/,/g, '')) || 0;
}

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

/**
 * ตัวหนังสือของจำนวนที่จ่ายจริง (buy = ช่อง Total, sell = ช่อง Amount) ตัดจุลภาคแล้ว
 * ส่งต่อเป็นสตริง — useSwap แปลงเป็นหน่วยเล็กสุดตรงๆ ไม่ผ่าน float
 */
function orderInputText(order) {
    return String((order.side === 'buy' ? order.total : order.amount) ?? '').replace(/,/g, '').trim();
}

/*
 * ค่าธรรมเนียมแพลตฟอร์ม % ถูกยกเว้นสำหรับไม้นี้ไหม
 * = ระบบเครดิต TPIX เปิดอยู่ และไม้นี้จ่ายผ่านใบอนุญาต (เลือกเครดิต หรือได้รับยกเว้นค่าบริการ)
 * ผู้ใช้ที่เลือกจ่ายเป็นเหรียญ → ไม่มีใบอนุญาต ค่าธรรมเนียม % ตามปกติผ่าน useSwap
 */
function platformFeeWaivedFor(feeMethod, feeQuote = tradingFee.currentQuote.value) {
    if (feeQuote?.enabled !== true) return false;
    return feeQuote.waived === true || feeMethod !== 'onchain';
}

// Preview ราคาจริงจาก router (debounce 400ms) — แสดงใน TradeForm ก่อนกดยืนยัน
const marketPreview = ref(null);
let previewTimer = null;
let previewSeq = 0;

function handleFormChange(form) {
    // ยกเลิก preview ที่รออยู่ทุกครั้ง — เดิม return ก่อน clearTimeout ตอนล้างช่องจำนวน
    // แล้ว timer เดิมยังยิงไปวาดตัวเลขของจำนวนเก่ากลับขึ้นมา
    clearTimeout(previewTimer);
    ++previewSeq;

    if (tradeFormMode.value !== 'onchain' || form.type !== 'market') {
        marketPreview.value = null;
        return;
    }
    const amountNum = num(form.amount);
    const totalNum = num(form.total);
    // buy ใช้ยอด quote (USDT) เป็น input จริง, sell ใช้ยอด base
    const inputAmount = form.side === 'buy' ? totalNum : amountNum;
    if (inputAmount <= 0) {
        marketPreview.value = null;
        return;
    }
    marketPreview.value = { loading: true };
    previewTimer = setTimeout(() => refreshMarketPreview(form, inputAmount), 400);
}

async function refreshMarketPreview(form, inputAmount) {
    const seq = ++previewSeq;
    try {
        const fromSym = form.side === 'buy' ? quoteSymbol.value : baseSymbol.value;
        const toSym = form.side === 'buy' ? baseSymbol.value : quoteSymbol.value;
        // slippage ที่เลือกในฟอร์ม (null = ใช้ค่าปริยาย) — ต้องตรงกับที่จะส่งเข้า router จริง
        const chosenSlippage = form.slippage != null && Number(form.slippage) > 0 ? Number(form.slippage) : null;
        let q;
        if (isDexPair.value) {
            const fromTok = dexToken(form.side === 'buy' ? 'quote' : 'base');
            const toTok = dexToken(form.side === 'buy' ? 'base' : 'quote');
            q = await dex.getTradeQuote(fromTok, toTok, inputAmount, chosenSlippage ?? 0.5);
        } else {
            // ใช้ token ที่ตรวจ decimals จาก on-chain แล้ว — กัน preview เพี้ยนถ้าค่า static ผิด
            const [fromTok, toTok] = await Promise.all([
                getVerifiedTradeToken(fromSym),
                getVerifiedTradeToken(toSym),
            ]);
            // ส่งตัวหนังสือของช่องกรอก (ไม่ผ่าน float) และบอกว่าค่าธรรมเนียม % ถูกยกเว้นไหม — ต้องตรงกับตอนส่งจริง
            q = await swap.getQuote(fromTok, toTok, orderInputText(form), {
                slippage: chosenSlippage,
                waiveFee: platformFeeWaivedFor(form.feeMethod),
            });
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
            feeText: q.waived
                ? t('trade.form.feePaidWithCredit')
                : `${fmtQty(q.feeAmount)} ${fromSym} (${q.feeRate}%)`,
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

    // กันอีกชั้น (ฟอร์มพาไปหน้ายืนยันอยู่แล้ว) — ห้ามปล่อยให้เงินออกแล้วบันทึกไม้ไม่ได้
    if (kycBlocked.value) {
        router.visit('/kyc');
        return;
    }

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
 * ทางจ่ายค่าบริการของไม้บน BSC (ระบบเครดิต TPIX — แอดมินเปิด/ปิดได้)
 *
 *  'none'    = ระบบปิด → ค่าธรรมเนียม % ตามปกติ (useSwap โอนเข้ากระเป๋าค่าธรรมเนียม)
 *  'onchain' = ผู้ใช้เลือกจ่ายเป็นเหรียญ → ไม่ขอใบอนุญาต ค่าธรรมเนียม % ตามปกติ
 *  'ticket'  = จ่ายด้วยเครดิต TPIX หรือไม้นี้ได้รับยกเว้น → ขอใบอนุญาต และ "ไม่" หัก % ซ้ำ
 *
 * ⚠️ FAIL-CLOSED: ระบบเปิดอยู่แต่ยังไม่มีใบเสนอราคา (ผู้ใช้กดเร็วกว่า debounce ของฟอร์ม)
 *    ต้องขอใหม่ก่อนตัดสิน — เดิมถือว่า "ปิด" แล้ววางไม้โดยไม่มีใบอนุญาต
 *    ใช้ทั้งไม้ BSC และไม้บนพูล TPIX (เดิมฝั่ง TPIX อ่าน currentQuote ตรงๆ = ไม่มีใบเสนอราคาก็วางไม้ฟรี · ออดิท L2)
 */
async function resolveFeePlan(order, owner, orderValueUsd, chainId = BSC_CHAIN_ID) {
    let feeQuote = tradingFee.currentQuote.value;

    if (!feeQuote) {
        await tradingFee.loadTiers();
        if (tradingFee.feeEnabled.value === true && orderValueUsd > 0) {
            feeQuote = await tradingFee.quote({
                wallet: owner,
                orderValueUsd,
                chainId,
                pair: currentPair.value,
            });
            if (!feeQuote) throw friendly(t('trade.status.feeQuoteFailed'));
        }
    }

    if (feeQuote?.enabled !== true || !(orderValueUsd > 0)) return { mode: 'none' };
    return platformFeeWaivedFor(order.feeMethod, feeQuote) ? { mode: 'ticket' } : { mode: 'onchain' };
}

/**
 * ปิดใบอนุญาตพร้อม tx hash — ลองซ้ำหนึ่งครั้งถ้าครั้งแรกล้ม (เน็ตสะดุด)
 * ปิดไม่ได้เลยก็ไม่เสียหาย: ใบที่ผูกธุรกรรมไว้แล้ว ตัวเก็บกวาดฝั่งเซิร์ฟเวอร์ถามผลบนเชนแล้วปิดให้เอง
 */
async function consumeTicketWithRetry(owner, uuid, txHash) {
    for (let attempt = 0; attempt < 2; attempt++) {
        const done = await tradingFee.consumeTicket(owner, uuid, txHash);
        if (done) return done;
    }
    return null;
}

/**
 * ผูกใบอนุญาตกับธุรกรรมของไม้ — เรียกทันทีที่กระเป๋าส่งธุรกรรมออก (ลองซ้ำหนึ่งครั้ง)
 * ผูกแล้ว เซิร์ฟเวอร์ตัดสินตั๋วจากผลบนเชน: ไม้ลง = ปิด · ไม้ไม่ลง = คืน — แม้ผู้ใช้ปิดแท็บไปก่อน
 */
async function bindTicketWithRetry(owner, uuid, txHash) {
    if (!txHash) return null;
    for (let attempt = 0; attempt < 2; attempt++) {
        const bound = await tradingFee.bindTicket(owner, uuid, txHash);
        if (bound) return bound;
    }
    return null;
}

/**
 * คืนค่าบริการของไม้ที่ไม่ได้ลง
 * ใบที่ผูกธุรกรรมแล้วคืนได้เมื่อเซิร์ฟเวอร์เห็นผล revert บนเชน — โหนดของเซิร์ฟเวอร์ตามหัวช้ากว่ากระเป๋าได้
 * จึงถามซ้ำสั้นๆ · ไม่ทันก็ไม่เสียเงิน ตัวเก็บกวาดคืนให้เมื่อเชนยืนยัน
 */
async function refundTicketWhenSettled(owner, uuid) {
    for (let attempt = 0; attempt < 3; attempt++) {
        if (attempt > 0) await new Promise(resolve => setTimeout(resolve, 3000));
        const result = await tradingFee.refundTicket(owner, uuid, 'ไม้ไม่ได้ลง');
        if (!result?.pending) return result;
    }
    return null;
}

/** บัญชีในกระเป๋ายังเป็นบัญชีที่เริ่มไม้นี้ไหม */
function isSameWallet(owner) {
    return String(walletStore.address || '').toLowerCase() === String(owner || '').toLowerCase();
}

/**
 * Market order เทรดจริงบน BSC ผ่าน PancakeSwap
 * ลำดับ: ตรวจ token on-chain → สลับเชนเป็น BSC → ทางจ่ายค่าบริการ → quote จริง
 * → กันราคาเพี้ยน/ดันราคาพูล → กระเป๋าค่าธรรมเนียมพร้อม → approve (ถ้าจำเป็น)
 * → ใบอนุญาต (ถ้าจ่ายด้วยเครดิต) → swap → refresh ยอด
 */
async function executeBscMarketOrder(order) {
    if (order.type !== 'market') {
        showOrderError(t('trade.status.limitSoon'));
        return;
    }

    // กันอีกชั้น (ปุ่มถูกปิดอยู่แล้ว): คู่ยังไม่ยืนยันว่าอยู่บน BSC / กระเป๋าฝังที่สลับเชนไม่ได้
    if (tradeUnavailableReason.value) {
        showOrderError(tradeUnavailableReason.value);
        return;
    }

    const totalVal = num(order.total);
    // buy จ่าย quote token (USDT) ตามช่อง Total, sell จ่าย base token ตามช่อง Amount
    // ส่งต่อเป็นตัวหนังสือ — จำนวนจริงคิดเป็นหน่วยเล็กสุดใน useSwap (ไม่ผ่าน float)
    const inputText = orderInputText(order);
    const inputAmount = num(inputText);
    if (inputAmount <= 0) {
        showOrderError(t('trade.enterAmount'));
        return;
    }

    // timer ของ toast ครั้งก่อนต้องไม่มาลบ toast "กำลังทำรายการ" ของรอบนี้กลางทาง
    clearTimeout(toastTimer);
    isSubmitting.value = true;
    orderStatus.value = 'executing';
    orderMessage.value = t('trade.status.preparing');
    orderTxUrl.value = null;

    // จำกระเป๋าไว้ตั้งแต่ต้น — สลับบัญชีกลางทางแล้ว consume/refund ต้องยังหาใบอนุญาตเจอ
    const owner = walletStore.address;

    // ใบอนุญาตวางไม้ที่ขอไว้ — ต้องคืนเงินถ้าไม้ไม่ได้ลง (ดู finally)
    let ticket = null;
    // คำขอผูกใบอนุญาตกับธุรกรรม (ยิงตอนกระเป๋าส่งธุรกรรมออก) — ต้องจบก่อนปิด/คืนตั๋ว
    let bindPromise = null;

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
            } catch (switchErr) {
                /*
                 * บอกเหตุให้ตรง — เดิมทุกกรณีขึ้นว่า "คุณยกเลิกการสลับเครือข่าย"
                 * กระเป๋าฝัง (TPIX Wallet) สลับไป BSC ไม่ได้เลย ไม่ได้ยกเลิกอะไร
                 */
                if (switchErr?.message === 'EMBEDDED_WALLET_SINGLE_CHAIN') throw friendly(t('swapPage.embeddedNoBsc'));
                if (switchErr?.code === 4001 || switchErr?.code === 'ACTION_REJECTED') throw friendly(t('trade.status.switchCancelled'));
                throw friendly(t('swapPage.switchFailed'));
            }
            if (walletStore.chainId !== BSC_CHAIN_ID) {
                throw friendly(t('trade.status.switchToBsc'));
            }
        }

        /*
         * 3) ทางจ่ายค่าบริการ (ระบบเครดิต TPIX) — ต้องรู้ก่อนขอราคา
         *    เพราะไม้ที่จ่ายด้วยเครดิตต้อง "ไม่" ถูกหัก % ซ้ำจากเหรียญที่สวอป
         */
        const orderValueUsd = Number(order.orderValueUsd) > 0 ? Number(order.orderValueUsd) : totalVal;
        const feePlan = await resolveFeePlan(order, owner, orderValueUsd);

        // 4) Quote จริงจาก PancakeSwap router (รวมค่าธรรมเนียมแพลตฟอร์มแล้ว ถ้าไม่ได้ยกเว้น)
        const quoteOpts = { slippage: order.slippage, waiveFee: feePlan.mode === 'ticket' };
        // ราคาที่พรีวิวในฟอร์มอยู่ (ถ้าตรงไม้นี้) — อ่านก่อนขอราคาใหม่ เพราะ getQuote จะจำตัวใหม่ทับ
        const seen = swap.recentQuote(fromTok, toTok, inputText, quoteOpts);
        const quote = await swap.getQuote(fromTok, toTok, inputText, quoteOpts);
        if (!quote) {
            throw friendly(swap.error.value || t('trade.status.noLiquidity'));
        }

        // 5) กันราคา on-chain เพี้ยนจากราคาตลาด (สภาพคล่องบาง/pool ผิดปกติ) — เกิน 10% ไม่ส่ง
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
        // ไม้นี้ดันราคาพูลเกินเพดาน — หรือวัดไม่ได้เลยทั้งสองทาง (ไม่มีราคาตลาดให้เทียบ) = ไม่ส่ง
        if (Number.isFinite(quote.priceImpact) && quote.priceImpact > BSC_PRICE_IMPACT_BLOCK_PCT) {
            throw friendly(t('trade.status.priceImpact', { impact: quote.priceImpact.toFixed(2) }));
        }
        if (!Number.isFinite(quote.priceImpact) && !(marketPrice > 0)) {
            throw friendly(t('swapPage.priceImpactUnknown'));
        }
        /*
         * ราคาใหม่ต้องไม่แย่กว่า "ได้รับอย่างน้อย" ที่ผู้ใช้เห็นในฟอร์ม (ออดิท 2026-10-28 L7)
         * ⚠️ เดิมด่านเดียวคือ 10% เทียบราคาตลาด — พูลขยับระหว่างพรีวิวกับกดส่ง ผู้ใช้ได้น้อยกว่าที่จอบอกโดยไม่รู้ตัว
         *    ตอนนี้แย่กว่าที่เห็น = หยุด บอกตัวเลขใหม่ ให้กดยืนยันอีกครั้ง (แบบเดียวกับไม้บนพูล TPIX)
         */
        if (seen && quote.netOutput < seen.minimumReceived) {
            throw friendly(t('trade.status.dexPriceMoved', {
                receive: fmtQty(quote.netOutput),
                min: fmtQty(quote.minimumReceived),
                symbol: toSym,
            }));
        }

        /*
         * 6) กระเป๋าค่าธรรมเนียมต้องพร้อม "ก่อน" ขอให้ผู้ใช้ approve
         *    เดิมเช็กตอนจะสวอป — ผู้ใช้จ่ายค่าแก๊ส approve ไปแล้วค่อยเจอ "ระบบปิดปรับปรุง"
         */
        if (quote.feeWei > 0n) {
            await swap.ensureFeeCollector();
        }

        // 7) Approve router ถ้า allowance ไม่พอ (เฉพาะ token ไม่ใช่ BNB) — approveToken รอลงบล็อกให้แล้ว
        if (!fromTok.native) {
            const hasAllowance = await swap.checkAllowance(fromTok.address, inputText, fromTok.decimals);
            if (!hasAllowance) {
                orderMessage.value = t('trade.status.approving', { symbol: fromSym });
                await swap.approveToken(fromTok.address);
            }
        }

        /*
         * 8) ขอใบอนุญาตวางไม้ — ค่าบริการ (เครดิต TPIX) ถูกเก็บตรงนี้
         *
         * ⚠️ ต้องขอตรงนี้ ไม่ใช่ตั้งแต่ต้น
         *    ขั้น 1-7 ล้มด้วยเหตุที่ไม่เกี่ยวกับค่าบริการได้ (ไม่มีสภาพคล่อง ·
         *    ราคาเพี้ยน · ผู้ใช้กดยกเลิกตอนสลับเชนหรือ approve) เก็บเงินก่อน
         *    แล้วคืนวนไปมาทุกครั้งไม่มีประโยชน์กับใคร
         *
         * ⚠️ ได้ใบแล้วต้องปิดบัญชีให้จบทุกทาง — สำเร็จ = ใช้ตั๋ว · ล้ม = คืนเงิน
         *    ปล่อยค้างเมื่อไหร่ = ผู้ใช้เสีย TPIX โดยไม่ได้อะไรเลย
         *
         * เลือกจ่ายเป็นเหรียญ (onchain) = ไม่ขอใบ — ค่าธรรมเนียม % ถูกโอนโดย useSwap อยู่แล้ว
         * (เซิร์ฟเวอร์ปฏิเสธตั๋วแบบ onchain แล้ว: ONCHAIN_FEE_NOT_SUPPORTED)
         *
         * ⚠️ บัญชีในกระเป๋าเปลี่ยนระหว่างสลับเชน/approve = ห้ามเก็บค่าบริการให้บัญชีเดิม
         *    แล้วไปส่งไม้จากบัญชีใหม่ (ออดิท 2026-10-28 L6 — ฝั่งพูล TPIX กันไว้แล้ว ฝั่งนี้ไม่มี)
         */
        if (!isSameWallet(owner)) {
            throw friendly(t('trade.status.accountChanged'));
        }
        let deadlineCap = null;
        if (feePlan.mode === 'ticket') {
            orderMessage.value = t('trade.status.preparing');
            ticket = await tradingFee.issueTicket({
                wallet: owner,
                pair: currentPair.value,
                side: order.side,
                orderValueUsd,
                chainId: BSC_CHAIN_ID,
                method: 'tpix_credit',
            });

            // ไม่ได้ใบ = วางไม้ไม่ได้ ตามที่เจ้าของกำหนด — บอกเหตุผลตรงๆ
            if (!ticket) {
                throw friendly(tradingFee.error.value || t('trade.status.ticketFailed'));
            }

            // ไม้ต้องหมดอายุก่อนใบอนุญาต (เผื่อ 60 วิ) — เดิม deadline 20 นาทีเกินอายุตั๋ว 15 นาที
            const expiresAt = Math.floor(Date.parse(ticket.expires_at) / 1000);
            if (Number.isFinite(expiresAt)) deadlineCap = expiresAt - 60;
        }

        // 9) ส่ง swap จริง — executeSwap เก็บ platform fee + บันทึก backend ให้แล้ว
        //    slippage ที่ผู้ใช้เลือกในฟอร์มมีผลจริงกับ minOut ที่ส่งเข้า router
        orderMessage.value = order.side === 'buy' ? t('trade.status.confirmBuy') : t('trade.status.confirmSell');
        const slippage = Number.isFinite(Number(order.slippage)) && order.slippage !== null
            ? Number(order.slippage)
            : quote.slippage;

        // ราคาจาก quote เป็นค่าสำรอง — executeSwap คิดราคาจริงจากยอดที่ได้รับในใบเสร็จเอง
        const quotedPrice = order.side === 'buy'
            ? (quote.netOutput > 0 ? quote.amountIn / quote.netOutput : null)
            : (quote.amountIn > 0 ? quote.netOutput / quote.amountIn : null);

        const result = await swap.executeSwap(fromTok, toTok, inputText, quote, slippage, {
            pair: currentPair.value,
            side: order.side,
            price: quotedPrice,
            feeTicket: ticket?.uuid || null,
            expectedOwner: owner,
            minOutFloor: seen?.minOutWei ?? null,
            deadlineCap,
            // ป๊อปอัพที่สองในกระเป๋าคือค่าธรรมเนียม — ต้องบอก ไม่งั้นผู้ใช้กดปฏิเสธเพราะนึกว่าแปลก
            onStage: (stage, info) => {
                if (stage === 'sent') {
                    if (ticket) bindPromise = bindTicketWithRetry(owner, ticket.uuid, info?.hash);
                } else if (stage === 'fee') {
                    orderMessage.value = t('trade.status.confirmFee', { amount: fmtQty(info?.amount), symbol: info?.symbol || fromSym });
                } else if (stage === 'recording') {
                    orderMessage.value = t('trade.status.recording');
                }
            },
        });

        /*
         * ไม้ลงจริงแล้ว (ตั้งค่า null กัน finally คืนเงินซ้ำ)
         * บันทึกไม้ผ่าน = เซิร์ฟเวอร์ปิดตั๋วในจังหวะเดียวกับที่ยืนยันไม้แล้ว · บันทึกไม่ผ่าน = ปิดเองพร้อม hash
         */
        if (ticket) {
            await bindPromise;
            if (!result?.recorded) await consumeTicketWithRetry(owner, ticket.uuid, result?.hash || null);
            ticket = null;
        }

        // ยอดจริงจากใบเสร็จ (ไม่ใช่ตัวเลขจาก quote)
        const received = Number(result?.amountOut) > 0 ? result.amountOut : quote.netOutput;
        const paid = Number(result?.amountIn) > 0 ? result.amountIn : inputAmount;
        orderStatus.value = 'success';
        orderMessage.value = order.side === 'buy'
            ? t('trade.status.bought', { amount: fmtQty(received), symbol: toSym })
            : t('trade.status.sold', { amount: fmtQty(paid), from: fromSym, out: fmtQty(received), to: toSym });
        orderTxUrl.value = result?.url || null;

        playTradeSound();
        fetchBscFormBalances();
        fetchBalances();
        myTrades.load(true);   // ไม้ที่เพิ่งวางต้องโผล่บนกราฟทันที
    } catch (err) {
        orderStatus.value = 'error';
        // error ที่ตั้งใจ throw เองมีข้อความพร้อมแสดง — นอกนั้น useSwap map ให้แล้ว
        orderMessage.value = err?.isFriendly ? err.message : (swap.error.value || t('trade.status.failed'));
        // ส่งธุรกรรมไปแล้วแต่ยืนยันผลไม่ได้ — แนบลิงก์ BscScan ให้ตรวจก่อนสั่งซ้ำ
        if (err?.txUrl) orderTxUrl.value = err.txUrl;
        /*
         * ธุรกรรมอาจลงเชนแล้ว — ห้ามคืนค่าบริการ และห้ามปิดตั๋วเองด้วย (ออดิท 2026-10-28 L8)
         * เดิมปิดตั๋วทันที = ไม้ที่สุดท้ายไม่ลง ผู้ใช้เสียค่าบริการฟรีๆ
         * ตอนนี้ผูกตั๋วกับ hash ไว้ แล้วเซิร์ฟเวอร์ตัดสินจากเชน: ลง = ปิด · ไม่ลง = คืน
         */
        if (err?.txSent && ticket) {
            const bound = await bindPromise;
            if (!bound) await bindTicketWithRetry(owner, ticket.uuid, err.txHash || null);
            ticket = null;
        }
        playErrorSound();
    } finally {
        /*
         * ⚠️ ใบอนุญาตที่ยังค้าง = เงินที่หักไปแล้วแต่ไม่มีไม้ลง ต้องคืนทุกกรณี
         *
         * อยู่ใน finally ไม่ใช่ catch เพราะทางที่ออกจาก try ได้มีมากกว่าการ throw
         * (ผู้ใช้กดยกเลิกในกระเป๋าเป็นเคสที่เกิดบ่อยที่สุด และมาถึงตรงนี้เสมอ)
         *
         * ตัวเก็บกวาดฝั่งเซิร์ฟเวอร์คืนให้อยู่แล้วเมื่อตั๋วหมดอายุ (ตั๋วที่ผูกธุรกรรมไว้ = ถามผลบนเชนก่อน)
         * ตรงนี้คือคืนทันที ไม่ให้ผู้ใช้ต้องรอเห็นยอดหายไป 15 นาทีแล้วค่อยกลับมา
         */
        if (ticket) {
            await bindPromise;
            await refundTicketWhenSettled(owner, ticket.uuid);
            ticket = null;
        }

        isSubmitting.value = false;
        clearTimeout(toastTimer);
        // มีลิงก์ tx (สำเร็จ หรือรอยืนยันผล) ค้าง toast ไว้นานขึ้นให้กดดูได้
        const holdMs = orderTxUrl.value ? 10000 : 4000;
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

    const amountVal = num(order.amount);
    const totalVal = num(order.total);
    const inputAmount = order.side === 'buy' ? totalVal : amountVal;
    if (inputAmount <= 0) {
        showOrderError(t('trade.enterAmount'));
        return;
    }

    clearTimeout(toastTimer);
    // decimals ของคู่ใช้ไม่ได้ (dexToken คืน null) — ไม่เดา ไม่ส่ง
    const fromTok = dexToken(order.side === 'buy' ? 'quote' : 'base');
    const toTok = dexToken(order.side === 'buy' ? 'base' : 'quote');
    if (!fromTok || !toTok) {
        showOrderError(t('tpixDexError.tokenMismatch'));
        return;
    }

    // กระเป๋าฝังยังไม่ปลดล็อก (รีเฟรชหน้าแล้ว) — เปิดหน้าต่างปลดล็อก ไม่ใช่ "เชื่อมกระเป๋า" ที่ไม่มีปุ่มให้กด
    if (walletStore.isLocked) {
        walletStore.openConnectModal?.();
        showOrderError(t('tpixDexError.unlockWallet'));
        return;
    }

    isSubmitting.value = true;
    orderStatus.value = 'executing';
    orderMessage.value = t('trade.status.preparing');
    orderTxUrl.value = null;

    // จำกระเป๋าไว้ตั้งแต่ต้น — สลับบัญชีกลางทางแล้ว consume/refund ต้องยังหาใบอนุญาตเจอ (แบบเดียวกับฝั่ง BSC)
    const owner = walletStore.address;
    let ticket = null;
    let bindPromise = null;

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

        // 2) decimals ในฐานข้อมูลต้องตรงกับสัญญาบนเชน (หน้า Swap ตรวจแบบเดียวกัน)
        const [fromCheck, toCheck] = await Promise.all([
            dex.verifyTokenDecimals(fromTok),
            dex.verifyTokenDecimals(toTok),
        ]);
        const tokenCheck = fromCheck !== true ? fromCheck : toCheck;
        if (tokenCheck !== true) throw friendly(t(tokenCheck));

        // ทางจ่ายค่าบริการ — FAIL-CLOSED ก่อนขอให้ผู้ใช้ approve (ออดิท 2026-10-28 L2)
        const orderValueUsd = Number(order.orderValueUsd) > 0 ? Number(order.orderValueUsd) : totalVal;
        const feePlan = await resolveFeePlan(order, owner, orderValueUsd, TPIX_CHAIN_ID);

        // 3) Quote จริงจาก router ของพูล
        const slippage = Number.isFinite(Number(order.slippage)) && order.slippage !== null
            ? Number(order.slippage)
            : 0.5;
        // ราคาที่พรีวิวในฟอร์มอยู่ (ถ้าตรงไม้นี้) — อ่านก่อนขอราคาใหม่ เพราะ getTradeQuote จะจำตัวใหม่ทับ
        const seen = dex.recentQuote(fromTok, toTok, inputAmount, slippage);
        const quote = await dex.getTradeQuote(fromTok, toTok, inputAmount, slippage);
        if (!quote) {
            throw friendly(dex.error.value || t('trade.status.noLiquidity'));
        }

        /*
         * 4) กันไม้ที่ดันราคาพูลเกิน 10% — พูลบางเกินกว่าจะรับไม้ขนาดนี้
         * ⚠️ เดิมเทียบ `> 10` — อ่านพูลไม่ได้ (null) ผ่านด่านไปได้ ตอนนี้ไม่รู้ค่า = ไม่ส่ง (เหมือนหน้า Swap)
         */
        if (dex.isPriceImpactBlocked(quote.priceImpact)) {
            throw friendly(quote.priceImpact === null || quote.priceImpact === undefined
                ? t('trade.status.priceImpactUnknown')
                : t('trade.status.priceImpact', { impact: Number(quote.priceImpact).toFixed(1) }));
        }

        /*
         * 5) ราคาใหม่ต้องไม่แย่กว่า "ได้รับอย่างน้อย" ที่ผู้ใช้เห็นในฟอร์ม
         * ⚠️ เดิมขอราคาใหม่ตอนกดแล้วตั้ง minOut จากราคานั้นเลย — พูลขยับระหว่างพรีวิวกับกดส่ง
         *    ผู้ใช้ได้น้อยกว่าที่จอบอกโดยไม่รู้ตัว · ตอนนี้แย่กว่าที่เห็น = หยุด บอกตัวเลขใหม่ ให้กดยืนยันอีกครั้ง
         */
        if (seen && quote.netOutput < seen.minimumReceived) {
            throw friendly(t('trade.status.dexPriceMoved', {
                receive: fmtQty(quote.netOutput),
                min: fmtQty(quote.minimumReceived),
                symbol: toTok.symbol,
            }));
        }

        // 6) Approve router ถ้า allowance ไม่พอ (เฉพาะโทเคน ไม่ใช่ TPIX)
        if (!fromTok.native) {
            const needs = await dex.needsApproval(fromTok.address, inputAmount, fromTok.decimals);
            if (needs) {
                orderMessage.value = t('trade.status.approving', { symbol: fromTok.symbol });
                const ok = await dex.approveToken(fromTok.address, inputAmount, fromTok.decimals);
                if (!ok) throw friendly(dex.error.value || t('trade.status.failed'));
            }
        }

        /*
         * 7) ใบอนุญาตวางไม้ — เฉพาะทางจ่ายด้วยเครดิต TPIX (คืนใน finally ถ้าไม้ไม่ได้ลง)
         * พูลบนเชน 4289 เก็บค่าธรรมเนียมในตัวอยู่แล้ว ทาง "จ่ายเป็นเหรียญ" ไม่มีธุรกรรมแยกให้เก็บ
         * (เซิร์ฟเวอร์ปฏิเสธตั๋ว method=onchain ด้วย 422 ONCHAIN_FEE_NOT_SUPPORTED)
         */
        if (!isSameWallet(owner)) {
            throw friendly(t('trade.status.accountChanged'));
        }
        if (feePlan.mode === 'ticket') {
            orderMessage.value = t('trade.status.preparing');
            ticket = await tradingFee.issueTicket({
                wallet: owner,
                pair: currentPair.value,
                side: order.side,
                orderValueUsd,
                chainId: TPIX_CHAIN_ID,
                method: 'tpix_credit',
            });
            if (!ticket) {
                throw friendly(tradingFee.error.value || 'ขอใบอนุญาตวางไม้ไม่สำเร็จ');
            }
        }

        // บัญชีในกระเป๋าเปลี่ยนระหว่างทาง — ผู้เซ็นตอนนี้ไม่ใช่เจ้าของใบอนุญาต/ยอดที่ตรวจมา ห้ามส่ง
        if (!isSameWallet(owner)) {
            throw friendly(t('trade.status.accountChanged'));
        }

        // 8) ส่ง swap จริง — บันทึกประวัติฝั่งเซิร์ฟเวอร์ให้แล้ว · minOut ไม่ต่ำกว่าที่ผู้ใช้เห็นในพรีวิว
        orderMessage.value = order.side === 'buy' ? t('trade.status.confirmBuy') : t('trade.status.confirmSell');
        const result = await dex.executeTradeSwap(fromTok, toTok, inputAmount, quote, slippage, {
            minOutFloor: seen?.minOutWei ?? null,
            onSent: (hash) => {
                if (ticket) bindPromise = bindTicketWithRetry(owner, ticket.uuid, hash);
            },
        });

        if (ticket) {
            await bindPromise;
            await consumeTicketWithRetry(owner, ticket.uuid, result?.hash || null);
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
        if (err?.txSent) {
            /*
             * ส่งออกไปแล้วแต่เชนยังไม่ยืนยัน — ไม้อาจลงแล้ว
             * ⚠️ เดิมบอก "ไม่สำเร็จ ลองใหม่" + คืนค่าบริการ → ผู้ใช้กดซ้ำ = สวอปสองรอบ (และได้ค่าบริการคืน)
             *    ตอนนี้: บอกว่ารอยืนยัน + ลิงก์ explorer · ผูกใบอนุญาตกับ hash ให้เซิร์ฟเวอร์ตัดสินจากเชน
             *    (ไม่ปิดตั๋วเอง — ไม้ที่สุดท้ายไม่ลงต้องได้ค่าบริการคืน · ออดิท L8)
             */
            orderMessage.value = t('trade.status.pendingTx');
            orderTxUrl.value = err.txUrl || null;
            if (ticket) {
                const bound = await bindPromise;
                if (!bound) await bindTicketWithRetry(owner, ticket.uuid, err.txHash || null);
                ticket = null;
            }
            fetchDexFormBalances();
            fetchDexData();
        } else {
            orderMessage.value = err?.isFriendly ? err.message : (dex.error.value || t('trade.status.failed'));
        }
        playErrorSound();
    } finally {
        if (ticket) {
            await bindPromise;
            await refundTicketWhenSettled(owner, ticket.uuid);
            ticket = null;
        }

        isSubmitting.value = false;
        clearTimeout(toastTimer);
        // มีลิงก์ tx (สำเร็จ หรือรอยืนยันผล) ค้าง toast ไว้นานขึ้นให้กดดูได้
        const holdMs = orderTxUrl.value ? 10000 : 4000;
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
    const priceVal = num(order.price);
    const amountVal = num(order.amount);

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
        /*
         * ทุก 10 วิ และหยุดเมื่อแท็บถูกซ่อน — รอบละ 3 คำขอ (ticker/orderbook/trades)
         * เดิม 5 วิ = 36 คำขอ/นาทีจากการ์ดนี้ใบเดียว เกือบชนเพดาน throttle:60,1 ของทั้ง API
         * (เปิดสองแท็บ/อยู่หลัง NAT เดียวกัน = โดน 429 แล้ว /dex/config ล้มตาม)
         */
        dexRefreshInterval = setInterval(() => {
            if (typeof document !== 'undefined' && document.hidden) return;
            fetchDexData();
        }, 10000);
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
            // Binance ไม่มีคู่นี้ (รีเฟรชก็ไม่หาย) ต่างจากเน็ตสะดุด — บอกให้ตรงกับที่เกิดจริง
            dataError.value = binanceErrorCode.value === 'invalid-symbol'
                ? t('trade.notice.pairUnavailable')
                : t('trade.notice.connectError');
            isLoading.value = false;
            // เน็ตสะดุดตอนโหลด: เปิดสายสดไว้ให้ฟื้นเอง (คู่ที่ไม่มีจริง connectWebSocket ไม่เปิดให้อยู่แล้ว)
            if (binanceErrorCode.value !== 'invalid-symbol') connectWebSocket();
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

/**
 * หน้าถูกปิดแล้วหรือยัง — onMounted มี await หลายจังหวะ
 *
 * ⚠️ ผู้ใช้กดเปลี่ยนคู่ระหว่างที่ยังโหลดไม่เสร็จ (เปลี่ยนคู่ = Inertia สร้างหน้าใหม่ทั้งหน้า)
 *    เดิม connectWebSocket() และ interval 60 วิ ยังถูกเปิดต่อหลังหน้าปิดไปแล้ว
 *    ได้ socket ที่ไม่มีใครปิดและ timer ที่วิ่งตลอดไป สะสมทุกครั้งที่สลับคู่
 */
let disposed = false;

onMounted(async () => {
    measureBoard();
    observeAbove();
    wideQuery?.addEventListener('change', measureBoard);
    window.addEventListener('resize', measureBoard);
    // ฟอนต์ไทยโหลดเสร็จทีหลัง แล้วความสูงของแถบหัวเปลี่ยน — วัดใหม่อีกรอบ
    document.fonts?.ready.then(() => { if (!disposed) measureBoard(); }).catch(() => {});

    // ต้องรู้ก่อนว่าคู่นี้อยู่เชนไหนและ DEX พร้อมไหม ถึงจะเลือกฟีดได้ถูก
    await Promise.all([loadDexConfig(), loadPairMeta()]);
    if (disposed) return;
    await startFeeds();
    if (disposed) {
        stopFeeds();   // ฟีดเพิ่งเปิดระหว่างที่หน้าปิดไปแล้ว — ปิดทิ้งทันที
        return;
    }

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
    if (disposed) return;
    await startFeeds();
    if (disposed) {
        stopFeeds();
        return;
    }
    fetchDexFormBalances();
    // ไม้ของบอทผูกกับคู่ — สลับคู่แล้วต้องโหลดชุดใหม่ ไม่งั้นป้ายของคู่เก่าค้างจนรอบรีเฟรชถัดไป
    bot.loadTrades(currentPair.value);
});

/*
 * โชว์ป้ายใหม่ทุกครั้งที่คู่หรือโหมดเปลี่ยน — ป้ายของคู่ก่อนต้องปิดทิ้ง ไม่ซ้อนค้าง
 * คีย์รวมชื่อคู่ด้วย: สลับระหว่างสองคู่บน BSC ข้อความเดิมก็ต้องโผล่ให้คู่ใหม่
 */
let pairNoticeToast = null;
watch(
    () => (pairNotice.value ? `${props.pair}|${pairNotice.value.key}` : ''),
    (key) => {
        if (pairNoticeToast) dismissToast(pairNoticeToast);
        pairNoticeToast = key ? showToast({ text: t(pairNotice.value.key), type: pairNotice.value.type }) : null;
    },
);

onUnmounted(() => {
    disposed = true;
    // ป้ายลอยอยู่ใน AppLayout ทุกหน้า — ออกจากหน้าเทรดแล้วต้องไม่ตามไปหน้าอื่น
    if (pairNoticeToast) dismissToast(pairNoticeToast);
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
        <!-- ไม่มีเพดานความกว้างแล้ว — จอ 2560px เดิมใช้ได้จริงแค่ 1920px
             เหลือขอบดำข้างละ 320px ทิ้งเปล่า ทั้งที่หน้านี้เป็นกระดานตัวเลข
             ยิ่งกว้างยิ่งอ่านง่าย ไม่ใช่บทความที่ต้องคุมความยาวบรรทัด -->
        <div class="w-full">
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
                        v-if="orderTxUrl && (orderStatus === 'success' || orderStatus === 'error')"
                        :href="orderTxUrl"
                        target="_blank"
                        rel="noopener"
                        class="underline font-semibold whitespace-nowrap hover:opacity-80"
                    >
                        {{ t('trade.status.viewTx') }} ↗
                    </a>
                </div>
            </Transition>

            <!-- ป้ายบอกโหมดเทรดลอยเป็นบอลลูนแล้ว (pairNotice → showToast) ไม่กินพื้นที่กระดาน -->

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
                    :ref="el => setColRef(col, el)"
                    :style="columnStyle(col)"
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
                                :kyc-blocked="kycBlocked"
                                :unavailable-reason="tradeUnavailableReason"
                                @need-kyc="router.visit('/kyc')"
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
                                <OpenOrders v-if="activeTab === 'openOrders'" :current-pair="currentPair" />
                                <TradeHistory v-else-if="activeTab === 'history'" />
                                <div v-else class="text-center text-dark-400 text-sm">
                                    <!-- กระเป๋ายังไม่เซ็นยืนยัน — บอกตรงๆ แทนที่จะโชว์ว่าไม่มีเหรียญ -->
                                    <div v-if="walletStore.isConnected && fundsNeedVerify" class="py-8">
                                        <p class="text-dark-400 mb-3">{{ t('trade.tabs.verifyForFunds') }}</p>
                                        <button
                                            type="button"
                                            class="btn-primary text-sm px-6 py-2 disabled:opacity-50"
                                            :disabled="isVerifyingFunds"
                                            @click="verifyForFunds"
                                        >
                                            {{ isVerifyingFunds ? t('history.verifying') : t('history.verifyNow') }}
                                        </button>
                                    </div>
                                    <div v-else-if="walletStore.isConnected && balances.length > 0">
                                        <p class="text-[11px] text-dark-500 mb-2 text-left">{{ t('trade.tabs.fundsOnBsc') }}</p>
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
                            <!-- คู่ TPIX/DEX ใช้ polling ไม่มี WS ของ Binance — "สด" = โหลดล่าสุดไม่ error -->
                            <RecentTrades
                                :symbol="currentPair"
                                :trades="trades"
                                :is-loading="isLoading"
                                :live="(isDexPair || isTPIXPair) ? !dataError : binanceLive"
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

                <!-- ── ด้ามลากปรับความกว้างคอลัมน์ ─────────────────────────────
                     absolute จึงไม่นับเป็นรางของกริด ผังคอลัมน์จึงไม่เพี้ยน
                     ดับเบิลคลิก = คืนให้ระบบคิดความกว้างเอง                     -->
                <div
                    v-if="!isNarrow && colHandles.length"
                    class="pointer-events-none absolute inset-y-0 left-0 right-0 z-20"
                    aria-hidden="false"
                >
                    <button
                        v-for="h in colHandles"
                        :key="`grip-${h.col}`"
                        type="button"
                        class="pointer-events-auto group absolute top-0 h-full w-3 -ml-1.5 flex items-center justify-center cursor-col-resize focus:outline-none"
                        :style="{ left: `${h.x}px` }"
                        :title="t('trade.layout.resizeColumn')"
                        :aria-label="t('trade.layout.resizeColumn')"
                        @pointerdown="startColumnResize($event, h)"
                        @pointermove="onColumnResizeMove"
                        @pointerup="endColumnResize"
                        @pointercancel="endColumnResize"
                        @dblclick="layout.resetColumnWidth(h.col); nextTick(measureHandles)"
                    >
                        <span class="block h-16 w-1 rounded-full bg-white/10 group-hover:bg-primary-400/80 group-focus-visible:bg-primary-400 transition-colors"></span>
                    </button>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
