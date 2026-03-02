<script setup>
/**
 * TPIX TRADE - Spot Markets Page
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getCoinLogo } from '@/utils/cryptoLogos';

const searchQuery = ref('');

const spotPairs = ref([
    { base: 'BTC', quote: 'USDT', price: '67,234.50', change: '+2.45%', isUp: true, high: '68,100.00', low: '65,890.00', volume: '45.6B' },
    { base: 'ETH', quote: 'USDT', price: '3,456.78', change: '+1.23%', isUp: true, high: '3,520.00', low: '3,390.00', volume: '23.4B' },
    { base: 'BNB', quote: 'USDT', price: '567.89', change: '-0.56%', isUp: false, high: '575.00', low: '560.00', volume: '4.5B' },
    { base: 'SOL', quote: 'USDT', price: '178.90', change: '+5.67%', isUp: true, high: '182.00', low: '168.00', volume: '8.9B' },
    { base: 'ADA', quote: 'USDT', price: '0.6234', change: '+3.21%', isUp: true, high: '0.6400', low: '0.6000', volume: '1.2B' },
    { base: 'XRP', quote: 'USDT', price: '0.5678', change: '-0.89%', isUp: false, high: '0.5800', low: '0.5600', volume: '2.1B' },
    { base: 'DOGE', quote: 'USDT', price: '0.1234', change: '+8.90%', isUp: true, high: '0.1290', low: '0.1120', volume: '3.4B' },
    { base: 'AVAX', quote: 'USDT', price: '42.56', change: '-1.23%', isUp: false, high: '43.80', low: '41.90', volume: '890M' },
    { base: 'DOT', quote: 'USDT', price: '8.67', change: '+1.89%', isUp: true, high: '8.90', low: '8.45', volume: '567M' },
    { base: 'MATIC', quote: 'USDT', price: '0.9876', change: '+4.56%', isUp: true, high: '1.0100', low: '0.9400', volume: '1.5B' },
]);
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
                <div class="overflow-x-auto">
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
                    Live market data integration coming soon. Showing sample data.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
