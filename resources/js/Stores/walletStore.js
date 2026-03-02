/**
 * TPIX TRADE - Wallet Store (Pinia)
 * Centralized Web3 wallet state management
 * Supports MetaMask, Trust Wallet, Coinbase, OKX
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { BrowserProvider } from 'ethers';
import { BSC_CHAIN_CONFIG, switchToBSC, formatAddress } from '@/utils/web3';

const STORAGE_KEY = 'tpix_wallet';

/**
 * Detect the correct provider for a given wallet type.
 * Handles multi-wallet environments (EIP-6963 compatible).
 */
function _getProvider(walletType) {
    // Check for dedicated wallet namespace first
    if (walletType === 'trustwallet') {
        if (window.trustwallet) return window.trustwallet;
        if (window.ethereum?.isTrust) return window.ethereum;
    }
    if (walletType === 'okx') {
        if (window.okxwallet) return window.okxwallet;
    }
    if (walletType === 'coinbase') {
        if (window.coinbaseWalletExtension) return window.coinbaseWalletExtension;
        if (window.ethereum?.isCoinbaseWallet) return window.ethereum;
    }

    // Check for multi-provider array (when multiple extensions installed)
    if (window.ethereum?.providers?.length) {
        const providers = window.ethereum.providers;
        switch (walletType) {
            case 'metamask':
                return providers.find(p => p.isMetaMask && !p.isTrust && !p.isCoinbaseWallet) || providers[0];
            case 'trustwallet':
                return providers.find(p => p.isTrust) || providers[0];
            case 'coinbase':
                return providers.find(p => p.isCoinbaseWallet) || providers[0];
            default:
                return providers[0];
        }
    }

    // Single provider fallback
    return window.ethereum || null;
}

