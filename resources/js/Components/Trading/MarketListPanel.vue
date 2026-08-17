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
/** เพดานของ /api/v1/market/sparklines ต่อ 1 คำขอ */
const SPARKLINE_CAP = 40;

const tickers = ref([]);
const isLoading = ref(true);
const search = ref('');
const activeFilter = ref('all'); // all | favorites | gainers | losers
const sortKey = ref('volume');   // volume | change | name
const favorites = ref([]);

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
        }

        if (tickerRes.data?.success) {
            tickers.value = tickerRes.data.data.map(t => ({
                symbol: `${t.baseAsset}/${t.quoteAsset}`,
                pair: `${t.baseAsset}-${t.quoteAsset}`,
                base: t.baseAsset,
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
                symbol: 'TPIX/USDT', pair: 'TPIX-USDT', base: 'TPIX',
                logo: meta.TPIX?.logo || '/tpixlogo.webp',
                price: 0.10, change: 0, volume: 0, isTpix: true,
            });
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

    // TPIX เป็นเหรียญของแพลตฟอร์ม — ปักหมุดบนสุดเสมอ ยกเว้นตอนค้นหาเจาะจง
    if (!q) {
        const idx = list.findIndex(t => t.base === 'TPIX');
        if (idx > 0) list.unshift(list.splice(idx, 1)[0]);
    }

    return list;
});

// โหลดเส้นกราฟของรายการที่กรองแล้ว (ตัดที่เพดานของ API — คู่ที่เหลือได้ขีดจางแทน)
watch(filtered, (rows) => {
    const symbols = rows.filter(t => !t.isTpix).slice(0, SPARKLINE_CAP).map(t => t.pair);
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
                    :class="['market-row', row.symbol === currentPair && 'market-row--active']"
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

                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1">
                                <span class="text-white text-xs font-medium truncate">{{ row.base }}</span>
                                <span class="text-dark-500 text-[10px]">/{{ row.symbol.split('/')[1] }}</span>
                                <span v-if="row.isTpix || row.isIndex" class="text-[8px] px-1 py-px rounded bg-amber-500/15 text-amber-400 font-medium">
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
</style>
