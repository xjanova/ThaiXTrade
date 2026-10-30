/**
 * TPIX TRADE — แท็บและฟอร์มที่กำหนดว่าเงินไปไหน แก้ได้เฉพาะ super_admin
 *
 * เซิร์ฟเวอร์ตอบ 403 อยู่แล้ว หน้านี้กันแค่แอดมินระดับล่างเจอฟอร์มว่าง ๆ แล้วกดบันทึกไปชน 403
 *
 * กัน: แท็บเงินโผล่ให้ระดับล่าง · ไม่ส่ง prop แล้วเปิดแท็บให้เอง · ข้อความปฏิเสธที่อยู่กระเป๋าไม่ขึ้น
 *      · ปุ่มบันทึกค่าตั้งรวมโผล่ทั้งที่แก้ไม่ได้
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

const formErrors = reactive({});

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    usePage: () => ({ props: {} }),
    router: { post: vi.fn(), delete: vi.fn() },
    useForm: (data) => reactive({ ...data, errors: formErrors, processing: false, put: vi.fn(), post: vi.fn(), reset: vi.fn() }),
}));
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import Settings from '@/Pages/Admin/Settings/Index.vue';
import TradingFees from '@/Pages/Admin/TradingFees/Index.vue';

const MONEY_TABS = ['Revenue Wallet', 'Trading', 'Token Factory', 'Payment'];

function mountSettings(props = {}) {
    return mount(Settings, { props, global: { mocks: { $page: { props: {} } } } });
}

const tabLabels = (wrapper) => wrapper.findAll('button').map((button) => button.text());

describe('หน้าตั้งค่า — แท็บเงิน', () => {
    beforeEach(() => {
        Object.keys(formErrors).forEach((key) => delete formErrors[key]);
    });

    it('super_admin เห็นแท็บเงินครบ', () => {
        const labels = tabLabels(mountSettings({ canManageMoney: true }));

        MONEY_TABS.forEach((tab) => expect(labels).toContain(tab));
    });

    it('แอดมินระดับล่างไม่เห็นแท็บเงิน แต่ยังเห็นแท็บงานประจำ', () => {
        const labels = tabLabels(mountSettings({ canManageMoney: false }));

        MONEY_TABS.forEach((tab) => expect(labels).not.toContain(tab));
        ['General', 'SEO', 'Security', 'Social'].forEach((tab) => expect(labels).toContain(tab));
    });

    it('ไม่ส่ง prop มาต้องถือว่าไม่มีสิทธิ์', () => {
        const labels = tabLabels(mountSettings());

        MONEY_TABS.forEach((tab) => expect(labels).not.toContain(tab));
    });

    it('แสดงข้อความปฏิเสธที่อยู่กระเป๋าจากเซิร์ฟเวอร์', () => {
        formErrors.tpix_wallet = 'ที่อยู่กระเป๋าไม่ถูกต้อง';

        expect(mountSettings({ canManageMoney: true }).text()).toContain('ที่อยู่กระเป๋าไม่ถูกต้อง');
    });
});

describe('ค่าบริการวางไม้ — ค่าตั้งรวม', () => {
    const settingsForm = (wrapper) => wrapper.find('form');

    it('super_admin แก้และบันทึกได้', () => {
        const wrapper = mount(TradingFees, { props: { canManageMoney: true } });

        expect(wrapper.text()).toContain('บันทึกการตั้งค่า');
        expect(settingsForm(wrapper).attributes('inert')).toBeUndefined();
    });

    it('แอดมินระดับล่างเห็นได้แต่แก้ไม่ได้ และรู้ว่าเพราะอะไร', () => {
        const wrapper = mount(TradingFees, { props: { canManageMoney: false } });

        expect(wrapper.text()).not.toContain('บันทึกการตั้งค่า');
        expect(settingsForm(wrapper).attributes('inert')).toBeDefined();
        expect(wrapper.text()).toContain('แก้ได้เฉพาะ Super Admin');
    });
});
