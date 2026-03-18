<script setup>
/**
 * TPIX TRADE - Home Page
 * Landing page with real market data from Binance API
 * Developed by Xman Studio
 */

import { ref, onMounted, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getCoinLogo } from '@/utils/cryptoLogos';
import { useMarketData } from '@/Composables/useMarketData';
import versionData from '../../../version.json';

const { topGainers, topVolume, isLoading, fetchTickers, startAutoRefresh } = useMarketData();

const features = [
    {
        icon: 'shield',
        title: 'Non-Custodial',
        description: 'Your keys, your crypto. We never have access to your funds.'
    },
    {
        icon: 'globe',
        title: 'Multi-Chain',
        description: 'Trade across all major blockchains from one interface.'
    },
    {
        icon: 'lightning',
        title: 'Lightning Fast',
        description: 'Execute trades in milliseconds with our optimized engine.'
    },
    {
        icon: 'robot',
        title: 'AI-Powered',
        description: 'Get smart insights and trading suggestions from our AI.'
    },
];

const stats = computed(() => [
    { label: 'Supported Chains', value: '9' },
    { label: 'Trading Pairs', value: '100+' },
    { label: 'DEX Protocol', value: 'PancakeSwap' },
    { label: 'Network', value: 'BSC' },
]);

onMounted(async () => {
    await fetchTickers();
    startAutoRefresh();
});
</script>

