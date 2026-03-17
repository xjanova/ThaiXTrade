<script setup>
/**
 * TPIX TRADE - Staking Page (Coming Soon)
 * ระบบ Staking TPIX — APY 5%-200% ตาม lock period
 * Developed by Xman Studio
 */

import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const stakingTiers = [
    { period: 'Flexible', apy: '5%', color: 'text-gray-300' },
    { period: '30 Days', apy: '25%', color: 'text-primary-400' },
    { period: '90 Days', apy: '60%', color: 'text-accent-400' },
    { period: '180 Days', apy: '100%', color: 'text-trading-green' },
    { period: '365 Days', apy: '200%', color: 'text-warm-400' },
];
</script>

<template>
    <Head title="TPIX Staking — Coming Soon" />

    <AppLayout :hide-sidebar="true">
        <section class="relative py-20 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-1/4 right-1/4 w-96 h-96 rounded-full bg-trading-green/5 blur-[120px]" />
            </div>

            <div class="relative max-w-3xl mx-auto px-4 text-center">
                <img src="/logo.png" alt="TPIX" class="w-20 h-20 mx-auto mb-6 rounded-full" />
                <h1 class="text-4xl sm:text-5xl font-bold text-white mb-4">TPIX Staking</h1>
                <p class="text-xl text-gray-400 mb-8">
                    Earn rewards by staking your TPIX tokens
                </p>

                <!-- Coming Soon Badge -->
                <div class="inline-block px-6 py-3 rounded-full bg-trading-green/20 border border-trading-green/30 mb-12">
                    <span class="text-trading-green font-bold text-lg">Coming Soon — Q2 2026</span>
                </div>

                <!-- APY Tiers -->
                <div class="max-w-md mx-auto">
                    <h3 class="text-lg font-bold text-white mb-4">Staking APY Tiers</h3>
                    <div class="space-y-3">
                        <div
                            v-for="tier in stakingTiers"
                            :key="tier.period"
                            class="glass-dark flex items-center justify-between p-4 rounded-lg border border-white/10"
                        >
                            <span class="text-gray-300">{{ tier.period }}</span>
                            <span class="text-xl font-bold" :class="tier.color">{{ tier.apy }}</span>
                        </div>
                    </div>
                </div>

                <!-- Info -->
                <div class="mt-12 max-w-lg mx-auto text-left glass-dark p-6 rounded-xl border border-white/10">
                    <h3 class="text-white font-bold mb-3">How It Works</h3>
                    <ul class="space-y-2 text-sm text-gray-400">
                        <li>1. Connect your wallet and choose a staking pool</li>
                        <li>2. Select your lock period for higher APY</li>
                        <li>3. Stake your TPIX tokens</li>
                        <li>4. Earn rewards distributed from the staking pool (1.4B TPIX over 5 years)</li>
                        <li>5. Withdraw after the lock period ends</li>
                    </ul>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
