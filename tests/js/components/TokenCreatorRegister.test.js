/**
 * TPIX TRADE — หน้าลงทะเบียนผู้สร้างเหรียญ (/token-creator)
 *
 * เจ้าของสั่ง: เข้าด้วยกระเป๋าแล้วเซ็น · กรอกฟอร์มมาตรฐาน · KYC ส่งไปตรวจที่แอป Thaiprompt (ต้องโหลดแอป)
 *             · ข้อตกลงไทย-อังกฤษ เซ็นออนไลน์ · ออก PDF ได้
 *
 * กัน: ปุ่มเซ็นข้อตกลงกดได้ก่อน KYC/ก่อนติ๊กยอมรับ · KYC พาไปที่อื่นแทนแอป Thaiprompt
 *      · มือถือไม่มีปุ่มเปิดแอป · เชื่อม Thaiprompt โดยไม่กดยินยอม · error ฟอร์มไม่ขึ้นที่ช่อง
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

const page = reactive({ props: { flash: {}, errors: {}, auth: { user: null } }, url: '/token-creator' });
const routerPost = vi.fn();
const routerReload = vi.fn();
const axiosPost = vi.fn();
const axiosPut = vi.fn();
const WALLET = '0xabcdef0000000000000000000000000000000001';
const wallet = reactive({
    address: null,
    signer: null,
    openConnectModal: vi.fn(),
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
vi.mock('axios', () => ({ default: { post: (...a) => axiosPost(...a), put: (...a) => axiosPut(...a), get: vi.fn() } }));
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(() => Promise.resolve('data:image/png;base64,QR')) } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));
vi.mock('@/utils/mobileWallet', () => ({ isMobile: () => mobile }));

import QRCode from 'qrcode';
import Register from '@/Pages/TokenCreator/Register.vue';
import { t } from '@/Composables/useTranslation';

const agreement = {
    version: '2026-11-06',
    hash: 'a'.repeat(64),
    document: {
        title_th: 'ข้อตกลงผู้สร้างเหรียญ',
        title_en: 'Token Creator Agreement',
        draft_notice_th: 'ร่าง — ต้องให้ทนายตรวจ',
        draft_notice_en: 'Draft — lawyer review required',
        preamble_th: 'อารัมภบท',
        preamble_en: 'Preamble',
        sections: [{ id: 'law', title_th: 'กฎหมาย', title_en: 'Law', clauses: [{ th: 'ภาษาไทยมีผลเหนือกว่า', en: 'Thai prevails' }] }],
    },
};

const thaiprompt = (overrides = {}) => ({
    available: true,
    link: null,
    app_link: 'thaiprompt://ekyc?from=profile',
    download_url: 'https://main.thaiprompt.online/app/download',
    ...overrides,
});

const draft = (overrides = {}) => ({
    uuid: 'u-1',
    status: 'draft',
    wallet_address: WALLET,
    form: { project_name: 'Siam Farm', token_symbol: 'SFARM' },
    form_hash: 'f'.repeat(64),
    signed: false,
    starter_allocation_bps: null,
    rejection_reason: null,
    ...overrides,
});

const mounted = [];
const mountPage = (props = {}) => {
    const w = mount(Register, {
        props: {
            registration: null,
            kyc: { approved: false, level: null, thaiprompt: thaiprompt() },
            agreement,
            defaultStarterBps: 1000,
            options: { categories: ['utility'], fund_sources: ['own_funds'] },
            ...props,
        },
    });
    mounted.push(w);
    return w;
};
const $ = (w, sel) => w.find(`[data-test="${sel}"]`);

beforeEach(() => {
    routerPost.mockReset();
    routerReload.mockReset();
    axiosPost.mockReset();
    axiosPut.mockReset();
    wallet.address = null;
    wallet.signer = null;
    wallet.openConnectModal.mockReset();
    page.props.errors = {};
    page.props.flash = {};
    mobile = false;
    QRCode.toDataURL.mockClear();
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

describe('TokenCreator/Register — เข้าด้วยกระเป๋า', () => {
    it('ยังไม่เชื่อมกระเป๋า → ปุ่มเชื่อม · ไม่มีช่อง private key', () => {
        const w = mountPage();

        expect($(w, 'connect').exists()).toBe(true);
        expect($(w, 'no-key-warning').exists()).toBe(true);
        // ขั้นแรกไม่มีช่องกรอกอะไรเลย — ยืนยันตัวด้วยการเซ็นในกระเป๋าเท่านั้น
        expect(w.findAll('input, textarea')).toHaveLength(0);

        $(w, 'connect').trigger('click');
        expect(wallet.openConnectModal).toHaveBeenCalled();
    });

    it('เชื่อมแล้ว → ขอข้อความ เซ็น แล้วเริ่มใบ', async () => {
        wallet.address = WALLET;
        wallet.signer = { getAddress: vi.fn(() => Promise.resolve(WALLET)), signMessage: vi.fn(() => Promise.resolve('0xsig')) };
        axiosPost
            .mockResolvedValueOnce({ data: { data: { message: 'msg', nonce: 'n'.repeat(32) } } })
            .mockResolvedValueOnce({ data: { data: { registration: draft({ form_hash: null, form: null }) } } });

        const w = mountPage();
        await $(w, 'start').trigger('click');
        await flushPromises();

        expect(axiosPost.mock.calls[0]).toEqual(['/token-creator/challenge', { wallet_address: WALLET }]);
        expect(axiosPost.mock.calls[1][0]).toBe('/token-creator/start');
        expect(axiosPost.mock.calls[1][1]).toMatchObject({ wallet_address: WALLET, signature: '0xsig' });
        expect(routerReload).toHaveBeenCalled();
        expect($(w, 'creator-form').exists()).toBe(true);
    });

    it('กดยกเลิกในกระเป๋า → ข้อความเข้าใจง่าย ไม่เริ่มใบ', async () => {
        wallet.address = WALLET;
        wallet.signer = { getAddress: vi.fn(() => Promise.resolve(WALLET)), signMessage: vi.fn(() => Promise.reject({ code: 4001 })) };
        axiosPost.mockResolvedValueOnce({ data: { data: { message: 'msg', nonce: 'n'.repeat(32) } } });

        const w = mountPage();
        await $(w, 'start').trigger('click');
        await flushPromises();

        expect(axiosPost).toHaveBeenCalledTimes(1);
        expect($(w, 'error').text()).toBe(t('page.tokenCreator.errors.signCancelled'));
    });
});

describe('TokenCreator/Register — ฟอร์ม', () => {
    it('error จากเซิร์ฟเวอร์ขึ้นที่ช่อง', async () => {
        axiosPut.mockRejectedValueOnce({ response: { status: 422, data: { errors: { token_symbol: ['สัญลักษณ์ผิด'] } } } });
        const w = mountPage({ registration: draft({ form_hash: null }) });

        await $(w, 'creator-form').trigger('submit');
        await flushPromises();

        expect(w.text()).toContain('สัญลักษณ์ผิด');
        expect($(w, 'error').text()).toBe(t('page.tokenCreator.form.checkFields'));
    });

    it('บันทึกสำเร็จ → ไปขั้นตรวจทาน', async () => {
        axiosPut.mockResolvedValueOnce({ data: { data: { registration: draft() } } });
        const w = mountPage({ registration: draft({ form_hash: null }) });

        await $(w, 'creator-form').trigger('submit');
        await flushPromises();

        expect(axiosPut.mock.calls[0][0]).toBe('/token-creator/form');
        expect($(w, 'step-agreement').exists()).toBe(true);
    });
});

describe('TokenCreator/Register — KYC ในแอป Thaiprompt', () => {
    it('ยังไม่ผ่าน KYC → บอกให้โหลดแอป Thaiprompt (QR บนคอมพิวเตอร์)', async () => {
        const w = mountPage({ registration: draft() });
        await flushPromises();

        expect($(w, 'kyc-pending').exists()).toBe(true);
        expect($(w, 'kyc-download').attributes('href')).toBe('https://main.thaiprompt.online/app/download');
        expect(QRCode.toDataURL).toHaveBeenCalledWith('https://main.thaiprompt.online/app/download', expect.any(Object));
        expect($(w, 'kyc-download-qr').exists()).toBe(true);
        expect($(w, 'kyc-open-app').exists()).toBe(false);
        expect(w.text()).toContain(t('page.tokenCreator.kyc.appStep1'));
    });

    it('มือถือ → ปุ่มเปิดหน้ายืนยันตัวตนในแอป Thaiprompt ไม่มี QR', async () => {
        mobile = true;
        const w = mountPage({ registration: draft() });
        await flushPromises();

        expect($(w, 'kyc-open-app').attributes('href')).toBe('thaiprompt://ekyc?from=profile');
        expect(QRCode.toDataURL).not.toHaveBeenCalled();
    });

    it('เชื่อมบัญชี Thaiprompt ต้องกดยินยอมก่อน แล้วกลับมาหน้านี้', async () => {
        const w = mountPage({ registration: draft() });

        expect($(w, 'kyc-connect').attributes('disabled')).toBeDefined();
        await $(w, 'kyc-connect').trigger('click');
        expect(routerPost).not.toHaveBeenCalled();

        await $(w, 'kyc-consent').setValue(true);
        await $(w, 'kyc-connect').trigger('click');

        expect(routerPost).toHaveBeenCalledWith('/kyc/thaiprompt/connect', { consent: true, return: 'token-creator' }, expect.any(Object));
    });

    it('เชื่อมแล้วรอผล → ตรวจสถานะถาม Thaiprompt แล้วโหลดสถานะใหม่', async () => {
        axiosPost.mockResolvedValueOnce({ data: { success: true } });
        const w = mountPage({
            registration: draft(),
            kyc: { approved: false, level: null, thaiprompt: thaiprompt({ link: { status: 'pending', needs_reconnect: false } }) },
        });

        expect($(w, 'kyc-connect').exists()).toBe(false);
        expect($(w, 'kyc-linked').text()).toContain(t('page.tokenCreator.kyc.tpStatus.pending'));

        await $(w, 'kyc-refresh').trigger('click');
        await flushPromises();

        expect(axiosPost).toHaveBeenCalledWith('/kyc/thaiprompt/refresh');
        expect(routerReload).toHaveBeenCalledWith(expect.objectContaining({ only: ['kyc', 'registration'] }));
    });

    it('สิทธิ์เชื่อมหมดอายุ → ปุ่มเชื่อมใหม่', () => {
        const w = mountPage({
            registration: draft(),
            kyc: { approved: false, level: null, thaiprompt: thaiprompt({ link: { status: 'none', needs_reconnect: true } }) },
        });

        expect($(w, 'kyc-connect').text()).toBe(t('page.tokenCreator.kyc.reconnect'));
    });

    it('error จากการเชื่อม Thaiprompt (redirect กลับมา) ขึ้นในส่วน KYC', () => {
        page.props.errors = { kyc: 'ติดต่อ Thaiprompt ไม่ได้ชั่วคราว' };
        const w = mountPage({ registration: draft() });

        expect($(w, 'kyc-error').text()).toBe('ติดต่อ Thaiprompt ไม่ได้ชั่วคราว');
    });

    it('ผ่าน KYC แล้ว → ไม่ชวนโหลดแอปอีก', async () => {
        const w = mountPage({ registration: draft(), kyc: { approved: true, level: 'basic', thaiprompt: thaiprompt() } });
        await flushPromises();

        expect($(w, 'kyc-ok').exists()).toBe(true);
        expect($(w, 'kyc-thaiprompt-steps').exists()).toBe(false);
        expect(QRCode.toDataURL).not.toHaveBeenCalled();
    });

    it('ยังไม่ได้ตั้งค่า Thaiprompt ฝั่งเซิร์ฟเวอร์ → พาไปหน้า /kyc แทนปุ่มเชื่อม', () => {
        const w = mountPage({ registration: draft(), kyc: { approved: false, level: null, thaiprompt: thaiprompt({ available: false }) } });

        expect($(w, 'kyc-connect').exists()).toBe(false);
        expect($(w, 'kyc-go').attributes('href')).toBe('/kyc');
    });
});

describe('TokenCreator/Register — เซ็นข้อตกลง', () => {
    const signer = () => ({ getAddress: vi.fn(() => Promise.resolve(WALLET)), signMessage: vi.fn(() => Promise.resolve('0xsig2')) });
    const ackAll = async (w) => {
        await $(w, 'ack-read').setValue(true);
        await $(w, 'ack-thai').setValue(true);
        await $(w, 'ack-risk').setValue(true);
    };

    it('แสดงข้อตกลงภาษาไทยเป็นหลัก มีอังกฤษกำกับ และส่วนเริ่มโครงการ 10%', () => {
        const w = mountPage({ registration: draft(), kyc: { approved: true, level: 'basic', thaiprompt: thaiprompt() } });

        expect($(w, 'agreement-text').text()).toContain('ภาษาไทยมีผลเหนือกว่า');
        expect($(w, 'agreement-text').text()).toContain('Thai prevails');
        expect($(w, 'starter-note').text()).toContain('10');
    });

    it('ยังไม่ผ่าน KYC → ปุ่มเซ็นกดไม่ได้แม้ติ๊กครบ', async () => {
        wallet.address = WALLET;
        const w = mountPage({ registration: draft() });
        await ackAll(w);

        expect($(w, 'sign-agreement').attributes('disabled')).toBeDefined();
    });

    it('ต้องติ๊กยอมรับครบสามข้อก่อนเซ็น', async () => {
        wallet.address = WALLET;
        const w = mountPage({ registration: draft(), kyc: { approved: true, level: 'basic', thaiprompt: thaiprompt() } });

        await $(w, 'ack-read').setValue(true);
        await $(w, 'ack-thai').setValue(true);
        expect($(w, 'sign-agreement').attributes('disabled')).toBeDefined();

        await $(w, 'ack-risk').setValue(true);
        expect($(w, 'sign-agreement').attributes('disabled')).toBeUndefined();
    });

    it('กระเป๋าที่เชื่อมไม่ใช่ใบที่ลงทะเบียน → เตือนและปิดปุ่มเซ็น', async () => {
        wallet.address = '0x0000000000000000000000000000000000000009';
        const w = mountPage({ registration: draft(), kyc: { approved: true, level: 'basic', thaiprompt: thaiprompt() } });
        await ackAll(w);

        expect($(w, 'wallet-mismatch').exists()).toBe(true);
        expect($(w, 'sign-agreement').attributes('disabled')).toBeDefined();
    });

    it('เซ็นสำเร็จ → ส่งตรวจ แสดงสถานะรอตรวจ', async () => {
        wallet.address = WALLET;
        wallet.signer = signer();
        axiosPost
            .mockResolvedValueOnce({ data: { data: { message: 'agree', nonce: 'm'.repeat(32) } } })
            .mockResolvedValueOnce({ data: { data: { registration: draft({ status: 'submitted', signed: true }) } } });
        const w = mountPage({ registration: draft(), kyc: { approved: true, level: 'basic', thaiprompt: thaiprompt() } });
        await ackAll(w);

        await $(w, 'sign-agreement').trigger('click');
        await flushPromises();

        expect(wallet.signer.signMessage).toHaveBeenCalledWith('agree');
        expect(axiosPost.mock.calls[1]).toEqual(['/token-creator/submit', { nonce: 'm'.repeat(32), signature: '0xsig2' }]);
        expect($(w, 'step-status').exists()).toBe(true);
        expect($(w, 'signed-pdf').attributes('href')).toBe('/token-creator/pdf');
    });

    it('กดเซ็นรัวๆ → ขอข้อความครั้งเดียว', async () => {
        wallet.address = WALLET;
        wallet.signer = signer();
        let release;
        axiosPost.mockImplementationOnce(() => new Promise((r) => { release = r; }));
        const w = mountPage({ registration: draft(), kyc: { approved: true, level: 'basic', thaiprompt: thaiprompt() } });
        await ackAll(w);

        await $(w, 'sign-agreement').trigger('click');
        await $(w, 'sign-agreement').trigger('click');
        release({ data: { data: { message: 'agree', nonce: 'm'.repeat(32) } } });
        await flushPromises();

        expect(axiosPost.mock.calls.filter((c) => c[0] === '/token-creator/agreement/challenge')).toHaveLength(1);
    });
});

describe('TokenCreator/Register — สถานะ', () => {
    it('ถูกปฏิเสธ → เห็นเหตุผล แล้วกดแก้ไขกลับไปฟอร์ม', async () => {
        const w = mountPage({ registration: draft({ status: 'rejected', rejection_reason: 'เว็บไซต์ยังไม่ครบ', signed: true }) });

        expect($(w, 'rejection-reason').text()).toContain('เว็บไซต์ยังไม่ครบ');
        await $(w, 'resubmit').trigger('click');
        expect($(w, 'creator-form').exists()).toBe(true);
    });

    it('อนุมัติแล้ว → บอกส่วนเริ่มโครงการตามที่แอดมินกำหนด', () => {
        const w = mountPage({ registration: draft({ status: 'approved', starter_allocation_bps: 1500, signed: true }) });

        expect($(w, 'approved-desc').text()).toContain('15');
    });
});
