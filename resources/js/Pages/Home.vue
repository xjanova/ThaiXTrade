<script setup>
/**
 * ThaiXTrade - Home Page
 * Landing page with market overview
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const topGainers = ref([
    { symbol: 'PEPE', name: 'Pepe', price: '0.00001234', change: '+45.67%', volume: '2.5B' },
    { symbol: 'BONK', name: 'Bonk', price: '0.00002345', change: '+32.45%', volume: '1.8B' },
    { symbol: 'WIF', name: 'dogwifhat', price: '2.34', change: '+28.90%', volume: '890M' },
    { symbol: 'FLOKI', name: 'Floki', price: '0.000234', change: '+23.45%', volume: '567M' },
]);

const topVolume = ref([
    { symbol: 'BTC', name: 'Bitcoin', price: '67,234.50', change: '+2.45%', volume: '45.6B' },
    { symbol: 'ETH', name: 'Ethereum', price: '3,456.78', change: '+1.23%', volume: '23.4B' },
    { symbol: 'SOL', name: 'Solana', price: '178.90', change: '+5.67%', volume: '8.9B' },
    { symbol: 'BNB', name: 'BNB', price: '567.89', change: '-0.56%', volume: '4.5B' },
]);

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

const stats = ref([
    { label: 'Total Volume', value: '$12.5B+', suffix: '' },
    { label: 'Total Trades', value: '45M+', suffix: '' },
    { label: 'Supported Chains', value: '50+', suffix: '' },
    { label: 'Active Traders', value: '250K+', suffix: '' },
]);
</script>

<template>
    <Head title="Decentralized Trading Platform" />

    <AppLayout>
        <!-- Hero Section -->
        <section class="relative py-20 overflow-hidden">
            <!-- Background Effects -->
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] bg-primary-500/10 rounded-full blur-3xl"></div>
            </div>

            <div class="relative max-w-6xl mx-auto text-center">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass-sm text-sm mb-8">
                    <span class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></span>
                    <span class="text-dark-300">Live on 50+ Blockchains</span>
                </div>

                <h1 class="text-5xl md:text-7xl font-bold text-white mb-6">
                    Trade <span class="text-gradient">Decentralized</span>
                    <br />
                    Trade <span class="text-gradient-gold">Fearlessly</span>
                </h1>

                <p class="text-xl text-dark-400 max-w-2xl mx-auto mb-10">
                    ThaiXTrade is the most secure and fastest decentralized exchange.
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
                        <div class="space-y-4">
                            <div
                                v-for="coin in topGainers"
                                :key="coin.symbol"
                                class="flex items-center justify-between p-3 rounded-xl hover:bg-white/5 transition-colors cursor-pointer"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center text-white font-bold">
                                        {{ coin.symbol.charAt(0) }}
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
                        <div class="space-y-4">
                            <div
                                v-for="coin in topVolume"
                                :key="coin.symbol"
                                class="flex items-center justify-between p-3 rounded-xl hover:bg-white/5 transition-colors cursor-pointer"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-orange-400 to-orange-600 flex items-center justify-center text-white font-bold">
                                        {{ coin.symbol.charAt(0) }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-white">{{ coin.symbol }}</p>
                                        <p class="text-sm text-dark-400">{{ coin.name }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-mono text-white">${{ coin.price }}</p>
                                    <p :class="['text-sm font-medium', coin.change.startsWith('+') ? 'text-trading-green' : 'text-trading-red']">
                                        {{ coin.change }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="py-16">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
                        Why Trade with ThaiXTrade?
                    </h2>
                    <p class="text-dark-400 max-w-2xl mx-auto">
                        Built by traders, for traders. Experience the future of decentralized trading.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div v-for="feature in features" :key="feature.title" class="glass-card group">
                        <div class="w-14 h-14 rounded-2xl bg-primary-500/10 flex items-center justify-center mb-4 group-hover:bg-primary-500/20 transition-colors">
                            <!-- Shield Icon -->
                            <svg v-if="feature.icon === 'shield'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            <!-- Globe Icon -->
                            <svg v-else-if="feature.icon === 'globe'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <!-- Lightning Icon -->
                            <svg v-else-if="feature.icon === 'lightning'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                            <!-- Robot Icon -->
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
                <div class="glass-card text-center bg-gradient-to-br from-primary-500/10 to-primary-600/5 border-primary-500/20">
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

        <!-- Footer -->
        <footer class="border-t border-white/5 py-12">
            <div class="max-w-6xl mx-auto">
                <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center">
                            <span class="text-white font-bold text-lg">TX</span>
                        </div>
                        <div>
                            <p class="font-bold text-white">ThaiXTrade</p>
                            <p class="text-xs text-dark-400">by Xman Studio</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-6 text-dark-400">
                        <a href="#" class="hover:text-white transition-colors">Docs</a>
                        <a href="#" class="hover:text-white transition-colors">GitHub</a>
                        <a href="#" class="hover:text-white transition-colors">Discord</a>
                        <a href="#" class="hover:text-white transition-colors">Twitter</a>
                    </div>
                    <p class="text-sm text-dark-500">
                        © 2024 Xman Studio. All rights reserved.
                    </p>
                </div>
            </div>
        </footer>
    </AppLayout>
</template>
