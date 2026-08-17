<script setup>
/**
 * TPIX TRADE — MarketListPanel
 * รายการคู่เทรดคอลัมน์ซ้ายของหน้าเทรด: ไอคอนเหรียญ ราคา %24ชม. และเส้นกราฟย่อ
 *
 * ต่างจาก PairSelector (dropdown ที่แถบหัว) ตรงที่อันนี้เปิดค้างไว้ตลอด
 * ผู้ใช้จึงสลับคู่เทรดได้ใน 1 คลิก และเห็นตลาดทั้งกระดานพร้อมกัน
 *
 * ใช้ localStorage key `tpix.favPairs` ร่วมกับ PairSelector และหน้า Markets
 * → ติดดาวที่ไหนก็เห็นเหมือนกันทุกที่
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import CoinIcon from '@/Components/CoinIcon.vue';
import Sparkline from '@/Components/Trading/Sparkline.vue';
import { useSparklines } from '@/Composables/useSparklines';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    /** คู่ปัจจุบันในรูปแบบ BTC/USDT */
    currentPair: { type: String, default: 'BTC/USDT' },
});

const { t } = useTranslation();

const FAV_KEY = 'tpix.favPairs';
const REFRESH_MS = 30000;

const QUOTE_KEY = 'tpix.marketQuote';

const tickers = ref([]);
const isLoading = ref(true);
const search = ref('');
const activeFilter = ref('all'); // all | favorites | gainers | losers
const sortKey = ref('volume');   // volume | change | name
const favorites = ref([]);
/** สกุลหลักที่กำลังดูอยู่ — '' = ทุกสกุล */
const activeQuote = ref('');
/** สกุลหลักทั้งหมดที่แอดมินเปิดไว้ (มาจากทะเบียนคู่เทรด ไม่ได้ hardcode) */
const quoteAssets = ref([]);

const { series, load: loadSparklines } = useSparklines();

let refreshTimer = null;

// ป้ายทั้งหมดมาจากคีย์คำแปล — สลับภาษาแล้วต้องเปลี่ยนตามทันที
const filters = [
    { id: 'all', key: 'trade.market.all' },
    { id: 'favorites', key: 'trade.market.favorites' },
    { id: 'gainers', key: 'trade.market.gainers' },
    { id: 'losers', key: 'trade.market.losers' },
];

const sorts = [
    { id: 'volume', key: 'trade.market.byVolume' },
    { id: 'change', key: 'trade.market.byChange' },
    { id: 'name', key: 'trade.market.byName' },
];

// ── รายการโปรด ──────────────────────────────────────────────────────────────
function readFavorites() {
    try {
        const raw = JSON.parse(localStorage.getItem(FAV_KEY) || '[]');
        favorites.value = Array.isArray(raw) ? raw.filter(v => typeof v === 'string') : [];
    } catch {
        favorites.value = [];
    }
}

function toggleFavorite(pair) {
    favorites.value = favorites.value.includes(pair)
        ? favorites.value.filter(p => p !== pair)
        : [...favorites.value, pair];
    try {
        localStorage.setItem(FAV_KEY, JSON.stringify(favorites.value));
    } catch {
        // โหมดส่วนตัว/โควตาเต็ม — ยังใช้งานได้ในหน้านี้ แค่ไม่ถูกจำไว้
    }
}

