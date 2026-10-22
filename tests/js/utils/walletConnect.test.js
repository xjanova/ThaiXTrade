/**
 * TPIX TRADE — ตัวช่วย WalletConnect (QR บนคอม / เปิดแอปกระเป๋าบนมือถือ)
 *
 * จุดที่ชุดนี้คุม:
 *  - ทุกเชนเป็น "ไม่บังคับ" (กระเป๋าที่ไม่รู้จักเชน TPIX ต้องยังเชื่อมได้) + ไม่โหลดหน้าต่าง QR ของ Reown
 *  - provider มีตัวเดียวทั้งหน้า — สร้างแล้ว session เดิมกลับมาเอง · สร้างพลาดแล้วต้องลองใหม่ได้
 *  - ลิงก์เปิดแอป TPIX Wallet: Android ใช้ intent:// (ไม่ได้ติดตั้ง = ไปหน้าดาวน์โหลด แทนการเงียบ)
 *  - ค่าที่จำไว้ให้ sign-client ต้องเป็นรูปแบบที่ไลบรารีอ่านได้จริง → tpixwallet://wc?requestId=…
 *  - รอให้หน้ากลับมาอยู่ด้านหน้าก่อนขอลายเซ็น
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import {
    WC_OPTIONAL_CHAINS,
    WC_DEEPLINK_CHOICE_KEY,
    buildWalletConnectOptions,
    getWalletConnectProvider,
    resetWalletConnectProvider,
    hasActiveSession,
    isChainApproved,
    buildWalletAppLink,
    rememberDeepLinkChoice,
    clearDeepLinkChoice,
    waitForForeground,
    reviveRelay,
} from '@/utils/walletConnect';
import { TPIX_RPC_URL } from '@/utils/web3';
// ตัวจริงที่ sign-client ใช้ประกอบลิงก์เปิดแอปตอนส่งคำขอเซ็น (ไม่ได้ export ผ่านชื่อแพ็กเกจระดับบน)
import { formatDeeplinkUrl } from '../../../node_modules/@walletconnect/sign-client/node_modules/@walletconnect/utils/dist/index.js';

const ANDROID_UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36';
const IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';
const WC_URI = 'wc:7f6e504bfad60b485450578e05678ed3e8e8c4751d3c6160be17160d63ec90f9@2?relay-protocol=irn&symKey=587d5484ce2a2a6ee3ba1962fdd7e8588e06200c46823bd18fbd67def96ad303&expiryTimestamp=1730000000';

function session(chains, address = '0xd2eab07809921fcb36c7ab72d7b5d8d2c12a67d7') {
    return {
        topic: 'abc123',
        namespaces: {
            eip155: {
                chains: chains.map(c => `eip155:${c}`),
                accounts: chains.map(c => `eip155:${c}:${address}`),
                methods: ['personal_sign', 'eth_sendTransaction'],
                events: ['chainChanged', 'accountsChanged'],
            },
        },
    };
}

afterEach(() => {
    resetWalletConnectProvider();
    localStorage.removeItem(WC_DEEPLINK_CHOICE_KEY);
    vi.useRealTimers();
});

describe('ค่าตั้งของ provider', () => {
    it('ทุกเชนเป็น optional (TPIX อยู่ด้วย) และไม่ใช้หน้าต่าง QR ของ Reown', () => {
        const opts = buildWalletConnectOptions('pid', 'https://tpix.online');

        expect(opts.chains).toBeUndefined();
        expect(opts.optionalChains).toEqual(WC_OPTIONAL_CHAINS);
        expect(opts.optionalChains).toContain(4289);
        expect(opts.showQrModal).toBe(false);
        expect(opts.rpcMap[4289]).toBe(TPIX_RPC_URL);
        expect(opts.metadata.url).toBe('https://tpix.online');
        expect(opts.metadata.icons[0]).toMatch(/^https:\/\/tpix\.online\//);
    });
});

describe('provider ตัวเดียวทั้งหน้า', () => {
    it('เรียกหลายครั้ง (ทุกหน้าที่เปลี่ยน) ได้ตัวเดิม — init ครั้งเดียว', async () => {
        const fake = { session: null };
        const init = vi.fn(async () => fake);
        const loader = vi.fn(async () => ({ EthereumProvider: { init } }));

        const [a, b] = await Promise.all([
            getWalletConnectProvider('pid', loader),
            getWalletConnectProvider('pid', loader),
        ]);
        const c = await getWalletConnectProvider('pid', loader);

        expect(a).toBe(fake);
        expect(b).toBe(fake);
        expect(c).toBe(fake);
        expect(init).toHaveBeenCalledTimes(1);
        expect(init.mock.calls[0][0].projectId).toBe('pid');
    });

    it('สร้างไม่สำเร็จ (เน็ตสะดุด) → รอบถัดไปลองใหม่ ไม่จำความล้มเหลวไว้ทั้งหน้า', async () => {
        const init = vi.fn()
            .mockRejectedValueOnce(new Error('relay down'))
            .mockResolvedValueOnce({ session: null });
        const loader = async () => ({ EthereumProvider: { init } });

        await expect(getWalletConnectProvider('pid', loader)).rejects.toThrow('relay down');
        await expect(getWalletConnectProvider('pid', loader)).resolves.toEqual({ session: null });
        expect(init).toHaveBeenCalledTimes(2);
    });

    it('ไม่มี Project ID = บอกตรง ๆ ว่าต้องตั้งค่าที่ไหน', async () => {
        await expect(getWalletConnectProvider('')).rejects.toThrow(/Project ID/);
    });
});

describe('สถานะ session', () => {
    it('มี session + มีบัญชี = ใช้ได้ · session หายหรือบัญชีว่าง = ไม่ได้', () => {
        expect(hasActiveSession({ session: session([4289]), accounts: ['0x1'] })).toBe(true);
        expect(hasActiveSession({ session: null, accounts: ['0x1'] })).toBe(false);
        expect(hasActiveSession({ session: session([4289]), accounts: [] })).toBe(false);
        expect(hasActiveSession(null)).toBe(false);
    });

    it('รู้ว่ากระเป๋าอนุมัติเชน TPIX แล้วหรือยัง (สลับในเครื่องได้ไม่ต้องสลับแอป)', () => {
        expect(isChainApproved({ session: session([4289, 56]) }, 4289)).toBe(true);
        expect(isChainApproved({ session: session([56, 1]) }, 4289)).toBe(false);
        // บางกระเป๋าส่งแค่ accounts ไม่ส่ง chains
        const accountsOnly = { session: { namespaces: { eip155: { accounts: ['eip155:4289:0xabc'] } } } };
        expect(isChainApproved(accountsOnly, 4289)).toBe(true);
        expect(isChainApproved({ session: null }, 4289)).toBe(false);
    });
});

describe('ลิงก์เปิดแอปกระเป๋า', () => {
    it('TPIX Wallet บน Android = intent:// ระบุแพ็กเกจ + หน้าดาวน์โหลดสำรอง (ลิงก์จับคู่ไม่เพี้ยน)', () => {
        const link = buildWalletAppLink('tpix', { uri: WC_URI, origin: 'https://tpix.online', userAgent: ANDROID_UA });

        expect(link.startsWith('intent://wc?uri=')).toBe(true);
        expect(link).toContain(';scheme=tpixwallet;');
        expect(link).toContain(';package=com.xmanstudio.tpix_wallet;');
        expect(link).toContain(`S.browser_fallback_url=${encodeURIComponent('https://tpix.online/download')}`);
        expect(link.endsWith(';end')).toBe(true);

        // แอปได้ tpixwallet://wc?uri=… → ถอดแล้วต้องได้ลิงก์ wc: เดิมทุกตัวอักษร (มี symKey)
        const data = new URL(link.split('#Intent')[0].replace('intent://', 'tpixwallet://'));
        expect(data.searchParams.get('uri')).toBe(WC_URI);
    });

    it('TPIX Wallet บน iPhone = tpixwallet://wc?uri=…', () => {
        const link = buildWalletAppLink('tpix', { uri: WC_URI, userAgent: IOS_UA });
        expect(link).toBe(`tpixwallet://wc?uri=${encodeURIComponent(WC_URI)}`);
    });

    it('ปุ่ม "เปิดแอปเพื่อยืนยัน" ส่ง sessionTopic ไม่ใช่ลิงก์จับคู่ (แอปต้องไม่จับคู่ใหม่)', () => {
        expect(buildWalletAppLink('tpix', { sessionTopic: 'abc123', userAgent: IOS_UA }))
            .toBe('tpixwallet://wc?sessionTopic=abc123');
        expect(buildWalletAppLink('tpix', { sessionTopic: 'abc123', userAgent: ANDROID_UA, origin: 'https://tpix.online' }))
            .toMatch(/^intent:\/\/wc\?sessionTopic=abc123#Intent;scheme=tpixwallet;package=com\.xmanstudio\.tpix_wallet;/);
    });

    it('MetaMask/Trust ใช้รูปแบบมาตรฐาน <scheme>wc?uri= · เปิดเฉย ๆ = ราก scheme', () => {
        expect(buildWalletAppLink('metamask', { uri: WC_URI, userAgent: ANDROID_UA }))
            .toBe(`metamask://wc?uri=${encodeURIComponent(WC_URI)}`);
        expect(buildWalletAppLink('trustwallet', { uri: WC_URI, userAgent: IOS_UA }))
            .toBe(`trust://wc?uri=${encodeURIComponent(WC_URI)}`);
        expect(buildWalletAppLink('okx', { uri: WC_URI })).toBe(`okex://main/wc?uri=${encodeURIComponent(WC_URI)}`);
        expect(buildWalletAppLink('metamask', { sessionTopic: 'abc123' })).toBe('metamask://');
    });

    it('แอปที่ไม่รู้จัก = ไม่มีลิงก์', () => {
        expect(buildWalletAppLink('generic', { uri: WC_URI })).toBeNull();
        expect(buildWalletAppLink('phantom', { uri: WC_URI })).toBeNull();
    });
});

describe('จำแอปที่เลือกไว้ให้ sign-client เปิดเองตอนส่งคำขอเซ็น', () => {
    it('ค่าที่เก็บอ่านได้ด้วยตัวประกอบลิงก์ของไลบรารีจริง → tpixwallet://wc?requestId=…&sessionTopic=…', () => {
        rememberDeepLinkChoice('tpix');
        const choice = JSON.parse(localStorage.getItem(WC_DEEPLINK_CHOICE_KEY));

        expect(choice).toEqual({ href: 'tpixwallet://', name: 'TPIX Wallet' });
        expect(formatDeeplinkUrl(choice.href, 42, 'abc123')).toBe('tpixwallet://wc?requestId=42&sessionTopic=abc123');
    });

    it('MetaMask / OKX ได้ลิงก์ที่แอปนั้นรับ', () => {
        rememberDeepLinkChoice('metamask');
        expect(formatDeeplinkUrl(JSON.parse(localStorage.getItem(WC_DEEPLINK_CHOICE_KEY)).href, 1, 't'))
            .toBe('metamask://wc?requestId=1&sessionTopic=t');

        rememberDeepLinkChoice('okx');
        expect(formatDeeplinkUrl(JSON.parse(localStorage.getItem(WC_DEEPLINK_CHOICE_KEY)).href, 1, 't'))
            .toBe('okex://main/wc?requestId=1&sessionTopic=t');
    });

    it('ล้างได้ (คอมพิวเตอร์ต้องไม่มีค่านี้ค้าง) · แอปที่ไม่รู้จัก = ล้าง', () => {
        rememberDeepLinkChoice('tpix');
        clearDeepLinkChoice();
        expect(localStorage.getItem(WC_DEEPLINK_CHOICE_KEY)).toBeNull();

        rememberDeepLinkChoice('tpix');
        rememberDeepLinkChoice('generic');
        expect(localStorage.getItem(WC_DEEPLINK_CHOICE_KEY)).toBeNull();
    });
});

describe('รอหน้ากลับมาอยู่ด้านหน้า', () => {
    let visibility;
    let focused;

    beforeEach(() => {
        visibility = 'visible';
        focused = true;
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });
        vi.spyOn(document, 'hasFocus').mockImplementation(() => focused);
    });

    afterEach(() => {
        delete document.visibilityState;
        vi.restoreAllMocks();
    });

    it('อยู่ด้านหน้าแล้ว = ไปต่อทันที', async () => {
        await expect(waitForForeground({ timeoutMs: 1000 })).resolves.toBe(true);
    });

    it('รอจนผู้ใช้สลับกลับมาจากแอปกระเป๋า', async () => {
        vi.useFakeTimers();
        visibility = 'hidden';
        focused = false;
        let done = null;
        waitForForeground({ timeoutMs: 60000 }).then((v) => { done = v; });

        await vi.advanceTimersByTimeAsync(5000);
        expect(done).toBeNull();

        visibility = 'visible';
        focused = true;
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(0);
        expect(done).toBe(true);
    });

    it('มองเห็นแล้วแต่ยังไม่ได้ focus → ถามซ้ำเป็นระยะจนได้', async () => {
        vi.useFakeTimers();
        focused = false;
        let done = null;
        waitForForeground({ timeoutMs: 60000, pollMs: 500, focusGraceMs: 5000 }).then((v) => { done = v; });

        await vi.advanceTimersByTimeAsync(600);
        expect(done).toBeNull();

        focused = true;
        await vi.advanceTimersByTimeAsync(600);
        expect(done).toBe(true);
    });

    it('มองเห็นแต่ focus ไม่มาเลย → ไปต่อหลังช่วงผ่อนผัน (ไม่ค้างจนคำขอเซ็นไม่ถูกส่ง)', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        focused = false;
        let done = null;
        waitForForeground({ timeoutMs: 60000, pollMs: 500, focusGraceMs: 1500 }).then((v) => { done = v; });

        await vi.advanceTimersByTimeAsync(1000);
        expect(done).toBeNull();
        await vi.advanceTimersByTimeAsync(1100);
        expect(done).toBe(true);
    });

    it('ช่วงผ่อนผันนับเฉพาะตอนมองเห็น — อยู่เบื้องหลังนานแค่ไหนก็ยังรอ', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        visibility = 'hidden';
        focused = false;
        let done = null;
        waitForForeground({ timeoutMs: 60000, pollMs: 500, focusGraceMs: 1500 }).then((v) => { done = v; });

        await vi.advanceTimersByTimeAsync(10000);
        expect(done).toBeNull();

        visibility = 'visible';
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(1000);
        expect(done).toBeNull();
        await vi.advanceTimersByTimeAsync(1000);
        expect(done).toBe(true);
    });

    it('ไม่กลับมาเลย = หมดเวลา (false) ไม่ค้างตลอดไป', async () => {
        vi.useFakeTimers();
        visibility = 'hidden';
        let done = null;
        waitForForeground({ timeoutMs: 3000 }).then((v) => { done = v; });

        await vi.advanceTimersByTimeAsync(3100);
        expect(done).toBe(false);
    });
});

describe('ปลุก relay', () => {
    it('สั่งเปิดใหม่เฉพาะตอนหลุด — ต่ออยู่/กำลังต่อ ไม่ยุ่ง', () => {
        const transportOpen = vi.fn(() => Promise.resolve());
        const wc = (connected, connecting) => ({ signer: { client: { core: { relayer: { connected, connecting, transportOpen } } } } });

        reviveRelay(wc(true, false));
        reviveRelay(wc(false, true));
        expect(transportOpen).not.toHaveBeenCalled();

        reviveRelay(wc(false, false));
        expect(transportOpen).toHaveBeenCalledTimes(1);
    });

    it('โครงภายในไลบรารีเปลี่ยน → ไม่พัง', () => {
        expect(() => reviveRelay({})).not.toThrow();
        expect(() => reviveRelay(null)).not.toThrow();
    });
});
