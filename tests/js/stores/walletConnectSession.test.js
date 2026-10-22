/**
 * TPIX TRADE — เชื่อมกระเป๋าผ่าน WalletConnect (แอป TPIX Wallet / MetaMask มือถือ) แล้วต้องค้างอยู่
 *
 * เจ้าของสั่ง 2026-10-22: "ให้สแกนล็อกอินด้วยแอปกระเป๋าของเรา · มือถือเชื่อมแล้วหลุดเมื่อออกจากเว็บ"
 *
 * บั๊กที่ห้ามกลับมา:
 *  - รีโหลดหน้าแล้ว WalletConnect หลุด: tryReconnect ไปหา window.ethereum แทนการกู้ session
 *    (มือถือไม่มี = หลุด · คอมที่มี MetaMask = แอบไปผูกกับ MetaMask แทนแอปบนมือถือ)
 *  - ลายเซ็นเข้าสู่ระบบหมดเวลาใน 15 วิ — ผู้ใช้ยังสลับไปกดในแอปไม่ทัน
 *  - ขอสลับ/เพิ่มเชนก่อนขอลายเซ็น — บนมือถือคำขอนั้นค้างในแอป ขั้นเข้าสู่ระบบไม่เคยเริ่ม
 *  - มือถือ: ส่งคำขอเซ็นตอนหน้าอยู่เบื้องหลัง → ไม่มีอะไรเปิดแอปให้
 *  - Android ปิดแท็บระหว่างรอเซ็น → กลับมาแล้วไม่ได้เข้าสู่ระบบ
 *  - ตัดการเชื่อมต่อแล้ว session ค้างอยู่ใน relay / แอปตัดแล้วเว็บไม่รู้
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import { EventEmitter } from 'events';
import { useWalletStore } from '@/Stores/walletStore';
import { resetWalletConnectProvider, WC_DEEPLINK_CHOICE_KEY } from '@/utils/walletConnect';
import { isMobile } from '@/utils/mobileWallet';

const OWNER = '0xd2eab07809921fcb36c7ab72d7b5d8d2c12a67d7';
const OTHER = '0x2222222222222222222222222222222222222222';

// ── ม็อก: Inertia (props ของหน้า), axios, เสียง, การตรวจมือถือ, provider ของ WalletConnect ────────

const page = { props: { app: { walletconnect_project_id: 'test-project' }, auth: { user: null } } };
const reload = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: { reload: (...args) => reload(...args) },
}));

vi.mock('axios', () => ({
    default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn() },
}));

vi.mock('@/Composables/useSounds', () => ({
    playConnectSound: vi.fn(),
    playDisconnectSound: vi.fn(),
    playErrorSound: vi.fn(),
}));

vi.mock('@/utils/mobileWallet', async (importOriginal) => ({
    ...(await importOriginal()),
    isMobile: vi.fn(() => false),
}));

let currentWc = null;
const init = vi.fn(async () => currentWc);
vi.mock('@walletconnect/ethereum-provider', () => ({
    EthereumProvider: { init: (...args) => init(...args) },
}));

/**
 * provider ของ WalletConnect แบบจำลอง — ตอบ eth_accounts/eth_chainId ในเครื่องเหมือนตัวจริง
 * personal_sign ไปที่ "แอปบนมือถือ" (ฟังก์ชันที่เทสต์กำหนด) · จดทุกคำขอไว้ตรวจลำดับ
 */
