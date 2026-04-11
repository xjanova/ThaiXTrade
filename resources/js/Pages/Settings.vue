<script setup>
/**
 * TPIX TRADE - Settings Page
 * Transaction settings (persisted to localStorage) + wallet management
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import { isMobile, openTpixApp, downloadTpixApp, TPIX_APP } from '@/utils/mobileWallet';

const { t } = useTranslation();
const walletStore = useWalletStore();
const mobile = isMobile();
const isConnected = computed(() => walletStore.isConnected);

// === Transaction Settings (persisted to localStorage) ===
const SETTINGS_KEY = 'tpix_trade_settings';

function loadSettings() {
    try {
        const saved = localStorage.getItem(SETTINGS_KEY);
        if (saved) return JSON.parse(saved);
    } catch { /* ignore corrupt data */ }
    return null;
}

function saveSettings() {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify({
        slippageTolerance: slippageTolerance.value,
        txDeadline: txDeadline.value,
        gasOption: gasOption.value,
    }));
}

const saved = loadSettings();
const slippageTolerance = ref(saved?.slippageTolerance || '0.5');
const txDeadline = ref(saved?.txDeadline || '20');
const gasOption = ref(saved?.gasOption || 'standard');

// Auto-save เมื่อค่าเปลี่ยน
watch([slippageTolerance, txDeadline, gasOption], saveSettings);

const slippageOptions = ['0.1', '0.5', '1.0'];
const gasOptions = [
    { id: 'low', labelKey: 'settings.gasLow', descKey: 'settings.gasLowDesc' },
    { id: 'standard', labelKey: 'settings.gasStandard', descKey: 'settings.gasStandardDesc' },
    { id: 'fast', labelKey: 'settings.gasFast', descKey: 'settings.gasFastDesc' },
];

// === Wallet ===
const addressCopied = ref(false);

function copyAddress() {
    if (!walletStore.address) return;
    navigator.clipboard.writeText(walletStore.address).then(() => {
        addressCopied.value = true;
        setTimeout(() => { addressCopied.value = false; }, 2000);
    }).catch(() => {});
}

// ชื่อ chain ที่อ่านง่าย
const chainDisplayName = computed(() => {
    if (!walletStore.chainId) return '';
    if (walletStore.chainId === 56) return 'BNB Smart Chain';
    if (walletStore.chainId === 4289) return 'TPIX Chain';
    if (walletStore.chainId === 97) return 'BSC Testnet';
    return `Chain ID: ${walletStore.chainId}`;
});

// ชื่อ wallet type ที่อ่านง่าย
const walletTypeDisplay = computed(() => {
    const names = {
        metamask: 'MetaMask',
        trustwallet: 'Trust Wallet',
        coinbase: 'Coinbase Wallet',
        okx: 'OKX Wallet',
        tpix_wallet: 'TPIX Wallet',
    };
    return names[walletStore.walletType] || walletStore.walletType || '';
});

// Slippage warning
const slippageWarning = computed(() => {
    const val = parseFloat(slippageTolerance.value);
    if (isNaN(val) || val <= 0) return 'invalid';
    if (val > 5) return 'high';
    if (val < 0.05) return 'low';
    return null;
});

// Load TPIX balance ถ้าเป็น embedded wallet
onMounted(() => {
    if (walletStore.isEmbedded && walletStore.isConnected) {
        walletStore.refreshTPIXBalance();
    }
});
</script>

