/**
 * TPIX TRADE — ปุ่ม "เพิ่ม TPIX ลงกระเป๋า" ในเมนูกระเป๋าของแถบบน
 *
 * เจ้าของสั่ง 2026-10-11: "มีปุ่มเพิ่มเหรียญต่างหาก ด้วย"
 * ปุ่มต้องโผล่ทั้งเมนูกระเป๋าแยก (ยังไม่ล็อกอิน) และเมนูบัญชี (บัญชี = กระเป๋าใบเดียวกัน)
 * ซ่อนเมื่อกระเป๋ารับคำขอเพิ่มเหรียญไม่ได้ และกดรัวต้องไม่เปิดหน้าต่างซ้อน
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { reactive } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';

const page = reactive({ url: '/', props: { auth: { user: null }, kyc: {} } });

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: { post: vi.fn(), reload: vi.fn() },
    usePage: () => page,
}));
vi.mock('@/Components/Navigation/ChainSelector.vue', () => ({ default: { name: 'ChainSelector', template: '<div />' } }));
vi.mock('@/Components/Navigation/LanguageSwitcher.vue', () => ({ default: { name: 'LanguageSwitcher', template: '<div />' } }));

const toasts = vi.hoisted(() => ({ showToast: vi.fn() }));
vi.mock('@/Composables/useToasts', () => ({ showToast: toasts.showToast }));

import NavBar from '@/Components/Navigation/NavBar.vue';

const OWNER = '0xd2eab07809921fcb36c7ab72d7b5d8d2c12a67d7';

function connect(store, type = 'metamask') {
    store.address = OWNER;
    store.walletType = type;
    store.chainId = 4289;
}

/** template อ่าน $page ตรง ๆ (ลิงก์ตั้งค่า) — ส่งตัวเดียวกับ usePage ให้ */
function mountNav() {
    return mount(NavBar, { global: { mocks: { $page: page } } });
}

async function openWalletMenu(wrapper) {
    await wrapper.find('.wallet-badge').trigger('click');
}

describe('NavBar — ปุ่มเพิ่ม TPIX ลงกระเป๋า', () => {
    let store;

    beforeEach(() => {
        vi.clearAllMocks();
        setActivePinia(createPinia());
        store = useWalletStore();
        page.props.auth.user = null;
    });

    it('เมนูกระเป๋าแยก: กดแล้วเรียกเพิ่มเหรียญและแจ้งผล', async () => {
        connect(store);
        store.addTpixAssetsToWallet = vi.fn(async () => ({ ok: true, added: ['USDT'], skipped: [] }));
        const wrapper = mountNav();

        await openWalletMenu(wrapper);
        const btn = wrapper.find('.add-tpix-assets');
        expect(btn.exists()).toBe(true);
        expect(btn.text()).toContain('Add TPIX to wallet');

        await btn.trigger('click');
        await flushPromises();

        expect(store.addTpixAssetsToWallet).toHaveBeenCalledTimes(1);
        expect(toasts.showToast).toHaveBeenCalledWith(expect.objectContaining({ type: 'success' }));
        expect(toasts.showToast.mock.calls[0][0].text).toContain('USDT');
    });

    it('ไม่โผล่เมื่อเป็นกระเป๋าฝัง (TPIX Wallet)', async () => {
        connect(store, 'tpix_wallet');
        const wrapper = mountNav();

        await openWalletMenu(wrapper);

        expect(wrapper.find('.add-tpix-assets').exists()).toBe(false);
    });

    it('เมนูบัญชี (บัญชีกับกระเป๋าเป็นตัวตนเดียวกัน) ก็มีปุ่มนี้', async () => {
        connect(store);
        page.props.auth.user = { name: 'Owner', wallet_address: OWNER, has_password: false };
        const wrapper = mountNav();

        expect(wrapper.find('.wallet-badge').exists()).toBe(false);
        await wrapper.findAll('button').find(b => b.text().includes('Owner')).trigger('click');

        expect(wrapper.find('.add-tpix-assets').exists()).toBe(true);
    });

    it('กดซ้ำระหว่างที่กระเป๋ายังเปิดหน้าต่างค้าง = ไม่ส่งคำขอซ้อน', async () => {
        connect(store);
        let release;
        store.addTpixAssetsToWallet = vi.fn(() => new Promise((r) => { release = r; }));
        const wrapper = mountNav();

        await openWalletMenu(wrapper);
        await wrapper.find('.add-tpix-assets').trigger('click');
        await openWalletMenu(wrapper);
        await wrapper.find('.add-tpix-assets').trigger('click');

        expect(store.addTpixAssetsToWallet).toHaveBeenCalledTimes(1);
        release({ ok: true, added: [], skipped: ['USDT'] });
        await flushPromises();
        expect(toasts.showToast).toHaveBeenCalledWith(expect.objectContaining({ type: 'info' }));
    });

    it('ผู้ใช้ไม่ยอมสลับเชน → บอกว่าต้องไป TPIX Chain ก่อน', async () => {
        connect(store);
        store.addTpixAssetsToWallet = vi.fn(async () => ({ ok: false, reason: 'chain', added: [], skipped: [] }));
        const wrapper = mountNav();

        await openWalletMenu(wrapper);
        await wrapper.find('.add-tpix-assets').trigger('click');
        await flushPromises();

        expect(toasts.showToast).toHaveBeenCalledWith(expect.objectContaining({ type: 'error' }));
        expect(toasts.showToast.mock.calls[0][0].text).toContain('TPIX Chain');
    });
});
