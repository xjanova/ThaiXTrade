<script setup>
/**
 * TPIX TRADE - Markets Overview Page
 * Real-time market data from Binance API
 * ค้นหา + ตัวกรอง + เส้นกราฟย่อ 24 ชม. + แบ่งหน้า
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CoinIcon from '@/Components/CoinIcon.vue';
import PageArt from '@/Components/PageArt.vue';
import Sparkline from '@/Components/Trading/Sparkline.vue';
import { useMarketData } from '@/Composables/useMarketData';
import { useSparklines } from '@/Composables/useSparklines';

const FAV_KEY = 'tpix.favPairs';

const activeTab = ref('spot');

const tabs = [
    { id: 'spot', label: 'Spot', route: '/markets/spot' },
    { id: 'defi', label: 'DeFi', route: '/markets/defi' },
    { id: 'nft', label: 'NFT', route: '/markets/nft' },
];

const filters = [
    { id: 'all', label: 'All' },
    { id: 'favorites', label: '★ Favorites' },
    { id: 'gainers', label: 'Gainers' },
    { id: 'losers', label: 'Losers' },
];

const searchQuery = ref('');
const activeFilter = ref('all');
const page = ref(1);
const perPage = ref(25);
const favorites = ref([]);

const { tickers, isLoading, fetchTickers, startAutoRefresh } = useMarketData();
const { series, load: loadSparklines } = useSparklines();

// ── รายการโปรด (แชร์ key เดียวกับ PairSelector / Markets Spot) ───────────────
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

// เดิมใช้ setInterval(updateFiltered, 1000) ที่ไม่เคยถูกเคลียร์ — เปลี่ยนเป็น computed
// ซึ่ง react ตาม tickers ให้เองเมื่อ auto-refresh ดึงข้อมูลใหม่
const allTokens = computed(() =>
    tickers.value.map(t => {
        const change = parseFloat(t.priceChangePercent);
        return {
            symbol: t.baseAsset,
            pair: `${t.baseAsset}-USDT`,
            name: getTokenName(t.baseAsset),
            price: formatPrice(parseFloat(t.price)),
            change: formatChange(change),
            rawChange: change,
            rawVolume: parseFloat(t.quoteVolume),
            isUp: change >= 0,
            volume: formatVolume(parseFloat(t.quoteVolume)),
        };
    })
);

const filteredTokens = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return allTokens.value.filter(t => {
        if (q && !t.symbol.toLowerCase().includes(q) && !t.name.toLowerCase().includes(q)) return false;
        if (activeFilter.value === 'favorites') return favorites.value.includes(t.pair);
        if (activeFilter.value === 'gainers') return t.rawChange > 0;
        if (activeFilter.value === 'losers') return t.rawChange < 0;
        return true;
    });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredTokens.value.length / perPage.value)));

const pagedTokens = computed(() => {
    const start = (page.value - 1) * perPage.value;
    return filteredTokens.value.slice(start, start + perPage.value);
});

/** ลำดับที่แสดงในคอลัมน์ # ต้องต่อเนื่องข้ามหน้า */
const rankOffset = computed(() => (page.value - 1) * perPage.value);

watch([searchQuery, activeFilter, perPage], () => { page.value = 1; });
watch(totalPages, (n) => { if (page.value > n) page.value = n; });

// โหลดเส้นกราฟเฉพาะแถวที่แสดงอยู่จริง
watch(pagedTokens, (list) => {
    const symbols = list.map(t => t.pair);
    if (symbols.length) loadSparklines(symbols);
}, { immediate: true });

function goToPage(n) {
    page.value = Math.min(Math.max(1, n), totalPages.value);
}

function formatPrice(price) {
    if (price >= 1000) return price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (price >= 1) return price.toFixed(2);
    if (price >= 0.01) return price.toFixed(4);
    return price.toFixed(8);
}

function formatChange(change) {
    return (change >= 0 ? '+' : '') + change.toFixed(2) + '%';
}

function formatVolume(vol) {
    if (vol >= 1e12) return (vol / 1e12).toFixed(1) + 'T';
    if (vol >= 1e9) return (vol / 1e9).toFixed(1) + 'B';
    if (vol >= 1e6) return (vol / 1e6).toFixed(1) + 'M';
    return (vol / 1e3).toFixed(1) + 'K';
}

function getTokenName(symbol) {
    const names = {
        BTC: 'Bitcoin', ETH: 'Ethereum', BNB: 'BNB', SOL: 'Solana',
        XRP: 'XRP', ADA: 'Cardano', DOGE: 'Dogecoin', DOT: 'Polkadot',
        AVAX: 'Avalanche', MATIC: 'Polygon', LINK: 'Chainlink', UNI: 'Uniswap',
        ATOM: 'Cosmos', LTC: 'Litecoin', NEAR: 'NEAR Protocol', ARB: 'Arbitrum',
        PEPE: 'Pepe', SHIB: 'Shiba Inu', TRX: 'TRON', TON: 'Toncoin',
    };
    return names[symbol] || symbol;
}

