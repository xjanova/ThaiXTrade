/**
 * TPIX TRADE - Mobile Wallet Detection for Web
 * ตรวจจับ wallet app บนมือถือจากเว็บเบราว์เซอร์ทุกชนิด
 * รองรับ in-app browsers (LINE, Facebook, etc.)
 * Developed by Xman Studio
 */

// =============================================================================
// Platform Detection / ตรวจจับแพลตฟอร์ม
// =============================================================================

/**
 * ตรวจจับว่าเปิดจากมือถือหรือไม่
 */
export function isMobile() {
    if (typeof navigator === 'undefined') return false;
    return /Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
}

/**
 * ตรวจจับ OS ของมือถือ
 */
export function getMobileOS() {
    if (typeof navigator === 'undefined') return null;
    const ua = navigator.userAgent;
    if (/iPad|iPhone|iPod/.test(ua)) return 'ios';
    if (/Android/.test(ua)) return 'android';
    return null;
}

/**
 * ตรวจจับ in-app browser (LINE, Facebook, Instagram, etc.)
 */
export function detectInAppBrowser() {
    if (typeof navigator === 'undefined') return null;
    const ua = navigator.userAgent.toLowerCase();

    if (ua.includes('line/') || ua.includes('line ')) return 'line';
    if (ua.includes('fban') || ua.includes('fbav') || ua.includes('fb_iab')) return 'facebook';
    if (ua.includes('instagram')) return 'instagram';
    if (ua.includes('twitter') || ua.includes('x.com')) return 'twitter';
    if (ua.includes('telegram')) return 'telegram';
    if (ua.includes('wechat') || ua.includes('micromessenger')) return 'wechat';
    if (ua.includes('kakaotalk')) return 'kakaotalk';
    if (ua.includes('snapchat')) return 'snapchat';
    if (ua.includes('tiktok')) return 'tiktok';

    return null;
}

/**
 * ตรวจจับว่าอยู่ใน wallet in-app browser หรือไม่
 */
export function detectWalletBrowser() {
    if (typeof navigator === 'undefined') return null;
    const ua = navigator.userAgent.toLowerCase();

    // ตรวจจาก user agent
    if (ua.includes('metamask') || window.ethereum?.isMetaMask) return 'metamask';
    if (ua.includes('trust') || window.ethereum?.isTrust || window.trustwallet) return 'trustwallet';
    if (ua.includes('coinbasebrowser') || window.ethereum?.isCoinbaseWallet) return 'coinbase';
    if (ua.includes('tokenpocket') || window.ethereum?.isTokenPocket) return 'tokenpocket';
    if (ua.includes('okex') || ua.includes('okapp') || window.okxwallet) return 'okx';
    if (ua.includes('phantom')) return 'phantom';
    if (ua.includes('safepal')) return 'safepal';
    if (ua.includes('bitkeep') || ua.includes('bitget')) return 'bitget';

    return null;
}

// =============================================================================
// Wallet Provider Registry / รายชื่อ wallet ที่รองรับ
// =============================================================================

