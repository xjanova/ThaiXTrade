<script setup>
/**
 * TPIX TRADE - Markets Overview Page
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getCoinLogo } from '@/utils/cryptoLogos';

const activeTab = ref('spot');

const tabs = [
    { id: 'spot', label: 'Spot', route: '/markets/spot' },
    { id: 'defi', label: 'DeFi', route: '/markets/defi' },
    { id: 'nft', label: 'NFT', route: '/markets/nft' },
];

const trendingTokens = ref([
    { symbol: 'BTC', name: 'Bitcoin', price: '67,234.50', change: '+2.45%', isUp: true, volume: '45.6B', marketCap: '1.32T' },
    { symbol: 'ETH', name: 'Ethereum', price: '3,456.78', change: '+1.23%', isUp: true, volume: '23.4B', marketCap: '415B' },
    { symbol: 'BNB', name: 'BNB', price: '567.89', change: '-0.56%', isUp: false, volume: '4.5B', marketCap: '87B' },
    { symbol: 'SOL', name: 'Solana', price: '178.90', change: '+5.67%', isUp: true, volume: '8.9B', marketCap: '78B' },
    { symbol: 'ADA', name: 'Cardano', price: '0.6234', change: '+3.21%', isUp: true, volume: '1.2B', marketCap: '22B' },
    { symbol: 'AVAX', name: 'Avalanche', price: '42.56', change: '-1.23%', isUp: false, volume: '890M', marketCap: '16B' },
    { symbol: 'DOGE', name: 'Dogecoin', price: '0.1234', change: '+8.90%', isUp: true, volume: '3.4B', marketCap: '18B' },
    { symbol: 'DOT', name: 'Polkadot', price: '8.67', change: '+1.89%', isUp: true, volume: '567M', marketCap: '11B' },
]);
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
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/5 text-dark-400 text-sm">
                                <th class="text-left p-4">#</th>
                                <th class="text-left p-4">Token</th>
                                <th class="text-right p-4">Price</th>
                                <th class="text-right p-4">24h Change</th>
                                <th class="text-right p-4">Volume (24h)</th>
                                <th class="text-right p-4">Market Cap</th>
                                <th class="text-right p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(token, index) in trendingTokens"
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
                                <td class="p-4 text-right text-dark-300 font-mono">${{ token.marketCap }}</td>
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
                    Live market data integration coming soon. Showing sample data.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