onMounted(async () => {
    readFavorites();
    await fetchTickers();
    startAutoRefresh();
});
</script>

<template>
    <Head title="Markets" />

    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="relative rounded-2xl border border-white/5 mb-6">
                <PageArt art="section-markets" :opacity="65" fade="edges" rounded="rounded-2xl" loading="eager" />
                <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4 p-6">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-2">Markets</h1>
                        <p class="text-dark-400">Explore crypto markets and find trading opportunities.</p>
                    </div>

                    <!-- Search -->
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search tokens..."
                            class="w-full md:w-80 pl-10 pr-4 py-3 rounded-xl glass-sm border border-white/10 bg-dark-900/60 text-white placeholder-dark-400 focus:outline-none focus:border-primary-500/50"
                        />
                    </div>
                </div>
            </div>

            <!-- Category Tabs -->
            <div class="flex items-center gap-2 mb-4">
                <Link
                    v-for="tab in tabs"
                    :key="tab.id"
                    :href="tab.route"
                    :class="[
                        'px-5 py-2.5 rounded-xl text-sm font-medium transition-all',
                        activeTab === tab.id
                            ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30'
                            : 'glass-sm text-dark-400 hover:text-white hover:bg-white/5'
                    ]"
                >
                    {{ tab.label }}
                </Link>
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
                <span class="ml-auto text-xs text-dark-500 whitespace-nowrap">{{ filteredTokens.length }} tokens</span>
            </div>

            <!-- Markets Table -->
            <div class="glass-dark rounded-2xl overflow-hidden">
                <div v-if="isLoading" class="py-12 text-center text-dark-400">
                    <div class="animate-pulse">Loading live market data...</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/5 text-dark-400 text-sm">
                                <th class="text-left p-4 w-8"></th>
                                <th class="text-left p-4 hidden sm:table-cell">#</th>
                                <th class="text-left p-4">Token</th>
                                <th class="text-right p-4">Price</th>
                                <th class="text-right p-4">24h Change</th>
                                <th class="text-right p-4 hidden md:table-cell">Volume (24h)</th>
                                <th class="text-center p-4 hidden sm:table-cell">24h Chart</th>
                                <th class="text-right p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(token, index) in pagedTokens"
                                :key="token.symbol"
                                class="border-b border-white/5 hover:bg-white/5 transition-colors"
                            >
                                <td class="pl-4">
                                    <button
                                        @click="toggleFavorite(token.pair)"
                                        :class="['text-base transition-colors',
                                            favorites.includes(token.pair) ? 'text-amber-400' : 'text-dark-600 hover:text-amber-400']"
                                        :aria-label="favorites.includes(token.pair) ? 'Remove from favorites' : 'Add to favorites'"
                                    >
                                        {{ favorites.includes(token.pair) ? '★' : '☆' }}
                                    </button>
                                </td>
                                <td class="p-4 text-dark-400 hidden sm:table-cell">{{ rankOffset + index + 1 }}</td>
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <CoinIcon :symbol="token.symbol" size="md" />
                                        <div>
                                            <p class="font-medium text-white">{{ token.symbol }}</p>
                                            <p class="text-xs text-dark-400">{{ token.name }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 text-right font-mono text-white text-sm sm:text-base">${{ token.price }}</td>
                                <td class="p-4 text-right">
                                    <span :class="token.isUp ? 'text-trading-green' : 'text-trading-red'" class="font-medium text-sm sm:text-base">
                                        {{ token.change }}
                                    </span>
                                </td>
                                <td class="p-4 text-right text-dark-300 font-mono hidden md:table-cell">${{ token.volume }}</td>
                                <td class="p-4 hidden sm:table-cell">
                                    <div class="flex justify-center">
                                        <Sparkline
                                            :points="series[token.pair] || []"
                                            :is-up="token.isUp"
                                            :width="90"
                                            :height="32"
                                            :loading="series[token.pair] === undefined"
                                        />
                                    </div>
                                </td>
                                <td class="p-4 text-right">
                                    <Link :href="`/trade/${token.pair}`" class="text-primary-400 hover:text-primary-300 text-sm font-medium">
                                        Trade
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="pagedTokens.length === 0">
                                <td colspan="8" class="py-12 text-center text-dark-500">
                                    {{ activeFilter === 'favorites' ? 'No favorite tokens yet — tap ☆ to add one.' : 'No tokens match your search.' }}
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

                    <div v-if="totalPages > 1" class="flex items-center gap-2">
                        <button
                            @click="goToPage(page - 1)"
                            :disabled="page === 1"
                            class="px-3 py-1.5 rounded-lg text-sm text-dark-400 hover:text-white hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                        >
                            ‹ Prev
                        </button>
                        <span class="text-sm text-dark-400 font-mono">{{ page }} / {{ totalPages }}</span>
                        <button
                            @click="goToPage(page + 1)"
                            :disabled="page === totalPages"
                            class="px-3 py-1.5 rounded-lg text-sm text-dark-400 hover:text-white hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                        >
                            Next ›
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
