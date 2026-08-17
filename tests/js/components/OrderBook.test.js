/**
 * TPIX TRADE - OrderBook Component Tests
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import OrderBook from '@/Components/Trading/OrderBook.vue';

describe('OrderBook Component', () => {
    const defaultProps = {
        symbol: 'BTC/USDT',
    };

    // asks เรียงจากราคาต่ำสุด, bids เรียงจากราคาสูงสุด (รูปแบบเดียวกับฟีด Binance)
    const propsWithData = {
        symbol: 'BTC/USDT',
        tickerPrice: 70050,
        asks: [
            { price: 70100, amount: 0.5, total: 35050, depth: 50 },
            { price: 70200, amount: 0.3, total: 21060, depth: 30 },
        ],
        bids: [
            { price: 70000, amount: 0.8, total: 56000, depth: 80 },
            { price: 69900, amount: 0.4, total: 27960, depth: 40 },
        ],
    };

    it('renders correctly', () => {
        const wrapper = mount(OrderBook, { props: defaultProps });
        expect(wrapper.exists()).toBe(true);
    });

    it('shows column headers with the pair symbols', () => {
        const wrapper = mount(OrderBook, { props: defaultProps });
        expect(wrapper.text()).toContain('Price (USDT)');
        expect(wrapper.text()).toContain('Amount (BTC)');
        expect(wrapper.text()).toContain('Sum');
    });

    it('shows empty state when no data', () => {
        const wrapper = mount(OrderBook, { props: defaultProps });
        expect(wrapper.text()).toContain('No order book data');
    });

    it('displays bid and ask data when provided', () => {
        const wrapper = mount(OrderBook, { props: propsWithData });
        const html = wrapper.html();
        expect(html).toContain('trading-green');
        expect(html).toContain('trading-red');
    });

    it('computes the spread from the BEST ask, not the worst', () => {
        const wrapper = mount(OrderBook, { props: propsWithData });
        // best ask 70,100 − best bid 70,000 = 100.00 (เดิมหยิบ ask แพงสุดได้ 200)
        expect(wrapper.text()).toContain('Spread');
        expect(wrapper.text()).toContain('$100.00');
    });

    it('offers price-grouping steps scaled to the price', () => {
        const wrapper = mount(OrderBook, { props: propsWithData });
        const steps = wrapper.findAll('button').map(b => b.text());
        expect(steps).toContain('0.01');
        expect(steps).toContain('10');
    });

    it('merges rows into one bucket when a coarser step is picked', async () => {
        // ราคาห่างกัน $5 → ขั้น 0.01 แยกกัน, ขั้น 10 ยุบเป็นช่องเดียวทั้งสองฝั่ง
        const wrapper = mount(OrderBook, {
            props: {
                symbol: 'BTC/USDT',
                tickerPrice: 70050,
                asks: [
                    { price: 70091, amount: 0.5, total: 35045 },
                    { price: 70095, amount: 0.3, total: 21028 },
                ],
                bids: [
                    { price: 70005, amount: 0.8, total: 56004 },
                    { price: 70001, amount: 0.4, total: 28000 },
                ],
            },
        });

        expect(wrapper.findAll('.book-row')).toHaveLength(4);

        const coarsest = wrapper.findAll('button').find(b => b.text() === '10');
        await coarsest.trigger('click');

        expect(wrapper.findAll('.book-row')).toHaveLength(2);
    });

    it('emits price and cumulative amount when a row is clicked', async () => {
        const wrapper = mount(OrderBook, { props: propsWithData });

        // แถวสุดท้ายของฝั่ง bid = ราคาต่ำสุด → ปริมาณสะสม 0.8 + 0.4
        const rows = wrapper.findAll('.book-row');
        await rows[rows.length - 1].trigger('click');

        const payload = wrapper.emitted('select-price')[0][0];
        expect(payload.price).toBe(69900);
        expect(payload.amount).toBeCloseTo(1.2, 6);
        expect(payload.side).toBe('buy');
    });

    it('keeps every ask row reachable when the card is short', () => {
        // `justify-end` บนกล่องที่ scroll ได้ ทำให้แถวล้นออกด้านบนแล้วเลื่อนไปหาไม่ได้
        // (scrollHeight = clientHeight) — jsdom ไม่มี layout จึงตรวจที่สัญญาของคลาสแทน
        const wrapper = mount(OrderBook, { props: propsWithData });
        const asksBox = wrapper.findAll('.overflow-y-auto')[0];

        expect(asksBox.classes()).not.toContain('justify-end');
        expect(asksBox.find('.mt-auto').exists()).toBe(true);
        expect(asksBox.findAll('.book-row').length).toBe(2);
    });

    it('shows the buy/sell pressure split', () => {
        const wrapper = mount(OrderBook, { props: propsWithData });
        // bids 1.2 / (1.2 + 0.8) = 60%
        expect(wrapper.text()).toContain('B 60%');
        expect(wrapper.text()).toContain('40% S');
    });
});
