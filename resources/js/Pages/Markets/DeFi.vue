<script setup>
/**
 * TPIX TRADE - DeFi Tokens Page
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getCoinLogo } from '@/utils/cryptoLogos';

const defiTokens = ref([
    { symbol: 'UNI', name: 'Uniswap', price: '12.34', change: '+4.56%', isUp: true, tvl: '5.2B', category: 'DEX' },
    { symbol: 'AAVE', name: 'Aave', price: '123.45', change: '+2.34%', isUp: true, tvl: '12.3B', category: 'Lending' },
    { symbol: 'CAKE', name: 'PancakeSwap', price: '3.45', change: '+6.78%', isUp: true, tvl: '2.1B', category: 'DEX' },
    { symbol: 'CRV', name: 'Curve', price: '0.89', change: '-1.23%', isUp: false, tvl: '3.8B', category: 'DEX' },
    { symbol: 'MKR', name: 'Maker', price: '2,345.67', change: '+1.23%', isUp: true, tvl: '8.9B', category: 'Lending' },
    { symbol: 'COMP', name: 'Compound', price: '67.89', change: '-0.45%', isUp: false, tvl: '2.4B', category: 'Lending' },
    { symbol: 'SNX', name: 'Synthetix', price: '3.21', change: '+5.67%', isUp: true, tvl: '890M', category: 'Derivatives' },
    { symbol: 'SUSHI', name: 'SushiSwap', price: '1.23', change: '+3.45%', isUp: true, tvl: '567M', category: 'DEX' },
]);
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
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="glass-card text-center">
                    <p class="text-2xl font-bold text-white">$156B</p>
                    <p class="text-sm text-dark-400">Total TVL</p>
                </div>
                <div class="glass-card text-center">
                    <p class="text-2xl font-bold text-white">2,500+</p>
                    <p class="text-sm text-dark-400">Protocols</p>
                </div>
                <div class="glass-card text-center">
                    <p class="text-2xl font-bold text-trading-green">+12.5%</p>
                    <p class="text-sm text-dark-400">TVL 24h</p>
                </div>
                <div class="glass-card text-center">
                    <p class="text-2xl font-bold text-white">45</p>
                    <p class="text-sm text-dark-400">Chains</p>
                </div>
            </div>

            <!-- Tokens Table -->
            <div class="glass-dark rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/5 text-dark-400 text-sm">
                                <th class="text-left p-4">#</th>
                                <th class="text-left p-4">Protocol</th>
                                <th class="text-left p-4">Category</th>
                                <th class="text-right p-4">Price</th>
                                <th class="text-right p-4">24h Change</th>
                                <th class="text-right p-4">TVL</th>
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
                                <td class="p-4 text-right font-mono text-dark-300">${{ token.tvl }}</td>
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
                    Live DeFi data integration coming soon. Showing sample data.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