export const useWalletStore = defineStore('wallet', () => {
    // State
    const address = ref(null);
    const chainId = ref(null);
    const provider = ref(null);
    const signer = ref(null);
    const isConnecting = ref(false);
    const error = ref(null);
    const walletType = ref(null); // 'metamask', 'trustwallet', 'coinbase', 'okx'

    // Raw injected provider (for event listeners)
    let _rawProvider = null;

    // Computed
    const isConnected = computed(() => !!address.value);
    const shortAddress = computed(() => formatAddress(address.value));
    const isBSC = computed(() => chainId.value === BSC_CHAIN_CONFIG.chainIdNum);

    // Actions
    async function connect(type = 'metamask') {
        const injected = _getProvider(type);

        if (!injected) {
            const names = {
                metamask: 'MetaMask',
                trustwallet: 'Trust Wallet',
                coinbase: 'Coinbase Wallet',
                okx: 'OKX Wallet',
            };
            error.value = `${names[type] || 'Wallet'} not detected. Please install the extension or open in the wallet's browser.`;
            throw new Error(error.value);
        }

        isConnecting.value = true;
        error.value = null;
        _rawProvider = injected;

        try {
            // Request account access
            const accounts = await injected.request({
                method: 'eth_requestAccounts',
            });

            if (!accounts || accounts.length === 0) {
                throw new Error('No accounts returned from wallet.');
            }

            // Create ethers provider and signer
            const ethProvider = new BrowserProvider(injected);
            const ethSigner = await ethProvider.getSigner();
            const network = await ethProvider.getNetwork();

            // Update state
            address.value = accounts[0];
            chainId.value = Number(network.chainId);
            provider.value = ethProvider;
            signer.value = ethSigner;
            walletType.value = type;

            // Persist to localStorage
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                address: accounts[0],
                walletType: type,
            }));

            // Switch to BSC if not already on it
            if (chainId.value !== BSC_CHAIN_CONFIG.chainIdNum) {
                try {
                    await _switchChainOnProvider(injected);
                    // Re-create provider after chain switch
                    const newProvider = new BrowserProvider(injected);
                    const newSigner = await newProvider.getSigner();
                    const newNetwork = await newProvider.getNetwork();
                    provider.value = newProvider;
                    signer.value = newSigner;
                    chainId.value = Number(newNetwork.chainId);
                } catch (switchErr) {
                    console.warn('Could not auto-switch to BSC:', switchErr.message);
                }
            }

            // Set up event listeners
            _setupListeners();

            return address.value;
        } catch (err) {
            if (err.code === 4001) {
                error.value = 'Connection rejected by user.';
            } else {
                error.value = err.message || 'Failed to connect wallet.';
            }
            throw err;
        } finally {
            isConnecting.value = false;
        }
    }

    function disconnect() {
        address.value = null;
        chainId.value = null;
        provider.value = null;
        signer.value = null;
        walletType.value = null;
        error.value = null;
        localStorage.removeItem(STORAGE_KEY);
        _removeListeners();
        _rawProvider = null;
    }

    async function tryReconnect() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (!saved) return false;

        try {
            const { address: savedAddr, walletType: savedType } = JSON.parse(saved);
            if (!savedAddr) return false;

            const injected = _getProvider(savedType || 'metamask');
            if (!injected) return false;

            _rawProvider = injected;

            // Check if wallet is still connected (passive - no popup)
            const accounts = await injected.request({
                method: 'eth_accounts',
            });

            if (accounts && accounts.length > 0) {
                const ethProvider = new BrowserProvider(injected);
                const ethSigner = await ethProvider.getSigner();
                const network = await ethProvider.getNetwork();

                address.value = accounts[0];
                chainId.value = Number(network.chainId);
                provider.value = ethProvider;
                signer.value = ethSigner;
                walletType.value = savedType || 'metamask';

                _setupListeners();
                return true;
            }
        } catch (err) {
            console.warn('Auto-reconnect failed:', err.message);
            localStorage.removeItem(STORAGE_KEY);
        }

        return false;
    }

    async function switchChain(targetChainId = BSC_CHAIN_CONFIG.chainIdNum) {
        const injected = _rawProvider || _getProvider(walletType.value || 'metamask');
        if (!injected) return;

        try {
            await _switchChainOnProvider(injected);
            const ethProvider = new BrowserProvider(injected);
            const ethSigner = await ethProvider.getSigner();
            const newNetwork = await ethProvider.getNetwork();
            provider.value = ethProvider;
            signer.value = ethSigner;
            chainId.value = Number(newNetwork.chainId);
        } catch (err) {
            error.value = 'Failed to switch network.';
            throw err;
        }
    }

    // Switch to BSC on a specific provider
    async function _switchChainOnProvider(injected) {
        try {
            await injected.request({
                method: 'wallet_switchEthereumChain',
                params: [{ chainId: BSC_CHAIN_CONFIG.chainId }],
            });
        } catch (switchError) {
            // Chain not added - add it
            if (switchError.code === 4902) {
                await injected.request({
                    method: 'wallet_addEthereumChain',
                    params: [{
                        chainId: BSC_CHAIN_CONFIG.chainId,
                        chainName: BSC_CHAIN_CONFIG.chainName,
                        nativeCurrency: BSC_CHAIN_CONFIG.nativeCurrency,
                        rpcUrls: BSC_CHAIN_CONFIG.rpcUrls,
                        blockExplorerUrls: BSC_CHAIN_CONFIG.blockExplorerUrls,
                    }],
                });
            } else {
                throw switchError;
            }
        }
    }

    // Private: event listeners
    function _onAccountsChanged(accounts) {
        if (accounts.length === 0) {
            disconnect();
        } else {
            address.value = accounts[0];
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                address: accounts[0],
                walletType: walletType.value,
            }));
            // Refresh provider/signer
            const injected = _rawProvider || _getProvider(walletType.value);
            if (injected) {
                const ethProvider = new BrowserProvider(injected);
                ethProvider.getSigner().then(s => {
                    provider.value = ethProvider;
                    signer.value = s;
                });
            }
        }
    }

    function _onChainChanged(hexChainId) {
        chainId.value = parseInt(hexChainId, 16);
        const injected = _rawProvider || _getProvider(walletType.value);
        if (injected) {
            const ethProvider = new BrowserProvider(injected);
            ethProvider.getSigner().then(s => {
                provider.value = ethProvider;
                signer.value = s;
            });
        }
    }

    function _setupListeners() {
        const injected = _rawProvider;
        if (!injected) return;
        injected.on('accountsChanged', _onAccountsChanged);
        injected.on('chainChanged', _onChainChanged);
    }

    function _removeListeners() {
        const injected = _rawProvider;
        if (!injected) return;
        injected.removeListener('accountsChanged', _onAccountsChanged);
        injected.removeListener('chainChanged', _onChainChanged);
    }

    return {
        // State
        address,
        chainId,
        provider,
        signer,
        isConnecting,
        error,
        walletType,
        // Computed
        isConnected,
        shortAddress,
        isBSC,
        // Actions
        connect,
        disconnect,
        tryReconnect,
        switchChain,
    };
});