// ── ข้อมูลตลาด ──────────────────────────────────────────────────────────────
async function fetchTickers({ silent = false } = {}) {
    if (!silent) isLoading.value = true;

    try {
        const [tickerRes, pairRes] = await Promise.all([
            axios.get('/api/v1/market/tickers'),
            axios.get('/api/v1/market/pairs'),
        ]);

        // เก็บทั้งโลโก้และโหมดการทำงานจากทะเบียนคู่เทรดของแอดมิน
        const meta = {};
        if (pairRes.data?.success) {
            pairRes.data.data.forEach(p => {
                meta[p.base_asset] = { logo: p.base_logo, mode: p.execution_mode };
            });

            /*
             * รายการสกุลหลักมาจากคู่เทรดที่แอดมินเปิดจริง ไม่ได้เขียนตายไว้
             *
             * แอดมินเพิ่มคู่ THB เมื่อไหร่ ปุ่มหมวด THB ก็โผล่เอง ไม่ต้องแก้โค้ด
             * เรียงตามจำนวนคู่ที่มี — สกุลที่ใช้เยอะที่สุดอยู่ซ้ายสุด
             */
            const counts = {};
            pairRes.data.data.forEach(p => {
                if (p.quote_asset) counts[p.quote_asset] = (counts[p.quote_asset] || 0) + 1;
            });
            quoteAssets.value = Object.keys(counts).sort((a, b) => counts[b] - counts[a]);
        }

        if (tickerRes.data?.success) {
            tickers.value = tickerRes.data.data.map(t => ({
                symbol: `${t.baseAsset}/${t.quoteAsset}`,
                pair: `${t.baseAsset}-${t.quoteAsset}`,
                base: t.baseAsset,
                quote: t.quoteAsset,
                logo: meta[t.baseAsset]?.logo || null,
                // index = ดูราคา/กราฟได้ แต่ยังส่งคำสั่งไม่ได้
                isIndex: meta[t.baseAsset]?.mode === 'index',
                price: parseFloat(t.price) || 0,
                change: parseFloat(t.priceChangePercent) || 0,
                volume: parseFloat(t.quoteVolume || 0),
            }));
        }

        if (!tickers.value.find(t => t.base === 'TPIX')) {
            tickers.value.unshift({
                symbol: 'TPIX/USDT', pair: 'TPIX-USDT', base: 'TPIX', quote: 'USDT',
                logo: meta.TPIX?.logo || '/tpixlogo.webp',
                price: 0.10, change: 0, volume: 0, isTpix: true,
            });
        }

        // ยังไม่มีทะเบียนคู่เทรด (DB ว่าง) — เอาสกุลหลักจากราคาที่ได้มาแทน
        if (quoteAssets.value.length === 0) {
            quoteAssets.value = [...new Set(tickers.value.map(t => t.quote).filter(Boolean))];
        }

        // สกุลที่เคยเลือกไว้หายไปจากระบบแล้ว (แอดมินปิดคู่นั้น) — กลับไปดูทุกสกุล
        // ไม่งั้นผู้ใช้จะเจอลิสต์ว่างเปล่าโดยไม่รู้ว่าทำไม
        if (activeQuote.value && !quoteAssets.value.includes(activeQuote.value)) {
            activeQuote.value = '';
        }
    } catch {
        // API ล่ม — คงรายการเดิมไว้ ถ้ายังไม่เคยโหลดเลยให้เหลือ TPIX ตัวเดียว
        if (tickers.value.length === 0) {
            tickers.value = [{
                symbol: 'TPIX/USDT', pair: 'TPIX-USDT', base: 'TPIX',
                logo: '/tpixlogo.webp', price: 0.10, change: 0, volume: 0, isTpix: true,
            }];
        }
    } finally {
        isLoading.value = false;
    }
}

// ── กรอง + จัดเรียง ─────────────────────────────────────────────────────────
const filtered = computed(() => {
    const q = search.value.trim().toUpperCase();

    let list = tickers.value.filter(t => {
        // หมวดสกุลหลักกรองก่อนเสมอ — ยกเว้นตอนค้นหา เพราะคนพิมพ์ชื่อเหรียญ
        // คาดว่าจะเจอมัน ไม่ว่าจะอยู่หมวดไหน
        if (!q && activeQuote.value && t.quote !== activeQuote.value) return false;
        if (q && !t.symbol.includes(q) && !t.base.includes(q)) return false;
        if (activeFilter.value === 'favorites') return favorites.value.includes(t.pair);
        if (activeFilter.value === 'gainers') return t.change > 0;
        if (activeFilter.value === 'losers') return t.change < 0;
        return true;
    });

    list = [...list].sort((a, b) => {
        if (sortKey.value === 'name') return a.symbol.localeCompare(b.symbol);
        if (sortKey.value === 'change') return b.change - a.change;
        return b.volume - a.volume;
    });

    /*
     * ลำดับการปักหมุด (บนลงล่าง): TPIX → รายการโปรด → ที่เหลือ
     *
     * ทำหลังจัดเรียงเสร็จ เพราะการปักหมุดเป็นเรื่อง "ของฉันอยู่ไหน" ไม่ใช่ผลของ
     * การจัดเรียง — ติดดาวไว้แล้วยังต้องเลื่อนหาคือดาวไม่ได้ทำหน้าที่อะไรเลย
     *
     * ตอนค้นหาไม่ปักหมุด เพราะคนพิมพ์ชื่อเหรียญมาคาดว่าผลที่ตรงที่สุดอยู่บนสุด
     */
    if (!q) {
        const favs = list.filter(t => t.base !== 'TPIX' && favorites.value.includes(t.pair));
        const rest = list.filter(t => t.base !== 'TPIX' && !favorites.value.includes(t.pair));
        const tpix = list.filter(t => t.base === 'TPIX');

        list = [...tpix, ...favs, ...rest];
    }

    return list;
});

