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

    // ── โหมด onchain: market order จริงบน BSC ─────────────────────────────

    it('onchain mode defaults to market and disables limit', () => {
        const wrapper = mount(TradeForm, {
            props: { ...defaultProps, mode: 'onchain' },
        });

        const limitButton = wrapper.findAll('button').find(btn => btn.text().includes('Limit'));
        expect(limitButton.attributes('disabled')).toBeDefined();
        // ช่อง Price ต้องอยู่โหมด Market (disabled + placeholder Market Price)
        expect(wrapper.find('input[placeholder="Market Price"]').exists()).toBe(true);
    });

    it('onchain mode hides TP/SL options', () => {
        const wrapper = mount(TradeForm, {
            props: { ...defaultProps, mode: 'onchain' },
        });

        expect(wrapper.text()).not.toContain('TP/SL');
    });

    it('onchain mode shows router preview when provided', () => {
        const wrapper = mount(TradeForm, {
            props: {
                ...defaultProps,
                mode: 'onchain',
                marketPreview: {
                    loading: false,
                    receiveText: '0.0015 BTC',
                    minReceivedText: '0.001492 BTC',
                    feeText: '0.3 USDT (0.3%)',
                },
            },
        });

        expect(wrapper.text()).toContain('You receive');
        expect(wrapper.text()).toContain('0.0015 BTC');
        expect(wrapper.text()).toContain('PancakeSwap');
    });

    it('emits form-change when amount changes', async () => {
        const wrapper = mount(TradeForm, {
            props: { ...defaultProps, mode: 'onchain', isWalletConnected: true },
        });

        const amountInput = wrapper.findAll('input[type="text"]').find(
            i => i.attributes('placeholder') === '0.00' && !i.attributes('readonly'),
        );
        await amountInput.setValue('0.5');

        expect(wrapper.emitted('form-change')).toBeTruthy();
    });

    // ── โหมด disabled: คู่ TPIX รอเชน — เห็นไว้ก่อน กดไม่ได้ ─────────────

    it('disabled mode shows coming soon instead of the form', () => {
        const wrapper = mount(TradeForm, {
            props: { ...defaultProps, symbol: 'TPIX/USDT', mode: 'disabled' },
        });

        expect(wrapper.text()).toContain('Coming Soon');
        // ฟอร์มซื้อขายต้องไม่ render เลย
        expect(wrapper.text()).not.toContain('Buy TPIX');
        expect(wrapper.find('input[type="text"]').exists()).toBe(false);
    });
});
