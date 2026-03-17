<script setup>
/**
 * TPIX TRADE - Spot Markets Page
 * Real-time spot market data from Binance API
 * Developed by Xman Studio
 */

import { ref, computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getCoinLogo } from '@/utils/cryptoLogos';
import { useMarketData } from '@/Composables/useMarketData';

const searchQuery = ref('');
const { tickers, isLoading, fetchTickers, startAutoRefresh } = useMarketData();

const spotPairs = computed(() => {
    const q = searchQuery.value.toLowerCase();
    return tickers.value
        .filter(t => {
            if (!q) return true;
            return t.baseAsset?.toLowerCase().includes(q);
        })
        .map(t => {
            const price = parseFloat(t.price);
            const change = parseFloat(t.priceChangePercent);
            return {
                base: t.baseAsset,
                quote: 'USDT',
                price: formatPrice(price),
                change: (change >= 0 ? '+' : '') + change.toFixed(2) + '%',
                isUp: change >= 0,
                high: formatPrice(parseFloat(t.high)),
                low: formatPrice(parseFloat(t.low)),
                volume: formatVolume(parseFloat(t.quoteVolume)),
            };
        });
});

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
    await fetchTickers();
    startAutoRefresh();
});
</script>

<template>
    <Head title="Spot Markets" />

    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
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
                        class="w-full md:w-72 pl-10 pr-4 py-3 rounded-xl glass-sm border border-white/10 bg-transparent text-white placeholder-dark-400 focus:outline-none focus:border-primary-500/50"
                    />
                </div>
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
                                <th class="text-left p-4">Pair</th>
                                <th class="text-right p-4">Last Price</th>
                                <th class="text-right p-4">24h Change</th>
                                <th class="text-right p-4">24h High</th>
                                <th class="text-right p-4">24h Low</th>
                                <th class="text-right p-4">Volume (24h)</th>
                                <th class="text-right p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="pair in spotPairs"
                                :key="`${pair.base}-${pair.quote}`"
                                class="border-b border-white/5 hover:bg-white/5 transition-colors"
                            >
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full overflow-hidden bg-dark-800 flex items-center justify-center">
                                            <img v-if="getCoinLogo(pair.base)" :src="getCoinLogo(pair.base)" :alt="pair.base" class="w-7 h-7" />
                                            <span v-else class="text-sm font-bold text-white">{{ pair.base.charAt(0) }}</span>
                                        </div>
                                        <span class="font-medium text-white">{{ pair.base }}/{{ pair.quote }}</span>
                                    </div>
                                </td>
                                <td class="p-4 text-right font-mono text-white">${{ pair.price }}</td>
                                <td class="p-4 text-right">
                                    <span :class="pair.isUp ? 'text-trading-green' : 'text-trading-red'" class="font-medium">
                                        {{ pair.change }}
                                    </span>
                                </td>
                                <td class="p-4 text-right font-mono text-dark-300">${{ pair.high }}</td>
                                <td class="p-4 text-right font-mono text-dark-300">${{ pair.low }}</td>
                                <td class="p-4 text-right font-mono text-dark-300">${{ pair.volume }}</td>
                                <td class="p-4 text-right">
                                    <Link :href="`/trade/${pair.base}-${pair.quote}`" class="btn-primary text-sm px-4 py-2">
                                        Trade
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="p-4 text-center text-dark-500 text-sm border-t border-white/5">
                    Real-time data from Binance. Prices update every 15 seconds.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