/** แถวแรกที่ไม่ใช่รายการโปรด — ใช้ขีดเส้นคั่นให้เห็นว่ากลุ่มโปรดจบตรงไหน */
const firstNonFavoritePair = computed(() => {
    if (search.value.trim() || activeFilter.value === 'favorites') return null;

    const rows = filtered.value;
    const hasFav = rows.some(t => favorites.value.includes(t.pair));
    if (!hasFav) return null;

    return rows.find(t => t.base !== 'TPIX' && !favorites.value.includes(t.pair))?.pair ?? null;
});

function selectQuote(quote) {
    activeQuote.value = quote;
    try {
        localStorage.setItem(QUOTE_KEY, quote);
    } catch {
        // โหมดส่วนตัว/โควตาเต็ม — ใช้งานต่อได้ แค่ไม่ถูกจำไว้รอบหน้า
    }
}

/*
 * โหลดเส้นกราฟของทุกแถวที่แสดงอยู่ — ไม่ตัดทิ้ง
 *
 * ⚠️ เดิม slice(0, 40) ก่อนส่งไปขอ วัดได้จริงว่ามี 70 แถวแต่ได้กราฟแค่ 40
 *    30 แถวท้ายลิสต์ไม่มีกราฟตลอดกาล และไม่มีอะไรฟ้อง — แถวที่ไม่มีกราฟหน้าตา
 *    เหมือนแถวที่ "ยังโหลดไม่เสร็จ" ทุกประการ ผู้ใช้จึงรอไปเรื่อยๆ
 *
 *    เพดาน 40 เป็นของ "ต่อหนึ่งคำขอ" ไม่ใช่ต่อหนึ่งหน้า — useSparklines แบ่ง
 *    ก้อนให้เองอยู่แล้ว และฝั่งเซิร์ฟเวอร์ยิงขนานทั้งก้อน (~1 วิ ต่อ 40 เหรียญ)
 */
watch(filtered, (rows) => {
    const symbols = rows.filter(t => !t.isTpix).map(t => t.pair);
    if (symbols.length) loadSparklines(symbols);
}, { immediate: true });

// ── การแสดงผล ───────────────────────────────────────────────────────────────
function formatPrice(p) {
    if (p >= 1000) return p.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (p >= 1) return p.toFixed(2);
    if (p >= 0.01) return p.toFixed(4);
    return p.toFixed(8);
}

function formatVolume(v) {
    if (v >= 1e9) return (v / 1e9).toFixed(1) + 'B';
    if (v >= 1e6) return (v / 1e6).toFixed(1) + 'M';
    if (v >= 1e3) return (v / 1e3).toFixed(1) + 'K';
    return v.toFixed(0);
}

function selectPair(t) {
    if (t.symbol === props.currentPair) return;
    router.visit(`/trade/${t.pair}`);
}

onMounted(() => {
    readFavorites();
    try {
        activeQuote.value = localStorage.getItem(QUOTE_KEY) || '';
    } catch {
        activeQuote.value = '';
    }
    fetchTickers();
    refreshTimer = setInterval(() => fetchTickers({ silent: true }), REFRESH_MS);
});

onUnmounted(() => {
    clearInterval(refreshTimer);
});
</script>

