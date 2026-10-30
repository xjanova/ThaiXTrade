/**
 * TPIX TRADE — WalletConnect v2 (เว็บ ↔ แอปกระเป๋าบนมือถือ)
 *
 * ใช้ได้สองทาง:
 *   • คอมพิวเตอร์: โชว์ QR ให้แอป TPIX Wallet (หรือกระเป๋า WalletConnect ตัวไหนก็ได้) สแกน
 *   • มือถือ: เปิดแอปกระเป๋าด้วย deep link `<scheme>wc?uri=…` แล้วกลับมาที่เบราว์เซอร์
 *
 * ⚠️ ทำไมไม่ใช้หน้าต่าง QR ของ Reown (showQrModal: true)
 *    หน้าต่างนั้นไม่รู้จักแอป TPIX Wallet (ไม่อยู่ในรายชื่อกระเป๋าของ WalletConnect) — บนมือถือ
 *    ผู้ใช้จึงไม่มีทางเลือกเปิดแอปของเราเลย และมันยังโหลดไลบรารีหลายร้อย KB ทุกครั้งที่กด
 *    เราสร้าง URI เอง (event display_uri) แล้วโชว์ QR/ปุ่มเปิดแอปในหน้าต่างของเราแทน
 *
 * ⚠️ provider ต้องมีตัวเดียวทั้งหน้า (singleton)
 *    WalletConnect เก็บ session ไว้ใน IndexedDB ของเบราว์เซอร์ — สร้าง provider ใหม่ตอนเปิดหน้า
 *    แล้ว session เดิมกลับมาเอง (ไม่ต้องสแกนซ้ำ) แต่ถ้าสร้างซ้อนหลายตัว แต่ละตัวต่อ relay แยกกัน
 *    แล้วแย่งข้อความกันเอง
 *
 * Developed by Xman Studio
 */

import { TPIX_RPC_URL } from '@/utils/web3';
import { TPIX_COIN_PNG } from '@/utils/brand';

/** เชนที่ขอจากกระเป๋า — ทั้งหมดเป็น "ไม่บังคับ" (เหตุผลดู buildWalletConnectOptions) */
export const WC_OPTIONAL_CHAINS = [4289, 56, 137, 1];

/** RPC ที่ provider ใช้อ่านเชนเอง — TPIX ต้องเป็น rpc1 (rpc.tpix.online โดน Cloudflare 403) */
export const WC_RPC_MAP = {
    4289: TPIX_RPC_URL,
    56: 'https://bsc-dataseed1.binance.org',
    137: 'https://polygon-rpc.com',
    1: 'https://eth.llamarpc.com',
};

/**
 * คีย์ที่ sign-client ของ WalletConnect อ่านทุกครั้งที่ส่งคำขอ (personal_sign / ส่งธุรกรรม)
 * แล้วเปิดแอปกระเป๋าให้เอง: `${href}wc?requestId=…&sessionTopic=…`
 * (ชื่อคีย์ตายตัวในไลบรารี — WALLETCONNECT_DEEPLINK_CHOICE ของ @walletconnect/sign-client)
 */
export const WC_DEEPLINK_CHOICE_KEY = 'WALLETCONNECT_DEEPLINK_CHOICE';

/** เบราว์เซอร์บอกไม่ได้ว่าเปิดแอปสำเร็จไหม — หน้านี้ยังอยู่ด้านหน้าหลังเวลานี้ = น่าจะยังไม่ได้ติดตั้งแอป */
export const APP_OPEN_GRACE_MS = 2500;

/**
 * กระเป๋าที่เปิดจากเบราว์เซอร์มือถือด้วยลิงก์ WalletConnect ได้
 * scheme = ลิงก์ต้นทางที่แอปรับ (`<scheme>wc?uri=…`) — รูปแบบเดียวกับที่หน้าต่างของ Reown ใช้
 */
