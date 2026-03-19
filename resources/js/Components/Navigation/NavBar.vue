<script setup>
/**
 * TPIX TRADE - Navigation Bar Component
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useWalletStore } from '@/Stores/walletStore';
import ChainSelector from '@/Components/Navigation/ChainSelector.vue';
import LanguageSwitcher from '@/Components/Navigation/LanguageSwitcher.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const props = defineProps({
    user: Object,
    wallet: Object,
});

const emit = defineEmits(['toggle-sidebar', 'connect-wallet']);

const walletStore = useWalletStore();

const isWalletConnected = computed(() => walletStore.isConnected);
const shortAddress = computed(() => walletStore.shortAddress);

const menuOpen = ref(false);
const showWalletMenu = ref(false);

const handleDisconnect = () => {
    walletStore.disconnect();
    showWalletMenu.value = false;
};
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
                        <img src="/logo.png" alt="TPIX TRADE" class="w-10 h-10 rounded-xl object-cover shadow-glow-sm" />
                        <div class="hidden sm:block">
                            <h1 class="text-xl font-bold text-white">TPIX <span class="text-gradient">TRADE</span></h1>
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
                        <span>{{ t('nav.trade') }}</span>
                    </Link>
                    <Link href="/swap" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        <span>{{ t('nav.swap') }}</span>
                    </Link>
                    <Link href="/portfolio" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
                        </svg>
                        <span>{{ t('nav.portfolio') }}</span>
                    </Link>
                    <Link href="/token-sale" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>{{ t('nav.tokenSale') }}</span>
                    </Link>
                    <!-- More dropdown เพื่อไม่ให้ nav ยาวเกินไป -->
                    <div class="relative group">
                        <button class="nav-link">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z"/>
                            </svg>
                            <span>{{ t('common.viewAll') }}</span>
                        </button>
                        <div class="absolute top-full left-0 mt-1 w-48 rounded-xl glass border border-white/10 shadow-xl py-2 z-50 invisible group-hover:visible opacity-0 group-hover:opacity-100 transition-all">
                            <Link href="/ai-assistant" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                AI Assistant
                            </Link>
                            <Link href="/explorer" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                Explorer
                            </Link>
                            <Link href="/whitepaper" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                Whitepaper
                            </Link>
                            <Link href="/token-factory" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                Token Factory
                            </Link>
                            <Link href="/carbon-credits" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                Carbon Credits
                            </Link>
                            <div class="border-t border-white/5 my-1"></div>
                            <Link href="/bridge" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                {{ t('nav.bridge') }}
                            </Link>
                            <Link href="/staking" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                {{ t('nav.staking') }}
                            </Link>
                            <Link href="/blog" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                {{ t('nav.blog') }}
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Right: Wallet & User -->
                <div class="flex items-center gap-3">
                    <!-- ตัวเลือก Chain - รองรับหลาย chain พร้อม auto-switch -->
                    <ChainSelector class="hidden sm:block" />

                    <!-- Language Switcher -->
                    <LanguageSwitcher />

                    <!-- Connect Wallet Button -->
                    <button
                        v-if="!isWalletConnected"
                        @click="$emit('connect-wallet')"
                        class="btn-primary"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <span class="hidden sm:inline">{{ t('wallet.connect') }}</span>
                    </button>

                    <!-- Connected Wallet -->
                    <div v-else class="flex items-center gap-3 relative">
                        <button
                            @click="showWalletMenu = !showWalletMenu"
                            class="wallet-badge cursor-pointer hover:bg-white/10 transition-all"
                        >
                            <div class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></div>
                            <span class="wallet-address">{{ shortAddress }}</span>
                            <svg class="w-3 h-3 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <!-- Wallet Dropdown Menu -->
                        <Transition
                            enter-active-class="transition ease-out duration-100"
                            enter-from-class="opacity-0 scale-95"
                            enter-to-class="opacity-100 scale-100"
                            leave-active-class="transition ease-in duration-75"
                            leave-from-class="opacity-100 scale-100"
                            leave-to-class="opacity-0 scale-95"
                        >
                            <div
                                v-if="showWalletMenu"
                                class="absolute right-0 top-full mt-2 w-56 rounded-xl glass border border-white/10 shadow-xl py-2 z-50"
                                @click.stop
                            >
                                <div class="px-4 py-2 border-b border-white/5">
                                    <p class="text-xs text-dark-400">Connected Wallet</p>
                                    <p class="text-sm text-white font-mono truncate">{{ walletStore.address }}</p>
                                    <p class="text-xs text-dark-500 mt-1">Chain ID: {{ walletStore.chainId }}</p>
                                </div>
                                <!-- ลิงก์ดูที่อยู่บน block explorer (รองรับหลาย chain) -->
                                <a
                                    :href="walletStore.explorerAddressUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5 transition-colors"
                                    @click="showWalletMenu = false"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                    </svg>
                                    {{ t('nav.viewOnExplorer') }}
                                </a>
                                <button
                                    @click="handleDisconnect"
                                    class="w-full flex items-center gap-2 px-4 py-2 text-sm text-trading-red hover:bg-trading-red/10 transition-colors"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    {{ t('nav.disconnect') }}
                                </button>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </div>
    </nav>
</template>
