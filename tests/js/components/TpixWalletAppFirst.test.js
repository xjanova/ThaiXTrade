/**
 * TPIX TRADE — แอป TPIX Wallet ต้องเป็นทางเชื่อม/เข้าสู่ระบบอันดับ 1 และเด่นที่สุด
 *
 * เจ้าของสั่ง 2026-10-22: "ให้นำระบบเชื่อมต่อกระดานเทรดด้วย wallet app มาเป็นอันดับ 1 ในวิธีเชื่อมต่อ
 * และปรับปรุงโลโก้ให้สวยเด่น คือเน้นให้ใช้แอปมากกว่า"
 *
 * จุดที่ชุดนี้คุม:
 *  - หน้าต่างเชื่อมกระเป๋า (ใช้ทั้งเว็บ รวมหน้าเทรด): การ์ด TPIX Wallet มาก่อน MetaMask และกระเป๋าอื่นทั้งหมด
 *  - ใช้ไอคอนแอปตัวจริง (brand/tpix-wallet-app.webp) ไม่ใช่ไอคอนกระเป๋าทั่วไป / ตราทองรุ่นเก่า
 *  - ป้าย "แนะนำ" มีที่การ์ดนี้ที่เดียว · คอม = "สแกน QR" · มือถือ = "เปิดแอป" · มีลิงก์ดาวน์โหลด
 *  - กระเป๋าในเว็บถูกเรียกว่า TPIX Web Wallet และอยู่หลังกระเป๋าภายนอก (ไม่สับสนกับแอป)
 *  - หน้าเข้าสู่ระบบมีปุ่มเข้าสู่ระบบด้วยแอปเหนือฟอร์มอีเมล กดแล้วเริ่มเชื่อมแอปทันที
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { reactive } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import { isMobile } from '@/utils/mobileWallet';
import { TPIX_WALLET_APP_ICON } from '@/utils/brand';

const page = reactive({ url: '/', props: { auth: { user: null }, app: { walletconnect_project_id: 'pid' } } });
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: { post: vi.fn(), reload: vi.fn(), visit: (...args) => visit(...args) },
    usePage: () => page,
    useForm: (data) => reactive({ ...data, errors: {}, processing: false, post: vi.fn() }),
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
import TpixWalletAppCard from '@/Components/Wallet/TpixWalletAppCard.vue';
import Login from '@/Pages/Auth/Login.vue';

let store;

beforeEach(() => {
    setActivePinia(createPinia());
    store = useWalletStore();
    store.connectWalletConnect = vi.fn(({ wallet } = {}) => {
        store.wcStatus = 'pairing';
        store.wcWallet = wallet;
        return new Promise(() => {});
    });
    vi.mocked(isMobile).mockReturnValue(false);
    visit.mockClear();
    delete window.ethereum;
});

/** ลำดับใน DOM: a มาก่อน b ไหม */
function isBefore(a, b) {
    return Boolean(a.element.compareDocumentPosition(b.element) & Node.DOCUMENT_POSITION_FOLLOWING);
}

