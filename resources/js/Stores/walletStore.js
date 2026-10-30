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
import { BrowserProvider } from 'ethers';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { playConnectSound, playDisconnectSound, playErrorSound } from '@/Composables/useSounds';
import { isMobile } from '@/utils/mobileWallet';
import {
    getWalletConnectProvider,
    hasActiveSession,
    isChainApproved,
    rememberDeepLinkChoice,
    clearDeepLinkChoice,
    waitForForeground,
    reviveRelay,
} from '@/utils/walletConnect';
import {
    BSC_CHAIN_ID,
    getDefaultChainId,
    TPIX_CHAIN_CONFIG,
    switchToChain,
    fetchSupportedChains,
    getAddressUrl,
    formatAddress,
    addTPIXChainToWallet,
    addTokenToWallet,
} from '@/utils/web3';
import {
    fetchTpixChainTokens,
    wasAssetSuggestionShown,
    markAssetSuggestionShown,
} from '@/utils/walletAssets';
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
/*
 * กระเป๋าที่รับคำขอ wallet_addEthereumChain / wallet_watchAsset จากหน้าเว็บได้จริง
 * ไม่รวม tpix_wallet (กระเป๋าฝัง ไม่มีรายการเหรียญให้เพิ่ม)
 * walletconnect รวมด้วย: MetaMask/Trust/OKX มือถือรับทั้งสองคำสั่งผ่าน WalletConnect
 * ตัวที่ไม่รับจะตอบ error → นับเป็น "ข้าม" ไม่ใช่ "สำเร็จ" (ไม่หลอกผู้ใช้)
 */
const ASSET_CAPABLE_WALLETS = ['metamask', 'trustwallet', 'coinbase', 'okx', 'walletconnect'];
const CONNECT_TIMEOUT_MS = 30000; // 30 วินาที timeout สำหรับ wallet connection
const VERIFY_TIMEOUT_MS = 15000;  // 15 วินาที timeout สำหรับ signature verification
/** ลิงก์จับคู่ (QR) ของ WalletConnect หมดอายุใน 5 นาที — รอได้เท่านั้น */
const WC_PAIRING_TIMEOUT_MS = 5 * 60 * 1000;
/*
 * เวลารอลายเซ็นยืนยันตัวตนผ่าน WalletConnect — ต้องสั้นกว่าอายุ nonce ฝั่งเซิร์ฟเวอร์ (300 วิ)
 *
 * ⚠️ เดิมใช้ 15 วิเท่ากระเป๋าบนเบราว์เซอร์ ซึ่งไม่พอเลยเมื่อกระเป๋าอยู่บนมือถือ: ผู้ใช้ต้องสลับไป
 *    แอปกระเป๋า ปลดล็อก อ่านข้อความ กดยืนยัน แล้วสลับกลับ — หมดเวลาก่อนแทบทุกครั้ง ลายเซ็นที่มาถึง
 *    ทีหลังถูกทิ้ง ผู้ใช้เห็นว่า "เชื่อมแล้ว" แต่ไม่ได้เข้าสู่ระบบ และ API ทุกตัวตอบ 403
 */
const WC_VERIFY_TIMEOUT_MS = 270000;
/*
 * กำลังรอลายเซ็นจากแอปกระเป๋า (sessionStorage = เฉพาะแท็บนี้)
 * Android ปิดแท็บเบราว์เซอร์ที่อยู่เบื้องหลังได้ทุกเมื่อตอนผู้ใช้อยู่ในแอปกระเป๋า กลับมาหน้าเว็บโหลดใหม่
 * คำขอเซ็นเดิมหายไปกับหน้าเก่า → ขอใหม่ให้เองหนึ่งครั้ง แทนที่จะปล่อยให้ผู้ใช้ค้างว่า "ยังไม่ได้เข้าสู่ระบบ"
 */