export const WALLET_PROVIDERS = [
    {
        id: 'metamask',
        name: 'MetaMask',
        color: '#E2761B',
        deepLink: {
            // เปิดเว็บไซต์ภายใน MetaMask browser
            dapp: (url) => `https://metamask.app.link/dapp/${url.replace(/^https?:\/\//, '')}`,
            scheme: 'metamask://',
        },
        android: {
            package: 'io.metamask',
            // intent:// URL ที่จะเปิดแอป หรือไป Play Store อัตโนมัติ
            intent: (url) =>
                `intent://dapp/${url.replace(/^https?:\/\//, '')}#Intent;scheme=https;package=io.metamask;end`,
        },
        ios: {
            appStoreId: '1438144202',
            universalLink: (url) => `https://metamask.app.link/dapp/${url.replace(/^https?:\/\//, '')}`,
        },
        download: {
            android: 'https://play.google.com/store/apps/details?id=io.metamask',
            ios: 'https://apps.apple.com/app/metamask/id1438144202',
        },
        detect: () => !!window.ethereum?.isMetaMask,
    },
    {
        id: 'trustwallet',
        name: 'Trust Wallet',
        color: '#3375BB',
        deepLink: {
            dapp: (url) => `https://link.trustwallet.com/open_url?coin_id=60&url=${encodeURIComponent(url)}`,
            scheme: 'trust://',
        },
        android: {
            package: 'com.wallet.crypto.trustapp',
            intent: (url) =>
                `intent://open_url?coin_id=60&url=${encodeURIComponent(url)}#Intent;scheme=trust;package=com.wallet.crypto.trustapp;end`,
        },
        ios: {
            appStoreId: '1288339409',
            universalLink: (url) =>
                `https://link.trustwallet.com/open_url?coin_id=60&url=${encodeURIComponent(url)}`,
        },
        download: {
            android: 'https://play.google.com/store/apps/details?id=com.wallet.crypto.trustapp',
            ios: 'https://apps.apple.com/app/trust-wallet/id1288339409',
        },
        detect: () => !!window.trustwallet || !!window.ethereum?.isTrust,
    },
    {
        id: 'coinbase',
        name: 'Coinbase Wallet',
        color: '#0052FF',
        deepLink: {
            dapp: (url) => `https://go.cb-w.com/dapp?cb_url=${encodeURIComponent(url)}`,
            scheme: 'cbwallet://',
        },
        android: {
            package: 'org.toshi',
            intent: (url) =>
                `intent://dapp?url=${encodeURIComponent(url)}#Intent;scheme=cbwallet;package=org.toshi;end`,
        },
        ios: {
            appStoreId: '1278383455',
            universalLink: (url) => `https://go.cb-w.com/dapp?cb_url=${encodeURIComponent(url)}`,
        },
        download: {
            android: 'https://play.google.com/store/apps/details?id=org.toshi',
            ios: 'https://apps.apple.com/app/coinbase-wallet/id1278383455',
        },
        detect: () => !!window.coinbaseWalletExtension || !!window.ethereum?.isCoinbaseWallet,
    },
    {
        id: 'okx',
        name: 'OKX Wallet',
        color: '#000000',
        deepLink: {
            dapp: (url) => `okx://wallet/dapp/url?dappUrl=${encodeURIComponent(url)}`,
            scheme: 'okx://',
        },
        android: {
            package: 'com.okinc.okex.gp',
            intent: (url) =>
                `intent://wallet/dapp/url?dappUrl=${encodeURIComponent(url)}#Intent;scheme=okx;package=com.okinc.okex.gp;end`,
        },
        ios: {
            appStoreId: '1327268470',
            universalLink: (url) => `okx://wallet/dapp/url?dappUrl=${encodeURIComponent(url)}`,
        },
        download: {
            android: 'https://play.google.com/store/apps/details?id=com.okinc.okex.gp',
            ios: 'https://apps.apple.com/app/okx/id1327268470',
        },
        detect: () => !!window.okxwallet,
    },
    {
        id: 'tokenpocket',
        name: 'TokenPocket',
        color: '#2980FE',
        deepLink: {
            dapp: (url) => `tpoutside://open?params=${encodeURIComponent(JSON.stringify({ url, chain: 'BSC' }))}`,
            scheme: 'tpoutside://',
        },
        android: {
            package: 'vip.mytokenpocket',
            intent: (url) =>
                `intent://open?params=${encodeURIComponent(JSON.stringify({ url, chain: 'BSC' }))}#Intent;scheme=tpoutside;package=vip.mytokenpocket;end`,
        },
        ios: {
            appStoreId: '1436028753',
            universalLink: (url) =>
                `tpoutside://open?params=${encodeURIComponent(JSON.stringify({ url, chain: 'BSC' }))}`,
        },
        download: {
            android: 'https://play.google.com/store/apps/details?id=vip.mytokenpocket',
            ios: 'https://apps.apple.com/app/tokenpocket/id1436028753',
        },
        detect: () => !!window.ethereum?.isTokenPocket,
    },
];

// =============================================================================
// TPIX TRADE App Config / ตั้งค่าแอป TPIX TRADE
// =============================================================================

export const TPIX_APP = {
    name: 'TPIX TRADE',
    scheme: 'tpixtrade://',
    android: {
        package: 'com.xmanstudio.tpixtrade',
        intent: `intent://#Intent;scheme=tpixtrade;package=com.xmanstudio.tpixtrade;end`,
    },
    download: {
        // TODO: อัปเดต URL เมื่อ publish แอปแล้ว
        android: 'https://play.google.com/store/apps/details?id=com.xmanstudio.tpixtrade',
        ios: 'https://apps.apple.com/app/tpix-trade/id0000000000',
    },
};

// =============================================================================
// Deep Link Execution / เปิด deep link
// =============================================================================

/**
 * ลองเปิด deep link พร้อม timeout fallback
 * ถ้าแอปไม่ได้ติดตั้ง จะ redirect ไป fallback URL (เช่น Play Store)
 *
 * @param {string} deepLink - URL ที่จะเปิด
 * @param {string} fallbackUrl - URL สำรองถ้าเปิดไม่ได้
 * @param {number} timeout - เวลารอ (ms) ก่อน fallback
 */
