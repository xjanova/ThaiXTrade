<script setup>
/**
 * TPIX TRADE - DeFi Tokens Page
 * Real-time DeFi token data from Binance API
 * Developed by Xman Studio
 */

import { ref, computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getCoinLogo } from '@/utils/cryptoLogos';
import { useMarketData } from '@/Composables/useMarketData';

const DEFI_SYMBOLS = ['UNI', 'AAVE', 'CAKE', 'CRV', 'MKR', 'COMP', 'SNX', 'SUSHI', 'LINK', 'DYDX', '1INCH', 'LDO', 'PENDLE', 'INJ', 'FET', 'RENDER'];

const defiCategories = {
    UNI: 'DEX', AAVE: 'Lending', CAKE: 'DEX', CRV: 'DEX', MKR: 'Lending',
    COMP: 'Lending', SNX: 'Derivatives', SUSHI: 'DEX', LINK: 'Oracle', DYDX: 'Derivatives',
    '1INCH': 'Aggregator', LDO: 'Liquid Staking', PENDLE: 'Yield', INJ: 'Layer 1',
    FET: 'AI', RENDER: 'AI',
};

const { tickers, isLoading, fetchTickers, startAutoRefresh } = useMarketData();

const defiTokens = computed(() => {
    return tickers.value
        .filter(t => DEFI_SYMBOLS.includes(t.baseAsset))
        .map(t => {
            const price = parseFloat(t.price);
            const change = parseFloat(t.priceChangePercent);
            const volume = parseFloat(t.quoteVolume);
            return {
                symbol: t.baseAsset,
                name: getTokenName(t.baseAsset),
                price: formatPrice(price),
                change: (change >= 0 ? '+' : '') + change.toFixed(2) + '%',
                isUp: change >= 0,
                volume: formatVolume(volume),
                category: defiCategories[t.baseAsset] || 'DeFi',
            };
        })
        .sort((a, b) => {
            const idxA = DEFI_SYMBOLS.indexOf(a.symbol);
            const idxB = DEFI_SYMBOLS.indexOf(b.symbol);
            return idxA - idxB;
        });
});

const totalDefiVolume = computed(() => {
    const vol = tickers.value
        .filter(t => DEFI_SYMBOLS.includes(t.baseAsset))
        .reduce((sum, t) => sum + parseFloat(t.quoteVolume || 0), 0);
    return formatVolume(vol);
});

const avgDefiChange = computed(() => {
    const defi = tickers.value.filter(t => DEFI_SYMBOLS.includes(t.baseAsset));
    if (defi.length === 0) return '0.00';
    const avg = defi.reduce((sum, t) => sum + parseFloat(t.priceChangePercent || 0), 0) / defi.length;
    return (avg >= 0 ? '+' : '') + avg.toFixed(2);
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
    if (vol >= 1e3) return (vol / 1e3).toFixed(1) + 'K';
    return vol.toFixed(2);
}

function getTokenName(symbol) {
    const names = {
        UNI: 'Uniswap', AAVE: 'Aave', CAKE: 'PancakeSwap', CRV: 'Curve', MKR: 'Maker',
        COMP: 'Compound', SNX: 'Synthetix', SUSHI: 'SushiSwap', LINK: 'Chainlink', DYDX: 'dYdX',
        '1INCH': '1inch', LDO: 'Lido', PENDLE: 'Pendle', INJ: 'Injective', FET: 'Fetch.ai', RENDER: 'Render',
    };
    return names[symbol] || symbol;
}

onMounted(async () => {
    await fetchTickers();
    startAutoRefresh();
});
</script>

<template>
    <Head title="DeFi Tokens" />

    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <div class="flex items-center gap-3 mb-2">
                    <Link href="/markets" class="text-dark-400 hover:text-white transition-colors">Markets</Link>
                    <svg class="w-4 h-4 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                    <span class="text-white font-medium">DeFi</span>
                </div>
                <h1 class="text-3xl font-bold text-white mb-2">DeFi Tokens</h1>
                <p class="text-dark-400">Explore decentralized finance protocols and tokens.</p>
            </div>

            <!-- DeFi Stats -->
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-8">
                <div class="glass-card text-center">
                    <p class="text-2xl font-bold text-white">{{ defiTokens.length }}</p>
                    <p class="text-sm text-dark-400">DeFi Tokens</p>
                </div>
                <div class="glass-card text-center">
                    <p class="text-2xl font-bold text-white">${{ totalDefiVolume }}</p>
                    <p class="text-sm text-dark-400">24h Volume</p>
                </div>
                <div class="glass-card text-center">
                    <p :class="['text-2xl font-bold', avgDefiChange.startsWith('+') ? 'text-trading-green' : 'text-trading-red']">{{ avgDefiChange }}%</p>
                    <p class="text-sm text-dark-400">Avg Change 24h</p>
                </div>
            </div>

            <!-- Tokens Table -->
            <div class="glass-dark rounded-2xl overflow-hidden">
                <div v-if="isLoading" class="py-12 text-center text-dark-400">
                    <div class="animate-pulse">Loading live DeFi data...</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/5 text-dark-400 text-sm">
                                <th class="text-left p-4">#</th>
                                <th class="text-left p-4">Protocol</th>
                                <th class="text-left p-4">Category</th>
                                <th class="text-right p-4">Price</th>
                                <th class="text-right p-4">24h Change</th>
                                <th class="text-right p-4">Volume (24h)</th>
                                <th class="text-right p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(token, index) in defiTokens"
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
                                <td class="p-4">
                                    <span class="px-2 py-1 rounded-lg text-xs font-medium glass-sm text-dark-300">
                                        {{ token.category }}
                                    </span>
                                </td>
                                <td class="p-4 text-right font-mono text-white">${{ token.price }}</td>
                                <td class="p-4 text-right">
                                    <span :class="token.isUp ? 'text-trading-green' : 'text-trading-red'" class="font-medium">
                                        {{ token.change }}
                                    </span>
                                </td>
                                <td class="p-4 text-right font-mono text-dark-300">${{ token.volume }}</td>
                                <td class="p-4 text-right">
                                    <Link :href="`/trade/${token.symbol}-USDT`" class="text-primary-400 hover:text-primary-300 text-sm font-medium">
                                        Trade
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!isLoading && defiTokens.length === 0" class="py-12 text-center text-dark-400">
                    No DeFi token data available. Please try again later.
                </div>

                <div class="p-4 text-center text-dark-500 text-sm border-t border-white/5">
                    Real-time data from Binance. Prices update every 15 seconds.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