const PENDING_VERIFY_KEY = 'tpix_wallet_verify_pending';
const PENDING_VERIFY_MAX_AGE_MS = 5 * 60 * 1000;

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
    /*
     * เวลาที่เซ็นยืนยันความเป็นเจ้าของผ่านล่าสุด (ms) — null = ยังไม่เคยผ่านในหน้านี้
     *
     * address ถูกตั้งก่อนลายเซ็นเสร็จ หน้าจอที่ดู address จึงยิง API ไปก่อนแล้วเจอ 403
     * พอผู้ใช้เซ็นผ่านก็ไม่มีอะไรบอกให้โหลดใหม่ — การ์ดค้างที่ "ยืนยันกระเป๋า" ให้เซ็นซ้ำอีกรอบ
     * หน้าจอดูค่านี้แทน แล้วโหลดของที่ผูกกับกระเป๋าใหม่เองหลังเซ็นผ่าน
     */
    const verifiedAt = ref(null);
    const walletType = ref(null); // 'metamask', 'trustwallet', 'coinbase', 'okx', 'tpix_wallet'
    const tpixBalance = ref(null); // TPIX balance สำหรับ embedded wallet
    const isEmbedded = computed(() => walletType.value === 'tpix_wallet');
    /*
     * กระเป๋าฝังที่ยังไม่ได้ปลดล็อก — มีที่อยู่ (จาก localStorage) แต่ยังไม่มีผู้เซ็น
     *
     * ⚠️ รีเฟรชหน้าแล้ว tryReconnect() คืนแค่ที่อยู่ → isConnected = true ปุ่ม "เชื่อมกระเป๋า" หายทุกที่
     *    แต่ทุกธุรกรรมตอบ "กรุณาเชื่อมกระเป๋าก่อน" — ผู้ใช้ไม่มีปุ่มให้กดต่อ (ทางตัน)
     *    หน้าที่จะส่งธุรกรรมต้องดูค่านี้แล้วเปิดหน้าต่างปลดล็อก (openConnectModal) แทน
     */
    const isLocked = computed(() => isEmbedded.value && !!address.value && !signer.value);

    /*
     * ขั้นตอนเชื่อมผ่าน WalletConnect — หน้าต่างเชื่อมกระเป๋าดูค่านี้เพื่อบอกผู้ใช้ว่ากำลังรออะไร
     *   idle → pairing (โชว์ QR / ปุ่มเปิดแอป) → signing (รอลายเซ็นเข้าสู่ระบบในแอป) → done
     *   verify_failed = เชื่อมแล้วแต่เซ็นไม่ผ่าน (ปฏิเสธ/หมดเวลา) · error = จับคู่ไม่สำเร็จ
     */
    const wcStatus = ref('idle');
    const wcUri = ref(null);          // ลิงก์จับคู่ wc:… (ทำ QR / ลิงก์เปิดแอป)
    const wcWallet = ref(null);       // แอปที่เลือก: tpix | metamask | trustwallet | okx | tokenpocket | generic
    const wcSessionTopic = ref(null); // ใช้ทำลิงก์ "เปิดแอปเพื่อยืนยัน"
    /** เหตุที่เซ็นยืนยันไม่ผ่านล่าสุด: rejected | timeout | mismatch | failed */
    const verifyError = ref(null);
    /** กำลังกู้การเชื่อมต่อเดิมตอนเปิดหน้า — ปุ่ม "เชื่อมกระเป๋า" ไม่ควรโผล่แวบก่อนกู้เสร็จ */
    const isRestoring = ref(false);

    // รายการ chain ที่รองรับ (ดึงจาก backend API)
    const supportedChains = ref([]);

    // Raw injected provider (สำหรับ event listeners)
    let _rawProvider = null;
    /** provider ของ WalletConnect ที่ผูก event ไว้ (ตัวเดียวกับ _rawProvider เมื่อเชื่อมผ่าน WalletConnect) */
    let _wc = null;
    /** เลขรอบของการจับคู่ — รอบที่ถูกยกเลิก/ถูกแทนที่ห้ามมาเขียนทับสถานะ */
    let _wcAttempt = 0;
    let _wcCancel = null;
    let _reconnectPromise = null;

    // === Computed ===
    const isConnected = computed(() => !!address.value);
    const shortAddress = computed(() => formatAddress(address.value));

    // ตรวจสอบว่าอยู่บน chain หลัก (BSC) หรือไม่
    // อยู่บน BSC จริง ๆ ไหม (หน้า Swap ใช้ PancakeSwap ซึ่งอยู่บน BSC เท่านั้น)
    // — จงใจไม่ผูกกับ "เชนปริยาย" อีกต่อไป เพราะปริยายเป็น TPIX แล้ว
    const isBSC = computed(() => chainId.value === BSC_CHAIN_ID);

    /*
     * อยู่บนเชน TPIX (4289) หรือยัง
     *
     * ★ รอบขายเหรียญบังคับใช้ค่านี้ — เจ้าของกำหนดว่า "ผู้ซื้อต้องอยู่เชน TPIX
     *   จึงจะเห็นเหรียญ" เพราะ TPIX เป็นเหรียญเนทีฟของเชนนี้ ไม่ใช่โทเคนบน BSC
     *   ถ้าปล่อยให้ซื้อจากเชนอื่น ผู้ซื้อจะจ่ายเงินแล้วเปิดกระเป๋าไม่เห็นอะไรเลย
     *   แล้วคิดว่าโดนโกง — เป็นปัญหาความเชื่อมั่นที่แก้ทีหลังยากมาก
     */
    const isTpixChain = computed(() => chainId.value === TPIX_CHAIN_CONFIG.chainIdNum);

    /** กระเป๋านี้รับคำขอ "เพิ่มเหรียญ/เพิ่มเครือข่าย" จากหน้าเว็บได้ไหม (ปุ่มในเมนูกระเป๋าดูค่านี้) */
    const canAddTpixAssets = computed(() => isConnected.value && ASSET_CAPABLE_WALLETS.includes(walletType.value));

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
                /*
                 * ส่ง wallet_addEthereumChain ทุกครั้งที่เชื่อม แม้กระเป๋ามีเชนนี้แล้ว
                 * คนที่เคยเพิ่มไว้ได้ rpc.tpix.online ค้างอยู่ (โดน Cloudflare 403) — MetaMask
                 * เห็น RPC ต่างจากเดิมจะถามครั้งเดียวว่าอัปเดตไหม ถ้าค่าเหมือนเดิมจะไม่เด้งอะไร
                 */
                await addTPIXChainToWallet(injected);
                await _refreshProviderState(injected);

                // สลับให้ด้วยเมื่อเชนเปิดจริง — switchToChain เพิ่มให้เองถ้ายังไม่มี และตรวจว่าสลับจริง
                if (isTpixChainLive() && chainId.value !== TPIX_CHAIN_CONFIG.chainIdNum) {
                    await switchToChain(injected, TPIX_CHAIN_CONFIG.chainIdNum, TPIX_CHAIN_CONFIG);
                    await _refreshProviderState(injected);
                }
            } catch (tpixErr) {
                // ถ้า user ปฏิเสธหรือ error — ไม่ block connection
                console.warn('[TPIX] ไม่สามารถเพิ่ม/สลับไป TPIX Chain:', tpixErr.message);
            }

            // แจ้ง backend ว่า wallet connect สำเร็จ — สร้าง user อัตโนมัติ
            _registerWalletToBackend(address.value, chainId.value, type);

            // ยืนยัน wallet ownership ด้วย signature (ไม่ block connection — ใช้ timeout)
            // แล้วค่อยเสนอเพิ่มเหรียญ — ต่อคิวหลังป๊อปอัพเซ็น ไม่ให้สองหน้าต่างแย่งกันโผล่
            const owner = address.value;
            _ensureSignedIn(ethSigner, owner)
                .catch(() => {})
                .then(() => _autoSuggestTpixAssets(owner))
                .catch(() => {});

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
                if (res?.data?.data?.signed_out) router.reload({ only: ['auth', 'kyc'] });
            }).catch(() => {});
        }

        /*
         * WalletConnect: ปิด session ที่ relay ด้วย — แอปกระเป๋าเห็นว่าตัดแล้ว ไม่ค้างรายการเชื่อมต่อ
         * และรีโหลดหน้าแล้วต้องไม่กลับมาเชื่อมเองอีก
         * ถอด listener ก่อน ไม่งั้น event session_delete ที่ตามมาจะวนกลับมาเรียกตัวนี้ซ้ำ
         */
        const wc = _wc;
        _removeListeners();
        _detachWalletConnect();
        if (wc?.session) wc.disconnect().catch(() => {});
        clearDeepLinkChoice();
        _clearPendingVerification();
        _cancelPairing();

        linkError.value = null;
        verifyError.value = null;
        // ถ้าเป็น embedded wallet — ไม่ลบ encrypted key (แค่ lock)
        address.value = null;
        chainId.value = null;
        provider.value = null;
        signer.value = null;
        tpixBalance.value = null;
        walletType.value = null;
        error.value = null;
        wcStatus.value = 'idle';
        wcUri.value = null;
        wcWallet.value = null;
        wcSessionTopic.value = null;
        isConnecting.value = false;
        localStorage.removeItem(STORAGE_KEY);
        _rawProvider = null;
    }

    /**
     * เชื่อมต่อกลับอัตโนมัติจาก localStorage (ไม่มี popup)
     * ใช้ eth_accounts แทน eth_requestAccounts เพื่อไม่รบกวนผู้ใช้
     *
     * ⚠️ ทุกหน้าห่อด้วย <AppLayout> ซึ่งถูกสร้างใหม่ทุกครั้งที่เปลี่ยนหน้า จึงเรียกตัวนี้ทุกหน้า
     *    เชื่อมอยู่แล้ว = จบเลย · กำลังกู้อยู่ = รอผลรอบเดิม
     *    (เดิมสร้าง provider/ผู้เซ็นใหม่และผูก event ซ้ำทุกครั้งที่เปลี่ยนหน้า)
     */
    function tryReconnect() {
        if (address.value && (signer.value || isEmbedded.value)) return Promise.resolve(true);
        if (!_reconnectPromise) {
            _reconnectPromise = _tryReconnect().finally(() => {
                _reconnectPromise = null;
            });
        }
        return _reconnectPromise;
    }

    async function _tryReconnect() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (!saved) return false;

        isRestoring.value = true;
        try {
            const { address: savedAddr, walletType: savedType, wcWallet: savedWcWallet } = JSON.parse(saved);
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

            /*
             * WalletConnect: กู้ session จากที่เก็บของไลบรารีเอง — ห้ามตกไปใช้ window.ethereum
             *
             * ⚠️ เดิมไหลลงไปที่ _getProvider('walletconnect') ซึ่งคืน window.ethereum
             *    มือถือไม่มีส่วนขยาย → คืน null → หลุดการเชื่อมต่อทุกครั้งที่รีโหลด/สลับแอปกลับมา
             *    คอมที่มี MetaMask → แอบไปผูกกับ MetaMask แทนแอปบนมือถือ แล้วเซ็นด้วยกระเป๋าผิดใบ
             */
            if (savedType === 'walletconnect') {
                return await _restoreWalletConnect(savedAddr, savedWcWallet);
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
        } finally {
            isRestoring.value = false;
        }

        return false;
    }

    /**
     * กู้การเชื่อมต่อ WalletConnect เดิม (หลังรีโหลด / กลับมาจากแอปกระเป๋า / เปิดเว็บวันหลัง)
     * session อยู่ใน IndexedDB ของไลบรารี — สร้าง provider แล้วมันกลับมาเอง ไม่ต้องสแกนซ้ำ
     */
    async function _restoreWalletConnect(savedAddr, savedWcWallet) {
        let wc;
        try {
            [wc] = await withTimeout(
                Promise.all([
                    getWalletConnectProvider(_walletConnectProjectId()),
                    loadSupportedChains(),
                ]),
                15000,
                'WalletConnect restore timed out'
            );
        } catch (err) {
            // เน็ตสะดุด/relay ช้าตอนเปิดหน้า ≠ ผู้ใช้ตัดการเชื่อมต่อ — ไม่ลืมการเชื่อมต่อไว้ ลองใหม่หน้าถัดไป
            console.warn('[TPIX] กู้ WalletConnect ไม่สำเร็จ:', err?.message);
            return false;
        }

        if (!hasActiveSession(wc)) {
            // session หมดอายุ หรือถูกตัดจากฝั่งแอปกระเป๋าแล้ว — ล้างของค้าง ไม่โชว์ว่ายังเชื่อมอยู่
            localStorage.removeItem(STORAGE_KEY);
            clearDeepLinkChoice();
            return false;
        }

        const owner = wc.accounts.find(a => String(a).toLowerCase() === String(savedAddr).toLowerCase())
            || wc.accounts[0];
        const ethProvider = new BrowserProvider(wc);
        const ethSigner = await withTimeout(ethProvider.getSigner(owner), 10000, 'Signer timeout');
        const network = await withTimeout(ethProvider.getNetwork(), 10000, 'Network timeout');

        address.value = owner;
        chainId.value = Number(network.chainId);
        provider.value = ethProvider;
        signer.value = ethSigner;
        walletType.value = 'walletconnect';
        wcWallet.value = savedWcWallet || null;
        wcSessionTopic.value = wc.session?.topic || null;
        _rawProvider = wc;
        _attachWalletConnect(wc);
        _setupListeners();
        _saveConnection(owner, 'walletconnect');
        _resumePendingVerification(owner);
        return true;
    }

    // === WalletConnect v2 ===

    /**
     * เชื่อมต่อผ่าน WalletConnect v2 — คอมพิวเตอร์โชว์ QR ให้แอปมือถือสแกน · มือถือเปิดแอปกระเป๋าด้วยลิงก์
     * รองรับ TPIX Wallet app, MetaMask mobile, Trust Wallet, etc.
     * หน้าต่างเชื่อมกระเป๋าอ่าน wcStatus / wcUri เพื่อวาด QR และบอกว่ารออะไรอยู่
     *
     * จบเมื่อเชื่อม + เซ็นเข้าสู่ระบบแล้ว · เซ็นไม่ผ่าน = ยังเชื่อมอยู่ (wcStatus = verify_failed)
     *
     * @param {{wallet?: string}} [opts] wallet = แอปที่ผู้ใช้เลือก (คีย์ใน WC_MOBILE_WALLETS) หรือ 'generic'
     * @returns {Promise<string>} ที่อยู่กระเป๋า
     */
    async function connectWalletConnect({ wallet = 'generic' } = {}) {
        _cancelPairing(); // รอบก่อนหน้าที่ยังค้างอยู่จบตรงนี้
        const attempt = ++_wcAttempt;
        const mobile = isMobile();

        isConnecting.value = true;
        error.value = null;
        verifyError.value = null;
        wcStatus.value = 'pairing';
        wcUri.value = null;
        wcWallet.value = wallet;

        let wc = null;
        const onUri = (uri) => {
            if (attempt === _wcAttempt) wcUri.value = uri;
        };

        try {
            wc = await withTimeout(
                getWalletConnectProvider(_walletConnectProjectId()),
                CONNECT_TIMEOUT_MS,
                'WalletConnect connection timed out.'
            );
            _assertCurrentAttempt(attempt);

            // session เก่าค้างอยู่ — ผู้ใช้กดเชื่อมใหม่เอง ต้องเริ่มสด ไม่งั้นได้กระเป๋าใบเดิมกลับมาทั้งที่เลือกแอปอื่น
            _detachWalletConnect();
            if (wc.session) {
                await withTimeout(wc.disconnect(), 5000, 'WalletConnect reset timed out').catch(() => {});
            }
            _attachWalletConnect(wc);

            // มือถือ: จำแอปที่เลือก — sign-client เปิดแอปนั้นให้เองตอนส่งคำขอเซ็น/ส่งธุรกรรม
            // คอมพิวเตอร์: ห้ามมีค่านี้ค้าง (กระเป๋าอยู่บนมือถืออีกเครื่อง ลิงก์เปิดแอปบนคอมไม่มีใครรับ)
            if (mobile && wallet !== 'generic') rememberDeepLinkChoice(wallet);
            else clearDeepLinkChoice();

            wc.on('display_uri', onUri);
            const pairing = wc.connect();
            _disposeIfAbandoned(pairing, attempt, wc);
            await _raceCancel(withTimeout(
                pairing,
                WC_PAIRING_TIMEOUT_MS,
                'WalletConnect pairing timed out. Please try again.'
            ));
            _assertCurrentAttempt(attempt);

            if (!hasActiveSession(wc)) {
                throw new Error('No accounts returned from WalletConnect.');
            }

            // สร้าง ethers provider จาก WalletConnect provider (eth_accounts/eth_chainId ตอบในเครื่อง ไม่ไปถามแอป)
            const owner = wc.accounts[0];
            const ethProvider = new BrowserProvider(wc);
            const ethSigner = await withTimeout(
                ethProvider.getSigner(owner),
                10000,
                'Failed to get signer from WalletConnect.'
            );
            const network = await withTimeout(
                ethProvider.getNetwork(),
                10000,
                'Failed to detect network.'
            );

            // อัปเดต state
            address.value = owner;
            chainId.value = Number(network.chainId);
            provider.value = ethProvider;
            signer.value = ethSigner;
            walletType.value = 'walletconnect';
            wcSessionTopic.value = wc.session?.topic || null;
            _rawProvider = wc;
            wcStatus.value = 'signing';
            _saveConnection(owner, 'walletconnect');

            await loadSupportedChains();

            /*
             * สลับไป TPIX Chain เฉพาะเมื่อกระเป๋าอนุมัติเชนนี้ใน session แล้ว = สลับในเครื่อง ไม่ต้องให้ผู้ใช้กดในแอป
             * ยังไม่อนุมัติ (เช่น MetaMask ที่ยังไม่รู้จักเชน TPIX) → ไม่ขอตอนนี้
             * เดิมขอสลับ/เพิ่มเชน "ก่อน" ขอลายเซ็น — บนมือถือคำขอนั้นค้างรอในแอปกระเป๋าโดยไม่มีอะไรบอก
             * ขั้นเข้าสู่ระบบเลยไม่เคยเริ่ม · หน้าที่ต้องใช้เชน TPIX ขอสลับเองตอนผู้ใช้กด
             */
            if (isTpixChainLive() && chainId.value !== TPIX_CHAIN_CONFIG.chainIdNum
                && isChainApproved(wc, TPIX_CHAIN_CONFIG.chainIdNum)) {
                try {
                    await switchToChain(wc, TPIX_CHAIN_CONFIG.chainIdNum, TPIX_CHAIN_CONFIG);
                    await _refreshProviderState(wc);
                } catch (tpixErr) {
                    console.warn('[TPIX] WalletConnect: ไม่สามารถสลับไป TPIX Chain:', tpixErr?.message);
                }
            }

            _registerWalletToBackend(owner, chainId.value, 'walletconnect');

            // ตั้งค่า event listeners (WalletConnect provider ก็รองรับ events เหมือน MetaMask)
            _setupListeners();

            playConnectSound();
            isConnecting.value = false;

            // เชื่อมแล้ว — ต่อด้วยลายเซ็นเข้าสู่ระบบ (รอได้นาน ผู้ใช้ต้องไปกดในแอป)
            const verified = await _verifyThroughWallet(owner, { mobile });
            if (attempt === _wcAttempt && wcStatus.value === 'signing') {
                wcStatus.value = verified ? 'done' : 'verify_failed';
            }

            // เสนอเพิ่มเหรียญเฉพาะคอมพิวเตอร์ — บนมือถือคือการสลับแอปอีกรอบโดยที่ผู้ใช้ไม่ได้ขอ
            if (verified && !mobile) _autoSuggestTpixAssets(owner).catch(() => {});

            return owner;
        } catch (err) {
            if (err?.code === 'WC_CANCELLED' || attempt !== _wcAttempt) {
                // ผู้ใช้ปิดหน้าต่าง / เริ่มรอบใหม่เอง — ไม่ใช่ความผิดพลาด
                throw Object.assign(new Error('WalletConnect cancelled'), { code: 'WC_CANCELLED' });
            }
            playErrorSound();
            wcStatus.value = 'error';
            if (err.message?.includes('timed out')) {
                error.value = err.message;
            } else if (err.message?.includes('User rejected') || err.code === 4001) {
                error.value = 'Connection rejected by user.';
            } else {
                error.value = err.message || 'WalletConnect failed.';
            }
            throw err;
        } finally {
            wc?.removeListener?.('display_uri', onUri);
            if (attempt === _wcAttempt) isConnecting.value = false;
        }
    }

    /**
     * ผู้ใช้ปิดหน้าต่างเชื่อมกระเป๋า
     * กำลังรอจับคู่ = เลิกรอ · กำลังรอลายเซ็น = รอต่อเงียบ ๆ (ไปกดยืนยันในแอปทีหลังก็ยังเข้าสู่ระบบได้)
     */
    function cancelWalletConnect() {
        if (wcStatus.value === 'signing') return;
        _cancelPairing();
        isConnecting.value = false;
        wcStatus.value = 'idle';
        wcUri.value = null;
        verifyError.value = null;
        if (walletType.value !== 'walletconnect') error.value = null;
    }

    /**
     * จบรอบจับคู่ที่ค้างอยู่ — เลื่อนเลขรอบเสมอ (ไม่ใช่เฉพาะตอนกำลังรอสแกน)
     * ผู้ใช้ปิดหน้าต่างตอนไลบรารียังโหลดไม่เสร็จ: ยังไม่มีอะไรให้ reject แต่รอบนั้นต้องไม่เดินต่อไปสร้าง QR เบื้องหลัง
     */
    function _cancelPairing() {
        const cancel = _wcCancel;
        _wcCancel = null;
        _wcAttempt++;
        cancel?.();
    }

    function _assertCurrentAttempt(attempt) {
        if (attempt !== _wcAttempt) {
            throw Object.assign(new Error('WalletConnect cancelled'), { code: 'WC_CANCELLED' });
        }
    }

    /** รอการจับคู่ แต่ให้ผู้ใช้กดยกเลิกได้ (ไลบรารีไม่มีทางยกเลิกคำขอที่ส่งไปแล้ว — abortPairingAttempt เป็น no-op) */
    function _raceCancel(promise) {
        return new Promise((resolve, reject) => {
            _wcCancel = () => reject(Object.assign(new Error('WalletConnect cancelled'), { code: 'WC_CANCELLED' }));
            promise.then(resolve, reject);
        }).finally(() => {
            _wcCancel = null;
        });
    }

    /**
     * ผู้ใช้ยกเลิกไปแล้ว แต่ไปกดอนุมัติในแอปทีหลัง → session ที่ไม่มีใครใช้เกิดขึ้นเบื้องหลัง
     * ปิดทิ้ง ไม่งั้นแอปกระเป๋าค้างรายการเชื่อมต่อ และหน้าเว็บไม่รู้ตัวว่ามันมีอยู่
     */
    function _disposeIfAbandoned(pairing, attempt, wc) {
        pairing.then(() => {
            const abandoned = attempt !== _wcAttempt
                && walletType.value !== 'walletconnect'
                && wcStatus.value !== 'pairing';
            if (abandoned) wc.disconnect().catch(() => {});
        }).catch(() => {});
    }

    function _onWalletConnectEnded() {
        // แอปกระเป๋าตัดการเชื่อมต่อเอง / session หมดอายุ → ตัดฝั่งเว็บตาม (ผู้ใช้กระเป๋าล้วนออกจากระบบด้วย)
        if (walletType.value !== 'walletconnect' || hasActiveSession(_wc)) return;
        disconnect();
    }

    function _attachWalletConnect(wc) {
        if (_wc === wc) return;
        _detachWalletConnect();
        _wc = wc;
        wc.on('session_delete', _onWalletConnectEnded);
        wc.on('disconnect', _onWalletConnectEnded);
        _hookRelayRevive();
    }

    function _detachWalletConnect() {
        if (!_wc) return;
        _wc.removeListener?.('session_delete', _onWalletConnectEnded);
        _wc.removeListener?.('disconnect', _onWalletConnectEnded);
        _wc = null;
    }

    let _reviveHooked = false;
    /** กลับมาจากแอปกระเป๋า → ปลุก WebSocket ไป relay ทันที คำตอบจากแอปจะได้มาถึงไม่ต้องรอรอบ heartbeat */
    function _hookRelayRevive() {
        if (_reviveHooked || typeof document === 'undefined') return;
        _reviveHooked = true;
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && _wc) reviveRelay(_wc);
        });
    }

    /**
     * ขอลายเซ็นเข้าสู่ระบบผ่านแอปกระเป๋า (WalletConnect)
     *
     * มือถือ: รอให้ผู้ใช้กลับมาที่หน้านี้ก่อนค่อยส่งคำขอ — ส่งตอนหน้าอยู่เบื้องหลัง sign-client
     * ไม่เปิดแอปให้ ("Document does not have focus") คำขอไปค้างในแอปโดยที่ผู้ใช้ไม่รู้
     *
     * @param {{mobile?: boolean, force?: boolean}} opts force = ขอเซ็นจริงแม้เว็บดูเหมือนเข้าสู่ระบบอยู่แล้ว
     */
    async function _verifyThroughWallet(owner, { mobile = false, force = false } = {}) {
        if (!force && _sessionWalletAddress() === String(owner).toLowerCase()) {
            verifiedAt.value = Date.now();
            return true;
        }
        if (mobile) await waitForForeground({ timeoutMs: WC_VERIFY_TIMEOUT_MS });
        if (String(address.value).toLowerCase() !== String(owner).toLowerCase() || !signer.value) return false;

        _markPendingVerification(owner);
        try {
            return await _verifyWalletOwnership(signer.value, owner, { timeoutMs: WC_VERIFY_TIMEOUT_MS });
        } finally {
            _clearPendingVerification();
        }
    }

    /** หน้าเดิมถูกปิดไประหว่างรอลายเซ็น (Android ปิดแท็บเบื้องหลัง) — ขอใหม่ให้หนึ่งครั้ง */
    function _resumePendingVerification(owner) {
        const pending = _readPendingVerification();
        if (!pending || pending.address !== String(owner).toLowerCase()) return;

        // เว็บเข้าสู่ระบบด้วยกระเป๋านี้แล้ว = ลายเซ็นรอบก่อนผ่านไปแล้ว ไม่ต้องขอซ้ำ
        if (_sessionWalletAddress() === pending.address) {
            _clearPendingVerification();
            return;
        }

        wcStatus.value = 'signing';
        verifyError.value = null;
        showConnectModal.value = true; // หน้าต่างบอกว่ารอยืนยันในแอป + ปุ่มเปิดแอป
        _verifyThroughWallet(owner, { mobile: isMobile() })
            .then((ok) => {
                if (wcStatus.value === 'signing') wcStatus.value = ok ? 'done' : 'verify_failed';
            })
            .catch(() => {});
    }

    function _markPendingVerification(owner) {
        try {
            sessionStorage.setItem(PENDING_VERIFY_KEY, JSON.stringify({
                address: String(owner).toLowerCase(),
                at: Date.now(),
            }));
        } catch {
            // เขียนไม่ได้ (โหมดส่วนตัว) — แค่ไม่ได้ขอซ้ำให้อัตโนมัติถ้าหน้าถูกปิดกลางทาง
        }
    }

    function _readPendingVerification() {
        try {
            const raw = JSON.parse(sessionStorage.getItem(PENDING_VERIFY_KEY) || 'null');
            if (!raw?.address || !raw?.at || Date.now() - raw.at > PENDING_VERIFY_MAX_AGE_MS) return null;
            return raw;
        } catch {
            return null;
        }
    }

    function _clearPendingVerification() {
        try {
            sessionStorage.removeItem(PENDING_VERIFY_KEY);
        } catch {
            // ไม่มี sessionStorage = ไม่มีอะไรต้องล้าง
        }
    }

    /** กระเป๋าที่เว็บเข้าสู่ระบบอยู่ (จาก props ของ Inertia) — null = ยังไม่ได้เข้าสู่ระบบ */
    function _sessionWalletAddress() {
        try {
            const wallet = usePage()?.props?.auth?.user?.wallet_address;
            return typeof wallet === 'string' && wallet ? wallet.toLowerCase() : null;
        } catch {
            return null;
        }
    }

    /** Project ID จาก admin settings (Inertia shared props) — fallback ไป env variable */
    function _walletConnectProjectId() {
        let fromAdmin = '';
        try {
            fromAdmin = usePage()?.props?.app?.walletconnect_project_id || '';
        } catch {
            fromAdmin = '';
        }
        return fromAdmin || import.meta.env.VITE_WALLETCONNECT_PROJECT_ID || '';
    }

    /** จดการเชื่อมต่อไว้ให้ tryReconnect — WalletConnect จำแอปที่เลือกไว้ด้วย (ปุ่ม "เปิดแอป" ใช้) */
    function _saveConnection(addr, type) {
        const record = { address: addr, walletType: type };
        if (type === 'walletconnect' && wcWallet.value) record.wcWallet = wcWallet.value;
        localStorage.setItem(STORAGE_KEY, JSON.stringify(record));
    }

    /**
     * สลับไปยัง chain ที่ระบุ (รองรับทุก chain ในระบบ)
     * ถ้าไม่ระบุ targetChainId จะสลับไป chain หลัก (BSC)
     * @param {number} targetChainId - Chain ID เป้าหมาย
     */
    async function switchChain(targetChainId = getDefaultChainId()) {
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
    async function _verifyWalletOwnership(walletSigner, walletAddress, { timeoutMs = VERIFY_TIMEOUT_MS } = {}) {
        linkError.value = null;
        verifyError.value = null;

        try {
            // 1. ขอ nonce + message จาก backend (+ timeout)
            const signRes = await withTimeout(
                axios.post('/api/v1/wallet/sign', { wallet_address: walletAddress }),
                VERIFY_TIMEOUT_MS,
                'Signature request timed out'
            );

            if (!signRes.data?.success) {
                verifyError.value = 'failed';
                return false;
            }

            const { message, nonce } = signRes.data.data;

            // 2. ให้ผู้ใช้ sign message ด้วย wallet (+ timeout — WalletConnect รอนานกว่า ผู้ใช้ต้องไปกดในแอปมือถือ)
            const signature = await withTimeout(
                walletSigner.signMessage(message),
                timeoutMs,
                'Signature timed out'
            );

            /*
             * ลายเซ็นมาถึงหลังผู้ใช้ตัดการเชื่อมต่อ / สลับไปบัญชีอื่นแล้ว (รอในแอปมือถือได้หลายนาที)
             * → ห้ามส่งไปเข้าสู่ระบบ ไม่งั้นกด "ตัดการเชื่อมต่อ" ไปแล้วเว็บแอบเข้าสู่ระบบกลับเองทีหลัง
             */
            if (String(address.value || '').toLowerCase() !== String(walletAddress).toLowerCase()) {
                verifyError.value = 'failed';
                return false;
            }

            // 3. ส่ง signature กลับไปยืนยัน
            //    ส่ง chain_id + wallet_type ไปด้วย ไม่งั้นประวัติการเชื่อมต่อจะถูก
            //    บันทึกเป็น BSC/metamask ตามค่าปริยายของเซิร์ฟเวอร์เสมอ
            const verifyRes = await withTimeout(
                axios.post('/api/v1/wallet/verify-signature', {
                    wallet_address: walletAddress,
                    signature,
                    nonce,
                    chain_id: chainId.value || getDefaultChainId(),
                    wallet_type: walletType.value || 'metamask',
                }),
                VERIFY_TIMEOUT_MS,
                'Verification timed out'
            );

            if (!verifyRes.data?.success) {
                verifyError.value = 'failed';
                return false;
            }

            // เซ็นผ่านแล้ว — ปลุกหน้าจอที่เคยเจอ 403 ให้โหลดใหม่ (ดู verifiedAt ด้านบน)
            verifiedAt.value = Date.now();

            /*
             * เซ็นผ่านแล้วเซิร์ฟเวอร์เปิด session ให้ — ต้องดึง props ใหม่
             *
             * Inertia เก็บ props ที่ได้ตอนโหลดหน้าไว้ ถ้าไม่สั่งดึงใหม่ `auth.user`
             * จะยังเป็น null จนกว่าผู้ใช้จะกดเปลี่ยนหน้าเอง — เขาจะเห็นปุ่ม
             * "เข้าสู่ระบบ" ทั้งที่เข้าไปแล้ว
             */
            if (verifyRes.data?.data?.signed_in) {
                // kyc ด้วย — สถานะยืนยันตัวตนผูกกับบัญชีนี้ ไม่ดึงใหม่ปุ่ม "ยืนยันตัวตนก่อนเทรด"
                // ค้างอยู่ทั้งที่บัญชีของกระเป๋ายืนยันในแอปผ่านแล้ว
                router.reload({ only: ['auth', 'kyc'] });
            }

            return true;
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
                verifyError.value = 'link';
                return false;
            }

            // ถ้า user ปฏิเสธ sign (code 4001) หรือ timeout — ไม่ block connection
            verifyError.value = _verifyErrorCode(err);
            console.warn('[TPIX] Wallet verification skipped:', err.message || err);
            return false;
        }
    }

    /** แปลงความผิดพลาดตอนเซ็นเป็นรหัสที่หน้าจอใช้เลือกข้อความ */
    function _verifyErrorCode(err) {
        // ethers ห่อ error ของกระเป๋าไว้หลายชั้น (ACTION_REJECTED / info.error) — รวมข้อความทุกชั้นก่อนดู
        const text = [err?.message, err?.shortMessage, err?.error?.message, err?.info?.error?.message]
            .filter(Boolean).join(' ');
        if (err?.code === 4001 || err?.code === 'ACTION_REJECTED' || err?.info?.error?.code === 4001
            || /user rejected|user denied|rejected by user/i.test(text)) return 'rejected';
        // แอป TPIX Wallet ไม่ยอมเซ็นเมื่อบัญชีที่เปิดอยู่ไม่ใช่ใบที่เชื่อมกับเว็บไว้ (4100 wallet_changed)
        if (/wallet_changed/i.test(text) || err?.response?.data?.error?.code === 'INVALID_SIGNATURE') return 'mismatch';
        if (/timed out/i.test(text)) return 'timeout';
        return 'failed';
    }

    /**
     * เซ็นยืนยันตอนเชื่อมกระเป๋า — ข้ามได้ถ้าเว็บเข้าสู่ระบบด้วยกระเป๋าใบนี้อยู่แล้ว
     *
     * session ของเว็บที่ผูกกับกระเป๋านี้ (ผูกได้เฉพาะหลังพิสูจน์ลายเซ็น) ผ่านด่าน VerifyWalletOwnership
     * อยู่แล้ว ขอเซ็นซ้ำทุกครั้งที่กดเชื่อม = ป๊อปอัพเกินจำเป็น
     */
    function _ensureSignedIn(walletSigner, owner) {
        if (_sessionWalletAddress() === String(owner).toLowerCase()) {
            verifiedAt.value = Date.now();
            return Promise.resolve(true);
        }
        return _verifyWalletOwnership(walletSigner, owner);
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

        /*
         * WalletConnect: กระเป๋าอยู่ในแอปมือถือ — เปิดหน้าต่างบอกว่ารอยืนยันในแอป (มีปุ่มเปิดแอปบนมือถือ)
         * และรอได้นานพอให้ผู้ใช้ไปกดในแอป · ส่งคำขอทันที (ผู้ใช้เพิ่งแตะปุ่ม = เปิดแอปให้อัตโนมัติได้)
         */
        if (walletType.value === 'walletconnect') {
            wcStatus.value = 'signing';
            verifyError.value = null;
            showConnectModal.value = true;
            const ok = await _verifyThroughWallet(address.value, { force: true });
            if (wcStatus.value === 'signing') wcStatus.value = ok ? 'done' : 'verify_failed';
            return true;
        }

        await _verifyWalletOwnership(signer.value, address.value);

        return true;
    }

    /**
     * ปุ่ม "เพิ่ม TPIX ลงกระเป๋า" — เครือข่าย TPIX Chain (= เหรียญ TPIX) + USDT บนเชน TPIX
     *
     * TPIX เป็นเหรียญเนทีฟ เพิ่มเป็นโทเคนไม่ได้ (ดู utils/walletAssets.js) จึงเพิ่ม/สลับเครือข่ายให้
     * แล้วกระเป๋าจะโชว์ TPIX เอง · USDT ต้องเพิ่มตอนอยู่บนเชน TPIX เพราะกระเป๋าผูกโทเคน
     * เข้ากับเครือข่ายที่เลือกอยู่ขณะนั้น
     *
     * @returns {Promise<{ok: boolean, reason?: string, added: string[], skipped: string[]}>}
     *   reason: 'unsupported' กระเป๋าไม่รองรับ · 'chain' ผู้ใช้ไม่สลับเชน
     *           'no_tokens' ดึงรายการเหรียญไม่ได้ · 'account_changed' สลับบัญชีกลางทาง
     */
    async function addTpixAssetsToWallet() {
        const empty = { added: [], skipped: [] };
        if (!canAddTpixAssets.value) return { ok: false, reason: 'unsupported', ...empty };

        const injected = _rawProvider || _getProvider(walletType.value);
        if (!injected?.request) return { ok: false, reason: 'unsupported', ...empty };

        const owner = address.value;

        // 1) เครือข่าย TPIX Chain — ยังไม่มีในกระเป๋าก็เพิ่มให้ในขั้นนี้ (พร้อมโลโก้ใหม่)
        if (chainId.value === TPIX_CHAIN_CONFIG.chainIdNum) {
            // อยู่เชนแล้ว: ส่งค่าเชนล่าสุดซ้ำ ให้กระเป๋าที่ยังจำ rpc.tpix.online อัปเดตเป็น rpc1
            // (ปฏิเสธก็ไม่เป็นไร — ยังเพิ่มเหรียญต่อได้ เพราะอยู่เชนถูกอยู่แล้ว)
            await addTPIXChainToWallet(injected);
        } else {
            try {
                await switchChain(TPIX_CHAIN_CONFIG.chainIdNum);
            } catch {
                return { ok: false, reason: 'chain', ...empty };
            }
            if (chainId.value !== TPIX_CHAIN_CONFIG.chainIdNum) {
                return { ok: false, reason: 'chain', ...empty };
            }
        }

        // 2) โทเคนบนเชน TPIX
        const tokens = await fetchTpixChainTokens();
        if (tokens.length === 0) return { ok: false, reason: 'no_tokens', ...empty };

        const result = { ok: true, added: [], skipped: [] };
        for (const token of tokens) {
            // ผู้ใช้สลับบัญชีในกระเป๋าระหว่างรอ — หยุด ไม่เพิ่มเหรียญให้บัญชีที่ไม่ได้กด
            if (address.value !== owner) return { ...result, ok: false, reason: 'account_changed' };
            const ok = await addTokenToWallet(token, injected);
            (ok ? result.added : result.skipped).push(token.symbol);
        }

        markAssetSuggestionShown(owner);
        return result;
    }

    /**
     * เพิ่มโทเคน ERC-20 หนึ่งตัวลงกระเป๋า (EIP-747) บนเชนที่กระเป๋าอยู่ตอนนี้ — เช่น wTPIX บน BSC หลังสะพาน mint ให้
     * ผู้เรียกต้องสลับเชนให้ถูกก่อน (wallet_watchAsset ผูกกับเครือข่ายที่เลือกอยู่)
     * กระเป๋าที่ไม่รองรับ (กระเป๋าฝัง/WalletConnect) = false เงียบ ๆ ไม่โยน error
     *
     * @param {{address: string, symbol: string, decimals: number, image?: string}} token
     */
    async function watchAsset(token) {
        if (!canAddTpixAssets.value) return false;
        const injected = _rawProvider || _getProvider(walletType.value);
        if (!injected?.request) return false;
        return addTokenToWallet(token, injected);
    }

    /**
     * เสนอเพิ่มเหรียญอัตโนมัติหลังเชื่อมกระเป๋า — ครั้งเดียวต่อกระเป๋า
     *
     * ไม่สลับเชนเพิ่มอีกรอบ: connect() สลับให้แล้วถ้าเชน TPIX เปิดใช้จริง ถ้าผู้ใช้ไม่ยอมสลับ
     * ก็ไม่ตามรบเร้า (ปุ่มในเมนูกระเป๋าทำให้ได้ทุกเมื่อ)
     * จดว่า "ถามแล้ว" ก่อนเด้งป๊อปอัพ — กดปฏิเสธแล้วต้องไม่โดนถามซ้ำทุกครั้งที่รีเฟรช/เชื่อมใหม่
     */
    async function _autoSuggestTpixAssets(owner) {
        if (!canAddTpixAssets.value || address.value !== owner) return;
        if (chainId.value !== TPIX_CHAIN_CONFIG.chainIdNum) return;
        if (wasAssetSuggestionShown(owner)) return;

        const tokens = await fetchTpixChainTokens();
        if (tokens.length === 0 || address.value !== owner) return;

        const injected = _rawProvider || _getProvider(walletType.value);
        if (!injected?.request) return;

        markAssetSuggestionShown(owner);
        for (const token of tokens) {
            if (address.value !== owner) return;
            await addTokenToWallet(token, injected);
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
    /** เลขลำดับรอบสลับบัญชี — ผู้เซ็นของรอบเก่าที่ตอบช้าห้ามมาทับรอบใหม่ */
    let _accountSeq = 0;

    function _onAccountsChanged(accounts) {
        if (accounts.length === 0) {
            disconnect();
        } else {
            const next = accounts[0];
            const changed = String(next).toLowerCase() !== String(address.value || '').toLowerCase();
            const seq = ++_accountSeq;

            /*
             * ⚠️ บัญชีเปลี่ยน = ปลดผู้เซ็นเดิมทิ้งทันที จนกว่าผู้เซ็นของบัญชีใหม่จะพร้อม
             *    เดิม address เปลี่ยนทันทีแต่ signer เปลี่ยนตามทีหลัง (async) — ธุรกรรมที่ส่งในช่วงนั้น
             *    ถูกเซ็นด้วยบัญชีเก่าแต่ระบุผู้รับ/บันทึกเป็นบัญชีใหม่ · ไม่มีผู้เซ็น = ทุกด่านส่งธุรกรรมปฏิเสธเอง
             *    (บางกระเป๋ายิง event ซ้ำด้วยบัญชีเดิม — ไม่ต้องปลด)
             */
            if (changed) signer.value = null;

            address.value = next;
            _saveConnection(next, walletType.value);
            // Refresh provider/signer
            const injected = _rawProvider || _getProvider(walletType.value);
            if (injected) {
                const ethProvider = new BrowserProvider(injected);
                // ลองซ้ำหนึ่งรอบ — ผู้เซ็นถูกปลดไปแล้ว ถ้าพลาดเงียบ ๆ ผู้ใช้จะส่งธุรกรรมไม่ได้จนกว่าจะเชื่อมใหม่
                const attach = (retry) => ethProvider.getSigner().then(s => {
                    if (seq !== _accountSeq) return;
                    provider.value = ethProvider;
                    signer.value = s;
                }).catch(err => {
                    if (retry && seq === _accountSeq) {
                        setTimeout(() => attach(false), 500);
                        return;
                    }
                    console.warn('Failed to refresh signer after account change:', err.message);
                });
                attach(true);
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
        // ผูกซ้ำ = handler ทำงานสองรอบต่อ event (สลับบัญชีครั้งเดียว ผู้เซ็นถูกสร้างซ้อน) — ถอดของเดิมก่อนเสมอ
        injected.removeListener?.('accountsChanged', _onAccountsChanged);
        injected.removeListener?.('chainChanged', _onChainChanged);
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
        verifiedAt,
        verifyError,
        walletType,
        supportedChains,
        showConnectModal,
        isRestoring,
        // WalletConnect flow (หน้าต่างเชื่อมกระเป๋าอ่าน)
        wcStatus,
        wcUri,
        wcWallet,
        wcSessionTopic,
        // Computed
        isConnected,
        shortAddress,
        isBSC,
        isTpixChain,
        canAddTpixAssets,
        isOnSupportedChain,
        currentChain,
        explorerAddressUrl,
        // Actions
        connect,
        disconnect,
        tryReconnect,
        verifyOwnership,
        addTpixAssetsToWallet,
        watchAsset,
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
        isLocked,
        // WalletConnect v2
        connectWalletConnect,
        cancelWalletConnect,
        // Modal control
        openConnectModal,
        closeConnectModal,
    };
});
