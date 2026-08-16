<script setup>
/**
 * TPIX TRADE - Spot Markets Page
 * Real-time spot market data from Binance API
 * ค้นหา + ตัวกรอง + จัดเรียง + เส้นกราฟย่อ 24 ชม. + แบ่งหน้า
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CoinIcon from '@/Components/CoinIcon.vue';
import PageArt from '@/Components/PageArt.vue';
import Sparkline from '@/Components/Trading/Sparkline.vue';
import { useMarketData } from '@/Composables/useMarketData';
import { useSparklines } from '@/Composables/useSparklines';

const FAV_KEY = 'tpix.favPairs';

const searchQuery = ref('');
const activeFilter = ref('all');   // all | favorites | gainers | losers
const sortKey = ref('volume');     // pair | price | change | volume
const sortDir = ref('desc');
const page = ref(1);
const perPage = ref(25);
const favorites = ref([]);

const { tickers, isLoading, fetchTickers, startAutoRefresh } = useMarketData();
const { series, load: loadSparklines } = useSparklines();
const pairLogos = ref({}); // base_asset → logo URL จาก admin DB

const filters = [
    { id: 'all', label: 'All' },
    { id: 'favorites', label: '★ Favorites' },
    { id: 'gainers', label: 'Gainers' },
    { id: 'losers', label: 'Losers' },
];

// sortable: false = คอลัมน์ประกอบ ไม่ต้องเรียง (คลิกแล้วไม่มีอะไรเกิดขึ้น)
const columns = [
    { key: 'pair', label: 'Pair', align: 'left', sortable: true },
    { key: 'price', label: 'Last Price', align: 'right', sortable: true },
    { key: 'change', label: '24h Change', align: 'right', sortable: true },
    { key: 'high', label: '24h High', align: 'right', sortable: false, hide: 'hidden xl:table-cell' },
    { key: 'low', label: '24h Low', align: 'right', sortable: false, hide: 'hidden xl:table-cell' },
    { key: 'volume', label: 'Volume (24h)', align: 'right', sortable: true, hide: 'hidden md:table-cell' },
];

async function fetchPairLogos() {
    try {
        const { data } = await axios.get('/api/v1/market/pairs');
        if (data?.success) {
            data.data.forEach(p => { pairLogos.value[p.base_asset] = p.base_logo; });
        }
    } catch {}
}

// ── รายการโปรด (แชร์ key เดียวกับ PairSelector) ─────────────────────────────
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
        // เขียนไม่ได้ (โหมดส่วนตัว) — ใช้ได้ในหน้านี้ แค่ไม่ถูกจำ
    }
}

// ── ข้อมูลดิบ → แถวสำหรับตาราง ──────────────────────────────────────────────
const rows = computed(() =>
    tickers.value.map(t => {
        const price = parseFloat(t.price);
        const change = parseFloat(t.priceChangePercent);
        return {
            base: t.baseAsset,
            quote: 'USDT',
            pair: `${t.baseAsset}-USDT`,
            logo: pairLogos.value[t.baseAsset] || null,
            rawPrice: price,
            rawChange: change,
            rawVolume: parseFloat(t.quoteVolume),
            price: formatPrice(price),
            change: (change >= 0 ? '+' : '') + change.toFixed(2) + '%',
            isUp: change >= 0,
            high: formatPrice(parseFloat(t.high)),
            low: formatPrice(parseFloat(t.low)),
            volume: formatVolume(parseFloat(t.quoteVolume)),
        };
    })
);

const filteredRows = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();

    const list = rows.value.filter(r => {
        if (q && !r.base.toLowerCase().includes(q)) return false;
        if (activeFilter.value === 'favorites') return favorites.value.includes(r.pair);
        if (activeFilter.value === 'gainers') return r.rawChange > 0;
        if (activeFilter.value === 'losers') return r.rawChange < 0;
        return true;
    });

    const dir = sortDir.value === 'asc' ? 1 : -1;
    return [...list].sort((a, b) => {
        if (sortKey.value === 'pair') return a.base.localeCompare(b.base) * dir;
        if (sortKey.value === 'price') return (a.rawPrice - b.rawPrice) * dir;
        if (sortKey.value === 'change') return (a.rawChange - b.rawChange) * dir;
        return (a.rawVolume - b.rawVolume) * dir;
    });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / perPage.value)));

const pagedRows = computed(() => {
    const start = (page.value - 1) * perPage.value;
    return filteredRows.value.slice(start, start + perPage.value);
});

/** เลขหน้าแบบย่อ: 1 … 4 5 6 … 20 */
const pageNumbers = computed(() => {
    const total = totalPages.value;
    const current = page.value;
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

    const pages = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);
    if (start > 2) pages.push('…');
    for (let i = start; i <= end; i++) pages.push(i);
    if (end < total - 1) pages.push('…');
    pages.push(total);
    return pages;
});

