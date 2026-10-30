/**
 * TPIX TRADE — ฟอร์มซื้อเหรียญ: ชำระด้วย USDT (ตรวจเงินเข้าเองจากเชน)
 *
 * สิ่งที่ห้ามพัง:
 *  - เลือก USDT แล้วไม่มีค่าบริการบัตร ยอดรวม = ค่าเหรียญ
 *  - ส่งเชนที่เลือกไปกับคำสั่งซื้อ และแสดงยอดเฉพาะ + ที่อยู่ที่เซิร์ฟเวอร์ออกให้ (ไม่คำนวณเองฝั่งหน้าเว็บ)
 *  - ปุ่ม "จ่ายจากกระเป๋า" สลับไปเชนของคำสั่งซื้อ แล้วโอนยอดดิบจากเซิร์ฟเวอร์เป๊ะ — กดซ้ำไม่จ่ายสองรอบ
 *  - แผงคำสั่งซื้อไม่หายตอนกระเป๋าอยู่บนเชนที่จ่าย (ไม่ใช่ 4289)
 *  - ถามสถานะเองจนได้รับเงิน แล้วหยุดถาม · เซิร์ฟเวอร์ลืมการยืนยันกระเป๋า → เซ็นใหม่แล้วลองอีกครั้ง
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { reactive, ref, computed } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const transfer = vi.fn(async () => ({ hash: '0x' + 'ab'.repeat(32) }));
vi.mock('ethers', () => ({
    Contract: vi.fn(function Contract(address, abi, signer) {
        this.address = address;
        this.signer = signer;
        this.transfer = transfer;
    }),
}));

const wallet = reactive({
    address: '0x1111111111111111111111111111111111111111',
    shortAddress: '0x1111…1111',
    isConnected: true,
    chainId: 4289,
    signer: { kind: 'signer' },
    switchChain: vi.fn(async (id) => { wallet.chainId = id; }),
    openConnectModal: vi.fn(),
    verifyOwnership: vi.fn(async () => true),
});
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const phase = { id: 1, name: 'Private Sale', price_usd: 0.05, min_purchase: 100 };

const ORDER = {
    id: '6c1f0b7e-6a2a-4a51-9a3b-0d6d4cbf1a10',
    reference: 'TPX-ABCDEFG',
    status: 'awaiting_payment',
    chain_id: 56,
    chain_name: 'BNB Smart Chain',
    chain_short: 'BSC',
    native_symbol: 'BNB',
    explorer: 'https://bscscan.com',
    token_address: '0x55d398326f99059ff775485246999027b3197955',
    token_symbol: 'USDT',
    token_decimals: 18,
    pay_to_address: '0x5a1e000000000000000000000000000000005a1e',
    pay_amount: '10.003471',
    pay_amount_raw: '10003471000000000000',
    amount_usd: 10,
    tpix_amount: 200,
    price_per_tpix: 0.05,
    expires_at: new Date(Date.now() + 30 * 60 * 1000).toISOString(),
    payments: [],
};

const saleStore = reactive({
    sale: {
        payment_methods: { card: true, bank: false, crypto: true },
        card_fee_percent: 3,
        receive_chain_id: 4289,
        crypto_chains: [
            { chain_id: 56, name: 'BNB Smart Chain', short: 'BSC', native: 'BNB', token_symbol: 'USDT' },
            { chain_id: 1, name: 'Ethereum', short: 'ETH', native: 'ETH', token_symbol: 'USDT' },
        ],
    },
    createStripeCheckout: vi.fn(),
    createBankOrder: vi.fn(),
    createCryptoOrder: vi.fn(async () => ({ ok: true, data: { ...ORDER } })),
    fetchCryptoOrder: vi.fn(async () => ({ ...ORDER })),
});
vi.mock('@/Stores/tokenSaleStore', () => ({ useTokenSaleStore: () => saleStore }));

const paymentAmount = ref('');
const preview = ref(null);
vi.mock('@/Composables/useTokenSale', () => ({
    useTokenSale: () => ({
        selectedCurrency: ref('USD'),
        paymentAmount,
        preview,
        isLoadingPreview: ref(false),
        error: ref(null),
        phaseClosedReason: ref(null),
        currentPhase: computed(() => phase),
        currentPrice: computed(() => phase.price_usd),
        isSoldOut: computed(() => false),
        exceedsRemaining: computed(() => false),
        calculatePreview: vi.fn(async () => {}),
    }),
}));

vi.mock('@/utils/mobileWallet', () => ({ isMobile: () => false, downloadTpixApp: vi.fn() }));
vi.mock('@/utils/web3', () => ({ TPIX_CHAIN_CONFIG: { chainIdNum: 4289 } }));
vi.mock('@/Components/Brand/BrandLogo.vue', () => ({ default: { template: '<span />' } }));

import BuyForm from '@/Components/TokenSale/BuyForm.vue';

async function typeAmount(wrapper, value) {
    await wrapper.find('[data-testid="amount-input"]').setValue(String(value));
    preview.value = { payment_usd_value: Number(value), tpix_amount: Number(value) / phase.price_usd };
    await flushPromises();
}

async function placeOrder(wrapper, amount = 10) {
    await wrapper.find('[data-testid="method-crypto"]').trigger('click');
    await typeAmount(wrapper, amount);
    await wrapper.find('[data-testid="submit-order"]').trigger('click');
    await flushPromises();
}

describe('BuyForm — USDT payment', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'setTimeout', 'clearTimeout', 'Date'] });
        localStorage.clear();
        paymentAmount.value = '';
        preview.value = null;
        wallet.chainId = 4289;
        wallet.address = '0x1111111111111111111111111111111111111111';
        transfer.mockClear();
        wallet.switchChain.mockClear();
        saleStore.createCryptoOrder.mockClear();
        saleStore.createCryptoOrder.mockImplementation(async () => ({ ok: true, data: { ...ORDER } }));
        saleStore.fetchCryptoOrder.mockReset();
        saleStore.fetchCryptoOrder.mockImplementation(async () => ({ ...ORDER }));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('has no card fee and sends the chosen network with the order', async () => {
        const wrapper = mount(BuyForm);
        await wrapper.find('[data-testid="method-crypto"]').trigger('click');
        await wrapper.find('[data-testid="crypto-chain-1"]').trigger('click');
        await typeAmount(wrapper, 100);

        expect(wrapper.find('[data-testid="fee-row"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="crypto-fee-row"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="total-usd"]').text()).toBe('$100');
        expect(wrapper.find('[data-testid="submit-order"]').text()).toContain('รับยอดโอน USDT');

        await wrapper.find('[data-testid="submit-order"]').trigger('click');
        await flushPromises();

        expect(saleStore.createCryptoOrder).toHaveBeenCalledWith(expect.objectContaining({
            amount_usd: 100, phase_id: 1, chain_id: 1, wallet_address: wallet.address,
        }));
        wrapper.unmount();
    });

    it('shows the server-issued exact amount and address, with a countdown', async () => {
        const wrapper = mount(BuyForm);
        await placeOrder(wrapper);

        expect(wrapper.find('[data-testid="crypto-amount"]').text()).toBe('10.003471 USDT');
        expect(wrapper.find('[data-testid="crypto-pay-to"]').text()).toBe(ORDER.pay_to_address);
        expect(wrapper.find('[data-testid="crypto-countdown"]').text()).toMatch(/^(30:00|29:5\d)$/);
        wrapper.unmount();
    });

    it('pays from the wallet on the order chain with the raw amount, only once', async () => {
        const wrapper = mount(BuyForm);
        await placeOrder(wrapper);

        await wrapper.find('[data-testid="crypto-pay-wallet"]').trigger('click');
        await flushPromises();

        expect(wallet.switchChain).toHaveBeenCalledWith(56);
        expect(transfer).toHaveBeenCalledWith(ORDER.pay_to_address, 10003471000000000000n);

        // อยู่บน BSC แล้ว แผงคำสั่งซื้อต้องยังอยู่ (ไม่ถูกด่านเชน 4289 บัง)
        expect(wrapper.find('[data-testid="crypto-order"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="crypto-sent"]').exists()).toBe(true);

        await wrapper.find('[data-testid="crypto-pay-wallet"]').trigger('click');
        await flushPromises();
        expect(transfer).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('tells the buyer no money left when they reject the transfer', async () => {
        transfer.mockRejectedValueOnce({ code: 'ACTION_REJECTED' });
        const wrapper = mount(BuyForm);
        await placeOrder(wrapper);

        await wrapper.find('[data-testid="crypto-pay-wallet"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('ยังไม่มีเงินออก');
        expect(wrapper.find('[data-testid="crypto-sent"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('polls until the payment is credited, then stops and reports success', async () => {
        const wrapper = mount(BuyForm);
        await placeOrder(wrapper);

        saleStore.fetchCryptoOrder.mockResolvedValueOnce({ ...ORDER, status: 'paid', transaction_id: 'tx-1' });
        await vi.advanceTimersByTimeAsync(6000);
        await flushPromises();

        expect(wrapper.find('[data-testid="crypto-paid"]').exists()).toBe(true);
        expect(wrapper.emitted('purchase-complete')).toHaveLength(1);

        const calls = saleStore.fetchCryptoOrder.mock.calls.length;
        await vi.advanceTimersByTimeAsync(30000);
        expect(saleStore.fetchCryptoOrder.mock.calls.length).toBe(calls);
        wrapper.unmount();
    });

    it('explains a payment that needs review instead of hiding it', async () => {
        const wrapper = mount(BuyForm);
        await placeOrder(wrapper);

        saleStore.fetchCryptoOrder.mockResolvedValueOnce({ ...ORDER, status: 'needs_review' });
        await vi.advanceTimersByTimeAsync(6000);
        await flushPromises();

        expect(wrapper.find('[data-testid="crypto-review"]').text()).toContain(ORDER.reference);
        wrapper.unmount();
    });

    it('drops the order panel when the buyer switches to another wallet', async () => {
        const wrapper = mount(BuyForm);
        await placeOrder(wrapper);
        expect(wrapper.find('[data-testid="crypto-order"]').exists()).toBe(true);

        wallet.address = '0x2222222222222222222222222222222222222222';
        await flushPromises();

        expect(wrapper.find('[data-testid="crypto-order"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('signs again and retries when the server forgot the wallet verification', async () => {
        saleStore.createCryptoOrder
            .mockResolvedValueOnce({ ok: false, code: 'WALLET_NOT_VERIFIED', message: 'x' })
            .mockResolvedValueOnce({ ok: true, data: { ...ORDER } });
        wallet.verifyOwnership.mockClear();

        const wrapper = mount(BuyForm);
        await placeOrder(wrapper);

        expect(wallet.verifyOwnership).toHaveBeenCalledTimes(1);
        expect(saleStore.createCryptoOrder).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="crypto-order"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('shows the same order again after a reload instead of losing the exact amount', async () => {
        const first = mount(BuyForm);
        await placeOrder(first);
        first.unmount();

        const reloaded = mount(BuyForm);
        await flushPromises();

        expect(saleStore.fetchCryptoOrder).toHaveBeenCalledWith(ORDER.id, wallet.address);
        expect(reloaded.find('[data-testid="crypto-amount"]').text()).toBe('10.003471 USDT');

        // เริ่มรายการใหม่ = ลืมคำสั่งซื้อเดิม
        await reloaded.find('[data-testid="crypto-new-order"]').trigger('click');
        reloaded.unmount();
        saleStore.fetchCryptoOrder.mockClear();
        const fresh = mount(BuyForm);
        await flushPromises();
        expect(saleStore.fetchCryptoOrder).not.toHaveBeenCalled();
        expect(fresh.find('[data-testid="crypto-order"]').exists()).toBe(false);
        fresh.unmount();
    });

    it('stops polling when the form is closed', async () => {
        const wrapper = mount(BuyForm);
        await placeOrder(wrapper);
        wrapper.unmount();

        const calls = saleStore.fetchCryptoOrder.mock.calls.length;
        await vi.advanceTimersByTimeAsync(30000);
        expect(saleStore.fetchCryptoOrder.mock.calls.length).toBe(calls);
    });
});
