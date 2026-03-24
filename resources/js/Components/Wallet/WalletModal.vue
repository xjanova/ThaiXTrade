<script setup>
/**
 * TPIX TRADE - Wallet Connection Modal
 * Real Web3 wallet connection via ethers.js
 * รองรับ Mobile deep link + In-app browser detection (LINE, Facebook, etc.)
 * Supports MetaMask, Trust Wallet, Coinbase, OKX, TokenPocket
 * + TPIX Embedded Wallet (ไม่ต้องติดตั้ง extension)
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import EmbeddedWalletSetup from './EmbeddedWalletSetup.vue';
import { isWalletStored, getStoredAddress } from '@/utils/embeddedWallet';
import {
    isMobile,
    detectInAppBrowser,
    detectWalletBrowser,
    isWalletDetected as checkWalletDetected,
    hasAnyWallet as checkAnyWallet,
    openWalletApp,
    openTpixApp,
    downloadTpixApp,
    getExternalBrowserUrl,
    WALLET_PROVIDERS,
} from '@/utils/mobileWallet';

const emit = defineEmits(['close', 'connected']);
const walletStore = useWalletStore();

const selectedWallet = ref(null);
const isConnecting = ref(false);
const error = ref(null);
const showEmbeddedSetup = ref(false);
const showUnlockPassword = ref(false);
const unlockPassword = ref('');

// ตรวจว่ามี TPIX Wallet เก็บอยู่แล้ว (ต้อง unlock)
const hasStoredWallet = computed(() => isWalletStored());
const storedAddress = computed(() => getStoredAddress());

// === Platform Detection (ค่าคงที่ ไม่เปลี่ยนระหว่าง session) ===
const mobile = isMobile();
const inAppBrowser = detectInAppBrowser();
const walletBrowser = detectWalletBrowser();
const isInWalletBrowser = !!walletBrowser;

// รายการ wallet (สร้างครั้งเดียว เพราะ mobile ไม่เปลี่ยน)
const allWallets = (() => {
    // ถ้าอยู่ใน wallet browser → แสดงแค่ wallet นั้นตัวเดียว
    if (isInWalletBrowser) {
        const provider = WALLET_PROVIDERS.find(p => p.id === walletBrowser);
        if (provider) {
            return [{
                id: provider.id,
                name: provider.name,
                description: 'Connected Browser',
                popular: true,
                color: provider.color,
                detected: true,
            }];
        }
    }

    const baseWallets = [
        // TPIX Embedded Wallet — อยู่บนสุดเสมอ
        {
            id: 'tpix_wallet',
            name: 'TPIX Wallet',
            description: 'Wallet ในตัวเว็บ — ไม่ต้องติดตั้ง',
            popular: true,
            color: '#06B6D4',
            embedded: true,
        },
        { id: 'metamask', name: 'MetaMask', description: mobile ? 'Open in MetaMask' : 'Browser extension', popular: true, color: '#E2761B' },
        { id: 'trustwallet', name: 'Trust Wallet', description: mobile ? 'Open in Trust Wallet' : 'Mobile & Extension', popular: true, color: '#3375BB' },
        { id: 'coinbase', name: 'Coinbase Wallet', description: mobile ? 'Open in Coinbase' : 'Browser & Mobile', popular: true, color: '#0052FF' },
        { id: 'okx', name: 'OKX Wallet', description: mobile ? 'Open in OKX' : 'Browser extension', popular: false, color: '#000000' },
        { id: 'tokenpocket', name: 'TokenPocket', description: mobile ? 'Open in TokenPocket' : 'Browser extension', popular: false, color: '#2980FE' },
    ];

    // Desktop: เพิ่ม WalletConnect (coming soon)
    if (!mobile) {
        baseWallets.push({
            id: 'walletconnect', name: 'WalletConnect', description: 'Coming soon',
            popular: false, color: '#3B99FC', supported: false,
        });
    }

    return baseWallets;
})();

// Pre-filter เพื่อไม่ต้อง filter ทุก render
const popularWallets = allWallets.filter(w => w.popular);
const otherWallets = allWallets.filter(w => !w.popular);

// ใช้ consolidated detection จาก mobileWallet.js
function isWalletDetected(walletId) {
    return checkWalletDetected(walletId);
}

// ตรวจว่าผู้ใช้มี wallet ภายนอกหรือไม่ (ถ้าไม่มี → แนะนำ TPIX Wallet)
const hasExternalWallet = computed(() => {
    if (isInWalletBrowser) return true;
    return checkAnyWallet();
});

const connectWallet = async (wallet) => {
    // TPIX Wallet — embedded wallet flow
    if (wallet.embedded) {
        if (hasStoredWallet.value) {
            showUnlockPassword.value = true;
        } else {
            showEmbeddedSetup.value = true;
        }
        return;
    }

    if (wallet.supported === false) {
        error.value = `${wallet.name} support coming soon.`;
        return;
    }

    selectedWallet.value = wallet.id;
    error.value = null;

    // === Mobile: ถ้าไม่มี injected provider → เปิดแอปผ่าน deep link ===
    if (mobile && !isWalletDetected(wallet.id)) {
        const provider = WALLET_PROVIDERS.find(p => p.id === wallet.id);
        if (provider) {
            openWalletApp(provider, window.location.href);
            return;
        }
    }

    // === Desktop หรือ อยู่ใน wallet browser: ใช้ injected provider ===
    if (!checkAnyWallet()) {
        error.value = 'No wallet detected. Please install a wallet extension.';
        return;
    }

    isConnecting.value = true;

    try {
        const address = await walletStore.connect(wallet.id);

        emit('connected', {
            address,
            wallet: wallet.id,
            chainId: walletStore.chainId,
        });

        emit('close');
    } catch (err) {
        if (err.code === 4001) {
            error.value = 'Connection rejected. Please try again.';
        } else {
            error.value = walletStore.error || err.message || 'Failed to connect. Please try again.';
        }
    } finally {
        isConnecting.value = false;
    }
};

/**
 * เปิด URL ใน external browser (หนีออกจาก in-app browser)
 */
