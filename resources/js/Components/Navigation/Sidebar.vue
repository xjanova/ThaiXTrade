<script setup>
/**
 * TPIX TRADE - Sidebar Component
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const favoritesPairs = ref([
    { symbol: 'BTC/USDT', price: '67,234.50', change: '+2.45%', isUp: true },
    { symbol: 'ETH/USDT', price: '3,456.78', change: '+1.23%', isUp: true },
    { symbol: 'BNB/USDT', price: '567.89', change: '-0.56%', isUp: false },
    { symbol: 'SOL/USDT', price: '178.90', change: '+5.67%', isUp: true },
]);

const recentTrades = ref([
    { type: 'buy', symbol: 'BTC', amount: '0.05', time: '2 min ago' },
    { type: 'sell', symbol: 'ETH', amount: '1.2', time: '15 min ago' },
    { type: 'buy', symbol: 'SOL', amount: '10', time: '1 hour ago' },
]);
</script>

<template>
    <aside class="w-64 min-h-[calc(100vh-120px)] glass-dark border-r border-white/5 p-4">
        <!-- Favorites Section -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-dark-300 uppercase tracking-wider">Favorites</h3>
                <button class="text-dark-400 hover:text-primary-400 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                </button>
            </div>
            <div class="space-y-1">
                <Link
                    v-for="pair in favoritesPairs"
                    :key="pair.symbol"
                    :href="`/trade/${pair.symbol.replace('/', '-')}`"
                    class="flex items-center justify-between p-2 rounded-xl hover:bg-white/5 transition-all group"
                >
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                        <span class="font-medium text-white group-hover:text-primary-400 transition-colors">{{ pair.symbol }}</span>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-mono text-white">${{ pair.price }}</p>
                        <p :class="['text-xs font-medium', pair.isUp ? 'text-trading-green' : 'text-trading-red']">
                            {{ pair.change }}
                        </p>
                    </div>
                </Link>
            </div>
        </div>

        <!-- Markets Section -->
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-dark-300 uppercase tracking-wider mb-3">Markets</h3>
            <div class="space-y-1">
                <Link href="/markets/spot" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span>Spot Markets</span>
                </Link>
                <Link href="/markets/defi" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                    </svg>
                    <span>DeFi Tokens</span>
                </Link>
                <Link href="/markets/nft" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span>NFT Markets</span>
                </Link>
            </div>
        </div>

        <!-- Recent Trades -->
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-dark-300 uppercase tracking-wider mb-3">Recent Activity</h3>
            <div class="space-y-2">
                <div
                    v-for="trade in recentTrades"
                    :key="trade.time"
                    class="flex items-center justify-between p-2 rounded-xl bg-white/5"
                >
                    <div class="flex items-center gap-2">
                        <div :class="['w-6 h-6 rounded-full flex items-center justify-center', trade.type === 'buy' ? 'bg-trading-green/20' : 'bg-trading-red/20']">
                            <svg :class="['w-3 h-3', trade.type === 'buy' ? 'text-trading-green' : 'text-trading-red']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path v-if="trade.type === 'buy'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-white">{{ trade.type === 'buy' ? 'Bought' : 'Sold' }} {{ trade.symbol }}</p>
                            <p class="text-xs text-dark-400">{{ trade.amount }} {{ trade.symbol }}</p>
                        </div>
                    </div>
                    <span class="text-xs text-dark-400">{{ trade.time }}</span>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mt-auto">
            <div class="p-4 rounded-xl bg-gradient-to-br from-primary-500/20 to-primary-600/10 border border-primary-500/20">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-xl bg-primary-500/20 flex items-center justify-center">
                        <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="font-semibold text-white">AI Trading</p>
                        <p class="text-xs text-dark-400">Smart analysis</p>
                    </div>
                </div>
                <button class="w-full btn-primary text-sm">
                    Get AI Insights
                </button>
            </div>
        </div>
    </aside>
</template>
