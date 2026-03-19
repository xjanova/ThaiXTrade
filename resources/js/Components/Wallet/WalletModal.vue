<script setup>
/**
 * TPIX TRADE - Wallet Connection Modal
 * Real Web3 wallet connection via ethers.js
 * Supports MetaMask, Trust Wallet, Coinbase, OKX
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import EmbeddedWalletSetup from './EmbeddedWalletSetup.vue';
import { isWalletStored, getStoredAddress } from '@/utils/embeddedWallet';

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

// Detect which wallets are available
const hasMetaMask = computed(() => !!window.ethereum?.isMetaMask || window.ethereum?.providers?.some(p => p.isMetaMask));
const hasTrust = computed(() => !!window.trustwallet || !!window.ethereum?.isTrust || window.ethereum?.providers?.some(p => p.isTrust));
const hasCoinbase = computed(() => !!window.coinbaseWalletExtension || !!window.ethereum?.isCoinbaseWallet || window.ethereum?.providers?.some(p => p.isCoinbaseWallet));
const hasOKX = computed(() => !!window.okxwallet);
const hasAnyWallet = computed(() => !!window.ethereum || !!window.trustwallet || !!window.okxwallet);

const wallets = [
    {
        id: 'tpix_wallet',
        name: 'TPIX Wallet',
        description: 'Wallet ในตัวเว็บ — ไม่ต้องติดตั้ง',
        popular: true,
        color: '#06B6D4',
        embedded: true,
    },
    {
        id: 'metamask',
        name: 'MetaMask',
        description: 'Browser extension',
        popular: true,
        color: '#E2761B',
    },
    {
        id: 'trustwallet',
        name: 'Trust Wallet',
        description: 'Mobile & Extension',
        popular: true,
        color: '#3375BB',
    },
    {
        id: 'coinbase',
        name: 'Coinbase Wallet',
        description: 'Browser & Mobile',
        popular: true,
        color: '#0052FF',
    },
    {
        id: 'okx',
        name: 'OKX Wallet',
        description: 'Browser extension',
        popular: false,
        color: '#000000',
    },
    {
        id: 'walletconnect',
        name: 'WalletConnect',
        description: 'Coming soon',
        popular: false,
        color: '#3B99FC',
        supported: false,
    },
];

function isWalletDetected(walletId) {
    switch (walletId) {
        case 'metamask': return hasMetaMask.value;
        case 'trustwallet': return hasTrust.value;
        case 'coinbase': return hasCoinbase.value;
        case 'okx': return hasOKX.value;
        default: return hasAnyWallet.value;
    }
}

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

    if (!hasAnyWallet.value) {
        error.value = 'No wallet detected. Please install MetaMask or Trust Wallet.';
        return;
    }

    selectedWallet.value = wallet.id;
    isConnecting.value = true;
    error.value = null;

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

            <p class="text-dark-400 text-sm mb-5">
                Connect your wallet to start trading on BSC. Your keys, your crypto.
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
                            <img src="/logo.png" class="w-8 h-8 rounded-lg" alt="TPIX" />
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

            <!-- Wallet List -->
            <div class="space-y-2 mb-5">
                <button
                    v-for="wallet in wallets.filter(w => w.popular)"
                    :key="wallet.id"
                    @click="connectWallet(wallet)"
                    :disabled="isConnecting"
                    :class="[
                        'w-full flex items-center gap-4 p-4 rounded-xl border transition-all',
                        selectedWallet === wallet.id && isConnecting
                            ? 'bg-primary-500/10 border-primary-500/50'
                            : 'bg-dark-800/50 border-white/5 hover:bg-white/5 hover:border-white/10'
                    ]"
                >
                    <!-- Wallet Icon -->
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" :style="{ background: wallet.color + '20' }">
                        <!-- TPIX Wallet -->
                        <img v-if="wallet.id === 'tpix_wallet'" src="/logo.png" class="w-6 h-6 rounded-md" alt="TPIX" />
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
                    <span v-else-if="isWalletDetected(wallet.id)" class="text-[10px] text-trading-green px-2 py-0.5 rounded-full bg-trading-green/10">Detected</span>
                    <svg v-else class="w-5 h-5 text-dark-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>

            <!-- More Wallets -->
            <div class="grid grid-cols-2 gap-2 mb-5">
                <button
                    v-for="wallet in wallets.filter(w => !w.popular)"
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
                        <svg v-else class="w-5 h-5 text-dark-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                    <div class="text-left">
                        <span class="text-sm text-dark-300">{{ wallet.name }}</span>
                        
                    </div>
                </button>
            </div>

            <!-- No Wallet Installed -->
            <div class="pt-4 border-t border-white/5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-accent-500/20 to-primary-500/20 rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <p class="font-medium text-white text-sm">Don't have a wallet?</p>
                        <p class="text-xs text-dark-400">
                            Use <span class="text-primary-400 font-medium">TPIX Wallet</span> above — no extension needed!
                        </p>
                    </div>
                </div>
            </div>

            </template><!-- end v-else (normal wallet selection) -->
        </div>
    </div>
</template>
