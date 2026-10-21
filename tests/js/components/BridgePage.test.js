/**
 * TPIX TRADE — หน้าสะพาน USDT (เงินจริงหลายเชน)
 *
 * สิ่งที่ห้ามพัง:
 *  - โอนเข้า vault ด้วยจำนวนหน่วยเล็กสุดที่ถูกต้องตาม decimals ของเชน (BSC = 18!)
 *  - สลับไปเชนต้นทาง/TPIX ก่อนส่งเสมอ — ส่งบนเชนผิด = เงินหาย
 *  - ยอดต่ำกว่าขั้นต่ำ / ที่อยู่ปลายทาง checksum ผิด → ปุ่มกดไม่ได้
 *  - สะพานยังไม่เปิด → ไม่มีปุ่มส่ง
 *  - mint เสร็จแล้วมีปุ่มไปซื้อ TPIX
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { reactive } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const VAULT = '0xf46131c82819d7621163f482b3fe88a228a7807c';
const USDT_TPIX = '0x0a4ee87551ef041f3eecfbbfdcffc1b46a4878f1';
const BSC_USDT = '0x55d398326f99059fF775485246999027B3197955';
const TX = `0x${'ab'.repeat(32)}`;

const wallet = reactive({
    address: '0x1111111111111111111111111111111111111111',
    isConnected: true,
    chainId: 56,
    provider: {},
    signer: {},
    switchChain: vi.fn(async (id) => { wallet.chainId = id; }),
    openConnectModal: vi.fn(),
});
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const contractCalls = [];
vi.mock('ethers', async (importOriginal) => {
    const actual = await importOriginal();
    class FakeContract {
        constructor(address) { this.address = address; }
        async balanceOf() { return this.address.toLowerCase() === USDT_TPIX ? 100_000_000n : 1_000n * 10n ** 18n; }
        async transfer(to, amount) { contractCalls.push({ fn: 'transfer', token: this.address, to, amount }); return { hash: TX }; }
        async bridgeBurn(amount, chainId, target, overrides) { contractCalls.push({ fn: 'bridgeBurn', amount, chainId, target, overrides }); return { hash: TX }; }
    }
    class FakeProvider {}
    return { ...actual, Contract: FakeContract, JsonRpcProvider: FakeProvider };
});

let info;
let statusResponse;
vi.mock('axios', () => ({
    default: {
        get: vi.fn(async (url) => {
            if (url === '/api/v1/bridge/info') return { data: { success: true, data: info } };
            if (url.startsWith('/api/v1/bridge/history/')) return { data: { success: true, data: { deposits: [], withdrawals: [] } } };
            if (url.startsWith('/api/v1/bridge/status/') || url.startsWith('/api/v1/bridge/withdrawals/')) return { data: { success: true, data: statusResponse } };
            throw new Error(`unexpected ${url}`);
        }),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const Bridge = (await import('@/Pages/Bridge.vue')).default;

function baseInfo(overrides = {}) {
    return {
        enabled: true,
        installed: true,
        vault_address: VAULT,
        minter_address: '0x7777777777777777777777777777777777777777',
        tpix: { chain_id: 4289, usdt_tpix: USDT_TPIX, explorer: 'https://explorer.tpix.online' },
        limits: { min_deposit: '1', max_deposit_per_tx: '1000', max_deposit_per_address_daily: '2000', min_withdraw: '2', max_withdraw_per_tx: '1000' },
        trade_url: '/trade?pair=TPIX-USDT',
        chains: [
            { chain_id: 56, name: 'BNB Smart Chain', enabled: true, usdt_address: BSC_USDT, usdt_decimals: 18, usdt_symbol: 'USDT', explorer: 'https://bscscan.com', withdraw_fee: '0.3', eta_minutes: 2 },
            { chain_id: 1, name: 'Ethereum', enabled: true, usdt_address: '0xdAC17F958D2ee523a2206206994597C13D831ec7', usdt_decimals: 6, usdt_symbol: 'USDT', explorer: 'https://etherscan.io', withdraw_fee: '1', eta_minutes: 20 },
        ],
        ...overrides,
    };
}

async function mountPage() {
    const wrapper = mount(Bridge);
    await flushPromises();
    return wrapper;
}

const buttonByText = (wrapper, text) => wrapper.findAll('button').find((b) => b.text().includes(text));

beforeEach(() => {
    info = baseInfo();
    statusResponse = { state: 'confirming', deposits: [], amount: '25', confirmations: 3, final: false };
    contractCalls.length = 0;
    wallet.chainId = 56;
    wallet.switchChain.mockClear();
    localStorage.removeItem('tpix.bridge.tracked.v2');
});

describe('Bridge page — deposit', () => {
    it('sends USDT to the vault with the 18-decimal BSC amount and starts tracking', async () => {
        const wrapper = await mountPage();

        await wrapper.find('input[inputmode="decimal"]').setValue('25');
        // ยังไม่ติ๊กยอมรับ = ส่งไม่ได้
        expect(buttonByText(wrapper, 'Send USDT to the bridge').attributes('disabled')).toBeDefined();
        await wrapper.find('[data-test="ack-own-wallet"]').setValue(true);
        await buttonByText(wrapper, 'Send USDT to the bridge').trigger('click');
        await flushPromises();

        expect(contractCalls).toEqual([{ fn: 'transfer', token: BSC_USDT, to: VAULT, amount: 25n * 10n ** 18n }]);
        expect(wrapper.text()).toContain('Waiting for finality');
        expect(JSON.parse(localStorage.getItem('tpix.bridge.tracked.v2'))[0]).toMatchObject({ kind: 'deposit', chainId: 56, txHash: TX });
    });

    it('blocks amounts below the minimum', async () => {
        const wrapper = await mountPage();

        await wrapper.find('input[inputmode="decimal"]').setValue('0.5');

        expect(wrapper.text()).toContain('Minimum is 1 USDT');
        expect(buttonByText(wrapper, 'Send USDT to the bridge').attributes('disabled')).toBeDefined();
    });

    it('blocks amounts above the cap that can really be minted (not just a review warning)', async () => {
        info = baseInfo({ deposit_max_per_tx: '1000', limits: { ...baseInfo().limits, max_deposit_per_tx: '1500' } });
        const wrapper = await mountPage();
        await wrapper.find('[data-test="ack-own-wallet"]').setValue(true);

        await wrapper.find('input[inputmode="decimal"]').setValue('1200');

        expect(wrapper.text()).toContain('Maximum is 1000 USDT per transfer');
        expect(buttonByText(wrapper, 'Send USDT to the bridge').attributes('disabled')).toBeDefined();
        expect(contractCalls).toEqual([]);
    });

    it('shows a configured deposit fee instead of "free" and nets it out of what you receive', async () => {
        info = baseInfo({ limits: { ...baseInfo().limits, deposit_fee: '0.5' } });
        const wrapper = await mountPage();

        await wrapper.find('input[inputmode="decimal"]').setValue('25');

        expect(wrapper.find('[data-test="deposit-fee"]').text()).toContain('0.5 USDT per deposit');
        expect(wrapper.text()).toContain('24.5');
    });

    it('marks disabled chains in the tracking list and tells a wrong-chain depositor how to get a refund', async () => {
        info = baseInfo({
            chains: [
                ...baseInfo().chains.slice(0, 1),
                { ...baseInfo().chains[1], enabled: false },
            ],
        });
        localStorage.setItem('tpix.bridge.tracked.v2', JSON.stringify([{ kind: 'deposit', chainId: 1, txHash: TX }]));
        statusResponse = { state: 'chain_disabled', deposits: [], refund: true };

        const wrapper = await mountPage();

        expect(wrapper.findAll('option').map((o) => o.text())).toContain('Ethereum (Temporarily off)');
        expect(wrapper.find('[data-test="refund-hint"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Deposits on this chain are not open');
    });

    it('asks to switch to the source chain first when the wallet is elsewhere', async () => {
        wallet.chainId = 4289;
        const wrapper = await mountPage();

        await buttonByText(wrapper, 'Switch to BNB Smart Chain').trigger('click');
        await flushPromises();

        expect(wallet.switchChain).toHaveBeenCalledWith(56);
        expect(contractCalls).toEqual([]);
    });

    it('shows no send button while the bridge is closed', async () => {
        info = baseInfo({ enabled: false, installed: false });
        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('The bridge is not open yet');
        expect(buttonByText(wrapper, 'Send USDT to the bridge').attributes('disabled')).toBeDefined();
    });

    it('offers to buy TPIX once the deposit is minted', async () => {
        localStorage.setItem('tpix.bridge.tracked.v2', JSON.stringify([{ kind: 'deposit', chainId: 56, txHash: TX }]));
        statusResponse = {
            state: 'minted',
            deposits: [{ id: 1, status: 'minted', mint_amount: '25', mint_url: 'https://explorer.tpix.online/tx/0xmint' }],
        };

        const wrapper = await mountPage();

        const buy = wrapper.findAll('a').find((a) => a.text().includes('Buy TPIX with this USDT'));
        expect(buy.attributes('href')).toBe('/trade?pair=TPIX-USDT');
    });
});

describe('Bridge page — withdraw', () => {
    it('burns on TPIX Chain with gasPrice 0 after switching, and shows the net amount', async () => {
        const wrapper = await mountPage();
        await buttonByText(wrapper, 'Withdraw').trigger('click');

        // ฟอร์มฝากยังอยู่ใน DOM (v-show) — เลือกช่องที่มองเห็นเท่านั้น
        const visible = wrapper.findAll('input').filter((i) => i.element.closest('[style*="display: none"]') === null);
        await visible.find((i) => i.attributes('inputmode') === 'decimal').setValue('25');
        await buttonByText(wrapper, 'Use my address').trigger('click');

        expect(wrapper.text()).toContain('24.7');

        await buttonByText(wrapper, 'Withdraw to BNB Smart Chain').trigger('click');
        // เผาแล้วยกเลิกไม่ได้ — ต้องผ่านกล่องยืนยันก่อนเปิดกระเป๋า
        expect(contractCalls).toEqual([]);
        expect(wrapper.text()).toContain('Confirm withdrawal');
        await wrapper.find('[data-test="confirm-withdraw"]').trigger('click');
        await flushPromises();

        expect(wallet.switchChain).toHaveBeenCalledWith(4289);
        expect(contractCalls).toEqual([{ fn: 'bridgeBurn', amount: 25_000_000n, chainId: 56n, target: wallet.address, overrides: { gasPrice: 0 } }]);
    });

    it('refuses a mistyped (bad checksum) recipient', async () => {
        const wrapper = await mountPage();
        await buttonByText(wrapper, 'Withdraw').trigger('click');

        const visible = wrapper.findAll('input').filter((i) => i.element.closest('[style*="display: none"]') === null);
        await visible.find((i) => i.attributes('inputmode') === 'decimal').setValue('25');
        await visible.find((i) => i.attributes('placeholder') === '0x…').setValue('0xF46131C82819d7621163F482b3fe88a228A7807c');

        expect(wrapper.text()).toContain('Invalid recipient address');
        expect(buttonByText(wrapper, 'Withdraw to BNB Smart Chain').attributes('disabled')).toBeDefined();
    });
});