export const WC_MOBILE_WALLETS = {
    tpix: {
        id: 'tpix',
        name: 'TPIX Wallet',
        scheme: 'tpixwallet://',
        androidPackage: 'com.xmanstudio.tpix_wallet',
        // ยังไม่ได้ติดตั้ง → หน้าดาวน์โหลดของเราเอง (มีลิงก์ APK ของ TPIX Wallet)
        downloadPath: '/download',
    },
    metamask: { id: 'metamask', name: 'MetaMask', scheme: 'metamask://' },
    trustwallet: { id: 'trustwallet', name: 'Trust Wallet', scheme: 'trust://' },
    okx: { id: 'okx', name: 'OKX Wallet', scheme: 'okex://main/' },
    tokenpocket: { id: 'tokenpocket', name: 'TokenPocket', scheme: 'tpoutside://' },
};

/** สร้างค่าตั้งของ EthereumProvider — แยกออกมาให้เทสต์ตรวจได้ */
export function buildWalletConnectOptions(projectId, origin) {
    return {
        projectId,
        /*
         * ★ ทุกเชนอยู่ใน optionalChains ไม่ใช่ chains (บังคับ)
         *   กระเป๋าที่ยังไม่รู้จักเชน TPIX (MetaMask/Trust มือถือครั้งแรก) ปฏิเสธทั้ง session
         *   ถ้าเชนบังคับมีตัวที่มันไม่รองรับ → สแกนแล้วเชื่อมไม่ติด และไม่มีโอกาสได้เพิ่มเชน TPIX เลย
         */
        optionalChains: WC_OPTIONAL_CHAINS,
        showQrModal: false,
        metadata: {
            name: 'TPIX TRADE',
            description: 'Decentralized Exchange on TPIX Chain',
            url: origin,
            icons: [`${origin}${TPIX_COIN_PNG}`],
        },
        rpcMap: WC_RPC_MAP,
    };
}

let _providerPromise = null;

/**
 * provider ตัวเดียวของทั้งหน้า (สร้างครั้งแรกแล้วใช้ซ้ำ) — สร้างแล้ว session เดิมใน IndexedDB กลับมาเอง
 * สร้างไม่สำเร็จ (เน็ตหลุด/relay ล่ม) → ล้างให้ลองใหม่ได้ ไม่จำความล้มเหลวไว้ทั้งหน้า
 *
 * @param {string} projectId
 * @param {() => Promise<{EthereumProvider: any}>} [loader] — สำหรับเทสต์
 */
export function getWalletConnectProvider(projectId, loader = () => import('@walletconnect/ethereum-provider')) {
    if (!projectId) {
        return Promise.reject(new Error('WalletConnect Project ID not configured. Please set it in Admin Settings → Trading.'));
    }
    if (!_providerPromise) {
        const origin = typeof window !== 'undefined' ? window.location.origin : 'https://tpix.online';
        _providerPromise = loader()
            .then(({ EthereumProvider }) => EthereumProvider.init(buildWalletConnectOptions(projectId, origin)))
            .catch((err) => {
                _providerPromise = null;
                throw err;
            });
    }
    return _providerPromise;
}

/** สำหรับเทสต์เท่านั้น — ลืม provider ตัวเดิม */
export function resetWalletConnectProvider() {
    _providerPromise = null;
}

/** มี session ที่ใช้ได้อยู่ (ผู้ใช้ยังไม่ได้ตัดการเชื่อมต่อจากฝั่งใดฝั่งหนึ่ง) */
export function hasActiveSession(wc) {
    return !!wc?.session && Array.isArray(wc.accounts) && wc.accounts.length > 0;
}

/**
 * กระเป๋าอนุมัติเชนนี้ไว้ใน session แล้วหรือยัง
 * อนุมัติแล้ว = สลับเชนได้ในเครื่องเลย ไม่ต้องส่งคำขอไปให้ผู้ใช้กดในแอป (บนมือถือคือไม่ต้องสลับแอป)
 */
