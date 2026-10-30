/**
 * TPIX TRADE — ปุ่มกู้ยอด claim (/admin/token-sales)
 *
 * รายการจ่ายจาก /token-sale/claim ที่ถูกปฏิเสธหรือ revert ทำให้เหรียญค้างใน claimed_amount
 * ปุ่มนี้คือทางให้แอดมินเข้าคิวใหม่ — เซิร์ฟเวอร์ส่งยอดมาเฉพาะแถวที่พิสูจน์ได้ว่าเงินไม่ออก
 *
 * กัน: ปุ่มโผล่กับออเดอร์ที่ไม่มีอะไรให้กู้ · กดแล้วส่งเลยโดยไม่เห็นยอด
 *      ไม่บอกว่าส่วนเกินวงเงินคืนให้ผู้ซื้อ · กดรัวแล้วยิงซ้ำ · ปุ่มออเดอร์โอนหายเมื่อมีทั้งสองปุ่ม
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

const CLAIM_LABEL = 'เข้าคิว claim ใหม่';
const BANK_LABEL = 'เข้าคิวจ่ายใหม่';

const order = (overrides = {}) => ({
    id: 7,
    uuid: 'b2f3c1de-0000-4000-8000-000000000007',
    status: 'confirmed',
    wallet_address: '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd',
    tpix_amount: '1000.000000000000000000',
    payment_usd_value: '100.00',
    payment_currency: 'USD',
    created_at: '2026-10-30T00:00:00.000000Z',
    metadata: { method: 'stripe' },
    requeue_payout_tpix: null,
    requeue_claim_tpix: null,
    requeue_claim_returned_tpix: null,
    ...overrides,
});

async function openTransactions(rows) {
    const wrapper = mount(TokenSalesIndex, { props: { transactions: { data: rows } } });
    await wrapper.findAll('button').find((b) => b.text().includes('Transactions')).trigger('click');

    return wrapper;
}

const buttonLabelled = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label);

describe('Admin/TokenSales/Index — กู้ยอด claim', () => {
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

        expect(buttonLabelled(wrapper, CLAIM_LABEL)).toBeUndefined();
    });

    it('บอกยอดก่อนส่ง แล้วยิงไปที่ออเดอร์นั้น', async () => {
        const wrapper = await openTransactions([order({ requeue_claim_tpix: '300' })]);

        await buttonLabelled(wrapper, CLAIM_LABEL).trigger('click');

        expect(confirmSpy.mock.calls[0][0]).toContain('300 TPIX');
        expect(confirmSpy.mock.calls[0][0]).toContain('b2f3c1de-0000-4000-8000-000000000007');
        expect(confirmSpy.mock.calls[0][0]).not.toContain('คืนเป็นสิทธิ์');
        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][0]).toBe('/admin/token-sales/claims/7/requeue');
    });

    it('แถวเกินวงเงิน: กล่องยืนยันบอกยอดที่คืนให้ผู้ซื้อ', async () => {
        const wrapper = await openTransactions([order({ requeue_claim_tpix: '120', requeue_claim_returned_tpix: '180' })]);

        await buttonLabelled(wrapper, CLAIM_LABEL).trigger('click');

        expect(confirmSpy.mock.calls[0][0]).toContain('120 TPIX');
        expect(confirmSpy.mock.calls[0][0]).toContain('180 TPIX จะคืนเป็นสิทธิ์');
    });

    it('กดยกเลิกในกล่องยืนยันต้องไม่ส่งอะไร', async () => {
        confirmSpy.mockReturnValue(false);
        const wrapper = await openTransactions([order({ requeue_claim_tpix: '300' })]);

        await buttonLabelled(wrapper, CLAIM_LABEL).trigger('click');

        expect(routerPost).not.toHaveBeenCalled();
    });

    it('กดรัวระหว่างรอผลต้องส่งครั้งเดียว แล้วกดใหม่ได้เมื่อจบ', async () => {
        const wrapper = await openTransactions([order({ requeue_claim_tpix: '300' })]);

        await buttonLabelled(wrapper, CLAIM_LABEL).trigger('click');
        await buttonLabelled(wrapper, CLAIM_LABEL).trigger('click');
        expect(routerPost).toHaveBeenCalledTimes(1);

        routerPost.mock.calls[0][2].onFinish();
        await wrapper.vm.$nextTick();

        await buttonLabelled(wrapper, CLAIM_LABEL).trigger('click');
        expect(routerPost).toHaveBeenCalledTimes(2);
    });

    it('ออเดอร์โอนที่ติดทั้งสองทางเห็นครบสองปุ่ม และแต่ละปุ่มยิงคนละ route', async () => {
        const wrapper = await openTransactions([order({
            metadata: { method: 'bank_transfer', reference: 'TPIX-ABC234' },
            requeue_payout_tpix: '200',
            requeue_claim_tpix: '300',
        })]);

        await buttonLabelled(wrapper, BANK_LABEL).trigger('click');
        routerPost.mock.calls[0][2].onFinish();
        await wrapper.vm.$nextTick();
        await buttonLabelled(wrapper, CLAIM_LABEL).trigger('click');

        expect(routerPost.mock.calls.map((call) => call[0])).toEqual([
            '/admin/token-sales/bank/7/requeue',
            '/admin/token-sales/claims/7/requeue',
        ]);
    });
});
