/**
 * TPIX TRADE — หน้า Proof of Reserves + หลังบ้านสะพาน (ตรวจว่าเรนเดอร์ได้และโชว์ตัวเลขถูกที่)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const reserves = {
    snapshot: {
        status: 'ok', supply: '19.99', vault: '19.99', payout_wallets: '0', owed: '0', backing: '19.99', deficit: '0',
        checked_at: '2026-10-11T05:00:00+00:00',
        chains: [{ chain_id: 56, name: 'BNB Smart Chain', symbol: 'USDT', readable: true, vault_units: '19990000', payout_units: '0',
            vault_url: 'https://bscscan.com/token/0x55d3?a=0xf461', payout_url: null }],
    },
    vault_address: '0xf46131c82819d7621163f482b3fe88a228a7807c',
    payout_address: null,
    minter_address: '0x7777777777777777777777777777777777777777',
    minter: { max_per_tx: '1000', daily_cap: '5000', remaining_today: '5000', paused: false },
    tpix: { chain_id: 4289, usdt_tpix: '0x0a4ee87551ef041f3eecfbbfdcffc1b46a4878f1', explorer: 'https://explorer.tpix.online' },
    chains: [{ chain_id: 56, name: 'BNB Smart Chain', usdt_address: '0x55d3', explorer: 'https://bscscan.com', withdraw_fee: '0.3' }],
    limits: {},
    enabled: true,
};

vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: { success: true, data: reserves } })), post: vi.fn(), put: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const Reserves = (await import('@/Pages/BridgeReserves.vue')).default;
const Admin = (await import('@/Pages/Admin/Bridge/Index.vue')).default;

describe('Proof of Reserves page', () => {
    it('shows supply vs backing with explorer links per chain', async () => {
        const wrapper = mount(Reserves);
        await flushPromises();

        expect(wrapper.text()).toContain('Fully backed');
        expect(wrapper.text()).toContain('19.99');
        const link = wrapper.findAll('a').find((a) => a.attributes('href') === 'https://bscscan.com/token/0x55d3?a=0xf461');
        expect(link.text()).toBe('19.99');
        expect(wrapper.text()).toContain(reserves.minter_address);
    });
});

describe('Admin bridge page', () => {
    it('renders the overview with readiness, signers and the switch', () => {
        const wrapper = mount(Admin, {
            props: {
                tab: 'overview',
                state: {
                    installed: true, site_switch: false, operational: false, switch_reason: 'หยุดอัตโนมัติ (reserve_deficit)',
                    readiness: [{ key: 'minter', ok: false, hint: 'ยังไม่ได้ลงทะเบียน bridge_minter' }],
                    signers: { relayer: { address: '0x1000000000000000000000000000000000000001', ready: true, passphrase_set: true } },
                    addresses: { vault: reserves.vault_address, usdt_tpix: reserves.tpix.usdt_tpix, minter: null },
                    minter: {}, paid_today: '0',
                },
                snapshot: { ...reserves.snapshot, unexplained_mint: '0', note: null },
                settings: { limits: { min_deposit: '1' }, chains: [{ chain_id: 56, name: 'BNB Smart Chain', configured: true, enabled: true, withdraw_fee: '0.3', usdt: { symbol: 'USDT', decimals: 18 } }] },
            },
        });

        expect(wrapper.text()).toContain('หยุดอัตโนมัติ (reserve_deficit)');
        expect(wrapper.text()).toContain('ลงทะเบียน TPIXBridgeMinter');
        expect(wrapper.text()).toContain('0x1000000000000000000000000000000000000001');
        expect(wrapper.text()).toContain('เปิดสะพาน');
    });
});