describe('หน้าต่างเชื่อมกระเป๋า — TPIX Wallet อันดับ 1', () => {
    it('การ์ด TPIX Wallet มาก่อน MetaMask, กระเป๋าอื่น และกระเป๋าในเว็บ', () => {
        const wrapper = mount(WalletModal);

        const card = wrapper.find('[data-testid="tpix-app-card"]');
        expect(card.exists()).toBe(true);
        for (const id of ['metamask', 'trustwallet', 'coinbase', 'tpix_wallet']) {
            expect(isBefore(card, wrapper.find(`[data-testid="wallet-option-${id}"]`)), id).toBe(true);
        }
        // แอปไม่ซ้ำในรายการล่าง (เดิมมีปุ่ม "TPIX Wallet App" ปนอยู่กลางรายการ)
        expect(wrapper.text()).not.toContain('TPIX Wallet App');
    });

    it('ไอคอนแอปตัวจริง + ป้ายแนะนำที่เดียว + ลิงก์ดาวน์โหลด', () => {
        const wrapper = mount(WalletModal);

        expect(wrapper.find('[data-testid="tpix-app-icon"]').attributes('src')).toBe(TPIX_WALLET_APP_ICON);
        expect(TPIX_WALLET_APP_ICON).toMatch(/^\/images\/brand\/tpix-wallet-app\.webp\?v=\d+$/);
        expect(wrapper.findAll('[data-testid="tpix-app-badge"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="tpix-app-badge"]').text()).toBe('Recommended');
        expect(wrapper.find('[data-testid="tpix-app-download"]').attributes('href')).toBe('/download');
    });

    it('คอมพิวเตอร์ = "สแกน QR" · กดแล้วเริ่มเชื่อมแอป TPIX Wallet', async () => {
        const wrapper = mount(WalletModal);
        const button = wrapper.find('[data-testid="tpix-app-connect"]');

        expect(button.text()).toContain('Scan QR with TPIX Wallet');
        await button.trigger('click');
        expect(store.connectWalletConnect).toHaveBeenCalledWith({ wallet: 'tpix' });
    });

    it('มือถือ = "เปิดแอป TPIX Wallet"', () => {
        vi.mocked(isMobile).mockReturnValue(true);
        const wrapper = mount(WalletModal);

        expect(wrapper.find('[data-testid="tpix-app-connect"]').text()).toContain('Open the TPIX Wallet app');
    });

    it('กระเป๋าในเว็บชื่อ TPIX Web Wallet และไม่มีป้ายแนะนำของตัวเอง', () => {
        const wrapper = mount(WalletModal);
        const web = wrapper.find('[data-testid="wallet-option-tpix_wallet"]');

        expect(web.text()).toContain('TPIX Web Wallet');
        expect(web.text()).not.toContain('แนะนำ');
        expect(web.text()).not.toContain('Recommended');
    });

    it('เปิดเว็บในเบราว์เซอร์ของแอป MetaMask บนมือถือ → ไม่โชว์การ์ดแอป (ใช้กระเป๋าของแอปนั้น)', () => {
        const ua = vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue(
            'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 MetaMaskMobile',
        );
        window.ethereum = { isMetaMask: true, request: vi.fn(), on: vi.fn(), removeListener: vi.fn() };
        const wrapper = mount(WalletModal);

        expect(wrapper.find('[data-testid="tpix-app-card"]').exists()).toBe(false);
        delete window.ethereum;
        ua.mockRestore();
    });

    it('คอมที่ติดตั้งส่วนขยาย MetaMask → ยังเห็นการ์ด TPIX Wallet อันดับ 1 และกระเป๋าอื่นครบ', () => {
        // เจ้าของเจอจริง 2026-10-22: "บนหน้าเว็บทำไมเห็นแต่ metamask"
        // ส่วนขยายใส่ window.ethereum.isMetaMask เหมือนแอป MetaMask — เดิมเลยถูกนับว่าอยู่ในแอป
        window.ethereum = { isMetaMask: true, request: vi.fn(), on: vi.fn(), removeListener: vi.fn() };
        const wrapper = mount(WalletModal);

        const card = wrapper.find('[data-testid="tpix-app-card"]');
        expect(card.exists()).toBe(true);
        for (const id of ['metamask', 'trustwallet', 'coinbase', 'tpix_wallet']) {
            const option = wrapper.find(`[data-testid="wallet-option-${id}"]`);
            expect(option.exists(), id).toBe(true);
            expect(isBefore(card, option), id).toBe(true);
        }
        expect(wrapper.text()).not.toContain('Wallet detected!');
        delete window.ethereum;
    });

    it('autoStart=tpix (มาจากหน้าเข้าสู่ระบบ) → เริ่มเชื่อมแอปทันทีไม่ต้องเลือกซ้ำ', () => {
        mount(WalletModal, { props: { autoStart: 'tpix' } });

        expect(store.connectWalletConnect).toHaveBeenCalledWith({ wallet: 'tpix' });
    });
});

describe('การ์ดเดี่ยว', () => {
    it('หน้าเข้าสู่ระบบ: หัวการ์ดบอกว่าเข้าสู่ระบบด้วยแอป', () => {
        const wrapper = mount(TpixWalletAppCard, { props: { signIn: true } });

        expect(wrapper.text()).toContain('Sign in with the TPIX Wallet app');
    });

    it('กำลังเชื่อมอยู่ = ปุ่มกดไม่ได้ (กันกดซ้ำ)', () => {
        const wrapper = mount(TpixWalletAppCard, { props: { busy: true } });

        expect(wrapper.find('[data-testid="tpix-app-connect"]').attributes('disabled')).toBeDefined();
    });
});

describe('หน้าเข้าสู่ระบบ', () => {
    it('ปุ่มแอป TPIX Wallet อยู่เหนือฟอร์มอีเมล · กดแล้วเปิดหน้าต่างและเริ่มเชื่อมแอป', async () => {
        const wrapper = mount(Login, { global: { stubs: { SocialLoginButtons: true } } });

        const card = wrapper.find('[data-testid="tpix-app-card"]');
        expect(card.exists()).toBe(true);
        expect(isBefore(card, wrapper.find('#email'))).toBe(true);

        await card.find('[data-testid="tpix-app-connect"]').trigger('click');
        await flushPromises();

        expect(wrapper.findComponent(WalletModal).exists()).toBe(true);
        expect(store.connectWalletConnect).toHaveBeenCalledWith({ wallet: 'tpix' });
    });

    it('เข้าสู่ระบบด้วยกระเป๋าเสร็จ → ไปต่อจากหน้าเข้าสู่ระบบ', async () => {
        const wrapper = mount(Login, { global: { stubs: { SocialLoginButtons: true } } });
        await wrapper.find('[data-testid="tpix-app-connect"]').trigger('click');
        await flushPromises();

        wrapper.findComponent(WalletModal).vm.$emit('connected', { address: '0x1', wallet: 'walletconnect' });
        await flushPromises();

        expect(visit).toHaveBeenCalledWith('/');
        expect(wrapper.findComponent(WalletModal).exists()).toBe(false);
    });
});
