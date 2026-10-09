<script setup>
/**
 * TPIX TRADE - Order Book Component
 * สมุดคำสั่งเรียลไทม์ + รวมราคาเป็นช่วง (grouping) + แถบความลึกสะสม
 *
 * คลิกแถวไหนก็ได้ → ส่งราคา + ปริมาณสะสมถึงระดับนั้นไปให้ฟอร์มเทรด
 * (พฤติกรรมเดียวกับกระดานใหญ่ — "กวาดถึงราคานี้")
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch, nextTick, onMounted, useTemplateRef } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';
import { decimalsUsed, decimalsForPrices, formatMarketPrice, MAX_PRICE_DECIMALS } from '@/Composables/useBinanceData';

const props = defineProps({
    symbol: { type: String, default: 'BTC/USDT' },
    asks: { type: Array, default: () => [] },
    bids: { type: Array, default: () => [] },
    tickerPrice: { type: Number, default: 0 },
    isLoading: { type: Boolean, default: false },
});

const emit = defineEmits(['select-price']);

const { t } = useTranslation();

const viewMode = ref('both'); // both | bids | asks
const groupIndex = ref(0);    // ดัชนีใน groupSteps (0 = ละเอียดที่สุด)

const quoteSymbol = computed(() => props.symbol.split('/')[1] || 'USDT');
const baseSymbol = computed(() => props.symbol.split('/')[0] || '');

/** ราคาอ้างอิงของคู่นี้ — ใช้กำหนดขั้นราคาและทศนิยมตอนยังไม่มีแถวให้ดู */
const refPrice = computed(() => Math.abs(props.tickerPrice || props.bids[0]?.price || props.asks[0]?.price || 1) || 1);

/**
 * ขั้นราคาที่เลือกได้ — อิงจากขนาดของราคาเอง
 * ราคา 70,000 → 0.01 / 0.1 / 1 / 10 (เหมือนกระดานใหญ่)
 * ราคา 0.1     → 0.0000001 ขึ้นไป
 *
 * ⚠️ ขั้นต้องไม่หยาบเกิน 1/10 ของราคา — ของเดิมตันขั้นต่ำไว้ที่ 1e-8 แล้วคูณขึ้นไป
 *    PEPE 0.00000394 จึงได้ขั้น 0.00001 ที่ใหญ่กว่าราคาเอง ฝั่งซื้อถูกปัดลงเป็น 0 ทั้งหมด
 *    คลิกแถวแล้วส่งราคา 0 เข้าฟอร์มเทรด
 * สร้างจากเลขชี้กำลังตรงๆ (1e-8) ไม่คูณ 10 ซ้ำๆ — 1e-8 * 10 ได้ 1.0000000000000001e-7
 */
const groupSteps = computed(() => {
    const price = refPrice.value;
    const magnitude = Math.floor(Math.log10(price));
    const ceiling = price / 10;

    // ละเอียดสุด ≈ 6 หลักนัยสำคัญ แต่ไม่ละเอียดกว่า 1e-8 (tick เล็กสุดของ Binance)...
    let exponent = Math.max(magnitude - 6, -8);
    // ...เว้นแต่ราคาจิ๋วจน 1e-8 ยังหยาบเกิน → ลงไปตามขนาดจริง (ไม่เกินทศนิยมที่แสดงได้)
    if (Number(`1e${exponent}`) > ceiling) exponent = Math.max(magnitude - 6, -MAX_PRICE_DECIMALS);

    const steps = [0, 1, 2, 3]
        .map(i => Number(`1e${exponent + i}`))
        .filter(s => s <= ceiling * (1 + 1e-9));

    return steps.length ? steps : [Number(`1e${exponent}`)];
});

const step = computed(() => groupSteps.value[groupIndex.value] ?? groupSteps.value[0]);

// เปลี่ยนคู่เทรด → ขนาดราคาเปลี่ยน ขั้นเดิมอาจหยาบ/ละเอียดเกินไป กลับไปค่าเริ่มต้น
watch(() => props.symbol, () => { groupIndex.value = 0; });
// ราคาร่วงจนขั้นที่เลือกไว้หายไปจากรายการ → กลับไปขั้นละเอียดสุด (ไม่งั้นไม่มีปุ่มไหนถูกเลือก)
watch(() => groupSteps.value.length, (len) => { if (groupIndex.value >= len) groupIndex.value = 0; });

