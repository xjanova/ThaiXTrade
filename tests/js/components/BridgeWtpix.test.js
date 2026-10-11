/**
 * TPIX TRADE — หน้าสะพาน: สลับสินทรัพย์ USDT | TPIX ⇄ wTPIX
 *
 * สิ่งที่ห้ามพัง:
 *  - ล็อกด้วย vault.lock() (ไม่ใช่โอนตรง) จำนวน 18 ทศนิยม · gasPrice 0 · หลังสลับไปเชน 4289
 *  - เผาด้วย wTPIX.burn() หลังสลับไป BSC
 *  - ต่ำกว่าขั้นต่ำ / ไม่ติ๊กยอมรับ / สะพานปิด → กดไม่ได้
 *  - ต้องผ่านกล่องยืนยันก่อนเปิดกระเป๋าเสมอ (ล็อก/เผาแล้วยกเลิกไม่ได้)
 *  - mint เสร็จแล้วมีลิงก์ BscScan + ปุ่มเพิ่ม wTPIX ลงกระเป๋า (wallet_watchAsset พร้อมโลโก้)
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { reactive } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const VAULT = '0x7a17000000000000000000000000000000000001';
const WTPIX = '0x7a17000000000000000000000000000000000002';
const LOGO = 'https://tpix.online/images/brand/tpix-coin.png?v=2';
const TX = `0x${'cd'.repeat(32)}`;
const E18 = 10n ** 18n;

const wallet = reactive({
    address: '0x1111111111111111111111111111111111111111',
    isConnected: true,
    isEmbedded: false,
    chainId: 56,
    provider: {},
    signer: {},
    switchChain: vi.fn(async (id) => { wallet.chainId = id; }),
    watchAsset: vi.fn(async () => true),
    openConnectModal: vi.fn(),
});
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const calls = [];
vi.mock('ethers', async (importOriginal) => {
    const actual = await importOriginal();
    class FakeContract {
        constructor(address) { this.address = address; }
        async balanceOf() { return 1_000n * E18; }
        async lock(overrides) { calls.push({ fn: 'lock', to: this.address, overrides, chainId: wallet.chainId }); return { hash: TX }; }
        async burn(amount) { calls.push({ fn: 'burn', to: this.address, amount, chainId: wallet.chainId }); return { hash: TX }; }
        async transfer() { calls.push({ fn: 'transfer' }); return { hash: TX }; }
    }
    class FakeProvider {
        async getBalance() { return 2_000n * E18; }
    }
    return { ...actual, Contract: FakeContract, JsonRpcProvider: FakeProvider };
});

let tpixInfo;
let statusResponse;
const usdtInfo = {
    enabled: true, installed: true, vault_address: '0xf46131c82819d7621163f482b3fe88a228a7807c',
    tpix: { chain_id: 4289, usdt_tpix: '0x0a4ee87551ef041f3eecfbbfdcffc1b46a4878f1', explorer: 'https://explorer.tpix.online' },
    limits: { min_deposit: '1', max_deposit_per_tx: '1000', min_withdraw: '2', max_withdraw_per_tx: '1000' },
    chains: [{ chain_id: 56, name: 'BNB Smart Chain', enabled: true, usdt_address: '0x55d398326f99059fF775485246999027B3197955', usdt_decimals: 18, usdt_symbol: 'USDT', explorer: 'https://bscscan.com', withdraw_fee: '0.3', eta_minutes: 2 }],
};

vi.mock('axios', () => ({
    default: {
        get: vi.fn(async (url, config = {}) => {
            const asset = config?.params?.asset;
            if (url === '/api/v1/bridge/info') return { data: { success: true, data: asset === 'tpix' ? tpixInfo : usdtInfo } };
            if (url.startsWith('/api/v1/bridge/history/')) {
                return { data: { success: true, data: asset === 'tpix' ? { transfers: [] } : { deposits: [], withdrawals: [] } } };
            }
            if (url.startsWith('/api/v1/bridge/status/')) return { data: { success: true, data: statusResponse } };
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

function baseTpixInfo(overrides = {}) {
    return {
        asset: 'tpix',
        enabled: true,
        installed: true,
        tpix: { chain_id: 4289, name: 'TPIX Chain', vault_address: VAULT, explorer: 'https://explorer.tpix.online', rpc: 'https://rpc.tpix.online', eta_minutes: 3 },
        bsc: { chain_id: 56, name: 'BNB Smart Chain', wtpix_address: WTPIX, minter_address: '0x7a17000000000000000000000000000000000003', explorer: 'https://bscscan.com', rpc: 'https://bsc-rpc.publicnode.com', eta_minutes: 2 },
        token: { symbol: 'wTPIX', decimals: 18, logo: LOGO },
        limits: { min_lock: '10', max_lock_per_tx: '100000', max_lock_per_address_daily: '200000', lock_fee: '1', min_burn: '10', max_burn_per_tx: '100000', max_burn_per_address_daily: '200000', burn_fee: '0' },
        vault: { min_lock: '10', max_lock: '100000', max_per_tx: '100000', daily_cap: '500000', remaining_today: '500000', paused: false },
        minter: { max_per_tx: '100000', daily_cap: '500000', remaining_today: '500000', paused: false },
        ...overrides,
    };
}

const byTest = (wrapper, id) => wrapper.find(`[data-test="${id}"]`);

async function mountOnTpix() {
    const wrapper = mount(Bridge);
    await flushPromises();
    await byTest(wrapper, 'asset-tpix').trigger('click');
    await flushPromises();
    return wrapper;
}

beforeEach(() => {
    tpixInfo = baseTpixInfo();
    statusResponse = { state: 'confirming', transfers: [], amount: '500', confirmations: 1, final: false };
    calls.length = 0;
    wallet.chainId = 56;
    wallet.switchChain.mockClear();
    wallet.watchAsset.mockClear();
    localStorage.removeItem('tpix.wtpix-bridge.tracked.v1');
    window.history.replaceState(null, '', '/bridge');
});

describe('Bridge page — asset switch', () => {
    it('starts on USDT and switches to the TPIX ⇄ wTPIX panel without touching the USDT flow', async () => {
        const wrapper = mount(Bridge);
        await flushPromises();

        expect(wrapper.text()).toContain('Send USDT to the bridge');
        expect(byTest(wrapper, 'wtpix-lock-button').exists()).toBe(false);

        await byTest(wrapper, 'asset-tpix').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('TPIX ⇄ wTPIX Bridge (BSC)');
        expect(byTest(wrapper, 'wtpix-lock-button').text()).toContain('Lock TPIX → receive wTPIX on BNB Smart Chain');
        expect(wrapper.text()).not.toContain('Send USDT to the bridge');
        expect(window.location.search).toBe('?asset=tpix');

        await byTest(wrapper, 'asset-usdt').trigger('click');
        expect(window.location.search).toBe('');
        expect(wrapper.text()).toContain('Send USDT to the bridge');
    });

    it('opens straight on the TPIX panel from a ?asset=tpix link', async () => {
        window.history.replaceState(null, '', '/bridge?asset=tpix');
        const wrapper = mount(Bridge);
        await flushPromises();

        expect(byTest(wrapper, 'wtpix-lock-button').exists()).toBe(true);
    });
});

describe('Bridge page — TPIX → wTPIX', () => {
    it('locks through vault.lock() on chain 4289 with gasPrice 0 after a confirmation, then tracks the tx', async () => {
        const wrapper = await mountOnTpix();

        await byTest(wrapper, 'wtpix-lock-amount').setValue('500');
        expect(byTest(wrapper, 'wtpix-lock-receive').text()).toContain('499');
        // ยังไม่ติ๊กยอมรับ = กดไม่ได้
        expect(byTest(wrapper, 'wtpix-lock-button').attributes('disabled')).toBeDefined();
        await byTest(wrapper, 'wtpix-ack-lock').setValue(true);

        await byTest(wrapper, 'wtpix-lock-button').trigger('click');
        expect(calls).toEqual([]);
        expect(wrapper.text()).toContain('Confirm lock');

        await byTest(wrapper, 'wtpix-confirm').trigger('click');
        await flushPromises();

        expect(wallet.switchChain).toHaveBeenCalledWith(4289);
        expect(calls).toEqual([{ fn: 'lock', to: VAULT, overrides: { value: 500n * E18, gasPrice: 0 }, chainId: 4289 }]);
        expect(JSON.parse(localStorage.getItem('tpix.wtpix-bridge.tracked.v1'))[0]).toMatchObject({ direction: 'to_bsc', chainId: 4289, txHash: TX });
        expect(wrapper.text()).toContain('Waiting for confirmations');
    });

    it('blocks amounts below the minimum and above the on-chain maximum', async () => {
        const wrapper = await mountOnTpix();
        await byTest(wrapper, 'wtpix-ack-lock').setValue(true);

        await byTest(wrapper, 'wtpix-lock-amount').setValue('5');
        expect(wrapper.text()).toContain('Minimum is 10 TPIX');
        expect(byTest(wrapper, 'wtpix-lock-button').attributes('disabled')).toBeDefined();

        await byTest(wrapper, 'wtpix-lock-amount').setValue('100001');
        expect(wrapper.text()).toContain('on-chain maximum of 100000');
        expect(byTest(wrapper, 'wtpix-lock-button').attributes('disabled')).toBeDefined();
    });

    it('shows the bridge as closed and offers no working button while it is off', async () => {
        tpixInfo = baseTpixInfo({ enabled: false, installed: false });
        const wrapper = await mountOnTpix();

        expect(byTest(wrapper, 'wtpix-closed').text()).toContain('The TPIX ⇄ wTPIX bridge is not open yet');
        await byTest(wrapper, 'wtpix-lock-amount').setValue('500');
        await byTest(wrapper, 'wtpix-ack-lock').setValue(true);
        expect(byTest(wrapper, 'wtpix-lock-button').attributes('disabled')).toBeDefined();
    });

    it('links the BSC mint and adds wTPIX to the wallet on BSC with the coin logo once minted', async () => {
        localStorage.setItem('tpix.wtpix-bridge.tracked.v1', JSON.stringify([{ direction: 'to_bsc', chainId: 4289, txHash: TX }]));
        statusResponse = {
            state: 'minted',
            transfers: [{ id: 7, direction: 'to_bsc', receive_amount: '499', dest_url: `https://bscscan.com/tx/0x${'ef'.repeat(32)}` }],
        };
        wallet.chainId = 4289;
        const wrapper = await mountOnTpix();

        expect(wrapper.text()).toContain('Done — wTPIX received on BSC');
        expect(byTest(wrapper, 'wtpix-dest-link').attributes('href')).toBe(`https://bscscan.com/tx/0x${'ef'.repeat(32)}`);

        await byTest(wrapper, 'wtpix-add-token').trigger('click');
        await flushPromises();

        expect(wallet.switchChain).toHaveBeenCalledWith(56);
        expect(wallet.watchAsset).toHaveBeenCalledWith({ address: WTPIX, symbol: 'wTPIX', decimals: 18, image: LOGO });
    });
});

describe('Bridge page — wTPIX → TPIX', () => {
    it('burns wTPIX on BSC after switching chains and confirming', async () => {
        wallet.chainId = 4289;
        const wrapper = await mountOnTpix();
        await byTest(wrapper, 'wtpix-tab-to_tpix').trigger('click');

        await byTest(wrapper, 'wtpix-burn-amount').setValue('200');
        expect(byTest(wrapper, 'wtpix-burn-receive').text()).toContain('200');
        await byTest(wrapper, 'wtpix-ack-burn').setValue(true);
        await byTest(wrapper, 'wtpix-burn-button').trigger('click');
        expect(wrapper.text()).toContain('Confirm burn');
        await byTest(wrapper, 'wtpix-confirm').trigger('click');
        await flushPromises();

        expect(wallet.switchChain).toHaveBeenCalledWith(56);
        expect(calls).toEqual([{ fn: 'burn', to: WTPIX, amount: 200n * E18, chainId: 56 }]);
        expect(JSON.parse(localStorage.getItem('tpix.wtpix-bridge.tracked.v1'))[0]).toMatchObject({ direction: 'to_tpix', chainId: 56 });
    });

    it('refuses a burn above the vault release cap (a deposit id pays once — it could never be released)', async () => {
        const wrapper = await mountOnTpix();
        await byTest(wrapper, 'wtpix-tab-to_tpix').trigger('click');
        await byTest(wrapper, 'wtpix-ack-burn').setValue(true);

        await byTest(wrapper, 'wtpix-burn-amount').setValue('150000');

        expect(byTest(wrapper, 'wtpix-burn-button').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('on-chain maximum of 100000');
    });
});
