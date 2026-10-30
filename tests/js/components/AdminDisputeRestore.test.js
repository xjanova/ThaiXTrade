/**
 * TPIX TRADE — กล่องกู้ออเดอร์หลัง chargeback (/admin/token-sales)
 *
 * ร้านยังถือเงินอยู่แต่ผู้ซื้อไม่มีเหรียญ ต้องมีคนตัดสินทุกแถว กล่องนี้จึงอยู่นอกแท็บและนอกตารางแบ่งหน้า
 * เซิร์ฟเวอร์ส่งแถวมาเฉพาะ super_admin และบอกเหตุถ้ากู้ไม่ได้ตั้งแต่ก่อนกด
 *
 * กัน: ปุ่มโผล่กับแถวที่กู้ไม่ได้ · กดแล้วส่งเลยโดยไม่ยืนยัน · กดรัวแล้วยิงซ้ำ
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

const RESTORE_LABEL = 'กู้ออเดอร์';

const order = (overrides = {}) => ({
    id: 9,
    uuid: 'b2f3c1de-0000-4000-8000-000000000009',
    wallet_address: '0xcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd',
    tpix_amount: '5000.000000000000000000',
    payment_usd_value: '500.00',
    restore_refusal: null,
    ...overrides,
});

const open = (rows) => mount(TokenSalesIndex, { props: { disputesAwaitingRestore: rows, canManageMoney: true } });

const restoreButton = (wrapper) => wrapper.findAll('button').find((b) => b.text() === RESTORE_LABEL);

describe('Admin/TokenSales/Index — กู้ออเดอร์หลัง chargeback', () => {
    let confirmSpy;

    beforeEach(() => {
        routerPost.mockReset();
        confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    afterEach(() => {
        confirmSpy.mockRestore();
    });

    it('ไม่มีกล่องเมื่อไม่มีออเดอร์รอตัดสิน', () => {
        expect(open([]).text()).not.toContain('ร้านถือเงินอยู่');
    });

    it('เห็นกล่องได้จากแท็บแรกโดยไม่ต้องเปิดแท็บรายการ', () => {
        expect(restoreButton(open([order()]))).toBeDefined();
    });

    it('แถวที่กู้ไม่ได้บอกเหตุและไม่มีปุ่ม', () => {
        const wrapper = open([order({ restore_refusal: 'ต้องคืนเงินใน Stripe' })]);

        expect(wrapper.text()).toContain('ต้องคืนเงินใน Stripe');
        expect(restoreButton(wrapper)).toBeUndefined();
    });

    it('กดแล้วยืนยันก่อนส่ง และบอกจำนวนเหรียญ', async () => {
        const wrapper = open([order()]);

        await restoreButton(wrapper).trigger('click');

        expect(confirmSpy.mock.calls[0][0]).toContain('5,000');
        expect(routerPost.mock.calls[0][0]).toBe('/admin/token-sales/dispute/9/restore');
    });

    it('ไม่ยืนยัน = ไม่ส่ง', async () => {
        confirmSpy.mockReturnValue(false);
        const wrapper = open([order()]);

        await restoreButton(wrapper).trigger('click');

        expect(routerPost).not.toHaveBeenCalled();
    });

    it('กดรัวส่งครั้งเดียว และกดใหม่ได้เมื่อคำขอจบ', async () => {
        const wrapper = open([order()]);
        const button = restoreButton(wrapper);

        await button.trigger('click');
        expect(button.attributes('disabled')).toBeDefined();

        // test-utils ไม่ปล่อย click จากปุ่ม disabled จึงเรียกตรง ๆ เพื่อพิสูจน์ด่านในฟังก์ชัน
        wrapper.vm.restoreDispute(order());

        expect(routerPost).toHaveBeenCalledTimes(1);

        routerPost.mock.calls[0][2].onFinish();
        await wrapper.vm.$nextTick();
        await button.trigger('click');

        expect(routerPost).toHaveBeenCalledTimes(2);
    });
});
