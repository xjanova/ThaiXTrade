/**
 * TPIX TRADE — TradeForm: การเติมราคาจากสมุดคำสั่ง
 *
 * บั๊กเดิมที่เทสต์ชุดนี้กันไม่ให้กลับมา:
 *  1. watcher เช็ค `orderType === 'limit'` ก่อนเติมราคา แต่โหมด onchain ถูกบังคับ
 *     เป็น market เสมอ → คลิกราคาในสมุดคำสั่งแล้วไม่มีอะไรเกิดขึ้น
 *  2. เทียบเฉพาะค่าราคา → คลิก "ราคาเดิมซ้ำ" ไม่ทำให้ฟอร์มขยับ
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import TradeForm from '@/Components/Trading/TradeForm.vue';

// ฟอร์มยิง /api/v1/swap/routes ตอน mount — คุม axios ไว้ไม่ให้ยิงจริงในเทสต์
vi.mock('axios', () => ({
    default: { get: vi.fn(() => Promise.resolve({ data: { success: false } })) },
}));

const baseProps = {
    symbol: 'BTC/USDT',
    tickerPrice: 67234.5,
    isWalletConnected: true,
};

/** ช่อง Price คือ input ตัวแรกของฟอร์มเสมอ */
const priceInput = wrapper => wrapper.findAll('input[type="text"]')[0];
const amountInput = wrapper => wrapper.findAll('input[type="text"]')[1];
const totalInput = wrapper => wrapper.findAll('input[type="text"]')[2];

describe('TradeForm — click-to-fill price', () => {
    it('fills the price field in onchain (market-only) mode', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, mode: 'onchain' } });

        await wrapper.setProps({ selectedPrice: { price: 69123.45, amount: 0.25, nonce: 1 } });

        expect(priceInput(wrapper).element.value).toBe('69,123.45');
        expect(amountInput(wrapper).element.value).toBe('0.250000');
    });

    it('switches to Limit automatically when limit orders are available', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, mode: 'internal' } });

        const marketButton = wrapper.findAll('button').find(b => b.text().trim() === 'Market');
        await marketButton.trigger('click');
        expect(priceInput(wrapper).attributes('disabled')).toBeDefined();

        await wrapper.setProps({ selectedPrice: { price: 70000, nonce: 1 } });

        // กลับมาแก้ราคาได้ = สลับเป็น Limit ให้แล้ว
        expect(priceInput(wrapper).attributes('disabled')).toBeUndefined();
        expect(priceInput(wrapper).element.value).toBe('70,000.00');
    });

    it('reacts to clicking the SAME price twice (nonce guard)', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, mode: 'onchain' } });

        await wrapper.setProps({ selectedPrice: { price: 70000, amount: 0.5, nonce: 1 } });
        await amountInput(wrapper).setValue('9');

        await wrapper.setProps({ selectedPrice: { price: 70000, amount: 0.5, nonce: 2 } });

        expect(amountInput(wrapper).element.value).toBe('0.500000');
    });

    it('accepts a bare number for backwards compatibility', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, mode: 'internal' } });

        await wrapper.setProps({ selectedPrice: 12345.6 });

        expect(priceInput(wrapper).element.value).toBe('12,345.60');
    });

    it('ignores a zero or invalid price', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, mode: 'internal' } });
        const before = priceInput(wrapper).element.value;

        await wrapper.setProps({ selectedPrice: { price: 0, nonce: 1 } });

        expect(priceInput(wrapper).element.value).toBe(before);
    });
});

describe('TradeForm — editable total', () => {
    it('derives the amount when the user types a budget into Total', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, mode: 'internal' } });

        await priceInput(wrapper).setValue('100');
        await totalInput(wrapper).setValue('250');

        expect(amountInput(wrapper).element.value).toBe('2.500000');
    });

    it('warns when the order exceeds the available balance', async () => {
        const wrapper = mount(TradeForm, {
            props: {
                ...baseProps,
                mode: 'internal',
                balances: [{ symbol: 'USDT', balance: '100' }],
            },
        });

        await priceInput(wrapper).setValue('100');
        await totalInput(wrapper).setValue('500');

        expect(wrapper.text()).toContain('Above your balance');
    });
});

describe('TradeForm — slippage', () => {
    it('sends the chosen slippage with the order in onchain mode', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, mode: 'onchain' } });

        const slippageButton = wrapper.findAll('button').find(b => b.text().trim() === '2%');
        await slippageButton.trigger('click');

        const submit = wrapper.findAll('button').find(b => b.text().includes('Buy BTC'));
        await submit.trigger('click');

        expect(wrapper.emitted('submit-order')[0][0].slippage).toBe(2);
    });

    it('defaults to auto slippage (null) when untouched', async () => {
        const wrapper = mount(TradeForm, { props: { ...baseProps, mode: 'onchain' } });

        const submit = wrapper.findAll('button').find(b => b.text().includes('Buy BTC'));
        await submit.trigger('click');

        expect(wrapper.emitted('submit-order')[0][0].slippage).toBeNull();
    });
});