function fakeWalletConnect({
    restoredSession = null,
    approvedChains = [4289, 56, 137, 1],
    account = OWNER,
    sign = async () => '0x' + '11'.repeat(65),
} = {}) {
    const events = new EventEmitter();
    const calls = [];
    const wc = {
        isWalletConnect: true,
        session: null,
        accounts: [],
        chainId: approvedChains[0],
        calls,
        approve: null,
        reject: null,
        signer: { client: { core: { relayer: { connected: true, connecting: false, transportOpen: vi.fn() } } } },
        on: (ev, fn) => { events.on(ev, fn); return wc; },
        removeListener: (ev, fn) => { events.removeListener(ev, fn); return wc; },
        emit: (ev, ...args) => events.emit(ev, ...args),
        listenerCount: (ev) => events.listenerCount(ev),
        connect: vi.fn(() => new Promise((resolve, reject) => {
            wc.approve = () => {
                wc.session = makeSession(approvedChains, account);
                wc.accounts = [account];
                wc.chainId = approvedChains[0];
                resolve();
            };
            wc.reject = (err) => reject(err);
            // ลิงก์จับคู่มาถึงหลังสร้าง pairing เสร็จ (เหมือน display_uri ของจริง)
            queueMicrotask(() => events.emit('display_uri', 'wc:topic@2?relay-protocol=irn&symKey=key'));
        })),
        disconnect: vi.fn(async () => {
            wc.session = null;
            wc.accounts = [];
        }),
        request: vi.fn(async ({ method, params }) => {
            calls.push({ method, params });
            switch (method) {
                case 'eth_accounts':
                case 'eth_requestAccounts':
                    return [...wc.accounts];
                case 'eth_chainId':
                    return '0x' + wc.chainId.toString(16);
                case 'wallet_switchEthereumChain':
                    wc.chainId = parseInt(params[0].chainId, 16);
                    events.emit('chainChanged', params[0].chainId);
                    return null;
                case 'personal_sign':
                    return sign(params);
                default:
                    return null;
            }
        }),
    };
    if (restoredSession) {
        wc.session = restoredSession;
        wc.accounts = [account];
    }
    return wc;
}

function makeSession(chains, account = OWNER) {
    return {
        topic: 'session-topic',
        peer: { metadata: { name: 'TPIX Wallet' } },
        namespaces: {
            eip155: {
                chains: chains.map(c => `eip155:${c}`),
                accounts: chains.map(c => `eip155:${c}:${account}`),
                methods: ['personal_sign', 'eth_sendTransaction'],
                events: ['chainChanged', 'accountsChanged'],
            },
        },
    };
}

/** เซิร์ฟเวอร์: /wallet/sign ออกข้อความ · /wallet/verify-signature ผ่านเสมอ (เข้าสู่ระบบ) */
function mockServer({ verifyStatus = 200 } = {}) {
    axios.post.mockImplementation(async (url) => {
        if (url === '/api/v1/wallet/sign') {
            return { data: { success: true, data: { message: 'TPIX TRADE: Sign this message to verify your wallet.\n\nNonce: abc', nonce: 'abc' } } };
        }
        if (url === '/api/v1/wallet/verify-signature') {
            if (verifyStatus !== 200) {
                throw Object.assign(new Error('Request failed'), { response: { status: verifyStatus, data: { error: { code: 'INVALID_SIGNATURE' } } } });
            }
            return { data: { success: true, data: { verified: true, signed_in: true } } };
        }
        return { data: { success: true, data: {} } };
    });
}

const posted = (url) => axios.post.mock.calls.filter(([u]) => u === url);
const flush = () => new Promise(r => setTimeout(r, 0));

let visibility;
let focused;

beforeEach(() => {
    setActivePinia(createPinia());
    resetWalletConnectProvider();
    init.mockClear();
    reload.mockClear();
    axios.post.mockReset();
    mockServer();
    vi.mocked(isMobile).mockReturnValue(false);
    page.props.auth.user = null;
    localStorage.clear();
    sessionStorage.clear();

    visibility = 'visible';
    focused = true;
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });
    vi.spyOn(document, 'hasFocus').mockImplementation(() => focused);

    // ส่วนขยาย MetaMask ที่ "มีบัญชีอื่น" อยู่ในเครื่อง — WalletConnect ต้องไม่ไปแตะมันเด็ดขาด
    window.ethereum = {
        isMetaMask: true,
        request: vi.fn(async ({ method }) => (method === 'eth_accounts' ? [OTHER] : method === 'eth_chainId' ? '0x38' : null)),
        on: vi.fn(),
        removeListener: vi.fn(),
    };
});

