<script setup>
/**
 * TPIX TRADE - Sidebar Component
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getBaseSymbol } from '@/utils/cryptoLogos';
import CoinIcon from '@/Components/CoinIcon.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

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
    <aside class="w-64 min-h-[calc(100vh-120px)] max-h-[calc(100vh-120px)] overflow-y-auto thin-scrollbar glass-dark border-r border-white/5 p-4">
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
                        <CoinIcon :symbol="getBaseSymbol(pair.symbol)" size="sm" />
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
            <h3 class="text-sm font-semibold text-dark-300 uppercase tracking-wider mb-3">{{ t('nav.markets') }}</h3>
            <div class="space-y-1">
                <Link href="/markets/spot" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span>{{ t('markets.spot') }}</span>
                </Link>
                <Link href="/markets/defi" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                    </svg>
                    <span>{{ t('markets.defi') }}</span>
                </Link>
                <Link href="/markets/nft" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span>{{ t('markets.nft') }}</span>
                </Link>
            </div>
        </div>

        <!-- Recent Trades -->
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-dark-300 uppercase tracking-wider mb-3">{{ t('trade.recentTrades') }}</h3>
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

        <!-- TPIX Ecosystem -->
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-dark-300 uppercase tracking-wider mb-3">{{ t('home.ecosystem') }}</h3>
            <div class="space-y-1">
                <Link href="/token-sale" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ t('nav.tokenSale') }}</span>
                </Link>
                <Link href="/token-factory" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                    </svg>
                    <span>{{ t('nav.tokenFactory') }}</span>
                </Link>
                <Link href="/carbon-credits" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ t('nav.carbonCredit') }}</span>
                </Link>
                <Link href="/blog" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
                    </svg>
                    <span>{{ t('nav.blog') }}</span>
                </Link>
                <Link href="/explorer" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <span>{{ t('nav.explorer') }}</span>
                </Link>
                <Link href="/whitepaper" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span>{{ t('nav.whitepaper') }}</span>
                </Link>
                <Link href="/bridge" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                    <span>{{ t('nav.bridge') }}</span>
                </Link>
                <Link href="/masternode" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <span>{{ t('nav.masternode') }}</span>
                </Link>
                <Link href="/validators" class="nav-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <span>Validators</span>
                </Link>
                <Link href="/download" class="nav-link text-primary-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    <span>Download App</span>
                </Link>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mt-auto">
            <div class="p-4 rounded-xl bg-gradient-to-br from-accent-500/15 via-primary-500/15 to-warm-500/10 border border-primary-500/20">
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
