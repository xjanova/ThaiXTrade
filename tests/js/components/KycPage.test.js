/**
 * TPIX TRADE — หน้า /kyc: ยืนยันตัวตนทำในแอปเท่านั้น
 *
 * เจ้าของสั่ง: "การ kyc ในเว็บทำไม่ได้ ให้ขึ้นว่าทำในแอพ"
 *
 * กัน: ฟอร์มส่งเอกสาร/ปุ่มเชื่อม Thaiprompt บนเว็บกลับมา · มือถือไม่มีปุ่มเปิดแอป
 *      · ผ่านแล้วยังชวนไปทำในแอป · คนที่ยังไม่รู้ว่าเป็นใครไม่มีทางไปต่อ
 *      · เซ็นกระเป๋าบนหน้านี้แล้วสถานะไม่อัปเดต
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive, nextTick } from 'vue';

const page = reactive({ props: { flash: {}, errors: {}, auth: { user: null } }, url: '/kyc' });
const routerPost = vi.fn();
const routerReload = vi.fn();
const wallet = reactive({
    address: null,
    isConnected: false,
    openConnectModal: vi.fn(),
    verifyOwnership: vi.fn(),
});
let mobile = false;

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3');
    return {
        ...actual,
        usePage: () => page,
        router: { post: (...a) => routerPost(...a), reload: (...a) => routerReload(...a), visit: vi.fn() },
        Head: { template: '<div />' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(() => Promise.resolve('data:image/png;base64,QR')) } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));
vi.mock('@/utils/mobileWallet', () => ({ isMobile: () => mobile }));

import QRCode from 'qrcode';
import KycIndex from '@/Pages/Kyc/Index.vue';

const base = {
    submission: null,
    history: [],
    gate: { features: {} },
    features: [{ key: 'trading', enabled: true, label_th: 'เทรด/สวอป', level: 'basic' }],
    deletionRequest: null,
    app: { deep_link: 'tpixtrade://kyc', download_url: 'https://tpix.online/download' },
};

// หน้าที่ค้างจากเทสต์ก่อนยังเฝ้า page (ตัวเดียวกันทั้งไฟล์) — ต้องถอดทุกครั้ง ไม่งั้นนับ reload เกิน
const mounted = [];
const mountPage = (props = {}) => {
    const w = mount(KycIndex, { props: { ...base, ...props } });
    mounted.push(w);
    return w;
};

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});
const button = (w, text) => w.findAll('button').find(b => b.text().includes(text));

beforeEach(() => {
    routerPost.mockReset();
    routerReload.mockReset();
    wallet.openConnectModal.mockReset();
    wallet.verifyOwnership.mockReset();
    wallet.address = null;
    wallet.isConnected = false;
    page.props.errors = {};
    page.props.auth = { user: null };
    mobile = false;
    QRCode.toDataURL.mockClear();
});

describe('Kyc page — verification happens in the app', () => {
    it('explains the in-app steps and offers nothing to submit on the web', () => {
        const w = mountPage();

        expect(w.find('[data-test="kyc-app-card"]').exists()).toBe(true);
        expect(w.text()).toContain('ยืนยันตัวตนในแอป TPIX TRADE');
        expect(w.text()).toContain('ยืนยันด้วย Thaiprompt');
        // ไม่มีทางยื่นบนเว็บเหลืออยู่เลย
        expect(w.find('form').exists()).toBe(false);
        expect(w.find('input[type="file"]').exists()).toBe(false);
        expect(w.find('input[type="checkbox"]').exists()).toBe(false);
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('opens the app straight from a phone', () => {
        mobile = true;
        const w = mountPage();

        const open = w.find('[data-test="kyc-open-app"]');
        expect(open.attributes('href')).toBe('tpixtrade://kyc');
        expect(w.text()).toContain('ยังไม่มีแอป? ดาวน์โหลด');
        expect(QRCode.toDataURL).not.toHaveBeenCalled();
    });

    it('shows a QR to the download page on a computer', async () => {
        const w = mountPage();
        await flushPromises();

        expect(QRCode.toDataURL).toHaveBeenCalledWith('https://tpix.online/download', expect.any(Object));
        expect(w.find('.app-qr img').attributes('src')).toBe('data:image/png;base64,QR');
        expect(w.find('[data-test="kyc-open-app"]').exists()).toBe(false);
    });

    it('stops sending people to the app once they are verified', () => {
        page.props.auth = { user: { id: 7 } };
        const w = mountPage({
            submission: { uuid: 'u1', status: 'approved', source: 'thaiprompt', reviewed_at: '2026-10-09T10:00:00Z', documents: [] },
            gate: { features: { trading: { required: true, passed: true } } },
        });

        expect(w.find('[data-test="kyc-app-card"]').exists()).toBe(false);
        expect(w.text()).toContain('ยืนยันผ่านบัญชี Thaiprompt');
        expect(w.find('.feature-check--on').exists()).toBe(true);
    });

    it('points a rejected manual submission back to the app', () => {
        page.props.auth = { user: { id: 7 } };
        const w = mountPage({
            submission: { uuid: 'u1', status: 'rejected', source: 'manual', reject_reason: 'รูปไม่ชัด', submitted_at: '2026-10-01T10:00:00Z' },
        });

        expect(w.text()).toContain('รูปไม่ชัด');
        expect(w.text()).toContain('ยืนยันตัวตนใหม่ได้ในแอป TPIX TRADE');
        expect(w.find('[data-test="kyc-app-card"]').exists()).toBe(true);
    });
});

describe('Kyc page — knowing who is looking', () => {
    it('asks a stranger to connect a wallet to see their status', async () => {
        const w = mountPage();

        expect(w.find('[data-test="kyc-recheck"]').exists()).toBe(false);
        await w.find('[data-test="kyc-connect-wallet"]').trigger('click');

        expect(wallet.openConnectModal).toHaveBeenCalledTimes(1);
        expect(wallet.verifyOwnership).not.toHaveBeenCalled();
    });

    it('asks a connected-but-unsigned wallet to sign instead of reconnecting', async () => {
        wallet.address = '0x1234567890abcdef1234567890abcdef12345678';
        wallet.isConnected = true;
        wallet.verifyOwnership.mockResolvedValue(true);
        const w = mountPage();

        expect(w.text()).toContain('0x1234…5678');
        await w.find('[data-test="kyc-connect-wallet"]').trigger('click');
        await flushPromises();

        expect(wallet.verifyOwnership).toHaveBeenCalledTimes(1);
        expect(wallet.openConnectModal).not.toHaveBeenCalled();
    });

    it('falls back to the connect dialog when the wallet cannot sign here', async () => {
        wallet.isConnected = true;
        wallet.address = '0x1234567890abcdef1234567890abcdef12345678';
        wallet.verifyOwnership.mockResolvedValue(false);
        const w = mountPage();

        await w.find('[data-test="kyc-connect-wallet"]').trigger('click');
        await flushPromises();

        expect(wallet.openConnectModal).toHaveBeenCalledTimes(1);
    });

    it('reloads this account\'s status right after the wallet signs in', async () => {
        mountPage();

        page.props.auth = { user: { id: 42 } };
        await nextTick();

        expect(routerReload).toHaveBeenCalledTimes(1);
        const only = routerReload.mock.calls[0][0].only;
        expect(only).toEqual(expect.arrayContaining(['submission', 'gate', 'kyc']));
    });

    it('lets a signed-in user re-check after finishing in the app', async () => {
        page.props.auth = { user: { id: 7 } };
        const w = mountPage();

        expect(w.find('[data-test="kyc-connect-wallet"]').exists()).toBe(false);
        await w.find('[data-test="kyc-recheck"]').trigger('click');
        // กดซ้ำระหว่างรอ = ไม่ยิงซ้ำ
        await w.find('[data-test="kyc-recheck"]').trigger('click');

        expect(routerReload).toHaveBeenCalledTimes(1);
        routerReload.mock.calls[0][0].onFinish();
        await nextTick();
        expect(button(w, 'ตรวจสอบสถานะ').attributes('disabled')).toBeUndefined();
    });

    it('still shows an error that came back from an older redirect', () => {
        page.props.errors = { kyc: 'คุณยังไม่ได้อนุญาตให้ Thaiprompt ส่งผลยืนยันตัวตนมาให้ TPIX TRADE' };
        const w = mountPage();

        expect(w.text()).toContain('คุณยังไม่ได้อนุญาตให้ Thaiprompt');
    });
});