<template>
    <Head :title="t('settings.title')" />

    <AppLayout>
        <div class="max-w-3xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">{{ t('settings.title') }}</h1>
                <p class="text-dark-400">{{ t('settings.subtitle') }}</p>
            </div>

            <!-- Transaction Settings -->
            <div class="glass-dark rounded-2xl p-6 mb-6">
                <h2 class="text-lg font-semibold text-white mb-6">{{ t('settings.txSettings') }}</h2>

                <!-- Slippage Tolerance -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-dark-300 mb-3">{{ t('settings.slippage') }}</label>
                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            v-for="option in slippageOptions"
                            :key="option"
                            @click="slippageTolerance = option"
                            :class="[
                                'px-4 py-2 rounded-xl text-sm font-medium transition-all',
                                slippageTolerance === option
                                    ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30'
                                    : 'glass-sm text-dark-400 hover:text-white'
                            ]"
                        >
                            {{ option }}%
                        </button>
                        <div class="relative flex-1 min-w-[120px]">
                            <input
                                v-model="slippageTolerance"
                                type="text"
                                class="w-full px-4 py-2 rounded-xl glass-sm border border-white/10 bg-transparent text-white text-right focus:outline-none focus:border-primary-500/50"
                            />
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-dark-400">%</span>
                        </div>
                    </div>
                    <!-- Slippage warnings -->
                    <p v-if="slippageWarning === 'high'" class="text-xs text-amber-400 mt-2">{{ t('settings.slippageHighWarn') }}</p>
                    <p v-else-if="slippageWarning === 'low'" class="text-xs text-amber-400 mt-2">{{ t('settings.slippageLowWarn') }}</p>
                    <p v-else-if="slippageWarning === 'invalid'" class="text-xs text-trading-red mt-2">{{ t('settings.slippageInvalid') }}</p>
                    <p v-else class="text-xs text-dark-500 mt-2">{{ t('settings.slippageDesc') }}</p>
                </div>

                <!-- Transaction Deadline -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-dark-300 mb-3">{{ t('settings.txDeadline') }}</label>
                    <div class="relative w-48">
                        <input
                            v-model="txDeadline"
                            type="text"
                            class="w-full px-4 py-2 rounded-xl glass-sm border border-white/10 bg-transparent text-white focus:outline-none focus:border-primary-500/50"
                        />
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-dark-400 text-sm">{{ t('settings.minutes') }}</span>
                    </div>
                    <p class="text-xs text-dark-500 mt-2">{{ t('settings.txDeadlineDesc') }}</p>
                </div>

                <!-- Gas Price -->
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-3">{{ t('settings.gasPrice') }}</label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <button
                            v-for="option in gasOptions"
                            :key="option.id"
                            @click="gasOption = option.id"
                            :class="[
                                'p-3 rounded-xl text-center transition-all',
                                gasOption === option.id
                                    ? 'bg-primary-500/20 border border-primary-500/30'
                                    : 'glass-sm border border-white/5 hover:border-white/10'
                            ]"
                        >
                            <p :class="['font-medium text-sm', gasOption === option.id ? 'text-primary-400' : 'text-white']">
                                {{ t(option.labelKey) }}
                            </p>
                            <p class="text-xs text-dark-400 mt-1">{{ t(option.descKey) }}</p>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Wallet Section -->
            <div class="glass-dark rounded-2xl p-6">
                <h2 class="text-lg font-semibold text-white mb-6">{{ t('settings.wallet') }}</h2>

                <!-- Connected State -->
                <div v-if="isConnected" class="space-y-4">
                    <!-- Wallet Type Badge -->
                    <div class="flex items-center gap-2 mb-2">
                        <span class="px-3 py-1 rounded-full text-xs font-medium bg-trading-green/10 text-trading-green border border-trading-green/20">
                            {{ t('wallet.connected') }}
                        </span>
                        <span v-if="walletTypeDisplay" class="px-3 py-1 rounded-full text-xs font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20">
                            {{ walletTypeDisplay }}
                        </span>
                    </div>

                    <!-- Address -->
                    <div class="flex items-center justify-between p-4 rounded-xl bg-white/5 gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-dark-400">{{ t('wallet.address') }}</p>
                            <p class="font-mono text-white text-sm sm:text-base truncate">{{ walletStore.address }}</p>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <button
                                @click="copyAddress"
                                class="p-2 rounded-lg text-dark-400 hover:text-primary-400 hover:bg-primary-500/10 transition-all"
                                :title="t('wallet.copyAddress')"
                            >
                                <svg v-if="!addressCopied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                                <svg v-else class="w-4 h-4 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            </button>
                            <div class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></div>
                        </div>
                    </div>

                    <!-- Network -->
                    <div class="flex items-center justify-between p-4 rounded-xl bg-white/5">
                        <div>
                            <p class="text-sm text-dark-400">{{ t('settings.network') }}</p>
                            <p class="text-white">{{ chainDisplayName }}</p>
                        </div>
                        <a
                            :href="walletStore.explorerAddressUrl"
                            target="_blank"
                            rel="noopener"
                            class="text-xs text-primary-400 hover:text-primary-300 transition-colors"
                        >
                            {{ t('nav.viewOnExplorer') }} →
                        </a>
                    </div>

                    <!-- TPIX Balance (embedded wallet only) -->
                    <div v-if="walletStore.isEmbedded && walletStore.tpixBalance !== null" class="flex items-center justify-between p-4 rounded-xl bg-white/5">
                        <div>
                            <p class="text-sm text-dark-400">{{ t('wallet.balance') }}</p>
                            <p class="text-white font-medium">{{ walletStore.tpixBalance }} TPIX</p>
                        </div>
                    </div>

                    <!-- Disconnect Button -->
                    <button
                        @click="walletStore.disconnect()"
                        class="w-full py-3 rounded-xl text-trading-red border border-trading-red/20 hover:bg-trading-red/10 transition-colors font-medium"
                    >
                        {{ t('wallet.disconnect') }}
                    </button>
                </div>

                <!-- Not Connected State — มีปุ่ม Connect ตรงนี้เลย -->
                <div v-else class="text-center py-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center">
                        <svg class="w-8 h-8 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                    <p class="text-dark-300 mb-2 font-medium">{{ t('settings.noWallet') }}</p>
                    <p class="text-sm text-dark-500 mb-6">{{ t('settings.noWalletDesc') }}</p>
                    <button
                        @click="walletStore.openConnectModal()"
                        class="btn-primary inline-flex items-center gap-2 px-8 py-3"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        {{ t('wallet.connect') }}
                    </button>
                </div>
            </div>

            <!-- TPIX Wallet App Section -->
            <div class="glass-dark rounded-2xl p-6 mt-6">
                <h2 class="text-lg font-semibold text-white mb-4">{{ t('settings.connectTpixApp') }}</h2>
                <p class="text-sm text-dark-400 mb-5">{{ t('settings.connectTpixAppDesc') }}</p>

                <div class="flex items-center gap-4 p-4 rounded-xl bg-gradient-to-r from-primary-500/10 to-accent-500/10 border border-primary-500/20">
                    <!-- App Icon -->
                    <div class="w-14 h-14 bg-gradient-to-br from-primary-500 to-accent-500 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-primary-500/20">
                        <img src="/tpixlogo.webp" class="w-9 h-9" alt="TPIX Wallet" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-white">TPIX Wallet</p>
                        <p class="text-xs text-dark-400 mt-0.5">TPIX Chain (4289) — Gasless</p>
                    </div>
                </div>

                <div class="flex gap-3 mt-4">
                    <button
                        v-if="mobile"
                        @click="openTpixApp"
                        class="flex-1 py-3 px-4 rounded-xl bg-primary-500/20 border border-primary-500/30 text-primary-300 font-medium text-sm hover:bg-primary-500/30 transition-all flex items-center justify-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                        {{ t('settings.openApp') }}
                    </button>
                    <button
                        @click="downloadTpixApp"
                        :class="[
                            'py-3 px-4 rounded-xl font-medium text-sm transition-all flex items-center justify-center gap-2',
                            mobile
                                ? 'flex-1 bg-accent-500/20 border border-accent-500/30 text-accent-300 hover:bg-accent-500/30'
                                : 'w-full bg-primary-500/20 border border-primary-500/30 text-primary-300 hover:bg-primary-500/30'
                        ]"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        {{ t('settings.downloadApp') }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
