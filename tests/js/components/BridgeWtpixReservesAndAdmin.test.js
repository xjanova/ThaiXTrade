/**
 * TPIX TRADE — Proof of Reserves (ฝั่ง TPIX ⇄ wTPIX) + หลังบ้านสะพาน wTPIX: เรนเดอร์ได้ และโชว์ตัวเลขถูกที่
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const usdtReserves = { snapshot: null, chains: [], limits: {}, enabled: false, tpix: {}, minter: {} };
const tpixReserves = {
    asset: 'tpix',
    snapshot: {
        status: 'ok', vault: '1000', supply: '999', owed: '0', backing: '1000', deficit: '-1',
        details: { vault_url: 'https://explorer.tpix.online/address/0x7a17000000000000000000000000000000000001', token_url: null },
        checked_at: '2026-10-11T12:00:00+00:00',
    },
    tpix: { chain_id: 4289, vault_address: '0x7a17000000000000000000000000000000000001', explorer: 'https://explorer.tpix.online' },
    bsc: { chain_id: 56, name: 'BNB Smart Chain', wtpix_address: '0x7a17000000000000000000000000000000000002', minter_address: '0x7a17000000000000000000000000000000000003', explorer: 'https://bscscan.com' },
    vault: { min_lock: '10', max_lock: '100000', max_per_tx: '100000', daily_cap: '500000', paused: false },
    minter: { max_per_tx: '100000', daily_cap: '500000', paused: false },
    limits: {},
    enabled: true,
};

const get = vi.fn(async (url, config = {}) => ({ data: { success: true, data: config?.params?.asset === 'tpix' ? tpixReserves : usdtReserves } }));
const post = vi.fn(async () => ({ data: { success: true, message: 'ok' } }));
vi.mock('axios', () => ({ default: { get: (...a) => get(...a), post: (...a) => post(...a), put: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const Reserves = (await import('@/Pages/BridgeReserves.vue')).default;
const Admin = (await import('@/Pages/Admin/Bridge/Wtpix.vue')).default;

beforeEach(() => {
    get.mockClear();
    post.mockClear();
    window.history.replaceState(null, '', '/bridge/reserves');
});

describe('Proof of Reserves — TPIX ⇄ wTPIX', () => {
    it('loads the TPIX snapshot only when that asset is selected and links the vault on the explorer', async () => {
        const wrapper = mount(Reserves);
        await flushPromises();
        expect(get).toHaveBeenCalledTimes(1);

        await wrapper.find('[data-test="reserves-asset-tpix"]').trigger('click');
        await flushPromises();

        expect(get).toHaveBeenLastCalledWith('/api/v1/bridge/reserves', { params: { asset: 'tpix' } });
        expect(wrapper.text()).toContain('TPIX reserves behind wTPIX');
        expect(wrapper.text()).toContain('Fully backed');
        expect(wrapper.text()).toContain('999');
        const vaultLink = wrapper.findAll('a').find((a) => a.attributes('href') === tpixReserves.snapshot.details.vault_url);
        expect(vaultLink.text()).toBe('1000');
    });
});

describe('Admin — TPIX ⇄ wTPIX bridge', () => {
    const state = {
        installed: true, site_switch: true, operational: true, mint_ready: false, release_ready: true,
        checks: [
            { key: 'minter_is_minter', ok: false, hint: 'wTPIX.minters(WTPIXBridgeMinter) ต้องเป็น true' },
            { key: 'guardian_keystore', ok: false, optional: true, hint: 'ตั้ง guardian' },
        ],
        signers: { relayer: { address: '0x7a17000000000000000000000000000000000004', ready: true, passphrase_set: true, bnb: '0.5' } },
        addresses: { vault: '0x7a17000000000000000000000000000000000001' },
        vault: { paused: false, balance: '1000' }, minter: { paused: false }, token: { total_supply: '999' },
    };

    it('shows the readiness checklist and asks for confirmation before approving', async () => {
        const row = { id: 9, direction: 'to_bsc', tx_hash: `0x${'a'.repeat(64)}`, source_chain_id: 4289, log_index: 0, recipient: '0xa11ce00000000000000000000000000000000a11',
            from_address: '0xa11ce00000000000000000000000000000000a11', amount: '150000', receive_amount: '149999', fee: '1', status: 'needs_review', review_reason: 'over_tx_limit' };
        const wrapper = mount(Admin, {
            props: { tab: 'transfers', state, transfers: { data: [row] }, counts: { needs_review: 1 }, settings: { limits: {} }, filters: {} },
        });

        expect(wrapper.text()).toContain('เกินวงเงินต่อครั้ง');
        await wrapper.findAll('button').find((b) => b.text() === 'อนุมัติ').trigger('click');
        await flushPromises();
        expect(post).not.toHaveBeenCalled();
        // ConfirmDialog teleport ไปที่ body — อ่านจาก document แทน wrapper
        expect(document.body.textContent).toContain('ยืนยันการอนุมัติ');
        expect(document.body.textContent).toContain('149999 wTPIX');
        wrapper.unmount();
    });

    it('marks blocking checks red and optional ones as advice', () => {
        const wrapper = mount(Admin, { props: { tab: 'overview', state, settings: { limits: {} }, filters: {} } });

        const list = wrapper.find('[data-test="readiness"]').text();
        expect(list).toContain('wTPIX.minters(minter) = true');
        expect(list).toContain('(แนะนำ)');
        expect(wrapper.text()).toContain('ยังไม่ผ่าน 1 ข้อที่บังคับ');
    });
});
