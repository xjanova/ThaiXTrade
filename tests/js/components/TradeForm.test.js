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

    it('onchain mode defaults to market but limit can be selected', () => {
        const wrapper = mount(TradeForm, {
            props: { ...defaultProps, mode: 'onchain' },
        });

        const limitButton = wrapper.find('[data-test="order-type-limit"]');
        // เดิมแท็บนี้ถูกปิดพร้อมป้าย "Soon" — ตอนนี้ต้องกดได้
        expect(limitButton.attributes('disabled')).toBeUndefined();
        expect(limitButton.text()).not.toContain('Soon');
        // เริ่มที่ Market: ช่อง Price ถูกปิด (placeholder Market Price) และยังไม่มีส่วนของคำสั่งตั้งราคา
        expect(wrapper.find('input[placeholder="Market Price"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="limit-note"]').exists()).toBe(false);
    });

    // ── โหมด onchain: คำสั่งตั้งราคา (limit) ──────────────────────────────

    async function limitForm(extraProps = {}) {
        const wrapper = mount(TradeForm, {
            props: {
                ...defaultProps,
                mode: 'onchain',
                isWalletConnected: true,
                balances: [{ symbol: 'USDT', balance: '5000' }, { symbol: 'BTC', balance: '1' }],
                ...extraProps,
            },
        });
        await wrapper.find('[data-test="order-type-limit"]').trigger('click');

        return wrapper;
    }

    async function fill(wrapper, { price, amount, total }) {
        const [priceInput, amountInput, totalInput] = wrapper.findAll('input[type="text"]');
        if (price !== undefined) {
            await priceInput.setValue(price);
        }
        if (amount !== undefined) {
            await amountInput.setValue(amount);
            await amountInput.trigger('input');
        }
        if (total !== undefined) {
            await totalInput.setValue(total);
            await totalInput.trigger('input');
        }
    }

    it('limit order on an onchain pair unlocks the price field and explains how it executes', async () => {
        const wrapper = await limitForm();

        expect(wrapper.find('input[placeholder="Market Price"]').exists()).toBe(false);
        expect(wrapper.findAll('input[type="text"]')[0].attributes('disabled')).toBeUndefined();
        expect(wrapper.find('[data-test="limit-note"]').text()).toContain('Keep the trade page or the app open');
        expect(wrapper.find('[data-test="limit-expiry"]').text()).toContain('7d');
        expect(wrapper.find('[data-test="submit-order"]').text()).toContain('Place buy order for BTC');
    });

    it('limit buy shows the minimum received at the limit price and submits the limit payload', async () => {
        const wrapper = await limitForm();
        await fill(wrapper, { price: '50000', total: '100' });

        // 100 USDT ÷ 50,000 = 0.002 BTC — ตัวเลขเดียวกับที่ส่งเป็น minOut บนเชนตอนราคาถึง
        expect(wrapper.find('[data-test="limit-note"]').text()).toContain('0.002 BTC');

        await wrapper.findAll('[data-test="limit-expiry"] button').find(b => b.text() === '30d').trigger('click');
        await wrapper.find('[data-test="submit-order"]').trigger('click');

        const [order] = wrapper.emitted('submit-order')[0];
        expect(order).toMatchObject({ side: 'buy', type: 'limit', price: '50000', total: '100', expiresInDays: 30 });
    });

    it('limit sell uses amount × limit price, not the live ticker', async () => {
        const wrapper = await limitForm();
        await wrapper.findAll('button').find(btn => btn.text() === 'Sell').trigger('click');
        await fill(wrapper, { price: '70000', amount: '0.5' });

        // ราคาตลาด 67,234.50 — คำสั่งตั้งราคาต้องคิดจากราคาที่ตั้ง (70,000 × 0.5 = 35,000)
        expect(wrapper.find('[data-test="limit-note"]').text()).toContain('35,000.00 USDT');
        expect(wrapper.find('[data-test="submit-order"]').text()).toContain('Place sell order for BTC');
    });

    it('limit order is still blocked when the balance cannot cover it', async () => {
        const wrapper = await limitForm({ balances: [{ symbol: 'USDT', balance: '50' }] });
        await fill(wrapper, { price: '50000', total: '100' });

        const submit = wrapper.find('[data-test="submit-order"]');
        expect(submit.attributes('disabled')).toBeDefined();
        await submit.trigger('click');
        expect(wrapper.emitted('submit-order')).toBeUndefined();
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
