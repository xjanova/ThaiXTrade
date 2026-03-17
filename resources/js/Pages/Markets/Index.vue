<script setup>
/**
 * TPIX TRADE - Markets Overview Page
 * Real-time market data from Binance API
 * Developed by Xman Studio
 */

import { ref, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getCoinLogo } from '@/utils/cryptoLogos';
import { useMarketData } from '@/Composables/useMarketData';

const activeTab = ref('spot');

const tabs = [
    { id: 'spot', label: 'Spot', route: '/markets/spot' },
    { id: 'defi', label: 'DeFi', route: '/markets/defi' },
    { id: 'nft', label: 'NFT', route: '/markets/nft' },
];

const searchQuery = ref('');
const { tickers, isLoading, fetchTickers, startAutoRefresh } = useMarketData();

const filteredTokens = ref([]);

function updateFiltered() {
    const q = searchQuery.value.toLowerCase();
    filteredTokens.value = tickers.value
        .filter(t => {
            if (!q) return true;
            return t.baseAsset?.toLowerCase().includes(q) ||
                   getTokenName(t.baseAsset)?.toLowerCase().includes(q);
        })
        .map(t => ({
            symbol: t.baseAsset,
            name: getTokenName(t.baseAsset),
            price: formatPrice(parseFloat(t.price)),
            change: formatChange(parseFloat(t.priceChangePercent)),
            isUp: parseFloat(t.priceChangePercent) >= 0,
            volume: formatVolume(parseFloat(t.quoteVolume)),
            marketCap: '-',
        }));
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
    await fetchTickers();
    updateFiltered();
    startAutoRefresh();

    // Watch tickers for updates
    setInterval(updateFiltered, 1000);
});
</script>

<template>
    <Head title="Markets" />

    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
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
                        @input="updateFiltered"
                        type="text"
                        placeholder="Search tokens..."
                        class="w-full md:w-80 pl-10 pr-4 py-3 rounded-xl glass-sm border border-white/10 bg-transparent text-white placeholder-dark-400 focus:outline-none focus:border-primary-500/50"
                    />
                </div>
            </div>

            <!-- Category Tabs -->
            <div class="flex items-center gap-2 mb-6">
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

            <!-- Markets Table -->
            <div class="glass-dark rounded-2xl overflow-hidden">
                <div v-if="isLoading" class="py-12 text-center text-dark-400">
                    <div class="animate-pulse">Loading live market data...</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/5 text-dark-400 text-sm">
                                <th class="text-left p-4">#</th>
                                <th class="text-left p-4">Token</th>
                                <th class="text-right p-4">Price</th>
                                <th class="text-right p-4">24h Change</th>
                                <th class="text-right p-4">Volume (24h)</th>
                                <th class="text-right p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(token, index) in filteredTokens"
                                :key="token.symbol"
                                class="border-b border-white/5 hover:bg-white/5 transition-colors"
                            >
                                <td class="p-4 text-dark-400">{{ index + 1 }}</td>
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full overflow-hidden bg-dark-800 flex items-center justify-center">
                                            <img v-if="getCoinLogo(token.symbol)" :src="getCoinLogo(token.symbol)" :alt="token.symbol" class="w-7 h-7" />
                                            <span v-else class="text-sm font-bold text-white">{{ token.symbol.charAt(0) }}</span>
                                        </div>
                                        <div>
                                            <p class="font-medium text-white">{{ token.symbol }}</p>
                                            <p class="text-xs text-dark-400">{{ token.name }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 text-right font-mono text-white">${{ token.price }}</td>
                                <td class="p-4 text-right">
                                    <span :class="token.isUp ? 'text-trading-green' : 'text-trading-red'" class="font-medium">
                                        {{ token.change }}
                                    </span>
                                </td>
                                <td class="p-4 text-right text-dark-300 font-mono">${{ token.volume }}</td>
                                <td class="p-4 text-right">
                                    <Link :href="`/trade/${token.symbol}-USDT`" class="text-primary-400 hover:text-primary-300 text-sm font-medium">
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
