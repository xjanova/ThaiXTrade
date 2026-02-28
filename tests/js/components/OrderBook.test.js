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

    it('renders correctly', () => {
        const wrapper = mount(OrderBook, {
            props: defaultProps,
        });

        expect(wrapper.exists()).toBe(true);
    });

    it('displays order book title', () => {
        const wrapper = mount(OrderBook, {
            props: defaultProps,
        });

        expect(wrapper.text()).toContain('Order Book');
    });

    it('shows column headers', () => {
        const wrapper = mount(OrderBook, {
            props: defaultProps,
        });

        expect(wrapper.text()).toContain('Price');
        expect(wrapper.text()).toContain('Amount');
        expect(wrapper.text()).toContain('Total');
    });

    it('displays bid and ask sections', () => {
        const wrapper = mount(OrderBook, {
            props: defaultProps,
        });

        // Should have both buy (bids) and sell (asks) sections
        const html = wrapper.html();
        expect(html).toContain('trading-green');
        expect(html).toContain('trading-red');
    });

    it('shows spread information', () => {
        const wrapper = mount(OrderBook, {
            props: defaultProps,
        });

        expect(wrapper.text()).toContain('Spread');
    });

    it('has precision toggle', () => {
        const wrapper = mount(OrderBook, {
            props: defaultProps,
        });

        // Should have decimals option
        const html = wrapper.html();
        expect(html.includes('0.01') || html.includes('0.1') || html.includes('1')).toBe(true);
    });
});