<template>
    <Head title="Decentralized Trading Platform" />

    <AppLayout :hide-sidebar="true">
        <!-- Hero Section -->
        <section class="relative py-20 overflow-hidden">
            <!-- Background Effects (brand multi-color glow) -->
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-1/3 left-1/3 w-[600px] h-[600px] bg-accent-500/8 rounded-full blur-3xl"></div>
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] bg-primary-500/10 rounded-full blur-3xl"></div>
                <div class="absolute bottom-1/3 right-1/3 w-[500px] h-[500px] bg-warm-500/5 rounded-full blur-3xl"></div>
            </div>

            <div class="relative max-w-6xl mx-auto text-center">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass-sm text-sm mb-8">
                    <span class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></span>
                    <span class="text-dark-300">Live on BNB Smart Chain</span>
                </div>

                <h1 class="text-5xl md:text-7xl font-bold text-white mb-6">
                    Trade <span class="text-gradient">Decentralized</span>
                    <br />
                    Trade <span class="text-gradient-gold">Fearlessly</span>
                </h1>

                <p class="text-xl text-dark-400 max-w-2xl mx-auto mb-10">
                    TPIX TRADE is the most secure and fastest decentralized exchange.
                    Trade directly from your wallet with zero custody risk.
                </p>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <Link href="/trade" class="btn-primary px-8 py-4 text-lg">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                        Start Trading
                    </Link>
                    <button class="btn-secondary px-8 py-4 text-lg">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Watch Demo
                    </button>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mt-20">
                    <div v-for="stat in stats" :key="stat.label" class="glass-card text-center">
                        <p class="text-3xl font-bold text-white mb-1">{{ stat.value }}</p>
                        <p class="text-dark-400">{{ stat.label }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Markets Preview -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto">
                <div class="grid md:grid-cols-2 gap-6">
                    <!-- Top Gainers -->
                    <div class="glass-dark rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-white">Top Gainers</h3>
                            <Link href="/markets" class="text-primary-400 hover:text-primary-300 text-sm">View All</Link>
                        </div>
                        <div v-if="isLoading" class="py-8 text-center text-dark-400">
                            <div class="animate-pulse">Loading live data...</div>
                        </div>
                        <div v-else class="space-y-4">
                            <div
                                v-for="coin in topGainers"
                                :key="coin.symbol"
                                class="flex items-center justify-between p-3 rounded-xl hover:bg-white/5 transition-colors cursor-pointer"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full overflow-hidden bg-dark-800 flex items-center justify-center">
                                        <img v-if="getCoinLogo(coin.symbol)" :src="getCoinLogo(coin.symbol)" :alt="coin.symbol" class="w-8 h-8" />
                                        <span v-else class="text-white font-bold">{{ coin.symbol.charAt(0) }}</span>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-white">{{ coin.symbol }}</p>
                                        <p class="text-sm text-dark-400">{{ coin.name }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-mono text-white">${{ coin.price }}</p>
                                    <p class="text-sm text-trading-green font-medium">{{ coin.change }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Volume -->
                    <div class="glass-dark rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-white">Top Volume</h3>
                            <Link href="/markets" class="text-primary-400 hover:text-primary-300 text-sm">View All</Link>
                        </div>
                        <div v-if="isLoading" class="py-8 text-center text-dark-400">
                            <div class="animate-pulse">Loading live data...</div>
                        </div>
                        <div v-else class="space-y-4">
                            <div
                                v-for="coin in topVolume"
                                :key="coin.symbol"
                                class="flex items-center justify-between p-3 rounded-xl hover:bg-white/5 transition-colors cursor-pointer"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full overflow-hidden bg-dark-800 flex items-center justify-center">
                                        <img v-if="getCoinLogo(coin.symbol)" :src="getCoinLogo(coin.symbol)" :alt="coin.symbol" class="w-8 h-8" />
                                        <span v-else class="text-white font-bold">{{ coin.symbol.charAt(0) }}</span>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-white">{{ coin.symbol }}</p>
                                        <p class="text-sm text-dark-400">{{ coin.name }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-mono text-white">${{ coin.price }}</p>
                                    <p :class="['text-sm font-medium', coin.isUp ? 'text-trading-green' : 'text-trading-red']">
                                        {{ coin.change }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TPIX Token Sale Banner — โฆษณาการขายเหรียญ -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto">
                <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-accent-500/20 via-primary-500/15 to-warm-500/20 border border-primary-500/20 p-8 md:p-12">
                    <!-- Glow Effect -->
                    <div class="absolute top-0 right-0 w-80 h-80 bg-primary-500/10 rounded-full blur-3xl"></div>
                    <div class="absolute bottom-0 left-0 w-60 h-60 bg-accent-500/10 rounded-full blur-3xl"></div>

                    <div class="relative flex flex-col lg:flex-row items-center gap-8">
                        <!-- Left: Text -->
                        <div class="flex-1 text-center lg:text-left">
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-trading-green/10 border border-trading-green/20 text-trading-green text-xs font-semibold mb-4">
                                <span class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></span>
                                Token Sale Live
                            </div>
                            <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
                                Buy <span class="text-gradient">TPIX</span> Token
                            </h2>
                            <p class="text-dark-300 mb-6 max-w-lg">
                                Join the TPIX ecosystem. Buy TPIX tokens at the best price during our ICO.
                                Pay with BNB or USDT on BSC Network.
                            </p>
                            <div class="flex flex-wrap items-center justify-center lg:justify-start gap-3">
                                <Link href="/token-sale" class="btn-primary px-6 py-3">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Buy TPIX Now
                                </Link>
                                <Link href="/whitepaper" class="btn-secondary px-6 py-3">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    Read Whitepaper
                                </Link>
                            </div>
                        </div>

                        <!-- Right: Price Cards -->
                        <div class="flex-shrink-0 grid grid-cols-3 gap-3">
                            <div class="glass-card text-center px-4 py-5 min-w-[100px]">
                                <p class="text-xs text-dark-400 mb-1">Private</p>
                                <p class="text-lg font-bold text-white">$0.05</p>
                            </div>
                            <div class="glass-card text-center px-4 py-5 min-w-[100px] border-primary-500/30">
                                <p class="text-xs text-primary-400 mb-1">Pre-Sale</p>
                                <p class="text-lg font-bold text-white">$0.08</p>
                            </div>
                            <div class="glass-card text-center px-4 py-5 min-w-[100px]">
                                <p class="text-xs text-dark-400 mb-1">Public</p>
                                <p class="text-lg font-bold text-white">$0.10</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TPIX Ecosystem — ลิงก์เข้าถึงทุกส่วนของระบบ -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
                        TPIX <span class="text-gradient">Ecosystem</span>
                    </h2>
                    <p class="text-dark-400 max-w-2xl mx-auto">
                        Explore the complete TPIX ecosystem. From token sale to staking, everything in one place.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Token Sale -->
                    <Link href="/token-sale" class="glass-card group hover:border-primary-500/30 transition-all">
                        <div class="w-12 h-12 rounded-xl bg-primary-500/10 flex items-center justify-center mb-4 group-hover:bg-primary-500/20 transition-colors">
                            <svg class="w-6 h-6 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">Token Sale</h3>
                        <p class="text-dark-400 text-sm">Buy TPIX at ICO price. Pay with BNB or USDT.</p>
                    </Link>

                    <!-- Whitepaper -->
                    <Link href="/whitepaper" class="glass-card group hover:border-accent-500/30 transition-all">
                        <div class="w-12 h-12 rounded-xl bg-accent-500/10 flex items-center justify-center mb-4 group-hover:bg-accent-500/20 transition-colors">
                            <svg class="w-6 h-6 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">Whitepaper</h3>
                        <p class="text-dark-400 text-sm">Learn about TPIX vision, tokenomics, and roadmap.</p>
                    </Link>

                    <!-- Explorer -->
                    <Link href="/explorer" class="glass-card group hover:border-trading-green/30 transition-all">
                        <div class="w-12 h-12 rounded-xl bg-trading-green/10 flex items-center justify-center mb-4 group-hover:bg-trading-green/20 transition-colors">
                            <svg class="w-6 h-6 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">Explorer</h3>
                        <p class="text-dark-400 text-sm">Browse TPIX Chain blocks, transactions, and addresses.</p>
                    </Link>

                    <!-- Staking -->
                    <Link href="/staking" class="glass-card group hover:border-warm-500/30 transition-all">
                        <div class="w-12 h-12 rounded-xl bg-warm-500/10 flex items-center justify-center mb-4 group-hover:bg-warm-500/20 transition-colors">
                            <svg class="w-6 h-6 text-warm-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">Staking</h3>
                        <p class="text-dark-400 text-sm">Stake TPIX and earn up to 200% APY rewards.</p>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
                        Why Trade with TPIX TRADE?
                    </h2>
                    <p class="text-dark-400 max-w-2xl mx-auto">
                        Built by traders, for traders. Experience the future of decentralized trading.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div v-for="feature in features" :key="feature.title" class="glass-card group">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-accent-500/10 via-primary-500/15 to-warm-500/10 flex items-center justify-center mb-4 group-hover:from-accent-500/20 group-hover:via-primary-500/25 group-hover:to-warm-500/15 transition-colors">
                            <svg v-if="feature.icon === 'shield'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            <svg v-else-if="feature.icon === 'globe'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <svg v-else-if="feature.icon === 'lightning'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                            <svg v-else class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">{{ feature.title }}</h3>
                        <p class="text-dark-400">{{ feature.description }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-16">
            <div class="max-w-4xl mx-auto">
                <div class="glass-card text-center bg-brand-gradient-subtle border-primary-500/20">
                    <h2 class="text-3xl font-bold text-white mb-4">
                        Ready to Start Trading?
                    </h2>
                    <p class="text-dark-400 mb-8 max-w-xl mx-auto">
                        Connect your wallet and start trading in seconds. No registration required.
                    </p>
                    <Link href="/trade" class="btn-primary px-8 py-4 text-lg inline-flex">
                        Launch App
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Footer ใช้จาก AppLayout — ไม่ต้องซ้ำที่นี่ -->
    </AppLayout>
</template>
