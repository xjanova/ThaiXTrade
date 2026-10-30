/**
 * TPIX TRADE — ปุ่มเข้าคิวจ่ายใหม่ของออเดอร์โอนธนาคาร (/admin/token-sales)
 *
 * หลังยืนยันเงินเข้าแล้ว ปุ่มยืนยันหายไป ถ้ารายการจ่ายถูกปฏิเสธหรือ revert เหรียญจะค้างใน claimed_amount
 * ปุ่มนี้คือทางให้แอดมินสั่งจ่ายใหม่ — เซิร์ฟเวอร์ส่งยอดมาเฉพาะออเดอร์ที่กดได้จริง
 *
 * กัน: ปุ่มโผล่กับออเดอร์ที่ยังมีรายการกำลังจ่าย · กดแล้วส่งเลยโดยไม่เห็นยอด · กดรัวแล้วยิงซ้ำ
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

const routerPost = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: r } = await import('vue');

    return {
        Head: { template: '<div />' },
        router: { post: (...args) => routerPost(...args) },
        useForm: (data) => r({ ...data, errors: {}, processing: false, reset() {}, clearErrors() {} }),
    };
});
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Admin/SaleLaunchPanel.vue', () => ({ default: { template: '<div />' } }));

import TokenSalesIndex from '@/Pages/Admin/TokenSales/Index.vue';

const REQUEUE_LABEL = 'เข้าคิวจ่ายใหม่';

const order = (overrides = {}) => ({
    id: 7,
    uuid: 'b2f3c1de-0000-4000-8000-000000000007',
    status: 'confirmed',
    wallet_address: '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd',
    tpix_amount: '1000.000000000000000000',
    payment_usd_value: '100.00',
    payment_currency: 'USD_BANK',
    created_at: '2026-10-30T00:00:00.000000Z',
    metadata: { method: 'bank_transfer', reference: 'TPIX-ABC234' },
    requeue_payout_tpix: null,
    ...overrides,
});

async function openTransactions(rows) {
    const wrapper = mount(TokenSalesIndex, { props: { transactions: { data: rows }, canManageMoney: true } });
    await wrapper.findAll('button').find((b) => b.text().includes('Transactions')).trigger('click');

    return wrapper;
}

const requeueButton = (wrapper) => wrapper.findAll('button').find((b) => b.text() === REQUEUE_LABEL);

describe('Admin/TokenSales/Index — เข้าคิวจ่ายใหม่', () => {
    let confirmSpy;

    beforeEach(() => {
        routerPost.mockReset();
        confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    afterEach(() => {
        confirmSpy.mockRestore();
    });

    it('ไม่มีปุ่มเมื่อเซิร์ฟเวอร์ไม่ได้ส่งยอดมา', async () => {
        const wrapper = await openTransactions([order()]);

        expect(requeueButton(wrapper)).toBeUndefined();
    });

    it('ออเดอร์รอยืนยันยังเห็นแค่ปุ่มยืนยันกับปฏิเสธ', async () => {
        const wrapper = await openTransactions([order({ status: 'pending' })]);

        expect(wrapper.text()).toContain('ได้รับเงินแล้ว');
        expect(requeueButton(wrapper)).toBeUndefined();
    });

    it('ออเดอร์เก่าไม่มีเวลาหมดอายุ ต้องคิดจากวันที่สร้างแบบเดียวกับหลังบ้าน', async () => {
        const hoursAgo = (h) => new Date(Date.now() - h * 3600 * 1000).toISOString();
        const confirmOrder = async (created_at) => {
            routerPost.mockReset();
            const wrapper = await openTransactions([order({ status: 'pending', created_at })]);
            await wrapper.findAll('button').find((b) => b.text().includes('ได้รับเงินแล้ว')).trigger('click');

            return routerPost.mock.calls[0][1];
        };

        expect(await confirmOrder(hoursAgo(49))).toEqual({ honor_expired: true });
        expect(await confirmOrder(hoursAgo(47))).toEqual({ honor_expired: false });
    });

    it('บอกยอดและรหัสอ้างอิงก่อนส่ง แล้วยิงไปที่ออเดอร์นั้น', async () => {
        const wrapper = await openTransactions([order({ requeue_payout_tpix: '200' })]);

        await requeueButton(wrapper).trigger('click');

        expect(confirmSpy.mock.calls[0][0]).toContain('200 TPIX');
        expect(confirmSpy.mock.calls[0][0]).toContain('TPIX-ABC234');
        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][0]).toBe('/admin/token-sales/bank/7/requeue');
    });

    it('กดยกเลิกในกล่องยืนยันต้องไม่ส่งอะไร', async () => {
        confirmSpy.mockReturnValue(false);
        const wrapper = await openTransactions([order({ requeue_payout_tpix: '200' })]);

        await requeueButton(wrapper).trigger('click');

        expect(routerPost).not.toHaveBeenCalled();
    });

    it('กดรัวระหว่างรอผลต้องส่งครั้งเดียว แล้วกดใหม่ได้เมื่อจบ', async () => {
        const wrapper = await openTransactions([order({ requeue_payout_tpix: '200' })]);

        await requeueButton(wrapper).trigger('click');
        await requeueButton(wrapper).trigger('click');
        expect(routerPost).toHaveBeenCalledTimes(1);

        routerPost.mock.calls[0][2].onFinish();
        await wrapper.vm.$nextTick();

        await requeueButton(wrapper).trigger('click');
        expect(routerPost).toHaveBeenCalledTimes(2);
    });
});