const openInExternalBrowser = () => {
    const url = getExternalBrowserUrl();
    window.location.href = url;
};

// Unlock embedded wallet ด้วย password
async function unlockEmbedded() {
    isConnecting.value = true;
    error.value = null;
    try {
        await walletStore.connectEmbedded(unlockPassword.value);
        showUnlockPassword.value = false;
        unlockPassword.value = '';
        emit('connected', { address: walletStore.address, wallet: 'tpix_wallet', chainId: walletStore.chainId });
        emit('close');
    } catch (err) {
        error.value = err.message;
    } finally {
        isConnecting.value = false;
    }
}

// Embedded setup done → connect เรียบร้อย
function onEmbeddedDone() {
    showEmbeddedSetup.value = false;
    emit('connected', { address: walletStore.address, wallet: 'tpix_wallet', chainId: walletStore.chainId });
    emit('close');
}

// ชื่อ in-app browser ที่อ่านง่าย (ค่าคงที่)
const IN_APP_DISPLAY_NAMES = {
    line: 'LINE', facebook: 'Facebook', instagram: 'Instagram',
    twitter: 'X (Twitter)', telegram: 'Telegram', wechat: 'WeChat',
    tiktok: 'TikTok', kakaotalk: 'KakaoTalk', snapchat: 'Snapchat',
};
const inAppBrowserDisplayName = IN_APP_DISPLAY_NAMES[inAppBrowser] || inAppBrowser;
</script>

