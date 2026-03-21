<script setup>
/**
 * TPIX TRADE - NFT Markets Page
 * NFT marketplace - Coming Soon
 * Developed by Xman Studio
 */

import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
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

            <!-- Coming Soon -->
            <div class="glass-dark rounded-2xl p-12 text-center border border-primary-500/20 bg-gradient-to-br from-accent-500/5 via-primary-500/5 to-warm-500/5">
                <div class="w-20 h-20 rounded-3xl bg-gradient-to-br from-accent-500/20 via-primary-500/20 to-warm-500/15 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-white mb-3">NFT Trading Coming Soon</h2>
                <p class="text-dark-400 max-w-lg mx-auto mb-6">
                    NFT marketplace integration is under development. Soon you'll be able to browse, buy, and sell NFTs directly on TPIX TRADE.
                </p>
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass-sm text-sm text-dark-300 mb-8">
                    <span class="w-2 h-2 rounded-full bg-warm-500 animate-pulse"></span>
                    <span>In Development</span>
                </div>

                <!-- Planned Features -->
                <div class="grid md:grid-cols-3 gap-4 max-w-3xl mx-auto mt-8">
                    <div class="glass-card text-center">
                        <div class="w-12 h-12 rounded-xl bg-primary-500/10 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                            </svg>
                        </div>
                        <h3 class="text-white font-semibold mb-1">Browse Collections</h3>
                        <p class="text-dark-400 text-sm">Explore NFT collections across multiple chains</p>
                    </div>
                    <div class="glass-card text-center">
                        <div class="w-12 h-12 rounded-xl bg-accent-500/10 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/>
                            </svg>
                        </div>
                        <h3 class="text-white font-semibold mb-1">Buy & Sell</h3>
                        <p class="text-dark-400 text-sm">Trade NFTs directly from your wallet</p>
                    </div>
                    <div class="glass-card text-center">
                        <div class="w-12 h-12 rounded-xl bg-trading-green/10 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                        </div>
                        <h3 class="text-white font-semibold mb-1">Verified Collections</h3>
                        <p class="text-dark-400 text-sm">Only verified and audited collections</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
