/**
 * TPIX TRADE - Wallet Store (Pinia)
 * Centralized Web3 wallet state management
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { BrowserProvider } from 'ethers';
import { BSC_CHAIN_CONFIG, switchToBSC, formatAddress } from '@/utils/web3';

const STORAGE_KEY = 'tpix_wallet';

export const useWalletStore = defineStore('wallet', () => {
    // State
    const address = ref(null);
    const chainId = ref(null);
    const provider = ref(null);
    const signer = ref(null);
    const isConnecting = ref(false);
    const error = ref(null);
    const walletType = ref(null); // 'metamask', 'trustwallet', 'coinbase'

    // Computed
    const isConnected = computed(() => !!address.value);
    const shortAddress = computed(() => formatAddress(address.value));
    const isBSC = computed(() => chainId.value === BSC_CHAIN_CONFIG.chainIdNum);

    // Actions
    async function connect(type = 'metamask') {
        if (!window.ethereum) {
            error.value = 'No wallet detected. Please install MetaMask.';
            throw new Error(error.value);
        }

        isConnecting.value = true;
        error.value = null;

        try {
            // Request account access
            const accounts = await window.ethereum.request({
                method: 'eth_requestAccounts',
            });

            if (!accounts || accounts.length === 0) {
                throw new Error('No accounts returned from wallet.');
            }

            // Create ethers provider and signer
            const ethProvider = new BrowserProvider(window.ethereum);
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
                    await switchToBSC();
                    // Re-create provider after chain switch
                    const newProvider = new BrowserProvider(window.ethereum);
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
    }

    async function tryReconnect() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (!saved || !window.ethereum) return false;

        try {
            const { address: savedAddr, walletType: savedType } = JSON.parse(saved);
            if (!savedAddr) return false;

            // Check if wallet is still connected
            const accounts = await window.ethereum.request({
                method: 'eth_accounts',
            });

            if (accounts && accounts.length > 0) {
                const ethProvider = new BrowserProvider(window.ethereum);
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
        if (!window.ethereum) return;

        try {
            await switchToBSC();
            const ethProvider = new BrowserProvider(window.ethereum);
            const ethSigner = await ethProvider.getSigner();
            const network = await ethProvider.getNetwork();
            provider.value = ethProvider;
            signer.value = ethSigner;
            chainId.value = Number(network.chainId);
        } catch (err) {
            error.value = 'Failed to switch network.';
            throw err;
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
            if (window.ethereum) {
                const ethProvider = new BrowserProvider(window.ethereum);
                ethProvider.getSigner().then(s => {
                    provider.value = ethProvider;
                    signer.value = s;
                });
            }
        }
    }

    function _onChainChanged(hexChainId) {
        chainId.value = parseInt(hexChainId, 16);
        // Refresh provider/signer on chain change
        if (window.ethereum) {
            const ethProvider = new BrowserProvider(window.ethereum);
            ethProvider.getSigner().then(s => {
                provider.value = ethProvider;
                signer.value = s;
            });
        }
    }

    function _setupListeners() {
        if (!window.ethereum) return;
        window.ethereum.on('accountsChanged', _onAccountsChanged);
        window.ethereum.on('chainChanged', _onChainChanged);
    }

    function _removeListeners() {
        if (!window.ethereum) return;
        window.ethereum.removeListener('accountsChanged', _onAccountsChanged);
        window.ethereum.removeListener('chainChanged', _onChainChanged);
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