<template>
    <div class="modal-overlay" @click.self="$emit('close')">
        <div class="modal-content max-w-md">
            <!-- Header -->
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-xl font-bold text-white">Connect Wallet</h2>
                <button
                    @click="$emit('close')"
                    class="p-2 rounded-xl text-dark-400 hover:text-white hover:bg-white/10 transition-all"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- In-App Browser Warning (LINE, Facebook, etc.) -->
            <div v-if="mobile && inAppBrowser && !isInWalletBrowser" class="mb-4 p-4 rounded-xl bg-amber-500/10 border border-amber-500/30">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-amber-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <div class="flex-1">
                        <p class="text-amber-300 text-sm font-medium mb-1">
                            Opened in {{ inAppBrowserDisplayName }}
                        </p>
                        <p class="text-amber-400/70 text-xs mb-3">
                            For the best experience, open in an external browser or use a wallet app directly.
                        </p>
                        <button
                            @click="openInExternalBrowser"
                            class="w-full py-2 px-3 rounded-lg bg-amber-500/20 border border-amber-500/30 text-amber-300 text-sm font-medium hover:bg-amber-500/30 transition-all"
                        >
                            Open in Browser
                        </button>
                    </div>
                </div>
            </div>

            <!-- Wallet Browser: Direct Connect -->
            <div v-if="isInWalletBrowser" class="mb-4 p-3 rounded-xl bg-trading-green/10 border border-trading-green/30">
                <p class="text-trading-green text-sm">
                    Wallet detected! Tap below to connect.
                </p>
            </div>

            <p v-if="!isInWalletBrowser" class="text-dark-400 text-sm mb-5">
                <template v-if="mobile">
                    Select a wallet app to connect. It will open automatically.
                </template>
                <template v-else>
                    Connect your wallet to start trading on BSC. Your keys, your crypto.
                </template>
            </p>

            <!-- Embedded Wallet Setup Flow -->
            <template v-if="showEmbeddedSetup">
                <EmbeddedWalletSetup @done="onEmbeddedDone" @cancel="showEmbeddedSetup = false" />
            </template>

            <!-- Unlock Embedded Wallet -->
            <template v-else-if="showUnlockPassword">
                <div class="space-y-4">
                    <div class="text-center">
                        <div class="w-12 h-12 mx-auto rounded-xl bg-primary-500/20 flex items-center justify-center mb-3">
                            <img src="/logo.webp" class="w-8 h-8" alt="TPIX" />
                        </div>
                        <h3 class="text-lg font-bold text-white">Unlock TPIX Wallet</h3>
                        <p class="text-dark-500 text-xs font-mono mt-1">{{ storedAddress }}</p>
                    </div>
                    <input v-model="unlockPassword" type="password"
                        class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                        placeholder="ใส่ Password" @keyup.enter="unlockEmbedded" />
                    <div v-if="error" class="p-2 rounded-lg bg-trading-red/10 text-trading-red text-sm">{{ error }}</div>
                    <div class="flex gap-3">
                        <button @click="showUnlockPassword = false; error = null" class="flex-1 py-2 text-dark-400 hover:text-white text-sm">กลับ</button>
                        <button @click="unlockEmbedded" :disabled="isConnecting" class="flex-1 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium text-sm">
                            {{ isConnecting ? 'กำลัง Unlock...' : 'Unlock' }}
                        </button>
                    </div>
                    <button @click="showEmbeddedSetup = true; showUnlockPassword = false" class="w-full text-center text-dark-500 hover:text-dark-400 text-xs">
                        สร้าง Wallet ใหม่ หรือ Import
                    </button>
                </div>
            </template>

            <!-- Normal wallet selection -->
            <template v-else>

            <!-- Error Message -->
            <div v-if="error" class="mb-4 p-3 rounded-xl bg-trading-red/10 border border-trading-red/30 text-trading-red text-sm">
                {{ error }}
            </div>

            <!-- No Wallet Detected Banner -->
            <div v-if="!hasExternalWallet && !hasStoredWallet" class="mb-4 p-4 rounded-xl bg-gradient-to-r from-primary-500/15 to-accent-500/15 border border-primary-500/30">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 bg-primary-500/20 rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <p class="text-white text-sm font-medium mb-1">ไม่พบกระเป๋าเงินคริปโต</p>
                        <p class="text-dark-400 text-xs">สร้าง <span class="text-primary-400 font-semibold">TPIX Wallet</span> ได้เลย ไม่ต้องติดตั้งแอปเพิ่ม ปลอดภัย เก็บ Private Key ในเบราว์เซอร์ของคุณเท่านั้น</p>
                    </div>
                </div>
            </div>

            <!-- Wallet List (Popular) -->
            <div class="space-y-2 mb-5">
                <button
                    v-for="wallet in popularWallets"
                    :key="wallet.id"
                    @click="connectWallet(wallet)"
                    :disabled="isConnecting"
                    :class="[
                        'w-full flex items-center gap-4 p-4 rounded-xl border transition-all',
                        selectedWallet === wallet.id && isConnecting
                            ? 'bg-primary-500/10 border-primary-500/50'
                            : wallet.embedded && !hasExternalWallet
                                ? 'bg-primary-500/10 border-primary-500/40 hover:bg-primary-500/15 hover:border-primary-500/60 ring-1 ring-primary-500/20'
                                : 'bg-dark-800/50 border-white/5 hover:bg-white/5 hover:border-white/10'
                    ]"
                >
                    <!-- Wallet Icon -->
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" :style="{ background: wallet.color + '20' }">
                        <!-- TPIX Wallet -->
                        <img v-if="wallet.id === 'tpix_wallet'" src="/logo.webp" class="w-6 h-6" alt="TPIX" />
                        <!-- MetaMask -->
                        <svg v-else-if="wallet.id === 'metamask'" class="w-6 h-6" viewBox="0 0 35 33">
                            <path d="M32.96 1l-13.14 9.72 2.45-5.73L32.96 1z" fill="#E2761B" stroke="#E2761B" stroke-width=".25"/>
                            <path d="M2.66 1l13.02 9.82-2.33-5.83L2.66 1zm25.57 22.53l-3.5 5.34 7.49 2.06 2.14-7.28-6.13-.12zm-26.96.12l2.13 7.28 7.47-2.06-3.48-5.34-6.12.12z" fill="#E4761B" stroke="#E4761B" stroke-width=".25"/>
                            <path d="M10.47 14.51l-2.08 3.14 7.4.34-.26-7.96-5.06 4.48zm14.68 0L20 9.93l-.17 8.06 7.4-.34-2.08-3.14zM10.87 28.87l4.49-2.16-3.88-3.02-.61 5.18zm8.89-2.16l4.51 2.16-.63-5.18-3.88 3.02z" fill="#E4761B" stroke="#E4761B" stroke-width=".25"/>
                        </svg>
                        <!-- Trust Wallet -->
                        <svg v-else-if="wallet.id === 'trustwallet'" class="w-6 h-6" viewBox="0 0 40 40" fill="none">
                            <path d="M20 4.5C20 4.5 33 10 33 20C33 30 20 35.5 20 35.5C20 35.5 7 30 7 20C7 10 20 4.5 20 4.5Z" fill="#3375BB"/>
                            <path d="M20 8C20 8 29.5 12.5 29.5 20C29.5 27.5 20 32 20 32" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>
                            <path d="M20 8C20 8 10.5 12.5 10.5 20C10.5 27.5 20 32 20 32" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none" opacity="0.5"/>
                        </svg>
                        <!-- Coinbase -->
                        <svg v-else-if="wallet.id === 'coinbase'" class="w-6 h-6" viewBox="0 0 40 40">
                            <circle cx="20" cy="20" r="18" fill="#0052FF"/>
                            <rect x="13" y="13" width="14" height="14" rx="3" fill="white"/>
                            <rect x="17" y="17" width="6" height="6" rx="1" fill="#0052FF"/>
                        </svg>
                        <!-- Generic -->
                        <svg v-else class="w-6 h-6 text-dark-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>

                    <div class="flex-1 text-left">
                        <p class="font-semibold text-white">{{ wallet.name }}</p>
                        <p class="text-xs text-dark-400">{{ wallet.description }}</p>
                    </div>

                    <!-- Status -->
                    <div v-if="selectedWallet === wallet.id && isConnecting" class="spinner"></div>
                    <span v-else-if="wallet.embedded && !hasExternalWallet" class="text-[10px] text-primary-300 px-2 py-0.5 rounded-full bg-primary-500/20 font-semibold">แนะนำ</span>
                    <span v-else-if="wallet.embedded && hasStoredWallet" class="text-[10px] text-trading-green px-2 py-0.5 rounded-full bg-trading-green/10">มี Wallet</span>
                    <span v-else-if="isWalletDetected(wallet.id)" class="text-[10px] text-trading-green px-2 py-0.5 rounded-full bg-trading-green/10">Detected</span>
                    <!-- Mobile: แสดง "Open" แทน arrow (ไม่แสดงสำหรับ embedded wallet) -->
                    <span v-else-if="mobile && !wallet.embedded" class="text-[10px] text-primary-400 px-2 py-0.5 rounded-full bg-primary-500/10">Open</span>
                    <svg v-else class="w-5 h-5 text-dark-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>

            <!-- More Wallets -->
            <div class="grid grid-cols-2 gap-2 mb-5">
                <button
                    v-for="wallet in otherWallets"
                    :key="wallet.id"
                    @click="connectWallet(wallet)"
                    :disabled="isConnecting"
                    :class="[
                        'flex items-center gap-3 p-3 rounded-xl border transition-all',
                        wallet.supported === false
                            ? 'bg-dark-800/30 border-white/5 opacity-50'
                            : 'bg-dark-800/50 border-white/5 hover:bg-white/5 hover:border-white/10'
                    ]"
                >
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :style="{ background: wallet.color + '20' }">
                        <!-- OKX -->
                        <svg v-if="wallet.id === 'okx'" class="w-5 h-5" viewBox="0 0 40 40">
                            <rect width="40" height="40" rx="8" fill="black"/>
                            <rect x="8" y="8" width="10" height="10" rx="1" fill="white"/>
                            <rect x="22" y="8" width="10" height="10" rx="1" fill="white"/>
                            <rect x="15" y="15" width="10" height="10" rx="1" fill="white"/>
                            <rect x="8" y="22" width="10" height="10" rx="1" fill="white"/>
                            <rect x="22" y="22" width="10" height="10" rx="1" fill="white"/>
                        </svg>
                        <!-- WalletConnect -->
                        <svg v-else-if="wallet.id === 'walletconnect'" class="w-5 h-5" viewBox="0 0 40 40">
                            <circle cx="20" cy="20" r="18" fill="#3B99FC"/>
                            <path d="M12.5 16.5C16.6 12.5 23.4 12.5 27.5 16.5L28 17C28.2 17.2 28.2 17.5 28 17.7L26.5 19.1C26.4 19.2 26.2 19.2 26.1 19.1L25.4 18.5C22.5 15.7 17.5 15.7 14.6 18.5L13.8 19.2C13.7 19.3 13.5 19.3 13.4 19.2L11.9 17.8C11.7 17.6 11.7 17.3 11.9 17.1L12.5 16.5Z" fill="white"/>
                            <path d="M30 19L31.4 20.3C31.6 20.5 31.6 20.8 31.4 21L25.4 26.8C25.2 27 24.8 27 24.6 26.8L20.5 22.8C20.45 22.75 20.35 22.75 20.3 22.8L16.2 26.8C16 27 15.6 27 15.4 26.8L9.4 21C9.2 20.8 9.2 20.5 9.4 20.3L10.8 19C11 18.8 11.4 18.8 11.6 19L15.7 23C15.75 23.05 15.85 23.05 15.9 23L20 19C20.2 18.8 20.6 18.8 20.8 19L24.9 23C24.95 23.05 25.05 23.05 25.1 23L29.2 19C29.4 18.8 29.8 18.8 30 19Z" fill="white"/>
                        </svg>
                        <!-- TokenPocket -->
                        <svg v-else-if="wallet.id === 'tokenpocket'" class="w-5 h-5" viewBox="0 0 40 40">
                            <circle cx="20" cy="20" r="18" fill="#2980FE"/>
                            <rect x="12" y="10" width="7" height="20" rx="2" fill="white"/>
                            <rect x="22" y="14" width="7" height="12" rx="2" fill="white" opacity="0.7"/>
                        </svg>
                        <svg v-else class="w-5 h-5 text-dark-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                    <div class="text-left">
                        <span class="text-sm text-dark-300">{{ wallet.name }}</span>
                    </div>
                </button>
            </div>

            <!-- TPIX TRADE App (Mobile Only) -->
            <div v-if="mobile" class="mb-5 p-4 rounded-xl bg-gradient-to-r from-primary-500/10 to-accent-500/10 border border-primary-500/20">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-primary-500 to-accent-500 rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-white text-sm">TPIX TRADE App</p>
                        <p class="text-xs text-dark-400">Trade faster with our native app</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button
                        @click="openTpixApp"
                        class="flex-1 py-2 px-3 rounded-lg bg-primary-500/20 border border-primary-500/30 text-primary-300 text-sm font-medium hover:bg-primary-500/30 transition-all"
                    >
                        Open App
                    </button>
                    <button
                        @click="downloadTpixApp"
                        class="flex-1 py-2 px-3 rounded-lg bg-accent-500/20 border border-accent-500/30 text-accent-300 text-sm font-medium hover:bg-accent-500/30 transition-all"
                    >
                        Download
                    </button>
                </div>
            </div>

            <!-- No Wallet — กดเพื่อสร้าง TPIX Wallet ฝังตัวได้เลย -->
            <button
                v-if="!mobile"
                @click="hasStoredWallet ? (showUnlockPassword = true) : (showEmbeddedSetup = true)"
                class="w-full pt-4 border-t border-white/5 group"
            >
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-primary-500/20 to-accent-500/20 rounded-xl flex items-center justify-center flex-shrink-0 group-hover:from-primary-500/30 group-hover:to-accent-500/30 transition-all">
                        <img src="/logo.webp" class="w-6 h-6" alt="TPIX" />
                    </div>
                    <div class="flex-1 text-left">
                        <p class="font-medium text-white text-sm">ยังไม่มีกระเป๋า?</p>
                        <p class="text-xs text-dark-400 group-hover:text-dark-300 transition-colors">
                            สร้าง <span class="text-primary-400 font-medium">TPIX Wallet</span> ได้เลย — ไม่ต้องติดตั้งอะไร
                        </p>
                    </div>
                    <svg class="w-5 h-5 text-dark-600 group-hover:text-primary-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </div>
            </button>

            </template><!-- end v-else (normal wallet selection) -->
        </div>
    </div>
</template>