function toggleSort(key) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        // ชื่อเหรียญเริ่มจาก A→Z, ตัวเลขเริ่มจากมาก→น้อย (สิ่งที่คนคาดหวัง)
        sortDir.value = key === 'pair' ? 'asc' : 'desc';
    }
}

function goToPage(n) {
    if (typeof n !== 'number') return;
    page.value = Math.min(Math.max(1, n), totalPages.value);
}

watch([searchQuery, activeFilter, sortKey, sortDir, perPage], () => { page.value = 1; });
watch(totalPages, (n) => { if (page.value > n) page.value = n; });

// โหลดเส้นกราฟเฉพาะแถวในหน้าที่แสดงอยู่
watch(pagedRows, (list) => {
    const symbols = list.map(r => r.pair);
    if (symbols.length) loadSparklines(symbols);
}, { immediate: true });

function formatPrice(price) {
    if (price >= 1000) return price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (price >= 1) return price.toFixed(2);
    if (price >= 0.01) return price.toFixed(4);
    return price.toFixed(8);
}

function formatVolume(vol) {
    if (vol >= 1e12) return (vol / 1e12).toFixed(1) + 'T';
    if (vol >= 1e9) return (vol / 1e9).toFixed(1) + 'B';
    if (vol >= 1e6) return (vol / 1e6).toFixed(1) + 'M';
    return (vol / 1e3).toFixed(1) + 'K';
}

onMounted(async () => {
    readFavorites();
    // ดึง pair logos + tickers parallel
    await Promise.all([fetchTickers(), fetchPairLogos()]);
    startAutoRefresh();
});
</script>

