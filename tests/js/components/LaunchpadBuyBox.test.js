/**
 * TPIX TRADE — กล่องซื้อ Launchpad (เงินจริง: USDT บน BSC → เหรียญบน TPIX Chain)
 *
 * สิ่งที่ห้ามพัง:
 *  - ไม่ติ๊กยอมรับความเสี่ยง + กระเป๋าตัวเอง = เซ็นไม่ได้ และส่ง risk_accepted: true เสมอเมื่อเซ็น
 *  - โอนยอดจากคำสั่ง (amount_units) ขยายเป็น 18 ทศนิยมของ BSC ไป vault ของคำสั่ง ด้วยสัญญา USDT ของคำสั่ง
 *  - สลับเชนก่อนส่ง · บัญชีเปลี่ยนระหว่างเซ็น = ทิ้งลายเซ็น · ปลายทางสองแหล่งไม่ตรง = ไม่ส่ง
 *  - ส่งแล้วรีโหลดหน้า = ไม่มีปุ่มส่งซ้ำ
 *  - คำสั่งค้างของกระเป๋า (OPEN_ORDER_EXISTS / เปิดหน้าใหม่) = เปิดคำสั่งเดิมให้ทำต่อ
 * (locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { reactive } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const VAULT = '0xf46131c82819d7621163f482b3fe88a228a7807c';
const BSC_USDT = '0x55d398326f99059ff775485246999027b3197955';
const BUYER = '0x1111111111111111111111111111111111111111';
const TX = `0x${'ab'.repeat(32)}`;
const ORDER_ID = '7f1c2a9e-0000-4000-8000-000000000001';

const wallet = reactive({
    address: BUYER,
    isConnected: true,
    chainId: 4289,
    provider: {},
    signer: { signMessage: vi.fn(async () => '0xsig') },
    switchChain: vi.fn(async (id) => { wallet.chainId = id; }),
    openConnectModal: vi.fn(),
});
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const transfers = [];
vi.mock('ethers', async (importOriginal) => {
    const actual = await importOriginal();
    class FakeContract {
        constructor(address) { this.address = address; }
        async balanceOf() { return 1_000n * 10n ** 18n; }
        async transfer(to, amount) { transfers.push({ token: this.address, to, amount }); return { hash: TX }; }
    }
    return { ...actual, Contract: FakeContract };
});

let routes;
const posts = [];
vi.mock('axios', () => ({
    default: {
        get: vi.fn(async (url) => {
            const hit = routes.get[url];
            if (hit instanceof Error) throw hit;
            if (hit === undefined) throw new Error(`unexpected GET ${url}`);
            return { data: { success: true, data: typeof hit === 'function' ? hit() : hit } };
        }),
        post: vi.fn(async (url, body) => {
            posts.push({ url, body });
            const hit = routes.post[url];
            if (hit instanceof Error) throw hit;
            if (hit === undefined) throw new Error(`unexpected POST ${url}`);
            return { data: { success: true, data: typeof hit === 'function' ? hit(body) : hit } };
        }),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const BuyBox = (await import('@/Components/Launchpad/LaunchpadBuyBox.vue')).default;

const HOUR = 3600_000;
const sale = {
    id: 1,
    token: { address: '0x54', symbol: 'IDTC', name: 'IDTC Token', decimals: 18 },
    price_usdt: '0.0000015',
    tokens_per_usdt: '666666.666666666666666666',
    starts_at: new Date(Date.now() - HOUR).toISOString(),
    ends_at: new Date(Date.now() + 100 * HOUR).toISOString(),
};
const launchpad = {
    enabled: true,
    order_ttl_minutes: 30,
    tpix_chain_id: 4289,
    tpix_explorer: 'https://explorer.tpix.online',
    chains: [{ chain_id: 56, name: 'BNB Smart Chain', enabled: true, usdt_address: BSC_USDT, usdt_decimals: 18, explorer: 'https://bscscan.com' }],
};

function order(overrides = {}) {
    return {
        id: ORDER_ID,
        sale_id: 1,
        buyer_address: BUYER,
        source_chain_id: 56,
        amount: '25.5',
        amount_units: '25500000',
        expected_tokens: '17000000',
        status: 'awaiting_payment',
        expires_at: new Date(Date.now() + 30 * 60_000).toISOString(),
        payment: { vault: VAULT, usdt_contract: BSC_USDT, usdt_decimals: 18 },
        ...overrides,
    };
}

const prepared = {
    message: 'TPIX Launchpad order…',
    issued_at: '2026-11-06T10:00:00Z',
    nonce: 'abc123',
    amount_units: '25500000',
    quote: { amount: '25.5', fee: '0', tokens: '17000000', vault: VAULT, usdt_contract: BSC_USDT, usdt_decimals: 18 },
};

function apiErr(code, details = {}, status = 422) {
    const e = new Error(code);
    e.response = { status, data: { success: false, error: { code, message: 'x', details } } };
    return e;
}

async function mountBox() {
    const wrapper = mount(BuyBox, { props: { sale, launchpad } });
    await flushPromises();
    return wrapper;
}

async function reachReview(wrapper) {
    await wrapper.find('[data-test="amount"]').setValue('25.5');
    await wrapper.find('[data-test="review"]').trigger('click');
    await flushPromises();
}

beforeEach(() => {
    routes = {
        get: { [`/api/v1/launchpad/wallet/${BUYER}/orders`]: [] },
        post: {
            '/api/v1/launchpad/orders/prepare': prepared,
            '/api/v1/launchpad/orders': () => order(),
        },
    };
    posts.length = 0;
    transfers.length = 0;
    wallet.address = BUYER;
    wallet.chainId = 4289;
    wallet.isConnected = true;
    wallet.signer.signMessage.mockClear();
    wallet.signer.signMessage.mockImplementation(async () => '0xsig');
    wallet.switchChain.mockClear();
    wallet.switchChain.mockImplementation(async (id) => { wallet.chainId = id; });
    localStorage.removeItem('tpix_launchpad_payments');
});

describe('Launchpad buy box — order', () => {
    it('needs both risk boxes ticked before it signs, then sends risk_accepted with the signed order', async () => {
        const wrapper = await mountBox();
        await reachReview(wrapper);

        expect(posts[0]).toEqual({ url: '/api/v1/launchpad/orders/prepare', body: { sale_id: 1, buyer: BUYER, amount: '25.5', source_chain_id: 56 } });
        expect(wrapper.find('[data-test="risk-warning"]').text()).toContain('exactly 25.5 USDT');
        expect(wrapper.find('[data-test="sign"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-test="accept-risk"]').setValue(true);
        expect(wrapper.find('[data-test="sign"]').attributes('disabled')).toBeDefined();
        await wrapper.find('[data-test="accept-wallet"]').setValue(true);
        await wrapper.find('[data-test="sign"]').trigger('click');
        await flushPromises();

        expect(wallet.signer.signMessage).toHaveBeenCalledWith(prepared.message);
        expect(posts[1].url).toBe('/api/v1/launchpad/orders');
        expect(posts[1].body).toMatchObject({ sale_id: 1, buyer: BUYER, amount: '25.5', source_chain_id: 56, issued_at: prepared.issued_at, nonce: 'abc123', signature: '0xsig', risk_accepted: true });
        expect(wrapper.find('[data-test="send-exact"]').text()).toContain('25.5 USDT');
        expect(wrapper.find('[data-test="vault"]').text()).toBe(VAULT);
    });

    it('rejects an amount with more than 6 decimals before calling the server', async () => {
        const wrapper = await mountBox();
        await wrapper.find('[data-test="amount"]').setValue('1.1234567');

        expect(wrapper.text()).toContain('up to 6 decimals');
        expect(wrapper.find('[data-test="review"]').attributes('disabled')).toBeDefined();
        expect(posts).toEqual([]);
    });

    it('drops a signature that arrives after the wallet switched account', async () => {
        const wrapper = await mountBox();
        await reachReview(wrapper);
        await wrapper.find('[data-test="accept-risk"]').setValue(true);
        await wrapper.find('[data-test="accept-wallet"]').setValue(true);
        wallet.signer.signMessage.mockImplementation(async () => {
            wallet.address = '0x2222222222222222222222222222222222222222';
            return '0xsig';
        });

        await wrapper.find('[data-test="sign"]').trigger('click');
        await flushPromises();

        expect(posts.filter((p) => p.url === '/api/v1/launchpad/orders')).toEqual([]);
    });

    it('shows the server reason in the chosen language with its numbers', async () => {
        routes.post['/api/v1/launchpad/orders/prepare'] = apiErr('AMOUNT_OUT_OF_RANGE', { min: '1', max: '1000' });
        const wrapper = await mountBox();
        await reachReview(wrapper);

        expect(wrapper.find('[data-test="notice"]').text()).toBe('The amount must be between 1 and 1000 USDT');
    });

    it('opens the existing order when the wallet already has one open', async () => {
        routes.post['/api/v1/launchpad/orders/prepare'] = apiErr('OPEN_ORDER_EXISTS', { order_id: ORDER_ID }, 409);
        routes.get[`/api/v1/launchpad/orders/${ORDER_ID}`] = order();
        const wrapper = await mountBox();
        await reachReview(wrapper);

        expect(wrapper.find('[data-test="order-status"]').text()).toBe('Waiting for your USDT');
        expect(wrapper.find('[data-test="notice"]').text()).toContain('unfinished order');
    });

    it('resumes an open order of this sale on load', async () => {
        routes.get[`/api/v1/launchpad/wallet/${BUYER}/orders`] = [order({ status: 'paid', payment: null })];
        const wrapper = await mountBox();

        expect(wrapper.find('[data-test="order-status"]').text()).toBe('Payment received — buying');
        expect(wrapper.find('[data-test="pay"]').exists()).toBe(false);
    });

    it('does not offer to buy when the launchpad is off or the sale has ended', async () => {
        let wrapper = mount(BuyBox, { props: { sale, launchpad: { ...launchpad, enabled: false } } });
        await flushPromises();
        expect(wrapper.find('[data-test="buy-closed"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="review"]').exists()).toBe(false);

        wrapper = mount(BuyBox, { props: { sale: { ...sale, ends_at: new Date(Date.now() - HOUR).toISOString() }, launchpad } });
        await flushPromises();
        expect(wrapper.find('[data-test="buy-not-live"]').exists()).toBe(true);
    });
});

describe('Launchpad buy box — payment', () => {
    async function mountWithOrder(o = order()) {
        routes.get[`/api/v1/launchpad/wallet/${BUYER}/orders`] = [o];
        routes.get[`/api/v1/launchpad/orders/${o.id}`] = o;
        return mountBox();
    }

    it('switches to BSC and sends the exact order amount in 18 decimals to the order vault', async () => {
        const wrapper = await mountWithOrder();

        await wrapper.find('[data-test="pay"]').trigger('click');
        await flushPromises();

        expect(wallet.switchChain).toHaveBeenCalledWith(56);
        expect(transfers).toEqual([{ token: BSC_USDT, to: VAULT, amount: 25_500_000n * 10n ** 12n }]);
        expect(JSON.parse(localStorage.getItem('tpix_launchpad_payments'))[ORDER_ID].txHash).toBe(TX);
        expect(wrapper.find('[data-test="pay"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="already-sent"]').exists()).toBe(true);
    });

    it('never sends when the chain switch did not happen', async () => {
        wallet.switchChain.mockImplementation(async () => {});
        const wrapper = await mountWithOrder();

        await wrapper.find('[data-test="pay"]').trigger('click');
        await flushPromises();

        expect(transfers).toEqual([]);
        expect(wrapper.find('[data-test="notice"]').text()).toContain('switch');
    });

    it('never sends when the order target disagrees with the page chain list', async () => {
        const wrapper = await mountWithOrder(order({ payment: { vault: VAULT, usdt_contract: '0x9999999999999999999999999999999999999999', usdt_decimals: 18 } }));

        await wrapper.find('[data-test="pay"]').trigger('click');
        await flushPromises();

        expect(transfers).toEqual([]);
    });

    it('does not offer to pay again after a reload once a payment was sent', async () => {
        localStorage.setItem('tpix_launchpad_payments', JSON.stringify({ [ORDER_ID]: { txHash: TX, at: Date.now() } }));
        const wrapper = await mountWithOrder();

        expect(wrapper.find('[data-test="pay"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="cancel"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="already-sent"]').text()).toContain('do not send again');
    });

    it('does not offer to pay after the order expired', async () => {
        const wrapper = await mountWithOrder(order({ expires_at: new Date(Date.now() - 1000).toISOString() }));

        expect(wrapper.find('[data-test="pay"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="expires"]').text()).toContain('has passed');
    });

    it('cancels with a signed cancel message after a confirm step', async () => {
        routes.get[`/api/v1/launchpad/orders/${ORDER_ID}/cancel-message`] = { message: 'Cancel order' };
        routes.post[`/api/v1/launchpad/orders/${ORDER_ID}/cancel`] = order({ status: 'cancelled', payment: null });
        const wrapper = await mountWithOrder();

        await wrapper.find('[data-test="cancel"]').trigger('click');
        expect(posts).toEqual([]);
        await wrapper.find('[data-test="cancel-confirm"]').trigger('click');
        await flushPromises();

        expect(wallet.signer.signMessage).toHaveBeenCalledWith('Cancel order');
        expect(posts.at(-1)).toEqual({ url: `/api/v1/launchpad/orders/${ORDER_ID}/cancel`, body: { signature: '0xsig' } });
        expect(wrapper.find('[data-test="order-status"]').text()).toBe('Cancelled');
        expect(wrapper.find('[data-test="to-bridge"]').exists()).toBe(true);
    });

    it('links the delivery transaction once the tokens arrive', async () => {
        const wrapper = await mountWithOrder(order({ status: 'delivered', payment: null, tokens_out: '17000000', delivery_tx_hash: TX }));
        // จบแล้ว = ไม่เปิดเองตอนโหลด · เปิดจาก "คำสั่งซื้อของฉัน"
        expect(wrapper.find('[data-test="order-status"]').exists()).toBe(false);
        await wrapper.find('[data-test="my-orders"] button').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="status-body"]').text()).toContain('17,000,000 IDTC');
        expect(wrapper.find('[data-test="delivery-tx"]').attributes('href')).toBe(`https://explorer.tpix.online/tx/${TX}`);
    });

    it('forgets the previous wallet order when the account changes', async () => {
        const wrapper = await mountWithOrder();
        routes.get['/api/v1/launchpad/wallet/0x2222222222222222222222222222222222222222/orders'] = [];

        wallet.address = '0x2222222222222222222222222222222222222222';
        await flushPromises();

        expect(wrapper.find('[data-test="order-status"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="amount"]').exists()).toBe(true);
    });
});
