<script setup>
/**
 * TPIX TRADE - Bridge Page (Coming Soon)
 * สะพานเชื่อม TPIX Chain ↔ BSC — lock/mint mechanism
 * Developed by Xman Studio
 */

import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
</script>

<template>
    <Head title="TPIX Bridge — Coming Soon" />

    <AppLayout :hide-sidebar="true">
        <section class="relative py-20 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-1/3 left-1/4 w-80 h-80 rounded-full bg-accent-500/10 blur-[100px]" />
                <div class="absolute bottom-1/3 right-1/4 w-80 h-80 rounded-full bg-primary-500/10 blur-[100px]" />
            </div>

            <div class="relative max-w-3xl mx-auto px-4 text-center">
                <img src="/logo.png" alt="TPIX" class="w-20 h-20 mx-auto mb-6 rounded-full" />
                <h1 class="text-4xl sm:text-5xl font-bold text-white mb-4">TPIX Bridge</h1>
                <p class="text-xl text-gray-400 mb-8">
                    Cross-chain bridge between TPIX Chain and BNB Smart Chain
                </p>

                <!-- Coming Soon Badge -->
                <div class="inline-block px-6 py-3 rounded-full bg-accent-500/20 border border-accent-500/30 mb-8">
                    <span class="text-accent-400 font-bold text-lg">Coming Soon — Q2 2026</span>
                </div>

                <!-- Bridge Preview -->
                <div class="glass-dark p-8 rounded-xl border border-white/10 max-w-md mx-auto">
                    <div class="flex items-center justify-between mb-6">
                        <div class="text-center">
                            <div class="w-12 h-12 rounded-full bg-primary-500/20 flex items-center justify-center mx-auto mb-2">
                                <span class="text-xl font-bold text-primary-400">T</span>
                            </div>
                            <span class="text-sm text-gray-400">TPIX Chain</span>
                        </div>
                        <div class="flex-1 px-4">
                            <div class="h-px bg-gradient-to-r from-primary-500 to-accent-500 relative">
                                <svg class="w-6 h-6 text-accent-400 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-dark-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-center">
                            <div class="w-12 h-12 rounded-full bg-yellow-500/20 flex items-center justify-center mx-auto mb-2">
                                <span class="text-xl font-bold text-yellow-400">B</span>
                            </div>
                            <span class="text-sm text-gray-400">BSC</span>
                        </div>
                    </div>

                    <p class="text-sm text-gray-400">
                        Bridge TPIX ↔ wTPIX (BEP-20) with secure lock/mint mechanism and relay service.
                    </p>
                </div>

                <!-- Features Preview -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-12 max-w-2xl mx-auto">
                    <div class="glass-dark p-4 rounded-lg border border-white/10">
                        <p class="text-primary-400 font-bold mb-1">Lock & Mint</p>
                        <p class="text-xs text-gray-400">Secure cross-chain transfers</p>
                    </div>
                    <div class="glass-dark p-4 rounded-lg border border-white/10">
                        <p class="text-trading-green font-bold mb-1">Low Fees</p>
                        <p class="text-xs text-gray-400">Only BSC gas required</p>
                    </div>
                    <div class="glass-dark p-4 rounded-lg border border-white/10">
                        <p class="text-accent-400 font-bold mb-1">Fast</p>
                        <p class="text-xs text-gray-400">~2 minute transfers</p>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
