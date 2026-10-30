/**
 * TPIX TRADE — ช่องทางรับเงินในฟอร์มรอบขายของหลังบ้าน
 *
 * เซิร์ฟเวอร์ปฏิเสธค่านอกนโยบายอยู่แล้ว หน้านี้กันไม่ให้ฟอร์มส่งค่าที่จะโดนปฏิเสธ
 *
 * กัน: ค่าเริ่มต้นเก่า ['USDT', 'STRIPE'] กลับมา · รอบเก่าที่ค้าง USDT ทำให้กดบันทึกไม่ได้
 *      · ปิดบัตรจากฟอร์มได้ · ข้อความปฏิเสธจากเซิร์ฟเวอร์ไม่ขึ้นแล้วปุ่มเงียบไปเฉย ๆ
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

const inertia = vi.hoisted(() => ({ submit: vi.fn() }));
const formErrors = reactive({});

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    usePage: () => ({ props: {} }),
    router: { post: vi.fn(), delete: vi.fn(), patch: vi.fn() },
    useForm: (data) => reactive({
        ...data,
        errors: formErrors,
        processing: false,
        post(url) { inertia.submit(url, { ...this }); },
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
}));
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Admin/SaleLaunchPanel.vue', () => ({ default: { template: '<div />' } }));
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

import TokenSalesIndex from '@/Pages/Admin/TokenSales/Index.vue';

const existingSale = {
    id: 1,
    name: 'TPIX Public Sale',
    total_supply_for_sale: 1000,
    total_sold: 0,
    status: 'draft',
    phases: [],
};

async function openSales(sales = []) {
    const wrapper = mount(TokenSalesIndex, {
        props: { canManageMoney: true, sales },
        global: { stubs: { teleport: true } },
    });

    await wrapper.findAll('button').find((button) => button.text().includes('Sales & Phases')).trigger('click');

    return wrapper;
}

async function openCreate() {
    const wrapper = await openSales();

    await wrapper.findAll('button').find((button) => button.text().includes('New Sale')).trigger('click');

    return wrapper;
}

async function openEdit(acceptCurrencies) {
    const wrapper = await openSales([{ ...existingSale, accept_currencies: acceptCurrencies }]);

    await wrapper.findAll('button').find((button) => button.text() === 'Edit').trigger('click');

    return wrapper;
}

const channelBox = (wrapper, value) => wrapper.find(`input[type="checkbox"][value="${value}"]`);

async function submittedChannels(wrapper) {
    await wrapper.find('form').trigger('submit');

    const [url, form] = inertia.submit.mock.calls.at(-1);

    expect(url).toBe('/admin/token-sales');

    return form.accept_currencies;
}

describe('ฟอร์มรอบขาย — ช่องทางรับเงิน', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.keys(formErrors).forEach((key) => delete formErrors[key]);
    });

    it('รอบใหม่เริ่มที่บัตรอย่างเดียว ไม่ใช่ค่าเก่า USDT + STRIPE', async () => {
        expect(await submittedChannels(await openCreate())).toEqual(['CARD']);
    });

    it('ติ๊กโอนเงินและ USDT แบบตรวจบนเชนเพิ่มจากบัตรได้', async () => {
        const wrapper = await openCreate();

        await channelBox(wrapper, 'BANK').setValue(true);
        await channelBox(wrapper, 'CRYPTO').setValue(true);

        expect(await submittedChannels(wrapper)).toEqual(['CARD', 'BANK', 'CRYPTO']);
    });

    it('เอาติ๊กออกแล้วช่องทางนั้นหายจากค่าที่ส่ง', async () => {
        const wrapper = await openCreate();

        await channelBox(wrapper, 'BANK').setValue(true);
        await channelBox(wrapper, 'BANK').setValue(false);

        expect(await submittedChannels(wrapper)).toEqual(['CARD']);
    });

    it('ปิดบัตรจากฟอร์มไม่ได้', async () => {
        const card = (await openCreate()).find('input[type="checkbox"][disabled]');

        expect(card.element.checked).toBe(true);
    });

    it('ไม่มีตัวเลือก USDT ทางเก่าหรือ STRIPE ให้ติ๊ก', async () => {
        const wrapper = await openCreate();

        ['USDT', 'BNB', 'STRIPE'].forEach((value) => expect(channelBox(wrapper, value).exists()).toBe(false));
    });

    it('แก้รอบเดิมเห็นช่องทางที่เปิดอยู่', async () => {
        const wrapper = await openEdit(['CARD', 'BANK']);

        expect(channelBox(wrapper, 'BANK').element.checked).toBe(true);
        expect(channelBox(wrapper, 'CRYPTO').element.checked).toBe(false);
        expect(await submittedChannels(wrapper)).toEqual(['CARD', 'BANK']);
    });

    it.each([
        ['ค่าเก่าที่เลิกรับแล้ว', ['USDT', 'STRIPE']],
        ['ไม่เคยตั้งค่า', null],
    ])('แก้รอบเดิมที่มี%sยังบันทึกได้ เพราะฟอร์มส่งแค่ค่าที่เซิร์ฟเวอร์รับ', async (_label, saved) => {
        expect(await submittedChannels(await openEdit(saved))).toEqual(['CARD']);
    });

    it('เปิดฟอร์มสร้างหลังแก้รอบเดิมไม่พาช่องทางของรอบนั้นมาด้วย', async () => {
        const wrapper = await openEdit(['CARD', 'BANK']);

        await wrapper.findAll('button').find((button) => button.text() === 'Cancel').trigger('click');
        await wrapper.findAll('button').find((button) => button.text().includes('New Sale')).trigger('click');

        expect(await submittedChannels(wrapper)).toEqual(['CARD']);
    });

    it.each([
        [['CARD'], 'Stripe'],
        [['CARD', 'CRYPTO'], 'Stripe + USDT'],
        [['CARD', 'BANK', 'CRYPTO'], 'Stripe + โอนเงิน + USDT'],
        [['USDT', 'BNB'], 'Stripe'],
        [null, 'Stripe'],
    ])('การ์ดรอบขายบอกช่องทางตามจริง %j', async (saved, label) => {
        const wrapper = await openSales([{ ...existingSale, accept_currencies: saved }]);

        expect(wrapper.findAll('p').map((p) => p.text())).toContain(label);
    });

    it('บอกว่าไม่รับ USDT แล้วเว้นกระเป๋าว่างได้', async () => {
        expect((await openCreate()).text()).toContain('ถ้าไม่เปิดรับ USDT เว้นว่างได้');
    });

    it('เซิร์ฟเวอร์ปฏิเสธแล้วผู้ใช้เห็นข้อความในฟอร์ม', async () => {
        const wrapper = await openCreate();

        formErrors.accept_currencies = 'ช่องทางรับเงินไม่ถูกต้อง';
        formErrors.sale_wallet_address = 'ต้องกรอกกระเป๋า';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('ช่องทางรับเงินไม่ถูกต้อง');
        expect(wrapper.text()).toContain('ต้องกรอกกระเป๋า');
    });
});