afterEach(() => {
    delete window.ethereum;
    delete document.visibilityState;
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('กู้การเชื่อมต่อเดิมตอนรีโหลด / กลับมาจากแอป / เปิดเว็บวันหลัง', () => {
    it('ได้กระเป๋าเดิมจาก session ของ WalletConnect — ไม่แตะ window.ethereum (MetaMask ในเครื่อง)', async () => {
        currentWc = fakeWalletConnect({ restoredSession: makeSession([4289, 56]) });
        localStorage.setItem('tpix_wallet', JSON.stringify({ address: OWNER, walletType: 'walletconnect', wcWallet: 'tpix' }));
        const store = useWalletStore();

        expect(await store.tryReconnect()).toBe(true);

        expect(store.address).toBe(OWNER);
        expect(store.walletType).toBe('walletconnect');
        expect(store.wcWallet).toBe('tpix');
        expect(store.chainId).toBe(4289);
        expect((await store.signer.getAddress()).toLowerCase()).toBe(OWNER);
        expect(window.ethereum.request).not.toHaveBeenCalled();
        // กู้เฉย ๆ ไม่ขอลายเซ็นเอง (ไม่มีป๊อปอัพทุกครั้งที่รีเฟรช)
        expect(posted('/api/v1/wallet/sign')).toHaveLength(0);
    });

    it('แอปกระเป๋าตัด session ไปแล้ว → ล้างของค้าง ไม่โชว์ว่ายังเชื่อมอยู่', async () => {
        currentWc = fakeWalletConnect();
        localStorage.setItem('tpix_wallet', JSON.stringify({ address: OWNER, walletType: 'walletconnect' }));
        localStorage.setItem(WC_DEEPLINK_CHOICE_KEY, JSON.stringify({ href: 'tpixwallet://', name: 'TPIX Wallet' }));
        const store = useWalletStore();

        expect(await store.tryReconnect()).toBe(false);
        expect(store.isConnected).toBe(false);
        expect(localStorage.getItem('tpix_wallet')).toBeNull();
        expect(localStorage.getItem(WC_DEEPLINK_CHOICE_KEY)).toBeNull();
    });

    it('เน็ตสะดุดตอนเปิดหน้า (สร้าง provider ไม่ได้) ≠ ตัดการเชื่อมต่อ — ไม่ลืม ลองใหม่หน้าถัดไปได้', async () => {
        init.mockRejectedValueOnce(new Error('relay unreachable'));
        localStorage.setItem('tpix_wallet', JSON.stringify({ address: OWNER, walletType: 'walletconnect' }));
        const store = useWalletStore();

        expect(await store.tryReconnect()).toBe(false);
        expect(localStorage.getItem('tpix_wallet')).not.toBeNull();

        currentWc = fakeWalletConnect({ restoredSession: makeSession([4289]) });
        expect(await store.tryReconnect()).toBe(true);
        expect(store.address).toBe(OWNER);
    });

    it('ทุกหน้าเรียก tryReconnect (AppLayout สร้างใหม่ทุกหน้า) → กู้รอบเดียว ไม่สร้างซ้ำ ไม่ผูก event ซ้อน', async () => {
        currentWc = fakeWalletConnect({ restoredSession: makeSession([4289]) });
        localStorage.setItem('tpix_wallet', JSON.stringify({ address: OWNER, walletType: 'walletconnect' }));
        const store = useWalletStore();

        const [a, b] = await Promise.all([store.tryReconnect(), store.tryReconnect()]);
        expect(a).toBe(true);
        expect(b).toBe(true);
        expect(await store.tryReconnect()).toBe(true);

        expect(init).toHaveBeenCalledTimes(1);
        expect(currentWc.listenerCount('accountsChanged')).toBe(1);
        expect(currentWc.listenerCount('session_delete')).toBe(1);
    });

    it('Android ปิดแท็บระหว่างรอลายเซ็น → กลับมาแล้วขอลายเซ็นใหม่ให้เองหนึ่งครั้ง + เปิดหน้าต่างบอก', async () => {
        currentWc = fakeWalletConnect({ restoredSession: makeSession([4289]) });
        localStorage.setItem('tpix_wallet', JSON.stringify({ address: OWNER, walletType: 'walletconnect', wcWallet: 'tpix' }));
        sessionStorage.setItem('tpix_wallet_verify_pending', JSON.stringify({ address: OWNER, at: Date.now() }));
        const store = useWalletStore();

        await store.tryReconnect();
        expect(store.wcStatus).toBe('signing');
        expect(store.showConnectModal).toBe(true);

        await vi.waitFor(() => expect(store.wcStatus).toBe('done'));
        expect(currentWc.calls.filter(c => c.method === 'personal_sign')).toHaveLength(1);
        expect(posted('/api/v1/wallet/verify-signature')).toHaveLength(1);
        expect(sessionStorage.getItem('tpix_wallet_verify_pending')).toBeNull();
    });

    it('ค้างไว้ว่ารอเซ็น แต่เว็บเข้าสู่ระบบด้วยกระเป๋านี้แล้ว → ไม่ขอซ้ำ', async () => {
        page.props.auth.user = { wallet_address: OWNER };
        currentWc = fakeWalletConnect({ restoredSession: makeSession([4289]) });
        localStorage.setItem('tpix_wallet', JSON.stringify({ address: OWNER, walletType: 'walletconnect' }));
        sessionStorage.setItem('tpix_wallet_verify_pending', JSON.stringify({ address: OWNER, at: Date.now() }));
        const store = useWalletStore();

        await store.tryReconnect();
        await flush();

        expect(store.wcStatus).toBe('idle');
        expect(posted('/api/v1/wallet/sign')).toHaveLength(0);
        expect(sessionStorage.getItem('tpix_wallet_verify_pending')).toBeNull();
    });
});

describe('สแกน QR บนคอม → อนุมัติในแอป → เซ็น → เข้าสู่ระบบ', () => {
    it('ครบวงจร: ได้ลิงก์ทำ QR · เชื่อม · สลับไป TPIX ในเครื่อง · เซ็น · เข้าสู่ระบบ', async () => {
        currentWc = fakeWalletConnect({ approvedChains: [56, 4289] });
        const store = useWalletStore();
        store.supportedChains = [{ chainId: 4289, status: 'live' }, { chainId: 56, status: 'live' }];

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).toMatch(/^wc:/));
        expect(store.wcStatus).toBe('pairing');

        currentWc.approve();
        const address = await done;

        expect(address).toBe(OWNER);
        expect(store.isConnected).toBe(true);
        expect(store.chainId).toBe(4289);
        expect(store.wcStatus).toBe('done');
        expect(store.verifiedAt).not.toBeNull();
        expect(reload).toHaveBeenCalledWith({ only: ['auth', 'kyc'] });

        // สลับเชนก่อนขอลายเซ็น และไม่มีคำขอเพิ่มเชนไปรบกวนแอป (อนุมัติเชนไว้แล้ว)
        const methods = currentWc.calls.map(c => c.method).filter(m => m.startsWith('wallet_') || m === 'personal_sign');
        expect(methods).toEqual(['wallet_switchEthereumChain', 'personal_sign']);

        expect(posted('/api/v1/wallet/verify-signature')[0][1]).toMatchObject({
            wallet_address: OWNER,
            chain_id: 4289,
            wallet_type: 'walletconnect',
        });
        expect(JSON.parse(localStorage.getItem('tpix_wallet'))).toEqual({ address: OWNER, walletType: 'walletconnect', wcWallet: 'tpix' });
        // คอมพิวเตอร์: ห้ามจำลิงก์เปิดแอป (กระเป๋าอยู่บนมือถืออีกเครื่อง)
        expect(localStorage.getItem(WC_DEEPLINK_CHOICE_KEY)).toBeNull();
    });

    it('ไม่ใช้ window.ethereum แม้มี MetaMask ในเครื่อง', async () => {
        currentWc = fakeWalletConnect();
        const store = useWalletStore();
        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        currentWc.approve();
        await done;

        expect(window.ethereum.request).not.toHaveBeenCalled();
        expect(store.address).toBe(OWNER);
    });

    it('ผู้ใช้กดยืนยันในแอปช้า 40 วิ (เกิน 15 วิแบบเดิม) → ยังเข้าสู่ระบบได้', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        currentWc = fakeWalletConnect({
            sign: () => new Promise(resolve => setTimeout(() => resolve('0x' + '22'.repeat(65)), 40000)),
        });
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        currentWc.approve();
        await vi.waitFor(() => expect(store.wcStatus).toBe('signing'));

        await vi.advanceTimersByTimeAsync(41000);
        await done;

        expect(store.wcStatus).toBe('done');
        expect(posted('/api/v1/wallet/verify-signature')).toHaveLength(1);
    });

    it('MetaMask ที่ยังไม่รู้จักเชน TPIX → ไม่ขอเพิ่ม/สลับเชนก่อนเซ็น (คำขอนั้นค้างในแอป)', async () => {
        currentWc = fakeWalletConnect({ approvedChains: [1, 56] });
        const store = useWalletStore();
        store.supportedChains = [{ chainId: 4289, status: 'live' }];

        const done = store.connectWalletConnect({ wallet: 'metamask' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        currentWc.approve();
        await done;

        const walletMethods = currentWc.calls.map(c => c.method).filter(m => m.startsWith('wallet_'));
        expect(walletMethods).toEqual([]);
        expect(currentWc.calls.some(c => c.method === 'personal_sign')).toBe(true);
        expect(store.wcStatus).toBe('done');
    });

    it('เว็บเข้าสู่ระบบด้วยกระเป๋านี้อยู่แล้ว → เชื่อมใหม่ไม่ต้องเซ็นซ้ำ', async () => {
        page.props.auth.user = { wallet_address: OWNER };
        currentWc = fakeWalletConnect();
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        currentWc.approve();
        await done;

        expect(store.wcStatus).toBe('done');
        expect(currentWc.calls.some(c => c.method === 'personal_sign')).toBe(false);
        expect(posted('/api/v1/wallet/sign')).toHaveLength(0);
    });

    it('ปฏิเสธในแอป → ยังเชื่อมอยู่ แต่บอกว่าเข้าสู่ระบบไม่สำเร็จเพราะอะไร + ลองใหม่ได้', async () => {
        let reject = true;
        currentWc = fakeWalletConnect({
            sign: async () => {
                if (reject) throw Object.assign(new Error('User rejected the request'), { code: 4001 });
                return '0x' + '33'.repeat(65);
            },
        });
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        currentWc.approve();
        await done;

        expect(store.isConnected).toBe(true);
        expect(store.wcStatus).toBe('verify_failed');
        expect(store.verifyError).toBe('rejected');

        reject = false;
        expect(await store.verifyOwnership()).toBe(true);
        expect(store.wcStatus).toBe('done');
    });

    it('ผู้ใช้ปิดหน้าต่างระหว่างรอสแกน → ยกเลิกเงียบ ๆ ไม่ขึ้นข้อความผิดพลาด', async () => {
        currentWc = fakeWalletConnect();
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        store.cancelWalletConnect();

        await expect(done).rejects.toMatchObject({ code: 'WC_CANCELLED' });
        expect(store.wcStatus).toBe('idle');
        expect(store.error).toBeNull();
        expect(store.isConnecting).toBe(false);
    });

    it('ปิดหน้าต่างตอนไลบรารียังโหลดไม่เสร็จ → ไม่สร้าง QR ต่อเบื้องหลัง', async () => {
        currentWc = fakeWalletConnect();
        let release;
        init.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve(currentWc); }));
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(init).toHaveBeenCalled());
        store.cancelWalletConnect();
        release();

        await expect(done).rejects.toMatchObject({ code: 'WC_CANCELLED' });
        expect(currentWc.connect).not.toHaveBeenCalled();
        expect(store.wcUri).toBeNull();
        expect(store.wcStatus).toBe('idle');
    });

    it('ลายเซ็นมาถึงหลังผู้ใช้กดตัดการเชื่อมต่อไปแล้ว → ไม่แอบเข้าสู่ระบบกลับเอง', async () => {
        let release;
        currentWc = fakeWalletConnect({ sign: () => new Promise(r => { release = () => r('0x' + '55'.repeat(65)); }) });
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        currentWc.approve();
        await vi.waitFor(() => expect(currentWc.calls.some(c => c.method === 'personal_sign')).toBe(true));

        store.disconnect();
        release();
        await done.catch(() => {});
        await flush();

        expect(posted('/api/v1/wallet/verify-signature')).toHaveLength(0);
        expect(store.isConnected).toBe(false);
        expect(reload).not.toHaveBeenCalledWith({ only: ['auth', 'kyc'] });
    });

    it('ยกเลิกแล้วแต่ไปกดอนุมัติในแอปทีหลัง → ปิด session ที่ไม่มีใครใช้ทิ้ง', async () => {
        currentWc = fakeWalletConnect();
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'tpix' }).catch(() => {});
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        store.cancelWalletConnect();
        currentWc.approve();
        await done;
        await flush();

        expect(currentWc.disconnect).toHaveBeenCalled();
        expect(store.isConnected).toBe(false);
    });
});