<template>
    <div class="flex flex-col h-full min-h-0">
        <!-- ค้นหา -->
        <div class="px-2.5 pt-2.5">
            <div class="relative">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-dark-500"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    v-model="search"
                    type="text"
                    :placeholder="t('trade.market.search')"
                    :aria-label="t('trade.market.searchAria')"
                    class="w-full bg-dark-800/70 border border-dark-700 rounded-lg pl-8 pr-2 py-1.5 text-white text-xs placeholder-dark-500 focus:border-primary-500 outline-none transition-colors"
                />
            </div>
        </div>

        <!--
            หมวดสกุลหลัก — ต้องเด่นที่สุดในแผงนี้

            เมื่อชื่อคู่ไม่มี "/USDT" ต่อท้ายแล้ว ปุ่มหมวดคือสิ่งเดียวที่บอกว่ากำลังดู
            ราคาเทียบกับสกุลไหนอยู่ ถ้ามันจางเท่าปุ่มอื่น ผู้ใช้จะอ่านราคา BTC 0.0341
            แล้วเข้าใจว่าเป็นดอลลาร์ — ตัวเลขเดียวกันคนละความหมายโดยสิ้นเชิง
        -->
        <div v-if="quoteAssets.length" class="px-2.5 pt-2.5">
            <div class="flex items-center gap-1 overflow-x-auto scrollbar-none">
                <!--
                    ปุ่ม "ทุกสกุล" มีความหมายเมื่อมีสกุลให้เลือกมากกว่าหนึ่งเท่านั้น
                    มีสกุลเดียวแล้วยังใส่ = ปุ่มสองอันที่ให้ผลเหมือนกันเป๊ะ
                -->
                <button
                    v-if="quoteAssets.length > 1"
                    type="button"
                    :class="['quote-tab', activeQuote === '' && 'quote-tab--active']"
                    @click="selectQuote('')"
                >{{ t('trade.market.allQuotes') }}</button>
                <button
                    v-for="quote in quoteAssets"
                    :key="quote"
                    type="button"
                    :class="['quote-tab',
                        (activeQuote === quote || (quoteAssets.length === 1 && !activeQuote)) && 'quote-tab--active']"
                    @click="selectQuote(quote)"
                >{{ quote }}</button>
            </div>
        </div>

        <!-- ตัวกรอง -->
        <div class="px-2.5 pt-2 flex items-center gap-1">
            <button
                v-for="f in filters"
                :key="f.id"
                type="button"
                :class="['flex-1 px-1.5 py-1 rounded-md text-[11px] font-medium transition-colors',
                    activeFilter === f.id
                        ? 'bg-primary-500/20 text-primary-300 ring-1 ring-primary-500/30'
                        : 'bg-white/5 text-dark-400 hover:text-white']"
                @click="activeFilter = f.id"
            >
                <span v-if="f.id === 'favorites'" class="text-amber-400">★</span>
                {{ t(f.key) }}
            </button>
        </div>

        <!-- จัดเรียง -->
        <div class="px-2.5 py-1.5 flex items-center gap-1.5 text-[10px]">
            <span class="text-dark-500">{{ t('trade.market.sort') }}</span>
            <button
                v-for="s in sorts"
                :key="s.id"
                type="button"
                :class="['px-1.5 py-0.5 rounded transition-colors',
                    sortKey === s.id ? 'text-primary-400 font-semibold bg-primary-500/10' : 'text-dark-500 hover:text-white']"
                @click="sortKey = s.id"
            >
                {{ t(s.key) }}
            </button>
            <span class="ml-auto text-dark-600 font-mono">{{ filtered.length }}</span>
        </div>

        <!-- รายการ -->
        <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar border-t border-white/5">
            <div v-if="isLoading" class="p-2.5 space-y-2">
                <div v-for="n in 8" :key="n" class="flex items-center gap-2">
                    <div class="skeleton w-6 h-6 rounded-full"></div>
                    <div class="flex-1 space-y-1">
                        <div class="skeleton h-2.5 w-20"></div>
                        <div class="skeleton h-2 w-12"></div>
                    </div>
                    <div class="skeleton h-2.5 w-12"></div>
                </div>
            </div>

            <template v-else>
                <!-- ดาวเป็นปุ่มแยกนอกปุ่มเลือกคู่ — ปุ่มซ้อนปุ่มเป็น HTML ที่ไม่ถูกต้อง
                     และ screen reader จะอ่านแถวเพี้ยน -->
                <div
                    v-for="row in filtered"
                    :key="row.pair"
                    :class="['market-row',
                        row.symbol === currentPair && 'market-row--active',
                        row.pair === firstNonFavoritePair && 'market-row--after-favorites']"
                >
                    <button
                        type="button"
                        :class="['shrink-0 w-4 text-xs leading-none transition-colors',
                            favorites.includes(row.pair) ? 'text-amber-400' : 'text-dark-600 hover:text-amber-400']"
                        :aria-label="favorites.includes(row.pair)
                            ? t('trade.market.removeFavorite', { pair: row.symbol })
                            : t('trade.market.addFavorite', { pair: row.symbol })"
                        @click="toggleFavorite(row.pair)"
                    >{{ favorites.includes(row.pair) ? '★' : '☆' }}</button>

                    <button
                        type="button"
                        class="flex items-center gap-1.5 flex-1 min-w-0 text-left"
                        :aria-current="row.symbol === currentPair ? 'true' : undefined"
                        @click="selectPair(row)"
                    >
                        <CoinIcon
                            :symbol="row.base"
                            size="sm"
                            :src="row.logo || (row.isTpix ? '/tpixlogo.webp' : undefined)"
                        />

                        <!--
                            ชื่อเหรียญห้ามย่อ — ชื่อที่ถูกตัดคือชื่อที่อ่านผิดได้

                            เลิกใช้ truncate แล้วให้ชื่อกำหนดความกว้างของตัวเอง (w-auto)
                            ช่องว่างที่ได้มาจากการตัด "/USDT" ทิ้งพอสำหรับสัญลักษณ์ที่ยาว
                            ที่สุดที่มีอยู่ ส่วนสกุลหลักไปอยู่ที่ปุ่มหมวดด้านบนแทน
                        -->
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1">
                                <span class="text-white text-xs font-semibold whitespace-nowrap">{{ row.base }}</span>
                                <!-- โชว์สกุลหลักต่อท้ายเฉพาะตอนดูรวมทุกสกุล ซึ่งชื่อเหรียญซ้ำกันได้ -->
                                <span v-if="!activeQuote" class="text-dark-500 text-[9px] font-mono shrink-0">{{ row.quote }}</span>
                                <span v-if="row.isTpix || row.isIndex" class="text-[8px] px-1 py-px rounded bg-amber-500/15 text-amber-400 font-medium shrink-0">
                                    {{ t('trade.market.soon') }}
                                </span>
                            </span>
                            <span class="block text-[9px] text-dark-500 font-mono">Vol {{ formatVolume(row.volume) }}</span>
                        </span>

                        <!-- ยังไม่เคยดึง = undefined → skeleton, ดึงแล้วไม่มีข้อมูล = [] → ขีดจาง -->
                        <Sparkline
                            :points="series[row.pair] || []"
                            :is-up="row.change >= 0"
                            :width="44"
                            :height="20"
                            :loading="!row.isTpix && series[row.pair] === undefined"
                        />

                        <span class="text-right w-[74px] shrink-0">
                            <span class="block text-white text-[11px] font-mono">{{ formatPrice(row.price) }}</span>
                            <span :class="['block text-[10px] font-mono', row.change >= 0 ? 'text-trading-green' : 'text-trading-red']">
                                {{ row.change >= 0 ? '+' : '' }}{{ row.change.toFixed(2) }}%
                            </span>
                        </span>
                    </button>
                </div>

                <p v-if="filtered.length === 0" class="text-dark-500 text-xs text-center py-10">
                    {{ activeFilter === 'favorites' ? t('trade.market.emptyFavorites') : t('trade.market.empty') }}
                </p>
            </template>
        </div>
    </div>
