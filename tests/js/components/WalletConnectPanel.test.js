/**
 * TPIX TRADE — หน้าต่างเชื่อมกระเป๋า: สแกน QR ด้วยแอป TPIX Wallet / เปิดแอปจากมือถือ
 *
 * จุดที่ชุดนี้คุม:
 *  - คอมพิวเตอร์: กดการ์ด TPIX Wallet แล้วได้ QR ในหน้าต่างของเราเอง (ไม่ใช่หน้าต่างของ Reown
 *    ที่ไม่รู้จักแอปเรา) พร้อมขั้นตอนสแกน
 *  - มือถือ: ได้ปุ่มเปิดแอป TPIX Wallet ที่พาลิงก์จับคู่ไปด้วย (เดิมเปิด tpixwallet:// เฉย ๆ = เชื่อมไม่ได้)
 *  - MetaMask บนมือถือ: เชื่อมผ่าน WalletConnect อยู่เบราว์เซอร์เดิม (เดิมพาไปเปิดในเบราว์เซอร์ของ MetaMask
 *    กลับมาที่ Chrome แล้วไม่ได้เชื่อม) — ยังเปิดในแอปได้จากลิงก์สำรอง
 *  - ขั้นยืนยันเข้าสู่ระบบ: บอกให้ไปกดในแอป + ปุ่มเปิดแอป (มือถือ) · ปิดหน้าต่างระหว่างรอเซ็น = ไม่ตัดการเชื่อมต่อ
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { reactive } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import { isMobile } from '@/utils/mobileWallet';

const page = reactive({ url: '/', props: { auth: { user: null }, app: { walletconnect_project_id: 'pid' } } });

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: { post: vi.fn(), reload: vi.fn() },
    usePage: () => page,
}));
vi.mock('@/Composables/useSounds', () => ({
    playConnectSound: vi.fn(),
    playDisconnectSound: vi.fn(),
    playErrorSound: vi.fn(),
    playClickSound: vi.fn(),
}));
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(async () => 'data:image/png;base64,QR') } }));
vi.mock('@/utils/mobileWallet', async (importOriginal) => ({
    ...(await importOriginal()),
    isMobile: vi.fn(() => false),
    openWalletApp: vi.fn(),
}));

import WalletModal from '@/Components/Wallet/WalletModal.vue';
import WalletConnectPanel from '@/Components/Wallet/WalletConnectPanel.vue';
import { openWalletApp } from '@/utils/mobileWallet';

const OWNER = '0xd2eab07809921fcb36c7ab72d7b5d8d2c12a67d7';
const URI = 'wc:topic@2?relay-protocol=irn&symKey=key';

let store;
let pending;

/** ให้ connectWalletConnect ของสโตร์เป็นตัวจำลอง — แผงอ่านแค่สถานะ (เทสต์ของสโตร์อยู่อีกไฟล์) */
function stubConnect() {
    pending = null;
    store.connectWalletConnect = vi.fn(({ wallet } = {}) => {
        store.wcStatus = 'pairing';
        store.wcWallet = wallet;
        store.wcUri = null;
        return new Promise((resolve, reject) => { pending = { resolve, reject }; });
    });
    store.cancelWalletConnect = vi.fn(() => {
        if (store.wcStatus === 'signing') return;
        store.wcStatus = 'idle';
        store.wcUri = null;
    });
}

function buttonByText(wrapper, text) {
    return wrapper.findAll('button').find(b => b.text().includes(text));
}

