/**
 * TPIX TRADE — TradeForm โหมด onchain (market order ที่ลงเชนจริง)
 *
 * บั๊กที่เทสต์ชุดนี้กันไม่ให้กลับมา (ออดิท 2026-10-21):
 *  - ช่องราคาถูกตั้งครั้งเดียวตอนเปิดหน้า → ซื้อด้วยจำนวนเหรียญแล้วจ่าย Total ที่คิดจากราคาเก่า
 *  - จำนวนเกินยอดแค่ขึ้นตัวหนังสือเตือน แต่ยังกดได้ → สวอปผ่าน ค่าธรรมเนียม revert เงียบ
 *  - ซื้อบนเชนบอกว่าต้องจ่าย "Total + ค่าธรรมเนียม" ทั้งที่ค่าธรรมเนียมหักจากใน Total
 *  - คู่ที่ยังเทรดไม่ได้ (กำลังตรวจ/ถูกปิด/กระเป๋าฝัง) ต้องปิดปุ่มพร้อมบอกเหตุผล
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(async (url) => (url === '/api/v1/market/pairs'
            ? { data: { success: true, data: [{ base_asset: 'BTC', quote_asset: 'USDT', fee_rate: 0.3 }] } }
            : { data: { success: false } })),
        post: vi.fn(async () => ({ data: { success: false } })),
    },
}));

const TradeForm = (await import('@/Components/Trading/TradeForm.vue')).default;

const baseProps = {
    symbol: 'BTC/USDT',
    tickerPrice: 60000,
    isWalletConnected: true,
    mode: 'onchain',
};

const amountInput = w => w.findAll('input[type="text"]')[1];
const totalInput = w => w.findAll('input[type="text"]')[2];
const submit = w => w.find('[data-test="submit-order"]');

describe('TradeForm — onchain market order', () => {
    it('re-prices Total from the live market price instead of the price at page load', async () => {
        const wrapper = mount(TradeForm, { props: baseProps });
        await amountInput(wrapper).setValue('0.1');
        expect(totalInput(wrapper).element.value).toBe('6,000.00');

        // ราคาขยับ 5% → ช่องที่ตามราคา (Total) ต้องคิดใหม่ ช่องที่ผู้ใช้กรอก (Amount) ไม่แตะ
        await wrapper.setProps({ tickerPrice: 63000 });
        expect(totalInput(wrapper).element.value).toBe('6,300.00');
        expect(amountInput(wrapper).element.value).toBe('0.1');
    });

    it('recomputes Total at the moment of submit, even for a small move', async () => {
        const wrapper = mount(TradeForm, { props: baseProps });
        await amountInput(wrapper).setValue('0.1');

        // ขยับน้อยกว่าเกณฑ์ resync (0.2%) — จอยังไม่เปลี่ยน แต่ตอนกดต้องใช้ราคาตอนนี้
        await wrapper.setProps({ tickerPrice: 60060 });
        expect(totalInput(wrapper).element.value).toBe('6,000.00');

        await submit(wrapper).trigger('click');
        expect(wrapper.emitted('submit-order')[0][0].total).toBe('6,006.00');
    });

    it('keeps a typed budget (Total) as the intent when the price moves', async () => {
        const wrapper = mount(TradeForm, { props: baseProps });
        await totalInput(wrapper).setValue('500');

        await wrapper.setProps({ tickerPrice: 50000 });
        expect(totalInput(wrapper).element.value).toBe('500');
        expect(amountInput(wrapper).element.value).toBe('0.010000');
    });

    it('blocks the order when the amount is above the balance', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, balances: [{ symbol: 'USDT', balance: '100' }] } });
        await totalInput(wrapper).setValue('100.2');

        expect(submit(wrapper).attributes('disabled')).toBeDefined();
        expect(submit(wrapper).text()).toBe('Insufficient USDT balance');
        await submit(wrapper).trigger('click');
        expect(wrapper.emitted('submit-order')).toBeUndefined();
    });

    it('blocks a zero balance too (a known zero is not "unknown")', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, balances: [{ symbol: 'USDT', balance: '0' }] } });
        await totalInput(wrapper).setValue('10');

        expect(submit(wrapper).attributes('disabled')).toBeDefined();
    });

    it('keeps BNB for gas when selling almost the whole balance', async () => {
        const wrapper = mount(TradeForm, {
            props: { ...baseProps, symbol: 'BNB/USDT', tickerPrice: 600, balances: [{ symbol: 'BNB', balance: '1' }] },
        });
        await wrapper.findAll('button').find(b => b.text().trim() === 'Sell').trigger('click');
        await amountInput(wrapper).setValue('0.999');

        expect(wrapper.text()).toContain('Keep at least 0.003 BNB for network fees');
        expect(submit(wrapper).attributes('disabled')).toBeDefined();
    });

    it('shows that a buy pays exactly Total (the fee is taken from inside it)', async () => {
        const wrapper = mount(TradeForm, { props: baseProps });
        await flushPromises();   // อัตราค่าธรรมเนียมจาก /market/pairs
        await totalInput(wrapper).setValue('100');

        expect(wrapper.text()).toContain('0.3000 USDT');
        expect(wrapper.text()).toContain('You pay in total');
        expect(wrapper.text()).toContain('100.00 USDT');
        expect(wrapper.text()).not.toContain('100.30');
    });

    it('disables the order with the reason the page gives (pair not tradable / embedded wallet)', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, unavailableReason: "This pair isn't open for trading right now." } });
        await totalInput(wrapper).setValue('10');

        expect(submit(wrapper).attributes('disabled')).toBeDefined();
        expect(submit(wrapper).text()).toBe('Trading unavailable');
        expect(wrapper.find('[data-test="unavailable-reason"]').text()).toBe("This pair isn't open for trading right now.");
        await submit(wrapper).trigger('click');
        expect(wrapper.emitted('submit-order')).toBeUndefined();
    });

    it('still lets a disconnected visitor press Connect Wallet while the pair is being checked', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, isWalletConnected: false, unavailableReason: 'Checking…' } });

        expect(submit(wrapper).attributes('disabled')).toBeUndefined();
        await submit(wrapper).trigger('click');
        expect(wrapper.emitted('connect-wallet')).toHaveLength(1);
    });

    it('tells the page which fee method is selected so the preview can waive the % fee', async () => {
        const wrapper = mount(TradeForm, { props: baseProps });
        await totalInput(wrapper).setValue('50');

        expect(wrapper.emitted('form-change').at(-1)[0].feeMethod).toBe('tpix_credit');
    });
});