</template>

<style scoped>
.market-row {
    @apply w-full flex items-center gap-1.5 px-2.5 py-1.5 border-l-2 border-transparent transition-colors;
    @apply hover:bg-white/5;
}

.market-row--active {
    @apply bg-primary-500/10 border-l-primary-500;
}

/* เส้นคั่นจบกลุ่มรายการโปรด — ไม่ต้องมีหัวข้อมากินความสูงของแผงที่แคบอยู่แล้ว */
.market-row--after-favorites {
    @apply border-t border-amber-500/20;
}

/*
 * ปุ่มหมวดสกุลหลัก — ตัวที่เลือกอยู่ต้องอ่านออกในพริบตา
 * ใช้พื้นทึบ + ตัวหนา + เงาเรือง ไม่ใช่แค่เปลี่ยนสีตัวอักษรเหมือนปุ่มอื่นในแผงนี้
 */
.quote-tab {
    @apply shrink-0 px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap transition-all;
    @apply bg-white/5 text-dark-400 ring-1 ring-transparent hover:text-white hover:bg-white/10;
}

.quote-tab--active {
    @apply bg-primary-500 text-white ring-primary-400/60;
    box-shadow: 0 0 12px rgba(6, 182, 212, 0.35);
}

.scrollbar-none::-webkit-scrollbar { display: none; }
.scrollbar-none { scrollbar-width: none; }
</style>