export function tryDeepLink(deepLink, fallbackUrl, timeout = 1500) {
    return new Promise((resolve) => {
        let didOpen = false;

        // ฟัง visibility change - ถ้าหน้าถูกซ่อน แปลว่าแอปเปิดได้
        const onVisibilityChange = () => {
            if (document.hidden) {
                didOpen = true;
                cleanup();
                resolve(true);
            }
        };

        const onBlur = () => {
            didOpen = true;
            cleanup();
            resolve(true);
        };

        const cleanup = () => {
            document.removeEventListener('visibilitychange', onVisibilityChange);
            window.removeEventListener('blur', onBlur);
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('blur', onBlur);

        // ลองเปิด deep link
        window.location.href = deepLink;

        // Timeout: ถ้าแอปไม่เปิด → ไป fallback
        setTimeout(() => {
            cleanup();
            if (!didOpen) {
                if (fallbackUrl) {
                    window.location.href = fallbackUrl;
                }
                resolve(false);
            }
        }, timeout);
    });
}

/**
 * เปิด wallet app ผ่าน deep link ที่เหมาะกับ OS
 * Android: ใช้ intent:// URL (เปิดแอป หรือไป Play Store อัตโนมัติ)
 * iOS: ใช้ universal link (fallback ไป App Store)
 *
 * @param {object} provider - wallet provider config
 * @param {string} dappUrl - URL ของ DApp ที่จะเปิดใน wallet browser
 */
export function openWalletApp(provider, dappUrl = window.location.href) {
    const os = getMobileOS();

    if (os === 'android') {
        // Android: intent:// URL จะ fallback ไป Play Store อัตโนมัติ
        window.location.href = provider.android.intent(dappUrl);
        return;
    }

    if (os === 'ios') {
        // iOS: ลอง universal link ก่อน, fallback ไป App Store
        const universalLink = provider.ios.universalLink(dappUrl);
        tryDeepLink(universalLink, provider.download.ios);
        return;
    }

    // Desktop fallback: ใช้ deep link ปกติ
    window.location.href = provider.deepLink.dapp(dappUrl);
}

/**
 * เปิดแอป TPIX TRADE หรือไป store ดาวน์โหลด
 */
export function openTpixApp() {
    const os = getMobileOS();

    if (os === 'android') {
        window.location.href = TPIX_APP.android.intent;
        return;
    }

    if (os === 'ios') {
        tryDeepLink(TPIX_APP.scheme, TPIX_APP.download.ios);
        return;
    }
}

/**
 * ดาวน์โหลดแอป TPIX TRADE
 */
export function downloadTpixApp() {
    const os = getMobileOS();
    const url = os === 'ios' ? TPIX_APP.download.ios : TPIX_APP.download.android;
    window.open(url, '_blank');
}

// =============================================================================
// Wallet Detection for Web / ตรวจจับ wallet บนเว็บ
// =============================================================================

/**
 * ตรวจจับ wallet ที่ใช้ได้บนเว็บ
 * - ถ้าอยู่ใน wallet browser → return wallet นั้นเป็นตัวหลัก (detected)
 * - ถ้าอยู่ใน desktop → ตรวจ window.ethereum extensions
 * - ถ้าอยู่ใน mobile browser → return ทุก wallet พร้อม deep link
 */
export function detectWallets() {
    const results = [];
    const walletBrowser = detectWalletBrowser();
    const inAppBrowser = detectInAppBrowser();
    const mobile = isMobile();

    for (const provider of WALLET_PROVIDERS) {
        const result = {
            provider,
            detected: false,
            method: null, // 'injected' | 'user_agent' | 'deep_link'
        };

        // 1. อยู่ใน wallet browser ของ wallet นี้
        if (walletBrowser === provider.id) {
            result.detected = true;
            result.method = 'user_agent';
        }
        // 2. Desktop: ตรวจ injected provider
        else if (!mobile && provider.detect()) {
            result.detected = true;
            result.method = 'injected';
        }
        // 3. Mobile: ทุก wallet สามารถเปิดผ่าน deep link ได้
        else if (mobile) {
            result.detected = false;
            result.method = 'deep_link';
        }

        results.push(result);
    }

    // เรียง: detected ก่อน
    results.sort((a, b) => {
        if (a.detected !== b.detected) return a.detected ? -1 : 1;
        return 0;
    });

    return {
        wallets: results,
        isInWalletBrowser: !!walletBrowser,
        walletBrowserId: walletBrowser,
        isInAppBrowser: !!inAppBrowser,
        inAppBrowserName: inAppBrowser,
        isMobile: mobile,
        os: getMobileOS(),
    };
}

/**
 * สร้าง URL สำหรับเปิดใน external browser (หนีออกจาก in-app browser)
 * สำหรับ LINE, Facebook, etc. ที่ deep link อาจไม่ทำงาน
 */
export function getExternalBrowserUrl(url = window.location.href) {
    const inApp = detectInAppBrowser();
    const os = getMobileOS();

    if (!inApp) return url;

    // LINE: ใช้ openExternal flag
    if (inApp === 'line') {
        return url + (url.includes('?') ? '&' : '?') + 'openExternalBrowser=1';
    }

    // Android: ลอง intent เปิด Chrome
    if (os === 'android') {
        return `intent://${url.replace(/^https?:\/\//, '')}#Intent;scheme=https;action=android.intent.action.VIEW;end`;
    }

    // iOS: ไม่มีทาง force เปิด Safari จาก in-app browser โดยตรง
    // แต่ user สามารถกดเมนู "Open in Safari" ได้
    return url;
}
