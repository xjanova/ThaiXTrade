<script setup>
/**
 * TPIX TRADE - Wallet Connection Modal
 * Real Web3 wallet connection via ethers.js
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';

const emit = defineEmits(['close', 'connected']);
const walletStore = useWalletStore();

const selectedWallet = ref(null);
const isConnecting = ref(false);
const error = ref(null);

const wallets = [
    {
        id: 'metamask',
        name: 'MetaMask',
        icon: 'https://upload.wikimedia.org/wikipedia/commons/3/36/MetaMask_Fox.svg',
        description: 'Connect using browser extension',
        popular: true,
        supported: true
    },
    {
        id: 'trustwallet',
        name: 'Trust Wallet',
        icon: 'https://trustwallet.com/assets/images/media/assets/trust_platform.svg',
        description: 'Connect Trust Wallet',
        popular: true,
        supported: true
    },
    {
        id: 'coinbase',
        name: 'Coinbase Wallet',
        icon: 'https://avatars.githubusercontent.com/u/18060234?s=200&v=4',
        description: 'Connect Coinbase Wallet',
        popular: true,
        supported: true
    },
    {
        id: 'walletconnect',
        name: 'WalletConnect',
        icon: 'https://avatars.githubusercontent.com/u/37784886?s=200&v=4',
        description: 'Scan with mobile wallet',
        popular: false,
        supported: false
    },
    {
        id: 'phantom',
        name: 'Phantom',
        icon: 'https://phantom.app/img/phantom-logo.svg',
        description: 'Solana & Multi-chain',
        popular: false,
        supported: false
    },
    {
        id: 'okx',
        name: 'OKX Wallet',
        icon: 'https://static.okx.com/cdn/assets/imgs/221/C5E6E1F698D06F8D.png',
        description: 'Connect OKX Wallet',
        popular: false,
        supported: true
    },
];

const connectWallet = async (wallet) => {
    if (!wallet.supported) {
        error.value = `${wallet.name} support coming soon. Please use MetaMask or another injected wallet.`;
        return;
    }

    // Check if browser has an injected wallet
    if (!window.ethereum) {
        error.value = wallet.id === 'metamask'
            ? 'MetaMask not detected. Please install MetaMask extension and reload the page.'
            : 'No Web3 wallet detected. Please install MetaMask or a compatible wallet.';
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
</script>

<template>
    <div class="modal-overlay" @click.self="$emit('close')">
        <div class="modal-content max-w-md">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
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

            <!-- Description -->
            <p class="text-dark-400 mb-6">
                Connect your wallet to start trading. Your keys, your crypto - we never have access to your funds.
            </p>

            <!-- Error Message -->
            <div v-if="error" class="mb-4 p-4 rounded-xl bg-trading-red/10 border border-trading-red/30 text-trading-red text-sm">
                {{ error }}
            </div>

            <!-- Popular Wallets -->
            <div class="mb-6">
                <h3 class="text-sm font-medium text-dark-400 uppercase tracking-wider mb-3">Popular</h3>
                <div class="space-y-2">
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
                        <img :src="wallet.icon" :alt="wallet.name" class="w-10 h-10 rounded-xl">
                        <div class="flex-1 text-left">
                            <p class="font-semibold text-white">{{ wallet.name }}</p>
                            <p class="text-sm text-dark-400">{{ wallet.description }}</p>
                        </div>
                        <div v-if="selectedWallet === wallet.id && isConnecting" class="spinner"></div>
                        <svg v-else class="w-5 h-5 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- More Wallets -->
            <div>
                <h3 class="text-sm font-medium text-dark-400 uppercase tracking-wider mb-3">More Options</h3>
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="wallet in wallets.filter(w => !w.popular)"
                        :key="wallet.id"
                        @click="connectWallet(wallet)"
                        :disabled="isConnecting"
                        :class="[
                            'flex flex-col items-center gap-2 p-4 rounded-xl border transition-all',
                            wallet.supported
                                ? 'bg-dark-800/50 border-white/5 hover:bg-white/5 hover:border-white/10'
                                : 'bg-dark-800/30 border-white/5 opacity-50'
                        ]"
                    >
                        <img :src="wallet.icon" :alt="wallet.name" class="w-8 h-8 rounded-lg">
                        <span class="text-xs text-dark-300">{{ wallet.name }}</span>
                        <span v-if="!wallet.supported" class="text-[10px] text-dark-500">Soon</span>
                    </button>
                </div>
            </div>

            <!-- No Wallet Installed Info -->
            <div class="mt-6 pt-6 border-t border-white/5">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 bg-gradient-to-br from-accent-500/20 to-primary-500/20 rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-7 h-7 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <p class="font-medium text-white mb-1">Don't have a wallet?</p>
                        <p class="text-sm text-dark-400">
                            <a href="https://metamask.io/download/" target="_blank" rel="noopener" class="text-primary-400 hover:text-primary-300 underline">
                                Install MetaMask
                            </a>
                            to get started with DeFi trading on BSC.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Security Note -->
            <div class="mt-6 p-4 rounded-xl bg-primary-500/10 border border-primary-500/20">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-primary-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-primary-400">Non-Custodial & Secure</p>
                        <p class="text-xs text-dark-400 mt-1">
                            TPIX TRADE never stores your private keys. All transactions are signed locally on your device.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
