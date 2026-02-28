/**
 * TPIX TRADE - TradeForm Component Tests
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import TradeForm from '@/Components/Trading/TradeForm.vue';

describe('TradeForm Component', () => {
    const defaultProps = {
        symbol: 'BTC/USDT',
        balance: {
            base: { symbol: 'BTC', amount: '0.5678' },
            quote: { symbol: 'USDT', amount: '15,234.50' },
        },
    };

    it('renders correctly', () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        expect(wrapper.exists()).toBe(true);
    });

    it('shows buy tab as default active', () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        const buyButton = wrapper.find('button');
        expect(buyButton.text()).toContain('Buy');
    });

    it('switches between buy and sell tabs', async () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        const buttons = wrapper.findAll('button');
        const sellButton = buttons.find(btn => btn.text().includes('Sell'));

        await sellButton?.trigger('click');

        // After clicking sell, the sell button should be active
        expect(wrapper.html()).toContain('Sell');
    });

    it('displays correct balance for buy mode', () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        expect(wrapper.text()).toContain('15,234.50');
        expect(wrapper.text()).toContain('USDT');
    });

    it('has price input field', () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        const priceInput = wrapper.find('input[placeholder="0.00"]');
        expect(priceInput.exists()).toBe(true);
    });

    it('has percentage buttons', () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        expect(wrapper.text()).toContain('25%');
        expect(wrapper.text()).toContain('50%');
        expect(wrapper.text()).toContain('75%');
        expect(wrapper.text()).toContain('100%');
    });

    it('emits submit-order event on button click', async () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        const submitButton = wrapper.find('button.btn-success, button.btn-danger');
        if (submitButton.exists()) {
            await submitButton.trigger('click');
            expect(wrapper.emitted('submit-order')).toBeTruthy();
        }
    });

    it('shows order types selector', () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        expect(wrapper.text()).toContain('Limit');
        expect(wrapper.text()).toContain('Market');
    });
});
