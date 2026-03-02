<script setup>
/**
 * TPIX TRADE - AI Assistant Page
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const features = [
    {
        icon: 'chart',
        title: 'Market Analysis',
        description: 'AI-powered technical and fundamental analysis of crypto markets.',
    },
    {
        icon: 'signal',
        title: 'Trading Signals',
        description: 'Real-time buy/sell signals based on advanced pattern recognition.',
    },
    {
        icon: 'shield',
        title: 'Risk Assessment',
        description: 'Evaluate portfolio risk and get suggestions for optimization.',
    },
    {
        icon: 'brain',
        title: 'Strategy Builder',
        description: 'Create automated trading strategies with AI assistance.',
    },
];
</script>

<template>
    <Head title="AI Assistant" />

    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">AI Trading Assistant</h1>
                <p class="text-dark-400">Intelligent tools to enhance your trading decisions.</p>
            </div>

            <!-- Coming Soon Banner -->
            <div class="glass-dark rounded-2xl p-8 mb-8 text-center border border-primary-500/20 bg-gradient-to-br from-accent-500/5 via-primary-500/5 to-warm-500/5">
                <div class="w-20 h-20 rounded-3xl bg-gradient-to-br from-accent-500/20 via-primary-500/20 to-warm-500/15 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-white mb-3">Coming Soon</h2>
                <p class="text-dark-400 max-w-lg mx-auto mb-6">
                    Our AI-powered trading assistant is under development. It will provide intelligent market analysis,
                    trading signals, and risk management tools to help you make better trading decisions.
                </p>
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass-sm text-sm text-dark-300">
                    <span class="w-2 h-2 rounded-full bg-warm-500 animate-pulse"></span>
                    <span>In Development</span>
                </div>
            </div>

            <!-- Feature Preview Cards -->
            <div class="grid md:grid-cols-2 gap-6">
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="glass-card group opacity-75"
                >
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-accent-500/10 via-primary-500/15 to-warm-500/10 flex items-center justify-center mb-4">
                        <!-- Chart Icon -->
                        <svg v-if="feature.icon === 'chart'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                        <!-- Signal Icon -->
                        <svg v-else-if="feature.icon === 'signal'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <!-- Shield Icon -->
                        <svg v-else-if="feature.icon === 'shield'" class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                        <!-- Brain Icon -->
                        <svg v-else class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-white mb-2">{{ feature.title }}</h3>
                    <p class="text-dark-400">{{ feature.description }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