describe('มือถือ: เปิดแอปกระเป๋า → อนุมัติ → กลับมาที่เบราว์เซอร์', () => {
    beforeEach(() => {
        vi.mocked(isMobile).mockReturnValue(true);
    });

    it('จำแอปที่เลือกไว้ให้ sign-client เปิดแอปเองตอนส่งคำขอเซ็น', async () => {
        currentWc = fakeWalletConnect();
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        expect(JSON.parse(localStorage.getItem(WC_DEEPLINK_CHOICE_KEY))).toEqual({ href: 'tpixwallet://', name: 'TPIX Wallet' });

        currentWc.approve();
        await done;
        expect(store.wcStatus).toBe('done');
    });

    it('ส่งคำขอเซ็นหลังผู้ใช้กลับมาที่หน้านี้เท่านั้น (ส่งตอนอยู่เบื้องหลัง = ไม่มีอะไรเปิดแอปให้)', async () => {
        currentWc = fakeWalletConnect();
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'metamask' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());

        // ผู้ใช้อยู่ในแอปกระเป๋า — อนุมัติแล้ว session มาถึงตอนหน้าเว็บยังอยู่เบื้องหลัง
        visibility = 'hidden';
        focused = false;
        currentWc.approve();
        await vi.waitFor(() => expect(store.wcStatus).toBe('signing'));
        await new Promise(r => setTimeout(r, 50));
        expect(currentWc.calls.some(c => c.method === 'personal_sign')).toBe(false);

        // กลับมาที่เบราว์เซอร์
        visibility = 'visible';
        focused = true;
        document.dispatchEvent(new Event('visibilitychange'));
        await done;

        expect(currentWc.calls.some(c => c.method === 'personal_sign')).toBe(true);
        expect(store.wcStatus).toBe('done');
    });

    it('ระหว่างรอเซ็นจดไว้ใน sessionStorage (เผื่อแท็บถูกปิด) แล้วล้างเมื่อจบ', async () => {
        let release;
        currentWc = fakeWalletConnect({ sign: () => new Promise(r => { release = () => r('0x' + '44'.repeat(65)); }) });
        const store = useWalletStore();

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        currentWc.approve();
        await vi.waitFor(() => expect(currentWc.calls.some(c => c.method === 'personal_sign')).toBe(true));

        expect(JSON.parse(sessionStorage.getItem('tpix_wallet_verify_pending'))).toMatchObject({ address: OWNER });
        release();
        await done;
        expect(sessionStorage.getItem('tpix_wallet_verify_pending')).toBeNull();
    });

    it('ไม่เสนอเพิ่มเหรียญอัตโนมัติบนมือถือ (คือการสลับแอปอีกรอบที่ผู้ใช้ไม่ได้ขอ)', async () => {
        currentWc = fakeWalletConnect();
        const store = useWalletStore();
        store.supportedChains = [{ chainId: 4289, status: 'live' }];

        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        currentWc.approve();
        await done;
        await flush();

        expect(currentWc.calls.some(c => c.method === 'wallet_watchAsset')).toBe(false);
    });
});

