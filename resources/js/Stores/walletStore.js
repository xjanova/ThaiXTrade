/**
 * TPIX TRADE - Wallet Store (Pinia)
 * ระบบจัดการ Wallet แบบรวมศูนย์ รองรับหลาย chain
 * รองรับ MetaMask, Trust Wallet, Coinbase, OKX + TPIX Wallet (embedded)
 * TPIX Wallet = self-custodial wallet ในตัวเว็บ (ไม่ต้อง MetaMask)
 * สลับ chain อัตโนมัติไปยัง chain หลัก (BSC) เมื่อเชื่อมต่อ
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref, shallowRef, computed } from 'vue';
import { BrowserProvider, JsonRpcProvider, parseEther } from 'ethers';
import axios from 'axios';
import { playConnectSound, playDisconnectSound, playErrorSound } from '@/Composables/useSounds';
import {
    BSC_CHAIN_CONFIG,
    DEFAULT_CHAIN_ID,
    TPIX_CHAIN_CONFIG,
    switchToChain,
    fetchSupportedChains,
    getAddressUrl,
    formatAddress,
    addTPIXChainToWallet,
} from '@/utils/web3';
import {
    generateWallet,
    importFromMnemonic,
    importFromPrivateKey,
    encryptAndStore,
    unlockWallet,
    connectToTPIXChain,
    getTPIXBalance,
    sendTPIX as sendTPIXTx,
    isWalletStored,
    getStoredAddress,
    clearWallet,
} from '@/utils/embeddedWallet';

const STORAGE_KEY = 'tpix_wallet';
const CONNECT_TIMEOUT_MS = 30000; // 30 วินาที timeout สำหรับ wallet connection
const VERIFY_TIMEOUT_MS = 15000;  // 15 วินาที timeout สำหรับ signature verification

/**
 * Promise with timeout — ป้องกันการค้างถาวร
 */