/** ปัดราคาให้ลงช่องเดียวกัน — asks ปัดขึ้น, bids ปัดลง (ฝั่งที่เสียเปรียบผู้ตั้งคำสั่ง) */
function bucket(price, up) {
    const s = step.value;
    if (!s || !Number.isFinite(price)) return price;
    // ราคาที่ลงขั้นพอดีอยู่แล้วต้องไม่ถูกปัดข้ามช่อง — 0.00000393 / 1e-8 ได้ 392.99999999999994
    // floor ตรงๆ จะกลายเป็น 0.00000392 (ราคาผิดหนึ่ง tick ทั้งที่แสดงและที่ส่งเข้าฟอร์ม)
    const q = price / s;
    const nearest = Math.round(q);
    const n = Math.abs(q - nearest) <= 1e-9 * Math.max(1, Math.abs(q))
        ? nearest
        : (up ? Math.ceil(q) : Math.floor(q));
    // ปัดทศนิยมทิ้งเศษ floating point (0.1*3 = 0.30000000000000004)
    return parseFloat((n * s).toPrecision(12));
}

/**
 * รวมแถวที่ราคาอยู่ช่องเดียวกัน แล้วสะสมปริมาณจากราคาที่ดีที่สุดออกไป
 * @param {Array} rows แถวดิบเรียงจาก "ดีที่สุด" ไป "แย่ที่สุด"
 */
function aggregate(rows, up) {
    const buckets = new Map();

    rows.forEach((row) => {
        const price = bucket(row.price, up);
        const prev = buckets.get(price) || { price, amount: 0, total: 0 };
        prev.amount += row.amount;
        prev.total += row.total ?? row.price * row.amount;
        buckets.set(price, prev);
    });

    // เรียงจากราคาที่ดีที่สุดออกไป: asks = ต่ำ→สูง, bids = สูง→ต่ำ
    const list = [...buckets.values()].sort((a, b) => (up ? a.price - b.price : b.price - a.price));

    let running = 0;
    list.forEach((row) => {
        running += row.amount;
        row.cumulative = running;
    });

    return list;
}

const groupedAsks = computed(() => aggregate(props.asks, true));
const groupedBids = computed(() => aggregate(props.bids, false));

/** ฐานของแถบความลึก — ใช้ยอดสะสมสูงสุดของทั้งสองฝั่ง เพื่อให้เทียบกันได้ตรงๆ */
const maxCumulative = computed(() => Math.max(
    groupedAsks.value.at(-1)?.cumulative || 0,
    groupedBids.value.at(-1)?.cumulative || 0,
    1e-12,
));

function depthPct(row) {
    return Math.min(100, (row.cumulative / maxCumulative.value) * 100);
}

/** asks แสดงกลับหัว (ราคาสูงอยู่บน) แต่ต้องสะสมจากราคาต่ำสุดก่อน */
const displayAsks = computed(() => [...groupedAsks.value].reverse());

// ── สเปรด ───────────────────────────────────────────────────────────────────
// ใช้ราคาดิบ ไม่ใช่ราคาที่รวมช่องแล้ว — เลือกขั้น 10 แล้ว ask ถูกปัดขึ้น bid ถูกปัดลง
// สเปรดจะพองเป็นอย่างน้อยหนึ่งขั้นทั้งที่ตลาดจริงห่างกันแค่ 0.01
// (และไม่หยิบแถวสุดท้ายแบบโค้ดรุ่นแรก ที่ได้ ask แพงสุด สเปรดกว้างเกินจริงหลายเท่า)
function bestOf(rows, pick) {
    const prices = rows.map(r => Number(r.price)).filter(p => Number.isFinite(p) && p > 0);
    return prices.length ? pick(...prices) : 0;
}
const bestAsk = computed(() => bestOf(props.asks, Math.min));
const bestBid = computed(() => bestOf(props.bids, Math.max));

/**
 * ทศนิยมที่ข้อมูลใช้จริง (≈ tick size ของคู่) — อ่านจากแถวดิบ
 * ใช้กับราคากลาง สเปรด และเป็นเพดานของคอลัมน์ราคา
 */