describe('ตัดการเชื่อมต่อ', () => {
    async function connected() {
        currentWc = fakeWalletConnect();
        const store = useWalletStore();
        const done = store.connectWalletConnect({ wallet: 'tpix' });
        await vi.waitFor(() => expect(store.wcUri).not.toBeNull());
        currentWc.approve();
        await done;
        return store;
    }

    it('ตัดจากเว็บ → ปิด session ที่ relay (แอปเห็นว่าตัดแล้ว) + ล้างทุกอย่าง + แจ้งเซิร์ฟเวอร์', async () => {
        const store = await connected();
        localStorage.setItem(WC_DEEPLINK_CHOICE_KEY, JSON.stringify({ href: 'tpixwallet://', name: 'TPIX Wallet' }));

        store.disconnect();
        await flush();

        expect(currentWc.disconnect).toHaveBeenCalled();
        expect(store.isConnected).toBe(false);
        expect(store.wcStatus).toBe('idle');
        expect(localStorage.getItem('tpix_wallet')).toBeNull();
        expect(localStorage.getItem(WC_DEEPLINK_CHOICE_KEY)).toBeNull();
        expect(posted('/api/v1/wallet/disconnect')[0][1]).toEqual({ wallet_address: OWNER });
    });

    it('ตัดจากในแอปกระเป๋า (session_delete) → เว็บตัดตาม ไม่ค้างว่ายังเชื่อมอยู่', async () => {
        const store = await connected();

        currentWc.session = null;
        currentWc.accounts = [];
        currentWc.emit('session_delete', { topic: 'session-topic' });

        expect(store.isConnected).toBe(false);
        expect(localStorage.getItem('tpix_wallet')).toBeNull();
        expect(posted('/api/v1/wallet/disconnect')).toHaveLength(1);
    });

    it('relay สะดุดเฉย ๆ (session ยังอยู่) ≠ ตัดการเชื่อมต่อ', async () => {
        const store = await connected();

        currentWc.emit('disconnect', { code: 0 });

        expect(store.isConnected).toBe(true);
    });
});