<template>
    <Head title="Spot Markets" />

    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="relative rounded-2xl border border-white/5 mb-6">
                <PageArt art="section-markets" :opacity="65" fade="edges" rounded="rounded-2xl" loading="eager" />
                <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4 p-6">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <Link href="/markets" class="text-dark-400 hover:text-white transition-colors">Markets</Link>
                        <svg class="w-4 h-4 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        <span class="text-white font-medium">Spot</span>
                    </div>
                    <h1 class="text-3xl font-bold text-white mb-2">Spot Markets</h1>
                    <p class="text-dark-400">Trade crypto pairs with real-time pricing.</p>
                </div>

                <!-- Search -->
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search pairs..."
                        class="w-full md:w-72 pl-10 pr-4 py-3 rounded-xl glass-sm border border-white/10 bg-dark-900/60 text-white placeholder-dark-400 focus:outline-none focus:border-primary-500/50"
                    />
                </div>
                </div>
            </div>

            <!-- ตัวกรอง -->
            <div class="flex items-center gap-2 mb-4 overflow-x-auto scrollbar-none pb-1">
                <button
                    v-for="f in filters"
                    :key="f.id"
                    @click="activeFilter = f.id"
                    :class="['px-3.5 py-1.5 rounded-lg text-sm font-medium whitespace-nowrap border transition-colors',
                        activeFilter === f.id
                            ? 'bg-primary-500/20 text-primary-300 border-primary-500/30'
                            : 'bg-white/5 text-dark-400 border-transparent hover:text-white']"
                >
                    {{ f.label }}
                </button>
                <span class="ml-auto text-xs text-dark-500 whitespace-nowrap">{{ filteredRows.length }} pairs</span>
            </div>

            <!-- Pairs Table -->
            <div class="glass-dark rounded-2xl overflow-hidden">
                <div v-if="isLoading" class="py-12 text-center text-dark-400">
                    <div class="animate-pulse">Loading live market data...</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/5 text-dark-400 text-sm">
                                <th class="text-left p-4 w-8"></th>
                                <th
                                    v-for="col in columns"
                                    :key="col.key"
                                    :class="['p-4 select-none transition-colors',
                                        col.sortable ? 'cursor-pointer hover:text-white' : '',
                                        col.align === 'right' ? 'text-right' : 'text-left', col.hide]"
                                    @click="col.sortable && toggleSort(col.key)"
                                >
                                    {{ col.label }}
                                    <span v-if="col.sortable && sortKey === col.key" class="text-primary-400 ml-0.5">
                                        {{ sortDir === 'asc' ? '▲' : '▼' }}
                                    </span>
                                </th>
                                <th class="text-center p-4 hidden sm:table-cell">24h Chart</th>
                                <th class="text-right p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in pagedRows"
                                :key="row.pair"
                                class="border-b border-white/5 hover:bg-white/5 transition-colors"
                            >
                                <td class="pl-4">
                                    <button
                                        @click="toggleFavorite(row.pair)"
                                        :class="['text-base transition-colors',
                                            favorites.includes(row.pair) ? 'text-amber-400' : 'text-dark-600 hover:text-amber-400']"
                                        :aria-label="favorites.includes(row.pair) ? 'Remove from favorites' : 'Add to favorites'"
                                    >
                                        {{ favorites.includes(row.pair) ? '★' : '☆' }}
                                    </button>
                                </td>
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <CoinIcon :symbol="row.base" size="md" :src="row.logo" />
                                        <span class="font-medium text-white">{{ row.base }}/{{ row.quote }}</span>
                                    </div>
                                </td>
                                <td class="p-4 text-right font-mono text-white text-sm sm:text-base">${{ row.price }}</td>
                                <td class="p-4 text-right">
                                    <span :class="row.isUp ? 'text-trading-green' : 'text-trading-red'" class="font-medium text-sm sm:text-base">
                                        {{ row.change }}
                                    </span>
                                </td>
                                <td class="p-4 text-right font-mono text-dark-300 hidden xl:table-cell">${{ row.high }}</td>
                                <td class="p-4 text-right font-mono text-dark-300 hidden xl:table-cell">${{ row.low }}</td>
                                <td class="p-4 text-right font-mono text-dark-300 hidden md:table-cell">${{ row.volume }}</td>
                                <td class="p-4 hidden sm:table-cell">
                                    <div class="flex justify-center">
                                        <Sparkline
                                            :points="series[row.pair] || []"
                                            :is-up="row.isUp"
                                            :width="90"
                                            :height="32"
                                            :loading="series[row.pair] === undefined"
                                        />
                                    </div>
                                </td>
                                <td class="p-4 text-right">
                                    <Link :href="`/trade/${row.pair}`" class="btn-primary text-sm px-4 py-2">
                                        Trade
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="pagedRows.length === 0">
                                <td colspan="9" class="py-12 text-center text-dark-500">
                                    {{ activeFilter === 'favorites' ? 'No favorite pairs yet — tap ☆ to add one.' : 'No pairs match your search.' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- แบ่งหน้า -->
                <div v-if="!isLoading" class="flex flex-col sm:flex-row items-center justify-between gap-3 p-4 border-t border-white/5">
                    <div class="flex items-center gap-2 text-xs text-dark-500">
                        <span>Rows</span>
                        <select
                            v-model.number="perPage"
                            class="bg-dark-800 border border-white/10 rounded-lg px-2 py-1 text-white outline-none focus:border-primary-500/50"
                        >
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                        </select>
                    </div>

                    <div v-if="totalPages > 1" class="flex items-center gap-1">
                        <button
                            @click="goToPage(page - 1)"
                            :disabled="page === 1"
                            class="px-2.5 py-1.5 rounded-lg text-sm text-dark-400 hover:text-white hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                        >
                            ‹
                        </button>
                        <button
                            v-for="(n, i) in pageNumbers"
                            :key="`${n}-${i}`"
                            @click="goToPage(n)"
                            :disabled="n === '…'"
                            :class="['min-w-[32px] px-2 py-1.5 rounded-lg text-sm transition-colors',
                                n === page ? 'bg-primary-500/20 text-primary-300 font-semibold'
                                           : n === '…' ? 'text-dark-600 cursor-default'
                                                       : 'text-dark-400 hover:text-white hover:bg-white/5']"
                        >
                            {{ n }}
                        </button>
                        <button
                            @click="goToPage(page + 1)"
                            :disabled="page === totalPages"
                            class="px-2.5 py-1.5 rounded-lg text-sm text-dark-400 hover:text-white hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                        >
                            ›
                        </button>
                    </div>

                    <p class="text-xs text-dark-500">Live from Binance · updates every 15s</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.scrollbar-none::-webkit-scrollbar { display: none; }
.scrollbar-none { scrollbar-width: none; }
</style>