const dataDecimals = computed(() => decimalsForPrices(
    [...props.asks, ...props.bids].map(r => r.price),
    refPrice.value,
));

/**
 * ทศนิยมของคอลัมน์ราคา = ละเอียดเท่าขั้นที่เลือก แต่ไม่เกินที่ข้อมูลมีจริง
 * XRP ขั้น 0.000001 → 4 ตำแหน่ง (1.4035 / 1.4036 แยกกันได้ ไม่ใช่ 1.40 ทั้งคู่)
 * BTC ขั้น 10 → 0 ตำแหน่ง (70,010)
 */
const rowDecimals = computed(() => Math.min(decimalsUsed(step.value), dataDecimals.value));

const spreadAmount = computed(() => {
    if (!bestAsk.value || !bestBid.value) return '0.00';
    // ของเดิม toFixed(2) → เหรียญราคาต่ำกว่าเซ็นต์ได้ "$0.00" ทุกครั้ง
    return formatMarketPrice(bestAsk.value - bestBid.value, dataDecimals.value);
});

const spreadPercent = computed(() => {
    if (!bestAsk.value || !bestBid.value) return '0.000';
    return ((bestAsk.value - bestBid.value) / bestBid.value * 100).toFixed(3);
});

/** สัดส่วนแรงซื้อ/แรงขายจากปริมาณรวมทั้งสมุด */
const buyPressure = computed(() => {
    const bidVol = groupedBids.value.at(-1)?.cumulative || 0;
    const askVol = groupedAsks.value.at(-1)?.cumulative || 0;
    const total = bidVol + askVol;
    return total > 0 ? (bidVol / total) * 100 : 50;
});

const priceIsUp = computed(() => {
    if (!bestBid.value) return true;
    return props.tickerPrice >= bestBid.value;
});

const hasData = computed(() => groupedAsks.value.length > 0 || groupedBids.value.length > 0);

// ── การแสดงผล ───────────────────────────────────────────────────────────────
/** ราคาในตาราง — ทศนิยมเท่ากันทั้งคอลัมน์ */
function formatPrice(price) {
    return formatMarketPrice(price, rowDecimals.value);
}

/** ราคากลาง — ละเอียดเต็มที่ข้อมูลมี ไม่ขึ้นกับขั้นที่เลือก */
function formatMidPrice(price) {
    return formatMarketPrice(price, dataDecimals.value);
}

function formatAmount(amount) {
    if (amount >= 1000) return amount.toLocaleString('en-US', { maximumFractionDigits: 2 });
    if (amount >= 1) return amount.toFixed(4);
    return amount.toFixed(6);
}

function formatStep(value) {
    if (value >= 1) return value.toLocaleString('en-US', { maximumFractionDigits: 0 });
    // ตัด 0 ท้ายทิ้ง แต่เหลืออย่างน้อย 1 ตำแหน่ง
    return value.toFixed(Math.min(MAX_PRICE_DECIMALS, Math.max(1, decimalsUsed(value))));
}

// ── การเลื่อนฝั่งขาย ────────────────────────────────────────────────────────
// ฝั่งขายเรียงกลับหัว (แพงสุดอยู่บน) ราคาที่ดีที่สุดจึงอยู่ "ล่างสุด" ติดเส้นสเปรด
// → ตรึงไว้ที่ก้นกล่องเสมอ เว้นแต่ผู้ใช้เลื่อนขึ้นไปดูเอง
const asksEl = useTemplateRef('asksEl');
let asksPinned = true;

function onAsksScroll(e) {
    const el = e.target;
    asksPinned = el.scrollHeight - el.scrollTop - el.clientHeight < 8;
}

function pinAsks() {
    nextTick(() => {
        if (asksPinned && asksEl.value) asksEl.value.scrollTop = asksEl.value.scrollHeight;
    });
}

watch(displayAsks, pinAsks);
watch(viewMode, () => { asksPinned = true; pinAsks(); });
onMounted(pinAsks);

/** ส่งราคา + ปริมาณสะสมถึงระดับที่คลิกไปให้ฟอร์มเทรด */
function pick(row, side) {
    // ราคา 0 / ติดลบ ห้ามหลุดเข้าฟอร์มเด็ดขาด — ด่านสุดท้ายเผื่อการปัดช่องพลาดในอนาคต
    if (!(Number(row?.price) > 0)) return;
    emit('select-price', {
        price: row.price,
        amount: row.cumulative,
        total: row.price * row.cumulative,
        side,
    });
}
</script>

