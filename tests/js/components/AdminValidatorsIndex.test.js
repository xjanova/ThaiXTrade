/**
 * หน้า /admin/validators — ตัวเลขรายตัวจากตัวเฝ้าดู + แท็บผูกกุญแจ + ฟอร์มโหวตที่บังคับ BLS
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { post: (...a) => axiosPost(...a), get: vi.fn().mockResolvedValue({ data: {} }) } }));

import Index from '@/Pages/Admin/Validators/Index.vue';

const BLS = '0x' + 'ab'.repeat(48);
const NEW = '0x' + '1'.repeat(40);

function mountPage(extra = {}) {
    return mount(Index, {
        props: {
            validators: [
                { address: '0x00affa6379fbd969cbe5906cdeee793960a500da', active: true, last_sealed_block: 3207996, last_signed_block: 3207999, blocks_sealed: 75, sign_rate: 74.3, missed_turns: 0, window_blocks: 300, silent: false, staking_wallet: null },
                { address: '0xdeff2d109b52ddc574f6edc374e7e081e4198e20', active: true, last_sealed_block: 3200000, last_signed_block: 3200000, blocks_sealed: 0, sign_rate: 0, missed_turns: 75, window_blocks: 300, silent: true, staking_wallet: null },
            ],
            onboarding: [{
                staking_wallet: '0x' + '5'.repeat(40), validator_address: NEW, bls_public_key: BLS,
                node_id: '16Uiu2HAmSmFLK2z9HhZifQ3xaZmbZkZbT3hcEodRvVdX9tNnB8xv', stage: 'awaiting_vote',
                commands: { script: `sudo bash ~/TPIX-Coin/infrastructure/single-host/validator-vote.sh auth --addr ${NEW} --bls ${BLS}`, lines: [] },
            }],
            stats: { active_validators: 4, quorum: 3, fault_tolerance: 1, votes_needed: 3, silent_validators: 1, awaiting_vote: 1, drop_needed: 0, monitor_block: 3207999 },
            ...extra,
        },
    });
}

const tab = (w, text) => w.findAll('button').find((b) => b.text().includes(text));

beforeEach(() => axiosPost.mockReset());

describe('Admin/Validators/Index', () => {
    it('shows real per-validator numbers and flags the silent one', async () => {
        const w = mountPage();
        await tab(w, 'Active Validators').trigger('click');

        const rows = w.findAll('tbody tr').filter((r) => r.text().includes('0x'));
        const silentRow = rows.find((r) => r.text().includes('0xdeff2d10'));
        expect(silentRow.text()).toContain('0%');
        expect(silentRow.find('.bg-red-500').exists()).toBe(true);
        expect(w.text()).toContain('74.3%');
        expect(w.text()).toContain('#3,207,996');
        expect(w.text()).not.toContain('Blocks Sealed');
    });

    it('lists bindings awaiting a vote with the command to run', async () => {
        const w = mountPage();
        await tab(w, 'ผูกกุญแจ / รอโหวต').trigger('click');
        expect(w.text()).toContain('รอโหวตเข้า');
        expect(w.text()).toContain(`validator-vote.sh auth --addr ${NEW} --bls ${BLS}`);
    });

    it('will not propose an add without a BLS key', async () => {
        const w = mountPage();
        await tab(w, 'Propose Vote').trigger('click');
        const inputs = w.findAll('input');
        await inputs[0].setValue(NEW);
        await w.findAll('button').find((b) => b.text() === 'Propose Vote' && !b.classes().includes('relative')).trigger('click');
        await flushPromises();

        expect(axiosPost).not.toHaveBeenCalled();
        expect(w.text()).toContain('BLS public key');

        axiosPost.mockResolvedValue({ data: { success: true, data: { cli_command: 'x', cli_instructions: ['line-a', 'line-b'], warnings: ['ระวัง'], votes_needed: 3, total_validators: 4 } } });
        await inputs[1].setValue(BLS);
        await w.findAll('button').find((b) => b.text() === 'Propose Vote' && !b.classes().includes('relative')).trigger('click');
        await flushPromises();

        expect(axiosPost).toHaveBeenCalledWith('/admin/validators/propose-vote', { address: NEW, action: 'add', bls: BLS, node_id: null });
        expect(w.text()).toContain('line-a');
        expect(w.text()).toContain('ระวัง');
    });
});
