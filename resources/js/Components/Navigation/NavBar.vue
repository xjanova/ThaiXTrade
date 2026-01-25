<script setup>
/**
 * ThaiXTrade - Navigation Bar Component
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    user: Object,
    wallet: Object,
});

const emit = defineEmits(['toggle-sidebar', 'connect-wallet']);

const isWalletConnected = computed(() => !!props.wallet?.address);
const shortAddress = computed(() => {
    if (!props.wallet?.address) return '';
    const addr = props.wallet.address;
    return `${addr.slice(0, 6)}...${addr.slice(-4)}`;
});

const menuOpen = ref(false);
</script>

<template>
    <nav class="sticky top-0 z-40 glass-dark border-b border-white/5">
        <div class="max-w-[1920px] mx-auto px-4 lg:px-6">
            <div class="flex items-center justify-between h-16">
                <!-- Left: Logo & Toggle -->
                <div class="flex items-center gap-4">
                    <!-- Mobile Menu Toggle -->
                    <button
                        @click="$emit('toggle-sidebar')"
                        class="lg:hidden p-2 rounded-xl text-dark-400 hover:text-white hover:bg-white/5"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>

                    <!-- Logo -->
                    <Link href="/" class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center shadow-glow-sm">
                            <span class="text-white font-bold text-lg">TX</span>
                        </div>
                        <div class="hidden sm:block">
                            <h1 class="text-xl font-bold text-white">ThaiX<span class="text-primary-400">Trade</span></h1>
                            <p class="text-xs text-dark-400">by Xman Studio</p>
                        </div>
                    </Link>
                </div>

                <!-- Center: Main Navigation -->
                <div class="hidden md:flex items-center gap-1">
                    <Link href="/trade" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                        <span>Trade</span>
                    </Link>
                    <Link href="/swap" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        <span>Swap</span>
                    </Link>
                    <Link href="/portfolio" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
                        </svg>
                        <span>Portfolio</span>
                    </Link>
                    <Link href="/ai-assistant" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <span>AI Assistant</span>
                    </Link>
                </div>

                <!-- Right: Wallet & User -->
                <div class="flex items-center gap-3">
                    <!-- Chain Selector -->
                    <button class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-xl glass-sm hover:bg-white/10 transition-all">
                        <img src="https://cryptologos.cc/logos/bnb-bnb-logo.svg" alt="BSC" class="w-5 h-5">
                        <span class="text-sm font-medium text-white">BSC</span>
                        <svg class="w-4 h-4 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <!-- Connect Wallet Button -->
                    <button
                        v-if="!isWalletConnected"
                        @click="$emit('connect-wallet')"
                        class="btn-primary"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <span class="hidden sm:inline">Connect Wallet</span>
                    </button>

                    <!-- Connected Wallet -->
                    <div v-else class="flex items-center gap-3">
                        <div class="wallet-badge">
                            <div class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></div>
                            <span class="wallet-address">{{ shortAddress }}</span>
                        </div>
                        <button class="p-2 rounded-xl hover:bg-white/10 transition-all">
                            <svg class="w-5 h-5 text-dark-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </nav>
</template>