beforeEach(() => {
    setActivePinia(createPinia());
    store = useWalletStore();
    stubConnect();
    vi.mocked(isMobile).mockReturnValue(false);
    vi.mocked(openWalletApp).mockClear();
    delete window.ethereum;
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('คอมพิวเตอร์ — สแกน QR ด้วยแอป TPIX Wallet', () => {
    it('กดการ์ด TPIX Wallet (อันดับ 1) → แผง QR ของเรา + ขั้นตอนสแกน (ไม่ปิดหน้าต่างจนกว่าจะเข้าสู่ระบบ)', async () => {
        const wrapper = mount(WalletModal);

        await wrapper.find('[data-testid="tpix-app-connect"]').trigger('click');
        expect(store.connectWalletConnect).toHaveBeenCalledWith({ wallet: 'tpix' });
        expect(wrapper.find('[data-testid="wc-preparing"]').exists()).toBe(true);

        store.wcUri = URI;
        await flushPromises();

        expect(wrapper.find('[data-testid="wc-qr"]').attributes('src')).toBe('data:image/png;base64,QR');
        expect(wrapper.text()).toContain('Open TPIX Wallet on your phone');
        // ยังไม่มีปุ่มเปิดแอปบนคอม (ไม่มีแอปรับลิงก์บนเครื่องนี้)
        expect(wrapper.find('[data-testid="wc-open-app"]').exists()).toBe(false);
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('เชื่อมแล้ว → ขั้นยืนยันในแอป → เข้าสู่ระบบแล้วปิดหน้าต่างเอง', async () => {
        vi.useFakeTimers();
        const wrapper = mount(WalletModal);
        await wrapper.find('[data-testid="tpix-app-connect"]').trigger('click');

        store.address = OWNER;
        store.wcStatus = 'signing';
        await flushPromises();
        expect(wrapper.find('[data-testid="wc-signing"]').text()).toContain('Look at your phone');
        expect(wrapper.find('[data-testid="wc-open-app-sign"]').exists()).toBe(false);

        store.wcStatus = 'done';
        pending.resolve(OWNER);
        await flushPromises();
        expect(wrapper.find('[data-testid="wc-done"]').exists()).toBe(true);

        await vi.advanceTimersByTimeAsync(1000);
        expect(wrapper.emitted('connected')?.[0]?.[0]).toMatchObject({ address: OWNER, wallet: 'walletconnect' });
        expect(wrapper.emitted('close')).toBeTruthy();
        vi.useRealTimers();
    });

    it('ปิดหน้าต่างระหว่างรอสแกน = ยกเลิกการจับคู่', async () => {
        const wrapper = mount(WalletModal);
        await wrapper.find('[data-testid="tpix-app-connect"]').trigger('click');

        await wrapper.find('.modal-overlay').trigger('click');

        expect(store.cancelWalletConnect).toHaveBeenCalled();
        expect(wrapper.emitted('close')).toBeTruthy();
    });
});

describe('มือถือ — เปิดแอปกระเป๋าแล้วกลับมาที่เบราว์เซอร์', () => {
    beforeEach(() => {
        vi.mocked(isMobile).mockReturnValue(true);
    });

    it('การ์ด TPIX Wallet → ปุ่มเปิดแอปพาลิงก์จับคู่ไปด้วย (ไม่ใช่ tpixwallet:// เฉย ๆ)', async () => {
        const wrapper = mount(WalletModal);
        await wrapper.find('[data-testid="tpix-app-connect"]').trigger('click');
        store.wcUri = URI;
        await flushPromises();

        const href = wrapper.find('[data-testid="wc-open-app"]').attributes('href');
        expect(href).toContain('wc?uri=');
        expect(href).toContain(encodeURIComponent(URI));
        expect(href).not.toBe('tpixwallet://');
        // QR ซ่อนไว้ (สแกนตัวเองไม่ได้) แต่เปิดดูได้
        expect(wrapper.find('[data-testid="wc-qr"]').exists()).toBe(false);
    });

    it('MetaMask (ไม่ได้เปิดจากในแอป MetaMask) → เชื่อมผ่าน WalletConnect ไม่พาออกไปเบราว์เซอร์ของ MetaMask', async () => {
        const wrapper = mount(WalletModal);
        await buttonByText(wrapper, 'MetaMask').trigger('click');

        expect(store.connectWalletConnect).toHaveBeenCalledWith({ wallet: 'metamask' });
        expect(openWalletApp).not.toHaveBeenCalled();

        store.wcUri = URI;
        await flushPromises();
        expect(wrapper.find('[data-testid="wc-open-app"]').attributes('href'))
            .toBe(`metamask://wc?uri=${encodeURIComponent(URI)}`);

        // ทางสำรองแบบเดิม: เปิดเว็บนี้ในเบราว์เซอร์ของ MetaMask
        await buttonByText(wrapper, 'MetaMask แทน').trigger('click');
        expect(openWalletApp).toHaveBeenCalled();
    });

    it('ขั้นยืนยัน: ปุ่มเปิดแอปเพื่อกดยืนยัน (ไม่ใช่ลิงก์จับคู่ใหม่)', async () => {
        store.wcWallet = 'tpix';
        store.wcSessionTopic = 'session-topic';
        store.address = OWNER;
        store.wcStatus = 'signing';
        const wrapper = mount(WalletConnectPanel, { props: { wallet: 'tpix' } });
        await flushPromises();

        const href = wrapper.find('[data-testid="wc-open-app-sign"]').attributes('href');
        expect(href).toContain('sessionTopic=session-topic');
        expect(href).not.toContain('uri=');
        expect(wrapper.text()).toContain('Open the app, tap Confirm');
    });
});

describe('ผลของขั้นยืนยัน', () => {
    it('ปฏิเสธในแอป → บอกเหตุผล + ลองใหม่ได้', async () => {
        store.address = OWNER;
        store.wcStatus = 'verify_failed';
        store.verifyError = 'rejected';
        store.verifyOwnership = vi.fn(async () => true);
        const wrapper = mount(WalletConnectPanel, { props: { wallet: 'tpix' } });

        expect(wrapper.find('[data-testid="wc-verify-failed"]').text()).toContain('declined');
        await wrapper.find('[data-testid="wc-retry-sign"]').trigger('click');
        expect(store.verifyOwnership).toHaveBeenCalled();
    });

    it('บัญชีในแอปไม่ตรงกับที่เชื่อม → บอกให้สลับบัญชีในแอป', () => {
        store.wcStatus = 'verify_failed';
        store.verifyError = 'mismatch';
        const wrapper = mount(WalletConnectPanel, { props: { wallet: 'tpix' } });

        expect(wrapper.text()).toContain('Switch back to it in the app');
    });

    it('หน้าต่างถูกเปิดใหม่ตอนสโตร์รอลายเซ็นอยู่ (รีโหลด/หน้าอื่นขอยืนยัน) → เห็นขั้นยืนยันทันที', async () => {
        store.address = OWNER;
        store.wcWallet = 'tpix';
        store.wcStatus = 'signing';
        const wrapper = mount(WalletModal);
        await flushPromises();

        expect(wrapper.find('[data-testid="wc-signing"]').exists()).toBe(true);

        // ปิดหน้าต่างระหว่างรอเซ็น → ไม่ยกเลิกการเชื่อมต่อ (ไปกดในแอปทีหลังได้)
        await wrapper.find('.modal-overlay').trigger('click');
        expect(store.wcStatus).toBe('signing');
    });
});
