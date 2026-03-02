<script setup>
/**
 * TPIX TRADE - NFT Markets Page
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const collections = ref([
    { name: 'Bored Ape Yacht Club', floor: '28.5 ETH', change: '+5.2%', isUp: true, volume: '1,234 ETH', items: '10,000' },
    { name: 'CryptoPunks', floor: '45.0 ETH', change: '-2.1%', isUp: false, volume: '890 ETH', items: '10,000' },
    { name: 'Pudgy Penguins', floor: '12.3 ETH', change: '+8.7%', isUp: true, volume: '567 ETH', items: '8,888' },
    { name: 'Azuki', floor: '8.9 ETH', change: '+3.4%', isUp: true, volume: '345 ETH', items: '10,000' },
    { name: 'Doodles', floor: '3.2 ETH', change: '-1.5%', isUp: false, volume: '123 ETH', items: '10,000' },
    { name: 'CloneX', floor: '2.8 ETH', change: '+1.2%', isUp: true, volume: '234 ETH', items: '20,000' },
]);
</script>

<template>
    <Head title="NFT Markets" />

    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <div class="flex items-center gap-3 mb-2">
                    <Link href="/markets" class="text-dark-400 hover:text-white transition-colors">Markets</Link>
                    <svg class="w-4 h-4 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                    <span class="text-white font-medium">NFT</span>
                </div>
                <h1 class="text-3xl font-bold text-white mb-2">NFT Markets</h1>
                <p class="text-dark-400">Discover and trade top NFT collections.</p>
            </div>

            <!-- Coming Soon Banner -->
            <div class="glass-dark rounded-2xl p-8 mb-8 text-center border border-primary-500/20 bg-gradient-to-br from-accent-500/5 via-primary-500/5 to-warm-500/5">
                <div class="w-20 h-20 rounded-3xl bg-gradient-to-br from-accent-500/20 via-primary-500/20 to-warm-500/15 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-white mb-3">NFT Trading Coming Soon</h2>
                <p class="text-dark-400 max-w-lg mx-auto">
                    NFT marketplace integration is under development. Soon you'll be able to browse, buy, and sell NFTs directly on TPIX TRADE.
                </p>
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass-sm text-sm text-dark-300 mt-6">
                    <span class="w-2 h-2 rounded-full bg-warm-500 animate-pulse"></span>
                    <span>In Development</span>
                </div>
            </div>

            <!-- Preview Collections Table -->
            <div class="glass-dark rounded-2xl overflow-hidden">
                <div class="p-4 border-b border-white/5">
                    <h3 class="text-lg font-semibold text-white">Trending Collections</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/5 text-dark-400 text-sm">
                                <th class="text-left p-4">#</th>
                                <th class="text-left p-4">Collection</th>
                                <th class="text-right p-4">Floor Price</th>
                                <th class="text-right p-4">24h Change</th>
                                <th class="text-right p-4">Volume (24h)</th>
                                <th class="text-right p-4">Items</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(collection, index) in collections"
                                :key="collection.name"
                                class="border-b border-white/5 hover:bg-white/5 transition-colors opacity-60"
                            >
                                <td class="p-4 text-dark-400">{{ index + 1 }}</td>
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-500/20 to-accent-500/20 flex items-center justify-center">
                                            <span class="text-sm font-bold text-white">{{ collection.name.charAt(0) }}</span>
                                        </div>
                                        <span class="font-medium text-white">{{ collection.name }}</span>
                                    </div>
                                </td>
                                <td class="p-4 text-right font-mono text-white">{{ collection.floor }}</td>
                                <td class="p-4 text-right">
                                    <span :class="collection.isUp ? 'text-trading-green' : 'text-trading-red'" class="font-medium">
                                        {{ collection.change }}
                                    </span>
                                </td>
                                <td class="p-4 text-right font-mono text-dark-300">{{ collection.volume }}</td>
                                <td class="p-4 text-right text-dark-300">{{ collection.items }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="p-4 text-center text-dark-500 text-sm border-t border-white/5">
                    Sample data shown. Live NFT market data coming soon.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