function withTimeout(promise, ms, message = 'Operation timed out') {
    return Promise.race([
        promise,
        new Promise((_, reject) =>
            setTimeout(() => reject(new Error(message)), ms)
        ),
    ]);
}

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
    const provider = shallowRef(null);
    const signer = shallowRef(null);
    const isConnecting = ref(false);
    const error = ref(null);
    const walletType = ref(null); // 'metamask', 'trustwallet', 'coinbase', 'okx', 'tpix_wallet'
    const tpixBalance = ref(null); // TPIX balance สำหรับ embedded wallet
    const isEmbedded = computed(() => walletType.value === 'tpix_wallet');

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
            // โหลดรายการ chain ที่รองรับ (ขนานกับ request accounts) + timeout ป้องกันค้าง
            const [accounts] = await withTimeout(
                Promise.all([
                    injected.request({ method: 'eth_requestAccounts' }),
                    loadSupportedChains(),
                ]),
                CONNECT_TIMEOUT_MS,
                'Wallet connection timed out. Please try again.'
            );

            if (!accounts || accounts.length === 0) {
                throw new Error('No accounts returned from wallet.');
            }

            // สร้าง ethers provider และ signer (+ timeout)
            const ethProvider = new BrowserProvider(injected);
            const ethSigner = await withTimeout(
                ethProvider.getSigner(),
                10000,
                'Failed to get wallet signer. Please try again.'
            );
            const network = await withTimeout(
                ethProvider.getNetwork(),
                10000,
                'Failed to detect network.'
            );

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

            // เพิ่ม TPIX Chain (4289) เข้ากระเป๋าอัตโนมัติ + สลับไป TPIX Chain
            try {
                await addTPIXChainToWallet(injected);
                // สลับไป TPIX Chain หลังเพิ่มสำเร็จ
                if (chainId.value !== TPIX_CHAIN_CONFIG.chainIdNum) {
                    await injected.request({
                        method: 'wallet_switchEthereumChain',
                        params: [{ chainId: TPIX_CHAIN_CONFIG.chainId }],
                    });
                    await _refreshProviderState(injected);
                }
            } catch (tpixErr) {
                // ถ้า user ปฏิเสธหรือ error — ไม่ block connection
                console.warn('[TPIX] ไม่สามารถเพิ่ม/สลับไป TPIX Chain:', tpixErr.message);
            }

            // แจ้ง backend ว่า wallet connect สำเร็จ — สร้าง user อัตโนมัติ
            _registerWalletToBackend(address.value, chainId.value, type);

            // ยืนยัน wallet ownership ด้วย signature (ไม่ block connection — ใช้ timeout)
            _verifyWalletOwnership(ethSigner, address.value).catch(() => {});

            // ตั้งค่า event listeners สำหรับ chain/account changes
            _setupListeners();

            // เล่นเสียงเชื่อมต่อสำเร็จ
            playConnectSound();

            return address.value;
        } catch (err) {
            playErrorSound();
            if (err.code === 4001) {
                error.value = 'Connection rejected by user.';
            } else if (err.message?.includes('timed out')) {
                error.value = err.message;
            } else {
                error.value = err.message || 'Failed to connect wallet.';
            }
            throw err;
        } finally {
            isConnecting.value = false;
        }
    }

    // === Embedded TPIX Wallet ===

    /**
     * สร้าง TPIX Wallet ใหม่ — generate mnemonic + encrypt + connect
     * @param {string} password — min 8 chars
     * @returns {Promise<{address: string, mnemonic: string}>}
     */
    async function createEmbeddedWallet(password) {
        isConnecting.value = true;
        error.value = null;
        try {
            const { wallet, mnemonic, address: addr } = generateWallet();
            await encryptAndStore(wallet, password);
            const connected = connectToTPIXChain(wallet);

            address.value = addr;
            chainId.value = TPIX_CHAIN_CONFIG.chainIdNum;
            provider.value = connected.provider;
            signer.value = connected;
            walletType.value = 'tpix_wallet';

            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                address: addr,
                walletType: 'tpix_wallet',
            }));

            await loadSupportedChains();
            await refreshTPIXBalance();

            return { address: addr, mnemonic };
        } catch (err) {
            error.value = err.message;
            throw err;
        } finally {
            isConnecting.value = false;
        }
    }

    /**
     * Import wallet จาก mnemonic หรือ private key
     * @param {string} mnemonicOrKey — seed phrase หรือ private key
     * @param {string} password — สำหรับ encrypt
     */
    async function importEmbeddedWallet(mnemonicOrKey, password) {
        isConnecting.value = true;
        error.value = null;
        try {
            const input = mnemonicOrKey.trim();
            // ถ้ามี space = mnemonic, ไม่มี = private key
            const wallet = input.includes(' ')
                ? importFromMnemonic(input)
                : importFromPrivateKey(input);

            await encryptAndStore(wallet, password);
            const connected = connectToTPIXChain(wallet);

            address.value = wallet.address;
            chainId.value = TPIX_CHAIN_CONFIG.chainIdNum;
            provider.value = connected.provider;
            signer.value = connected;
            walletType.value = 'tpix_wallet';

            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                address: wallet.address,
                walletType: 'tpix_wallet',
            }));

            await loadSupportedChains();
            await refreshTPIXBalance();

            return wallet.address;
        } catch (err) {
            error.value = err.message;
            throw err;
        } finally {
            isConnecting.value = false;
        }
    }

    /**
     * Unlock embedded wallet ด้วย password (reconnect)
     */
    async function connectEmbedded(password) {
        isConnecting.value = true;
        error.value = null;
        try {
            const wallet = await unlockWallet(password);
            const connected = connectToTPIXChain(wallet);

            address.value = wallet.address;
            chainId.value = TPIX_CHAIN_CONFIG.chainIdNum;
            provider.value = connected.provider;
            signer.value = connected;
            walletType.value = 'tpix_wallet';

            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                address: wallet.address,
                walletType: 'tpix_wallet',
            }));

            await loadSupportedChains();
            await refreshTPIXBalance();

            return wallet.address;
        } catch (err) {
            error.value = err.message;
            throw err;
        } finally {
            isConnecting.value = false;
        }
    }

    /**
     * ส่ง TPIX (gasless!) ผ่าน embedded wallet
     */
    async function sendTPIX(toAddress, amount) {
        if (walletType.value !== 'tpix_wallet' || !signer.value) {
            throw new Error('ต้องเชื่อม TPIX Wallet ก่อน');
        }
        const tx = await sendTPIXTx(signer.value, toAddress, amount);
        await refreshTPIXBalance();
        return tx;
    }

    /**
     * รีเฟรช TPIX balance
     */
    async function refreshTPIXBalance() {
        if (!address.value) return;
        try {
            tpixBalance.value = await getTPIXBalance(address.value);
        } catch {
            tpixBalance.value = '0';
        }
    }

    /**
     * ตรวจว่ามี embedded wallet เก็บอยู่ (ยังไม่ unlock)
     */
    function hasStoredEmbeddedWallet() {
        return isWalletStored();
    }

    function disconnect() {
        // เล่นเสียง disconnect
        playDisconnectSound();
        // แจ้ง backend ว่า disconnect
        if (address.value) {
            axios.post('/api/v1/wallet/disconnect', {
                wallet_address: address.value,
            }).catch(() => {});
        }
        // ถ้าเป็น embedded wallet — ไม่ลบ encrypted key (แค่ lock)
        address.value = null;
        chainId.value = null;
        provider.value = null;
        signer.value = null;
        tpixBalance.value = null;
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

            // Embedded wallet — ต้อง unlock ด้วย password (ไม่ auto-reconnect)
            // แต่แสดง address ให้เห็นว่ามี wallet อยู่
            if (savedType === 'tpix_wallet') {
                address.value = savedAddr;
                walletType.value = 'tpix_wallet';
                chainId.value = TPIX_CHAIN_CONFIG.chainIdNum;
                await loadSupportedChains();
                // ยังไม่มี signer จนกว่าจะ unlock
                return true;
            }

            const injected = _getProvider(savedType || 'metamask');
            if (!injected) return false;

            _rawProvider = injected;

            // โหลด chain list ขนานกับ check accounts (+ timeout ป้องกันค้าง)
            const [accounts] = await withTimeout(
                Promise.all([
                    injected.request({ method: 'eth_accounts' }),
                    loadSupportedChains(),
                ]),
                10000,
                'Auto-reconnect timed out'
            );

            if (accounts && accounts.length > 0) {
                const ethProvider = new BrowserProvider(injected);
                const ethSigner = await withTimeout(ethProvider.getSigner(), 10000, 'Signer timeout');
                const network = await withTimeout(ethProvider.getNetwork(), 10000, 'Network timeout');

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
     * ยืนยัน wallet ownership ด้วย signature (EIP-191 personal_sign)
     * Flow: requestSignature → sign message → verifySignature
     * เมื่อสำเร็จ backend จะ cache "wallet_verified:{address}" 4 ชม.
     * ทำให้ POST/PUT/DELETE requests ผ่าน VerifyWalletOwnership middleware ได้
     */
    async function _verifyWalletOwnership(walletSigner, walletAddress) {
        try {
            // 1. ขอ nonce + message จาก backend (+ timeout)
            const signRes = await withTimeout(
                axios.post('/api/v1/wallet/sign', { wallet_address: walletAddress }),
                VERIFY_TIMEOUT_MS,
                'Signature request timed out'
            );

            if (!signRes.data?.success) return;

            const { message, nonce } = signRes.data.data;

            // 2. ให้ผู้ใช้ sign message ด้วย wallet (+ timeout)
            const signature = await withTimeout(
                walletSigner.signMessage(message),
                VERIFY_TIMEOUT_MS,
                'Signature timed out'
            );

            // 3. ส่ง signature กลับไปยืนยัน
            await withTimeout(
                axios.post('/api/v1/wallet/verify-signature', {
                    wallet_address: walletAddress,
                    signature,
                    nonce,
                }),
                VERIFY_TIMEOUT_MS,
                'Verification timed out'
            );
        } catch (err) {
            // ถ้า user ปฏิเสธ sign (code 4001) หรือ timeout — ไม่ block connection
            console.warn('[TPIX] Wallet verification skipped:', err.message || err);
        }
    }

    /**
     * แจ้ง backend เมื่อ wallet connect — สร้าง user อัตโนมัติ + บันทึก connection
     * ไม่ block flow — fire and forget
     */
    function _registerWalletToBackend(walletAddress, walletChainId, walletType) {
        axios.post('/api/v1/wallet/connect', {
            wallet_address: walletAddress,
            chain_id: walletChainId || 56,
            wallet_type: walletType || 'metamask',
        }).then((res) => {
            if (res.data?.success) {
                console.log('[TPIX] ✅ Wallet registered to backend:', walletAddress);
            }
        }).catch((err) => {
            console.warn('[TPIX] Wallet registration failed:', err.message);
        });
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

    // Wallet modal state (ให้ทุก component เรียกเปิดได้)
    const showConnectModal = ref(false);
    function openConnectModal() { showConnectModal.value = true; }
    function closeConnectModal() { showConnectModal.value = false; }

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
        showConnectModal,
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
        // Embedded TPIX Wallet
        createEmbeddedWallet,
        importEmbeddedWallet,
        connectEmbedded,
        sendTPIX,
        refreshTPIXBalance,
        hasStoredEmbeddedWallet,
        tpixBalance,
        isEmbedded,
        // Modal control
        openConnectModal,
        closeConnectModal,
    };
});