export function isChainApproved(wc, chainId) {
    const ns = wc?.session?.namespaces?.eip155;
    if (!ns) return false;
    const caip = `eip155:${Number(chainId)}`;
    if (Array.isArray(ns.chains) && ns.chains.includes(caip)) return true;
    return Array.isArray(ns.accounts) && ns.accounts.some(a => String(a).startsWith(`${caip}:`));
}

/** ชื่อแอปกระเป๋าที่อยู่อีกฝั่งของ session (จาก metadata ของกระเป๋า) */
export function peerWalletName(wc) {
    return wc?.session?.peer?.metadata?.name || null;
}

/** ลิงก์ที่หน้าเว็บใช้เปิดแอป — ระบบปฏิบัติการคร่าว ๆ จาก userAgent */
function detectOs(userAgent) {
    const ua = userAgent ?? (typeof navigator !== 'undefined' ? navigator.userAgent : '');
    if (/iPad|iPhone|iPod/.test(ua)) return 'ios';
    if (/Android/.test(ua)) return 'android';
    return 'other';
}

/**
 * ลิงก์เปิดแอปกระเป๋าบนมือถือ
 *
 * @param {string} walletId — คีย์ใน WC_MOBILE_WALLETS
 * @param {{uri?: string, sessionTopic?: string, origin?: string, userAgent?: string}} opts
 *   uri = ลิงก์จับคู่ (wc:…) · ไม่ส่ง uri = แค่เปิดแอป (เช่นให้ไปกดยืนยันลายเซ็น)
 * @returns {string|null}
 */
export function buildWalletAppLink(walletId, { uri = null, sessionTopic = null, origin = null, userAgent } = {}) {
    const wallet = WC_MOBILE_WALLETS[walletId];
    if (!wallet) return null;

    let query = '';
    if (uri) query = `uri=${encodeURIComponent(uri)}`;
    else if (sessionTopic) query = `sessionTopic=${encodeURIComponent(sessionTopic)}`;

    /*
     * แอปของเราบน Android: ใช้ intent:// ระบุแพ็กเกจ + หน้าสำรอง
     * ยังไม่ได้ติดตั้งแอป ลิงก์ tpixwallet:// เฉย ๆ จะเงียบ (กดแล้วไม่มีอะไรเกิดขึ้น ผู้ใช้คิดว่าเว็บค้าง)
     * intent:// พาไปหน้าดาวน์โหลดแทน
     */
    if (wallet.androidPackage && detectOs(userAgent) === 'android') {
        const base = (origin ?? (typeof window !== 'undefined' ? window.location.origin : 'https://tpix.online'));
        const fallback = encodeURIComponent(`${base}${wallet.downloadPath}`);
        const scheme = wallet.scheme.replace('://', '');
        return `intent://wc${query ? `?${query}` : ''}#Intent;scheme=${scheme};package=${wallet.androidPackage};S.browser_fallback_url=${fallback};end`;
    }

    // แอปอื่นรับรูปแบบมาตรฐาน `<scheme>wc?uri=…` · เปิดเฉย ๆ = ราก scheme (MetaMask/Trust ไม่รู้จัก wc?sessionTopic)
    if (!uri && walletId !== 'tpix') return wallet.scheme;

    return `${wallet.scheme}wc${query ? `?${query}` : ''}`;
}

/**
 * จำว่าผู้ใช้เลือกแอปไหน — sign-client จะเปิดแอปนั้นให้เองทุกครั้งที่ส่งคำขอเซ็น/ส่งธุรกรรม
 * ใช้เฉพาะบนมือถือ (บนคอมพิวเตอร์กระเป๋าอยู่อีกเครื่อง เปิดลิงก์ไปก็ไม่มีแอปรับ)
 */
export function rememberDeepLinkChoice(walletId) {
    const wallet = WC_MOBILE_WALLETS[walletId];
    try {
        if (!wallet) {
            localStorage.removeItem(WC_DEEPLINK_CHOICE_KEY);
            return;
        }
        localStorage.setItem(WC_DEEPLINK_CHOICE_KEY, JSON.stringify({ href: wallet.scheme, name: wallet.name }));
    } catch {
        // โหมดส่วนตัวบางเบราว์เซอร์เขียน localStorage ไม่ได้ — แค่ไม่ได้เปิดแอปให้อัตโนมัติ
    }
}