<template>
    <div class="flex flex-col h-full min-h-0">
        <!-- แถบควบคุม: มุมมอง + ขั้นราคา -->
        <div class="flex items-center justify-between gap-2 px-3 py-1.5 border-b border-white/5 flex-shrink-0">
            <div class="flex items-center gap-1 p-0.5 rounded-lg bg-dark-800">
                <button
                    type="button" :title="t('trade.book.both')" :aria-label="t('trade.book.both')"
                    :class="['p-1 rounded transition-all', viewMode === 'both' ? 'bg-dark-600' : 'hover:bg-dark-700']"
                    @click="viewMode = 'both'"
                >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                        <rect x="2" y="2" width="12" height="5" rx="1" class="text-trading-red" />
                        <rect x="2" y="9" width="12" height="5" rx="1" class="text-trading-green" />
                    </svg>
                </button>
                <button
                    type="button" :title="t('trade.book.bidsOnly')" :aria-label="t('trade.book.bidsOnly')"
                    :class="['p-1 rounded transition-all', viewMode === 'bids' ? 'bg-dark-600' : 'hover:bg-dark-700']"
                    @click="viewMode = 'bids'"
                >
                    <svg class="w-3.5 h-3.5 text-trading-green" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                        <rect x="2" y="2" width="12" height="12" rx="1" />
                    </svg>
                </button>
                <button
                    type="button" :title="t('trade.book.asksOnly')" :aria-label="t('trade.book.asksOnly')"
                    :class="['p-1 rounded transition-all', viewMode === 'asks' ? 'bg-dark-600' : 'hover:bg-dark-700']"
                    @click="viewMode = 'asks'"
                >
                    <svg class="w-3.5 h-3.5 text-trading-red" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                        <rect x="2" y="2" width="12" height="12" rx="1" />
                    </svg>
                </button>
            </div>

            <!-- ขั้นราคา (รวมแถวที่ราคาใกล้กันให้อ่านง่าย) -->
            <div class="flex items-center gap-1">
                <span class="text-[10px] text-dark-500 hidden sm:inline">{{ t('trade.book.step') }}</span>
                <button
                    v-for="(s, i) in groupSteps"
                    :key="i"
                    type="button"
                    :class="['px-1.5 py-0.5 rounded text-[10px] font-mono transition-colors',
                        groupIndex === i ? 'bg-primary-500/20 text-primary-300' : 'text-dark-500 hover:text-white']"
                    @click="groupIndex = i"
                >
                    {{ formatStep(s) }}
                </button>
            </div>
        </div>

        <!-- หัวคอลัมน์ -->
        <div class="grid grid-cols-3 gap-1 px-3 py-1 text-[10px] font-medium text-dark-400 border-b border-white/5 flex-shrink-0">
            <span>{{ t('trade.book.priceCol', { quote: quoteSymbol }) }}</span>
            <span class="text-right">{{ t('trade.book.amountCol', { base: baseSymbol }) }}</span>
            <span class="text-right">{{ t('trade.book.totalCol') }}</span>
        </div>

        <!-- กำลังโหลด -->
        <div v-if="isLoading && !hasData" class="flex-1 flex items-center justify-center">
            <div class="text-dark-400 text-sm animate-pulse">{{ t('trade.book.loading') }}</div>
        </div>

        <!-- ไม่มีข้อมูล -->
        <div v-else-if="!hasData" class="flex-1 flex flex-col items-center justify-center py-8">
            <svg class="w-10 h-10 text-dark-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <p class="text-dark-500 text-sm font-medium">{{ t('trade.book.empty') }}</p>
            <p class="text-dark-600 text-xs mt-1">{{ t('trade.book.emptySub') }}</p>
        </div>

        <!-- สมุดคำสั่ง -->
        <div v-else class="flex-1 min-h-0 flex flex-col">
            <!-- ฝั่งขาย
                 ⚠️ ห้ามใช้ `justify-end` บนกล่องที่ scroll ได้ — เมื่อเนื้อหาสูงเกินกล่อง
                 แถวจะล้นออก "ด้านบน" และเลื่อนไปหาไม่ได้เลย (scrollHeight = clientHeight)
                 ใช้ `mt-auto` ที่ตัวลูกแทน: มีที่ว่างก็ดันลงล่าง, ล้นก็ scroll ได้ตามปกติ -->
            <div
                v-if="viewMode !== 'bids'"
                ref="asksEl"
                class="flex-1 min-h-0 overflow-y-auto custom-scrollbar flex flex-col"
                @scroll="onAsksScroll"
            >
                <div class="mt-auto">
                    <button
                        v-for="(ask, index) in displayAsks"
                        :key="`ask-${ask.price}-${index}`"
                        type="button"
                        class="book-row"
                        :title="t('trade.book.fillHint', { price: formatPrice(ask.price) })"
                        @click="pick(ask, 'sell')"
                    >
                        <span class="book-row__depth bg-trading-red/15 right-0" :style="{ width: `${depthPct(ask)}%` }"></span>
                        <span class="text-trading-red font-mono relative z-10">{{ formatPrice(ask.price) }}</span>
                        <span class="text-right text-white font-mono relative z-10">{{ formatAmount(ask.amount) }}</span>
                        <span class="text-right text-dark-400 font-mono relative z-10">{{ formatAmount(ask.cumulative) }}</span>
                    </button>
                </div>
            </div>

            <!-- ราคากลาง + สเปรด -->
            <div class="px-3 py-1.5 border-y border-white/5 bg-dark-800/50 flex-shrink-0">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-1.5">
                        <span :class="['text-base font-bold font-mono', priceIsUp ? 'text-trading-green' : 'text-trading-red']">
                            ${{ formatMidPrice(tickerPrice) }}
                        </span>
                        <svg v-if="priceIsUp" class="w-3.5 h-3.5 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                        </svg>
                        <svg v-else class="w-3.5 h-3.5 text-trading-red" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                        </svg>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] text-dark-400">{{ t('trade.book.spread') }} </span>
                        <span class="text-[10px] font-mono text-white">${{ spreadAmount }}</span>
                        <span class="text-[10px] text-dark-400 ml-1">({{ spreadPercent }}%)</span>
                    </div>
                </div>
            </div>

            <!-- ฝั่งซื้อ -->
            <div v-if="viewMode !== 'asks'" class="flex-1 min-h-0 overflow-y-auto custom-scrollbar">
                <button
                    v-for="(bid, index) in groupedBids"
                    :key="`bid-${bid.price}-${index}`"
                    type="button"
                    class="book-row"
                    :title="t('trade.book.fillHint', { price: formatPrice(bid.price) })"
                    @click="pick(bid, 'buy')"
                >
                    <span class="book-row__depth bg-trading-green/15 left-0" :style="{ width: `${depthPct(bid)}%` }"></span>
                    <span class="text-trading-green font-mono relative z-10">{{ formatPrice(bid.price) }}</span>
                    <span class="text-right text-white font-mono relative z-10">{{ formatAmount(bid.amount) }}</span>
                    <span class="text-right text-dark-400 font-mono relative z-10">{{ formatAmount(bid.cumulative) }}</span>
                </button>
            </div>

            <!-- แรงซื้อ vs แรงขาย -->
            <div class="px-3 py-1.5 border-t border-white/5 flex-shrink-0">
                <div class="flex items-center justify-between text-[10px] font-mono mb-1">
                    <span class="text-trading-green">B {{ buyPressure.toFixed(0) }}%</span>
                    <span class="text-dark-500 text-[9px] truncate px-1">{{ t('trade.book.clickHint') }}</span>
                    <span class="text-trading-red">{{ (100 - buyPressure).toFixed(0) }}% S</span>
                </div>
                <div class="h-1 rounded-full overflow-hidden bg-trading-red/40 flex">
                    <div class="h-full bg-trading-green transition-all duration-500" :style="{ width: `${buyPressure}%` }"></div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.book-row {
    @apply relative w-full grid grid-cols-3 gap-1 px-3 py-[3px] text-[11px] text-left;
    @apply hover:bg-white/10 transition-colors cursor-pointer;
}

.book-row__depth {
    @apply absolute top-0 h-full pointer-events-none transition-[width] duration-300;
}
</style>
