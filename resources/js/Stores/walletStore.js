/**
 * TPIX TRADE - Wallet Store (Pinia)
 * ระบบจัดการ Wallet แบบรวมศูนย์ รองรับหลาย chain
 * รองรับ MetaMask, Trust Wallet, Coinbase, OKX, WalletConnect v2 + TPIX Wallet (embedded)
 * TPIX Wallet = self-custodial wallet ในตัวเว็บ (ไม่ต้อง MetaMask)
 * WalletConnect v2 = เชื่อมต่อกระเป๋ามือถือผ่าน QR code (TPIX Wallet app, etc.)
 * สลับ chain อัตโนมัติไปยัง TPIX Chain เมื่อเชื่อมต่อ
 * Developed by Xman Studio
 */

import { defineStore } from 'pinia';
import { ref, shallowRef, computed } from 'vue';
import { BrowserProvider, JsonRpcProvider, parseEther } from 'ethers';
import { router, usePage } from '@inertiajs/vue3';
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
    /*
     * ผูกกระเป๋าเข้ากับบัญชีไม่สำเร็จ — แยกจาก `error` ของการเชื่อมต่อ
     *
     * เชื่อมกระเป๋าสำเร็จแล้วแต่ผูกกับบัญชีไม่ได้ (เป็นของบัญชีอื่น / บัญชีนี้ผูกใบอื่นไว้)
     * เป็นคนละเรื่องกับเชื่อมไม่ติด — ใช้งานกระเป๋าต่อได้ปกติ แค่ยังไม่รวมเป็นบัญชีเดียว
     */
    const linkError = ref(null);
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
     * TPIX Chain เปิดให้ใช้จริงแล้วหรือยัง (ตาม status ที่แบ็กเอนด์ประกาศ)
     *
     * ใช้เกณฑ์เดียวกับ ChainSelector — ไม่มี status ถือว่า live เพื่อรองรับ API เก่า
     * โหลดรายการเชนไม่สำเร็จ = ไม่รู้ = ไม่สลับ ปล่อยผู้ใช้อยู่บนเชนเดิมที่ใช้งานได้อยู่
     */
    function isTpixChainLive() {
        const tpix = supportedChains.value.find(c => c.chainId === TPIX_CHAIN_CONFIG.chainIdNum);
        if (!tpix) return false;

        return !tpix.status || tpix.status === 'live';
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

            /*
             * เพิ่ม TPIX Chain (4289) เข้ากระเป๋าอัตโนมัติ + สลับไปให้
             *
             * ⚠️ สลับให้เฉพาะตอนที่เชนเปิดใช้จริงแล้วเท่านั้น
             *    ตอนที่เชนยัง coming_soon ตัวเลือกเชนจะขึ้นป้าย "Soon" แล้วกดไม่ได้
             *    ถ้ายังบังคับสลับไปตรงนั้น ผู้ใช้จะถูกจอดบนเชนที่หน้าเว็บเองบอกว่า
             *    ยังไม่เปิด — เห็นยอดเป็นศูนย์ทั้งที่มีเหรียญ และไม่รู้ว่าต้องทำยังไงต่อ
             *
             *    ยังเพิ่มเชนเข้ากระเป๋าไว้ก่อนได้ (ไม่เสียหาย และพอเปิดจริงก็พร้อมใช้)
             */
            try {
                await addTPIXChainToWallet(injected);

                if (isTpixChainLive() && chainId.value !== TPIX_CHAIN_CONFIG.chainIdNum) {
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

            // แจ้ง backend + ยืนยัน ownership ผ่าน signature
            // จำเป็นเพื่อให้ POST/PUT/DELETE ผ่าน VerifyWalletOwnership middleware ได้
            _registerWalletToBackend(addr, TPIX_CHAIN_CONFIG.chainIdNum, 'tpix_wallet');
            _verifyWalletOwnership(connected, addr).catch(() => {});

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

            // แจ้ง backend + ยืนยัน ownership (จำเป็นสำหรับ trading/bridge/swap)
            _registerWalletToBackend(wallet.address, TPIX_CHAIN_CONFIG.chainIdNum, 'tpix_wallet');
            _verifyWalletOwnership(connected, wallet.address).catch(() => {});

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

            // แจ้ง backend + ยืนยัน ownership ทุกครั้งที่ unlock
            // cache หมดอายุ 4 ชม. — ต้อง re-verify เมื่อ unlock ครั้งต่อไป
            _registerWalletToBackend(wallet.address, TPIX_CHAIN_CONFIG.chainIdNum, 'tpix_wallet');
            _verifyWalletOwnership(connected, wallet.address).catch(() => {});

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
            }).then((res) => {
                // เซิร์ฟเวอร์ปิด session ให้ด้วย (ผู้ใช้ที่เข้ามาด้วยกระเป๋าล้วน)
                // ต้องดึง props ใหม่ ไม่งั้นเมนูบัญชียังค้างอยู่ทั้งที่ออกไปแล้ว
                if (res?.data?.data?.signed_out) router.reload({ only: ['auth'] });
            }).catch(() => {});
        }
        linkError.value = null;
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

    // === WalletConnect v2 ===

    /**
     * เชื่อมต่อผ่าน WalletConnect v2 — แสดง QR code ให้สแกนจากแอพมือถือ
     * รองรับ TPIX Wallet app, MetaMask mobile, Trust Wallet, etc.
     * หลังเชื่อมต่อจะสั่ง wallet เพิ่ม TPIX Chain + สลับ chain อัตโนมัติ
     */
    async function connectWalletConnect() {
        isConnecting.value = true;
        error.value = null;

        try {
            const { EthereumProvider } = await import('@walletconnect/ethereum-provider');

            // ดึง Project ID จาก admin settings (Inertia shared props) — fallback ไป env variable
            const pageProps = usePage()?.props;
            const projectId = pageProps?.app?.walletconnect_project_id
                || import.meta.env.VITE_WALLETCONNECT_PROJECT_ID
                || '';

            if (!projectId) {
                throw new Error('WalletConnect Project ID not configured. Please set it in Admin Settings → Trading.');
            }

            // สร้าง WalletConnect provider — จะแสดง QR modal อัตโนมัติ
            const wcProvider = await withTimeout(
                EthereumProvider.init({
                    projectId,
                    chains: [4289], // TPIX Chain เป็น chain หลัก
                    optionalChains: [56, 137, 1], // BSC, Polygon, ETH
                    showQrModal: true,
                    metadata: {
                        name: 'TPIX TRADE',
                        description: 'Decentralized Exchange on TPIX Chain',
                        url: window.location.origin,
                        icons: [`${window.location.origin}/tpixlogo.webp`],
                    },
                    // สั่ง wallet เพิ่ม TPIX Chain อัตโนมัติถ้ายังไม่มี (EIP-3085)
                    rpcMap: {
                        4289: TPIX_CHAIN_CONFIG.rpcUrls[0],
                        56: 'https://bsc-dataseed1.binance.org',
                        137: 'https://polygon-rpc.com',
                        1: 'https://eth.llamarpc.com',
                    },
                }),
                CONNECT_TIMEOUT_MS,
                'WalletConnect connection timed out.'
            );

            // เปิด QR modal แล้วรอ user สแกน + approve
            await withTimeout(
                wcProvider.enable(),
                60000, // 60 วินาที — ให้เวลาสแกน QR
                'WalletConnect pairing timed out. Please try again.'
            );

            const accounts = wcProvider.accounts;
            if (!accounts || accounts.length === 0) {
                throw new Error('No accounts returned from WalletConnect.');
            }

            // สร้าง ethers provider จาก WalletConnect provider
            const ethProvider = new BrowserProvider(wcProvider);
            const ethSigner = await withTimeout(
                ethProvider.getSigner(),
                10000,
                'Failed to get signer from WalletConnect.'
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
            walletType.value = 'walletconnect';
            _rawProvider = wcProvider;

            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                address: accounts[0],
                walletType: 'walletconnect',
            }));

            await loadSupportedChains();

            // แจ้ง backend + ยืนยัน ownership
            _registerWalletToBackend(accounts[0], chainId.value, 'walletconnect');
            _verifyWalletOwnership(ethSigner, accounts[0]).catch(() => {});

            // ตั้งค่า event listeners (WalletConnect provider ก็รองรับ events เหมือน MetaMask)
            _setupListeners();

            playConnectSound();
            return accounts[0];
        } catch (err) {
            playErrorSound();
            if (err.message?.includes('timed out')) {
                error.value = err.message;
            } else if (err.message?.includes('User rejected') || err.code === 4001) {
                error.value = 'Connection rejected by user.';
            } else {
                error.value = err.message || 'WalletConnect failed.';
            }
            throw err;
        } finally {
            isConnecting.value = false;
        }
    }

    /**
     * สลับไปยัง chain ที่ระบุ (รองรับทุก chain ในระบบ)
     * ถ้าไม่ระบุ targetChainId จะสลับไป chain หลัก (BSC)
     * @param {number} targetChainId - Chain ID เป้าหมาย
     */
    async function switchChain(targetChainId = DEFAULT_CHAIN_ID) {
        error.value = null;

        /*
         * ⚠️ กระเป๋าฝัง (TPIX Wallet) ห้ามตกไปใช้ window.ethereum เด็ดขาด
         *
         * เดิมโค้ดเป็น `_rawProvider || _getProvider(walletType.value || 'metamask')`
         * สำหรับกระเป๋าฝัง `_rawProvider` ไม่เคยถูกเซ็ต และ _getProvider('tpix_wallet')
         * ไม่ตรงเงื่อนไขใดเลยจึงตกมาที่ `return window.ethereum` = ส่วนขยาย MetaMask
         * จากนั้น _refreshProviderState() เขียนทับ signer ด้วยบัญชีของ MetaMask
         * ขณะที่ address/walletType ยังเป็นของกระเป๋าฝัง
         * → ธุรกรรมถัดไปถูกเซ็นด้วยบัญชีผิดใบ (เงินออกจากกระเป๋าที่ผู้ใช้ไม่ได้ตั้งใจ)
         *
         * กระเป๋าฝังใช้ได้เฉพาะ TPIX Chain อยู่แล้ว จึงไม่มีเหตุผลให้สลับเชน
         */
        if (isEmbedded.value) {
            if (Number(targetChainId) === TPIX_CHAIN_CONFIG.chainIdNum) {
                chainId.value = TPIX_CHAIN_CONFIG.chainIdNum;
                return;
            }

            error.value = 'TPIX Wallet ใช้ได้เฉพาะ TPIX Chain — เชื่อมกระเป๋าภายนอกเพื่อใช้เชนอื่น';
            throw new Error('EMBEDDED_WALLET_SINGLE_CHAIN');
        }

        const injected = _rawProvider || _getProvider(walletType.value || 'metamask');

        // เดิม return เฉยๆ ทำให้ผู้ใช้เห็นเหมือนสำเร็จทั้งที่ไม่มีอะไรเกิดขึ้น
        // และโค้ดที่เรียกต่อ (เช่นหน้า Bridge) เดินต่อบนเชนที่ผิด
        if (!injected) {
            error.value = 'ไม่พบกระเป๋าที่เชื่อมอยู่ — เชื่อมกระเป๋าก่อนสลับเครือข่าย';
            throw new Error('NO_WALLET_PROVIDER');
        }

        try {
            await switchToChain(injected, targetChainId);
            await _refreshProviderState(injected);
            error.value = null;
        } catch (err) {
            // ผู้ใช้กดยกเลิกเองไม่ใช่ระบบพัง — แยกข้อความให้ตรงกับสิ่งที่เกิดขึ้น
            error.value = err?.code === 4001
                ? 'คุณยกเลิกการสลับเครือข่าย'
                : 'ไม่สามารถสลับ network ได้';
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
        linkError.value = null;

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
            //    ส่ง chain_id + wallet_type ไปด้วย ไม่งั้นประวัติการเชื่อมต่อจะถูก
            //    บันทึกเป็น BSC/metamask ตามค่าปริยายของเซิร์ฟเวอร์เสมอ
            const verifyRes = await withTimeout(
                axios.post('/api/v1/wallet/verify-signature', {
                    wallet_address: walletAddress,
                    signature,
                    nonce,
                    chain_id: chainId.value || DEFAULT_CHAIN_ID,
                    wallet_type: walletType.value || 'metamask',
                }),
                VERIFY_TIMEOUT_MS,
                'Verification timed out'
            );

            /*
             * เซ็นผ่านแล้วเซิร์ฟเวอร์เปิด session ให้ — ต้องดึง props ใหม่
             *
             * Inertia เก็บ props ที่ได้ตอนโหลดหน้าไว้ ถ้าไม่สั่งดึงใหม่ `auth.user`
             * จะยังเป็น null จนกว่าผู้ใช้จะกดเปลี่ยนหน้าเอง — เขาจะเห็นปุ่ม
             * "เข้าสู่ระบบ" ทั้งที่เข้าไปแล้ว
             */
            if (verifyRes.data?.data?.signed_in) {
                router.reload({ only: ['auth'] });
            }
        } catch (err) {
            /*
             * ผูกกระเป๋าไม่ได้ (409) ต่างจากการเซ็นล้มเหลว — ต้องบอกผู้ใช้
             *
             * กระเป๋าใบนี้เป็นของบัญชีอื่น หรือบัญชีนี้ผูกใบอื่นไว้แล้ว เป็นเรื่องที่
             * ผู้ใช้ต้องไปจัดการเอง เงียบไว้เท่ากับปล่อยให้เขาใช้งานต่อโดยคิดว่า
             * ผูกสำเร็จแล้ว แล้วไปงงทีหลังว่าทำไมข้อมูลไม่ตรง
             */
            if (err?.response?.status === 409) {
                linkError.value = err.response.data?.error?.message || 'ผูกกระเป๋ากับบัญชีไม่สำเร็จ';
                return;
            }

            // ถ้า user ปฏิเสธ sign (code 4001) หรือ timeout — ไม่ block connection
            console.warn('[TPIX] Wallet verification skipped:', err.message || err);
        }
    }

    /**
     * เซ็นยืนยันกระเป๋าใหม่ โดยไม่ต้องตัดการเชื่อมต่อก่อน.
     *
     * ทำไมต้องมี: แถว `wallet_verified:` ฝั่งเซิร์ฟเวอร์อยู่ได้ 4 ชั่วโมง และถูกล้าง
     * ทุกครั้งที่ deploy (`cache:clear`) แต่ `tryReconnect()` ตอนเปิดหน้าใหม่แค่คืน
     * address จาก localStorage — ไม่ได้เซ็นซ้ำ ผู้ใช้จึงเห็นว่า "เชื่อมกระเป๋าแล้ว"
     * ทั้งที่ API ทุกตัวที่ผูกกับกระเป๋าตอบ 403 WALLET_NOT_VERIFIED
     *
     * ไม่เซ็นอัตโนมัติตอนเปิดหน้า เพราะป๊อปอัพขอลายเซ็นที่โผล่เองทุกครั้งที่รีเฟรช
     * ทำให้ผู้ใช้กดปฏิเสธจนติดเป็นนิสัย — ให้หน้าจอที่เจอ 403 เรียกตัวนี้ตอนผู้ใช้กดเอง
     *
     * ตัวนี้ไม่บอกว่า "ยืนยันสำเร็จไหม" โดยตั้งใจ — ไม่มี endpoint สาธารณะให้ถาม
     * และไม่ควรมี (ถามได้ทีละกระเป๋า = บอกคนนอกว่ากระเป๋าไหนกำลังใช้งานเว็บอยู่)
     * ผู้เรียกต้องยิง API ที่ตัวเองต้องใช้ซ้ำ แล้วดูว่าหลุด 403 หรือยัง — อันนั้น
     * คือความจริงที่ตรงกับสิ่งที่ผู้ใช้จะทำต่อจริงๆ
     *
     * @returns {Promise<boolean>} false เมื่อยังไม่มี signer (กระเป๋าฝังยังไม่ปลดล็อก)
     */
    async function verifyOwnership() {
        if (!signer.value || !address.value) return false;

        await _verifyWalletOwnership(signer.value, address.value);

        return true;
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
        linkError,
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
        verifyOwnership,
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
        // WalletConnect v2
        connectWalletConnect,
        // Modal control
        openConnectModal,
        closeConnectModal,
    };
});