export function clearDeepLinkChoice() {
    try {
        localStorage.removeItem(WC_DEEPLINK_CHOICE_KEY);
    } catch {
        // ไม่มี localStorage = ไม่มีอะไรต้องล้าง
    }
}

/** หน้านี้อยู่ด้านหน้าและรับการแตะได้แล้วหรือยัง */
export function isForeground() {
    if (typeof document === 'undefined') return true;
    if (document.visibilityState !== 'visible') return false;
    return typeof document.hasFocus !== 'function' || document.hasFocus();
}

/**
 * รอจนผู้ใช้กลับมาที่หน้านี้ (หลังสลับไปแอปกระเป๋า)
 *
 * ทำไมต้องรอ: ถ้าส่งคำขอเซ็นตอนหน้านี้อยู่เบื้องหลัง sign-client จะไม่เปิดแอปกระเป๋าให้
 * ("Document does not have focus, skipping deeplink") และ Android อาจหยุดหน้าเว็บกลางทาง
 *
 * มองเห็นแล้วแต่ยังไม่ได้ focus (แถบที่อยู่ถูกแตะค้าง / หน้าที่เพิ่งโหลดใหม่บางเบราว์เซอร์)
 * → รอ focus แค่ focusGraceMs แล้วไปต่อ: คำขอยังถึงแอปทาง relay และหน้าต่างมีปุ่ม "เปิดแอป" ให้แตะเอง
 * ดีกว่ารอ focus ที่อาจไม่มาเลยจนคำขอเซ็นไม่ถูกส่ง
 *
 * @returns {Promise<boolean>} true = กลับมาแล้ว · false = หมดเวลา
 */
export function waitForForeground({ timeoutMs = 120000, pollMs = 500, focusGraceMs = 1500 } = {}) {
    if (isForeground()) return Promise.resolve(true);

    return new Promise((resolve) => {
        let poll = null;
        let timer = null;
        let visibleSince = null;
        const finish = (ok) => {
            clearInterval(poll);
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', check);
            window.removeEventListener('focus', check);
            resolve(ok);
        };
        const check = () => {
            if (isForeground()) {
                finish(true);
                return;
            }
            if (document.visibilityState !== 'visible') {
                visibleSince = null;
                return;
            }
            visibleSince ??= Date.now();
            if (Date.now() - visibleSince >= focusGraceMs) finish(true);
        };
        document.addEventListener('visibilitychange', check);
        window.addEventListener('focus', check);
        // hasFocus() ไม่มี event ของตัวเองครบทุกเบราว์เซอร์ — ถามซ้ำเป็นระยะด้วย
        poll = setInterval(check, pollMs);
        timer = setTimeout(() => finish(false), timeoutMs);
        check();
    });
}

/**
 * ปลุกการเชื่อมต่อกับ relay หลังกลับมาจากแอปอื่น
 *
 * มือถือตัดเน็ตของแท็บที่อยู่เบื้องหลัง — WebSocket ไป relay ตายเงียบ ๆ คำตอบจากกระเป๋า
 * (อนุมัติการเชื่อม/ลายเซ็น) ค้างอยู่ที่ relay จนกว่าจะต่อใหม่ ตัวไลบรารีต่อใหม่เองก็จริง
 * แต่รอรอบ heartbeat — สั่งเปิดทันทีที่หน้ากลับมาให้คำตอบมาถึงไวขึ้น
 */
export function reviveRelay(wc) {
    try {
        const relayer = wc?.signer?.client?.core?.relayer;
        if (!relayer || relayer.connected || relayer.connecting) return;
        relayer.transportOpen?.().catch?.(() => {});
    } catch {
        // ไลบรารีเปลี่ยนโครงภายใน — ปล่อยให้มันต่อใหม่เองตามรอบ
    }
}
