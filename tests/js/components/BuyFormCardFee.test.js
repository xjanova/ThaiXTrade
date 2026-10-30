/**
 * TPIX TRADE — ฟอร์มซื้อเหรียญ: ชำระด้วยบัตร + ค่าบริการ 15%
 *
 * สิ่งที่ห้ามพัง:
 *  - เลือกบัตรแล้วต้องเห็นทันทีว่าต้องใส่จำนวนเงินและกรอกบัตรในหน้าถัดไป (เดิมผู้ซื้องง)
 *  - ปุ่มยอดเร็วเริ่มที่ยอดขั้นต่ำของเฟส
 *  - ค่าบริการบัตร 15% แสดงเป็นบรรทัดแยก และยอดรวมบนปุ่ม = ค่าเหรียญ + ค่าบริการ
 *  - ยอดที่ส่งไปสร้าง session คือ "ค่าเหรียญ" ไม่ใช่ยอดรวม (เซิร์ฟเวอร์บวกค่าบริการเอง)
 *  - ต่ำกว่าขั้นต่ำ → ปุ่มกดไม่ได้และบอกเหตุผล
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { reactive, ref, computed } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const wallet = reactive({
    address: '0x1111111111111111111111111111111111111111',
    shortAddress: '0x1111…1111',
    isConnected: true,
    chainId: 4289,
    switchChain: vi.fn(),
    openConnectModal: vi.fn(),
});
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const phase = { id: 1, name: 'Private Sale', price_usd: 0.05, min_purchase: 100 };
const saleStore = reactive({
    sale: { payment_methods: { card: true, bank: false }, card_fee_percent: 15, receive_chain_id: 4289 },
    createStripeCheckout: vi.fn(async () => ({ ok: true, url: 'https://checkout.stripe.com/c/pay/cs_test' })),
    createBankOrder: vi.fn(),
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
        calculatePreview: vi.fn(async () => {
            const usd = parseFloat(paymentAmount.value) || 0;
            preview.value = usd > 0 ? { payment_usd_value: usd, tpix_amount: usd / phase.price_usd } : null;
        }),
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

describe('BuyForm — card payment', () => {
    beforeEach(() => {
        paymentAmount.value = '';
        preview.value = null;
        saleStore.sale.payment_methods = { card: true, bank: false };
        saleStore.sale.card_claim_hold_days = 0;
        saleStore.createStripeCheckout.mockClear();
        delete window.location;
        window.location = { href: '' };
    });

    it('shows card brand logos and tells the buyer what to do next', () => {
        const wrapper = mount(BuyForm);
        const card = wrapper.find('[data-testid="method-card"]');
        expect(card.exists()).toBe(true);
        expect(card.findAll('svg.card-logo')).toHaveLength(4);
        expect(wrapper.text()).toContain('กรอกเลขบัตรในหน้าชำระเงินที่ปลอดภัย');
        expect(wrapper.find('[data-testid="submit-hint"]').text()).toContain('จำนวนเงิน');
    });

    it('quick amounts start at the phase minimum ($5 for 100 TPIX at $0.05)', () => {
        const wrapper = mount(BuyForm);
        const chips = wrapper.findAll('.amount-chip').map((c) => c.text());
        expect(chips[0]).toBe('$5');
    });

    it('adds a 15% card service fee as its own line and in the total', async () => {
        const wrapper = mount(BuyForm);
        await typeAmount(wrapper, 100);

        expect(wrapper.find('[data-testid="fee-row"]').text()).toContain('$15');
        expect(wrapper.find('[data-testid="total-usd"]').text()).toBe('$115');
        expect(wrapper.find('[data-testid="submit-order"]').text()).toContain('$115');
        expect(wrapper.text()).toContain('2,000 TPIX');
    });

    it('tells the buyer about the claim hold before they pay by card', async () => {
        saleStore.sale.card_claim_hold_days = 60;
        const wrapper = mount(BuyForm);
        await typeAmount(wrapper, 5);

        expect(wrapper.find('[data-testid="card-hold-note"]').text()).toContain('60 วัน');
    });

    it('does not mention a hold when it is turned off', async () => {
        const wrapper = mount(BuyForm);
        await typeAmount(wrapper, 5);

        expect(wrapper.find('[data-testid="card-hold-note"]').exists()).toBe(false);
    });

    it('tells the buyer when a bank order stops holding its price', async () => {
        saleStore.sale.payment_methods = { card: false, bank: true };
        saleStore.createBankOrder.mockResolvedValueOnce({
            ok: true,
            data: { reference: 'TPIX-ABC234', amount_usd: 5, tpix_amount: 100, expires_at: '2026-11-01T00:00:00Z', bank: {} },
        });

        const wrapper = mount(BuyForm);
        await wrapper.find('[data-testid="method-bank"]').trigger('click');
        await typeAmount(wrapper, 5);
        await wrapper.find('[data-testid="submit-order"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('TPIX-ABC234');
        expect(wrapper.find('[data-testid="bank-expiry-note"]').text()).toContain('ราคาอาจเปลี่ยน');
    });

    it('sends the token amount (not the total) to create the checkout', async () => {
        const wrapper = mount(BuyForm);
        await typeAmount(wrapper, 5);
        await wrapper.find('[data-testid="submit-order"]').trigger('click');
        await flushPromises();

        expect(saleStore.createStripeCheckout).toHaveBeenCalledWith(expect.objectContaining({ amount_usd: 5, phase_id: 1 }));
        expect(window.location.href).toContain('checkout.stripe.com');
    });

    it('asks the wallet to sign again and retries when the server forgot the verification', async () => {
        saleStore.createStripeCheckout
            .mockResolvedValueOnce({ ok: false, code: 'WALLET_NOT_VERIFIED', message: 'Wallet ownership not verified.' })
            .mockResolvedValueOnce({ ok: true, url: 'https://checkout.stripe.com/c/pay/cs_retry' });
        wallet.verifyOwnership = vi.fn(async () => true);

        const wrapper = mount(BuyForm);
        await typeAmount(wrapper, 5);
        await wrapper.find('[data-testid="submit-order"]').trigger('click');
        await flushPromises();

        expect(wallet.verifyOwnership).toHaveBeenCalledTimes(1);
        expect(saleStore.createStripeCheckout).toHaveBeenCalledTimes(2);
        expect(window.location.href).toContain('cs_retry');
    });

    it('tells the buyer to sign when they refuse the signature', async () => {
        saleStore.createStripeCheckout.mockResolvedValueOnce({ ok: false, code: 'WALLET_NOT_VERIFIED', message: 'x' });
        wallet.verifyOwnership = vi.fn(async () => { throw new Error('User rejected'); });

        const wrapper = mount(BuyForm);
        await typeAmount(wrapper, 5);
        await wrapper.find('[data-testid="submit-order"]').trigger('click');
        await flushPromises();

        expect(saleStore.createStripeCheckout).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('ต้องเซ็นยืนยันว่าเป็นเจ้าของกระเป๋าก่อนซื้อ');
    });

    it('blocks amounts below the minimum and says why', async () => {
        const wrapper = mount(BuyForm);
        await typeAmount(wrapper, 2);

        expect(wrapper.find('[data-testid="submit-order"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="submit-hint"]').text()).toContain('ขั้นต่ำ $5');
    });
});
