/**
 * TPIX TRADE - OrderBook: เหรียญราคาจิ๋ว + ทศนิยมของราคา
 *
 *  - PEPE 0.00000394: ขั้นรวมราคาเคยหยาบกว่าราคาเอง → ฝั่งซื้อถูกปัดเป็น 0 แล้วคลิกส่งราคา 0 เข้าฟอร์ม
 *  - XRP 1.4035/1.4036/1.4037: ราคา ≥ 1 เคยแสดง 2 ตำแหน่งเสมอ → ขึ้น "1.40" ทั้งสามแถว
 *  - สเปรดเคยคิดจากราคาที่รวมช่องแล้ว และปัด toFixed(2) → เหรียญต่ำกว่าเซ็นต์ได้ $0.00
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import OrderBook from '@/Components/Trading/OrderBook.vue';

const row = (price, amount) => ({ price, amount, total: price * amount });

/** ปุ่มเลือกขั้นราคา = ปุ่มที่ไม่ใช่แถวในสมุด และข้อความเป็นตัวเลขล้วน */
const stepButtons = wrapper => wrapper.findAll('button')
    .filter(b => !b.classes().includes('book-row') && /^[\d.,]+$/.test(b.text()));

const rowPrices = wrapper => wrapper.findAll('.book-row').map(r => r.findAll('span')[1].text());

describe('OrderBook — tiny-price pairs (PEPE)', () => {
    const pepe = {
        symbol: 'PEPE/USDT',
        tickerPrice: 0.00000394,
        asks: [row(0.00000395, 1_000_000), row(0.00000396, 2_000_000)],
        bids: [row(0.00000394, 3_000_000), row(0.00000393, 4_000_000)],
    };

    it('never offers a grouping step coarser than a tenth of the price', () => {
        const wrapper = mount(OrderBook, { props: pepe });
        const steps = stepButtons(wrapper).map(b => Number(b.text()));

        expect(steps.length).toBeGreaterThan(0);
        steps.forEach(s => expect(s).toBeLessThanOrEqual(pepe.tickerPrice / 10));
    });

    it('keeps every grouped bid above zero and never emits price 0', async () => {
        const wrapper = mount(OrderBook, { props: pepe });
        const buttons = stepButtons(wrapper);
        await buttons.at(-1).trigger('click'); // ขั้นหยาบที่สุดที่มีให้เลือก

        expect(rowPrices(wrapper).every(text => Number(text) > 0)).toBe(true);

        for (const r of wrapper.findAll('.book-row')) await r.trigger('click');
        const emitted = wrapper.emitted('select-price').map(([payload]) => payload.price);
        expect(emitted.length).toBeGreaterThan(0);
        emitted.forEach(price => expect(price).toBeGreaterThan(0));
    });

    it('shows full precision for rows, mid price and the sub-cent spread', () => {
        const wrapper = mount(OrderBook, { props: pepe });
        const text = wrapper.text();

        expect(rowPrices(wrapper)).toEqual(['0.00000396', '0.00000395', '0.00000394', '0.00000393']);
        expect(text).toContain('$0.00000394');
        expect(text).toContain('$0.00000001'); // สเปรด 1 tick — เดิมขึ้น $0.00
    });
});

describe('OrderBook — price label precision', () => {
    it('keeps adjacent XRP levels distinguishable', () => {
        const wrapper = mount(OrderBook, {
            props: {
                symbol: 'XRP/USDT',
                tickerPrice: 1.4035,
                asks: [row(1.4035, 100), row(1.4036, 200), row(1.4037, 300)],
                bids: [row(1.4034, 100), row(1.4033, 200)],
            },
        });

        const prices = rowPrices(wrapper);
        expect(prices).toEqual(['1.4037', '1.4036', '1.4035', '1.4034', '1.4033']);
        expect(new Set(prices).size).toBe(prices.length);
    });

    it('drops decimals the selected step cannot show (BTC grouped by 10)', async () => {
        const wrapper = mount(OrderBook, {
            props: {
                symbol: 'BTC/USDT',
                tickerPrice: 70000.01,
                asks: [row(70000.01, 0.5), row(70003.55, 0.2)],
                bids: [row(70000, 0.8), row(69995.12, 0.1)],
            },
        });

        expect(rowPrices(wrapper)).toContain('70,000.01');

        await stepButtons(wrapper).find(b => b.text() === '10').trigger('click');
        expect(rowPrices(wrapper)).toEqual(['70,010', '70,000', '69,990']);
    });

    it('computes the spread from raw best bid/ask, not the grouped levels', async () => {
        const wrapper = mount(OrderBook, {
            props: {
                symbol: 'BTC/USDT',
                tickerPrice: 70000.01,
                asks: [row(70000.01, 0.5)],
                bids: [row(70000, 0.8)],
            },
        });

        await stepButtons(wrapper).find(b => b.text() === '10').trigger('click');
        // ช่องรวมคือ 70,010 / 70,000 แต่ตลาดจริงห่างกันแค่ 0.01
        expect(wrapper.text()).toContain('$0.01');
        expect(wrapper.text()).not.toContain('$10.00');
    });
});
