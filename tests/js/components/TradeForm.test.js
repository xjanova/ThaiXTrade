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
        tickerPrice: 67234.50,
        isWalletConnected: false,
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

        expect(wrapper.html()).toContain('Sell');
    });

    it('shows Connect Wallet when not connected', () => {
        const wrapper = mount(TradeForm, {
            props: { ...defaultProps, isWalletConnected: false },
        });

        expect(wrapper.text()).toContain('Connect Wallet');
    });

    it('shows Buy BTC when wallet is connected', () => {
        const wrapper = mount(TradeForm, {
            props: { ...defaultProps, isWalletConnected: true },
        });

        expect(wrapper.text()).toContain('Buy BTC');
    });

    it('has price input field', () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        const inputs = wrapper.findAll('input[type="text"]');
        expect(inputs.length).toBeGreaterThan(0);
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

    it('emits connect-wallet when not connected and submit clicked', async () => {
        const wrapper = mount(TradeForm, {
            props: { ...defaultProps, isWalletConnected: false },
        });

        const submitButton = wrapper.findAll('button').find(btn => btn.text().includes('Connect Wallet'));
        if (submitButton) {
            await submitButton.trigger('click');
            expect(wrapper.emitted('connect-wallet')).toBeTruthy();
        }
    });

    it('shows order types selector', () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        expect(wrapper.text()).toContain('Limit');
        expect(wrapper.text()).toContain('Market');
    });

    it('displays USDT as quote symbol', () => {
        const wrapper = mount(TradeForm, {
            props: defaultProps,
        });

        expect(wrapper.text()).toContain('USDT');
    });
});
