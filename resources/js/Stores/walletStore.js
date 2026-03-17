/**
 * TPIX TRADE - Wallet Store (Pinia)
 * ระบบจัดการ Wallet แบบรวมศูนย์ รองรับหลาย chain
 * รองรับ MetaMask, Trust Wallet, Coinbase, OKX
 * สลับ chain อัตโนมัติไปยัง chain หลัก (BSC) เมื่อเชื่อมต่อ
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { BrowserProvider } from 'ethers';
import {
    BSC_CHAIN_CONFIG,
    DEFAULT_CHAIN_ID,
    switchToChain,
    fetchSupportedChains,
    getAddressUrl,
    formatAddress,
} from '@/utils/web3';

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
    // === State ===
    const address = ref(null);
    const chainId = ref(null);
    const provider = ref(null);
    const signer = ref(null);
    const isConnecting = ref(false);
    const error = ref(null);
    const walletType = ref(null); // 'metamask', 'trustwallet', 'coinbase', 'okx'

    // รายการ chain ที่รองรับ (ดึงจาก backend API)
    const supportedChains = ref([]);

    // Raw injected provider (สำหรับ event listeners)
    let _rawProvider = null;

    // === Computed ===
    const isConnected = computed(() => !!address.value);
    const shortAddress = computed(() => formatAddress(address.value));

    // ตรวจสอบว่าอยู่บน chain หลัก (BSC) หรือไม่
    const isBSC = computed(() => chainId.value === DEFAULT_CHAIN_ID);

    // ตรวจสอบว่าอยู่บน chain ที่รองรับหรือไม่
    const isOnSupportedChain = computed(() => {
        if (!chainId.value || supportedChains.value.length === 0) return false;
        return supportedChains.value.some(c => c.chainId === chainId.value);
    });

    // ข้อมูล chain ปัจจุบัน
    const currentChain = computed(() => {
        if (!chainId.value) return null;
        return supportedChains.value.find(c => c.chainId === chainId.value) || null;
    });

    // URL สำหรับดูที่อยู่บน block explorer
    const explorerAddressUrl = computed(() => {
        if (!address.value) return '#';
        return getAddressUrl(address.value, chainId.value);
    });

    // === Actions ===

    /**
     * โหลดรายการ chain ที่รองรับจาก backend
     * เรียกครั้งเดียวแล้ว cache ไว้ใน store
     */
    async function loadSupportedChains() {
        if (supportedChains.value.length > 0) return supportedChains.value;
        const chains = await fetchSupportedChains();
        supportedChains.value = chains;
        return chains;
    }

    /**
     * เชื่อมต่อ wallet แล้วสลับไป chain หลักอัตโนมัติ
     * ถ้าผู้ใช้อยู่บน chain อื่นที่ไม่ใช่ BSC จะ prompt ให้สลับ
     */
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
            // โหลดรายการ chain ที่รองรับ (ขนานกับ request accounts)
            const [accounts] = await Promise.all([
                injected.request({ method: 'eth_requestAccounts' }),
                loadSupportedChains(),
            ]);

            if (!accounts || accounts.length === 0) {
                throw new Error('No accounts returned from wallet.');
            }

            // สร้าง ethers provider และ signer
            const ethProvider = new BrowserProvider(injected);
            const ethSigner = await ethProvider.getSigner();
            const network = await ethProvider.getNetwork();

            // อัปเดต state
            address.value = accounts[0];
            chainId.value = Number(network.chainId);
            provider.value = ethProvider;
            signer.value = ethSigner;
            walletType.value = type;

            // บันทึกลง localStorage เพื่อ auto-reconnect
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                address: accounts[0],
                walletType: type,
            }));

            // สลับไป chain หลัก (BSC) อัตโนมัติถ้าไม่ได้อยู่บน chain ที่รองรับ
            // หรือถ้าอยู่บน chain อื่นที่ไม่ใช่ chain หลัก
            if (chainId.value !== DEFAULT_CHAIN_ID) {
                try {
                    await switchToChain(injected, DEFAULT_CHAIN_ID);
                    // สร้าง provider ใหม่หลังสลับ chain สำเร็จ
                    await _refreshProviderState(injected);
                } catch (switchErr) {
                    console.warn('[TPIX] ไม่สามารถสลับไป chain หลักอัตโนมัติ:', switchErr.message);
                }
            }

            // ตั้งค่า event listeners สำหรับ chain/account changes
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

    /**
     * เชื่อมต่อกลับอัตโนมัติจาก localStorage (ไม่มี popup)
     * ใช้ eth_accounts แทน eth_requestAccounts เพื่อไม่รบกวนผู้ใช้
     */
    async function tryReconnect() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (!saved) return false;

        try {
            const { address: savedAddr, walletType: savedType } = JSON.parse(saved);
            if (!savedAddr) return false;

            const injected = _getProvider(savedType || 'metamask');
            if (!injected) return false;

            _rawProvider = injected;

            // โหลด chain list ขนานกับ check accounts
            const [accounts] = await Promise.all([
                injected.request({ method: 'eth_accounts' }),
                loadSupportedChains(),
            ]);

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
            console.warn('[TPIX] Auto-reconnect ล้มเหลว:', err.message);
            localStorage.removeItem(STORAGE_KEY);
        }

        return false;
    }

    /**
     * สลับไปยัง chain ที่ระบุ (รองรับทุก chain ในระบบ)
     * ถ้าไม่ระบุ targetChainId จะสลับไป chain หลัก (BSC)
     * @param {number} targetChainId - Chain ID เป้าหมาย
     */
    async function switchChain(targetChainId = DEFAULT_CHAIN_ID) {
        const injected = _rawProvider || _getProvider(walletType.value || 'metamask');
        if (!injected) return;

        try {
            await switchToChain(injected, targetChainId);
            await _refreshProviderState(injected);
        } catch (err) {
            error.value = 'ไม่สามารถสลับ network ได้';
            throw err;
        }
    }

    /**
     * รีเฟรช provider, signer, chainId หลังจากสลับ chain
     * ใช้ภายในหลัง switchToChain สำเร็จ
     */
    async function _refreshProviderState(injected) {
        const ethProvider = new BrowserProvider(injected);
        const ethSigner = await ethProvider.getSigner();
        const newNetwork = await ethProvider.getNetwork();
        provider.value = ethProvider;
        signer.value = ethSigner;
        chainId.value = Number(newNetwork.chainId);
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
                }).catch(err => {
                    console.warn('Failed to refresh signer after account change:', err.message);
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
            }).catch(err => {
                console.warn('Failed to refresh signer after chain change:', err.message);
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
        supportedChains,
        // Computed
        isConnected,
        shortAddress,
        isBSC,
        isOnSupportedChain,
        currentChain,
        explorerAddressUrl,
        // Actions
        connect,
        disconnect,
        tryReconnect,
        switchChain,
        loadSupportedChains,
    };
});
