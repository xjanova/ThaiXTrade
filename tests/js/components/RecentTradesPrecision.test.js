/**
 * TPIX TRADE - RecentTrades: ทศนิยมของราคา + จุดสถานะ "ข้อมูลสด"
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import RecentTrades from '@/Components/Trading/RecentTrades.vue';

const trade = (id, price, isBuy = true) => ({ id, price, amount: 1, time: '12:00:00', isBuy });

describe('RecentTrades', () => {
    it('shows XRP trades with enough decimals to tell them apart', () => {
        const wrapper = mount(RecentTrades, {
            props: { symbol: 'XRP/USDT', trades: [trade(1, 1.4035), trade(2, 1.4036, false), trade(3, 1.4037)] },
        });
        const text = wrapper.text();

        expect(text).toContain('1.4035');
        expect(text).toContain('1.4036');
        expect(text).toContain('1.4037');
    });

    it('does not collapse sub-cent prices to zero', () => {
        const wrapper = mount(RecentTrades, {
            props: { symbol: 'PEPE/USDT', trades: [trade(1, 0.00000394), trade(2, 0.00000395)] },
        });

        expect(wrapper.text()).toContain('0.00000394');
        expect(wrapper.text()).toContain('0.00000395');
    });

    it('keeps the live dot green by default (existing callers unchanged)', () => {
        const wrapper = mount(RecentTrades, { props: { symbol: 'BTC/USDT' } });
        const dot = wrapper.find('[data-testid="live-dot"]');

        expect(dot.classes()).toContain('bg-trading-green');
        expect(dot.attributes('title')).toBe('Live');
    });

    it('greys the dot out while the stream is not delivering data', () => {
        const wrapper = mount(RecentTrades, { props: { symbol: 'BTC/USDT', live: false } });
        const dot = wrapper.find('[data-testid="live-dot"]');

        expect(dot.classes()).not.toContain('bg-trading-green');
        expect(dot.classes()).not.toContain('animate-pulse');
        expect(dot.attributes('title')).toBe('Connecting to live data…');
    });
});
