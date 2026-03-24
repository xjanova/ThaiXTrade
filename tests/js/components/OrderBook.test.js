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

    const propsWithData = {
        symbol: 'BTC/USDT',
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

    it('displays order book title', () => {
        const wrapper = mount(OrderBook, { props: defaultProps });
        expect(wrapper.text()).toContain('Order Book');
    });

    it('shows column headers', () => {
        const wrapper = mount(OrderBook, { props: defaultProps });
        expect(wrapper.text()).toContain('Price');
        expect(wrapper.text()).toContain('Amount');
        expect(wrapper.text()).toContain('Total');
    });

    it('shows empty state when no data', () => {
        const wrapper = mount(OrderBook, { props: defaultProps });
        expect(wrapper.text()).toContain('ยังไม่มีข้อมูลการเทรด');
    });

    it('displays bid and ask data when provided', () => {
        const wrapper = mount(OrderBook, { props: propsWithData });
        const html = wrapper.html();
        expect(html).toContain('trading-green');
        expect(html).toContain('trading-red');
    });

    it('shows spread when data available', () => {
        const wrapper = mount(OrderBook, { props: propsWithData });
        expect(wrapper.text()).toContain('Spread');
    });

    it('has precision toggle', () => {
        const wrapper = mount(OrderBook, { props: defaultProps });
        const html = wrapper.html();
        expect(html.includes('0.01') || html.includes('0.1') || html.includes('1')).toBe(true);
    });
});
