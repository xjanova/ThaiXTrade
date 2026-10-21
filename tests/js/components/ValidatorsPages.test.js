/**
 * TPIX TRADE — หน้า /validators และ /validators/apply
 *
 * บั๊กที่ชุดนี้กันไม่ให้กลับมา (ตรวจพบ 2026-10-22):
 *   1. หน้าแผนที่ปักหมุดเมืองไทยที่แจกให้ validator ตามลำดับ, ขึ้น Online ทุกตัว, uptime 99.5%
 *      และ "Total Staked" 40M ทั้งที่ NodeRegistryV2 ไม่มีใครวางค้ำ
 *   2. ฟอร์มสมัครให้พิมพ์ที่อยู่กระเป๋าของใครก็ได้ แล้วส่งโดยไม่ต้องเซ็น
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import axios from 'axios';
import ValidatorsIndex from '@/Pages/Validators/Index.vue';
import ValidatorsApply from '@/Pages/Validators/Apply.vue';
import { useWalletStore } from '@/Stores/walletStore';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: { reload: vi.fn() },
    usePage: () => ({ props: {} }),
}));

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', template: '<div><slot /></div>' },
}));

// แผนที่ไม่ใช่สิ่งที่ทดสอบ — Leaflet ต้องการ DOM ขนาดจริงที่ jsdom ไม่มี
vi.mock('leaflet', () => ({ default: {} }));

vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

const WALLET = '0x90f8bf6a479f320ead074411a4b0e7944ea8c9c1';
const OTHER = '0xffcf8fdee72ac11b5c542428b35eef5769c409f0';

const mounted = [];
function mountPage(component, props = {}) {
    const wrapper = mount(component, { props });
    mounted.push(wrapper);
    return wrapper;
}

beforeEach(() => {
    setActivePinia(createPinia());
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval'] });
    axios.post.mockReset();
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.useRealTimers();
});

// ============================================================================
//  /validators
// ============================================================================

describe('Validators/Index', () => {
    const chainValidator = (address, online, signed) => ({
        address,
        tier: 'validator',
        status: 'active',
        online,
        seals_signed: signed,
        seal_window: 20,
        uptime: null,
        country_code: null,
        country_name: null,
        latitude: null,
        longitude: null,
        location_known: false,
        endpoint: null,
        stake_amount: null,
    });

    const props = {
        validators: [
            chainValidator('0x00affa6379fbd969cbe5906cdeee793960a500da', true, 15),
            chainValidator('0xa7d8ea159a59c82effcef88ccd92cf47048b2fde', false, 0),
            chainValidator('0xdeff2d109b52ddc574f6edc374e7e081e4198e20', null, null),
        ],
        stats: {
            chain_reachable: true,
            total_validators: 3,
            active_validators: 1,
            seal_window: 20,
            block_height: 3208341,
            total_staked: null,
        },
        tiers: {},
    };

    it('shows unknown locations instead of made-up cities', () => {
        const w = mountPage(ValidatorsIndex, props);

        expect(w.find('[data-test="locations-unknown"]').exists()).toBe(true);
        expect(w.text()).not.toContain('Bangkok');
        expect(w.text()).toContain('Unknown');
    });

    it('shows signing status from the chain — not "online" for everyone', () => {
        const w = mountPage(ValidatorsIndex, props);

        const dot = addr => w.find(`[data-test="status-${addr}"]`).classes();
        expect(dot('0x00affa6379fbd969cbe5906cdeee793960a500da')).toContain('bg-emerald-500');
        expect(dot('0xa7d8ea159a59c82effcef88ccd92cf47048b2fde')).toContain('bg-red-500');
        expect(dot('0xdeff2d109b52ddc574f6edc374e7e081e4198e20')).toContain('bg-gray-500');

        expect(w.find('[data-test="seals-0x00affa6379fbd969cbe5906cdeee793960a500da"]').text()).toBe('15/20');
        expect(w.find('[data-test="seals-0xdeff2d109b52ddc574f6edc374e7e081e4198e20"]').text()).toBe('—');
        expect(w.find('[data-test="signing-summary"]').text()).toContain('1 / 3 signing');
        expect(w.find('[data-test="hero-signing"]').text()).toBe('1');
    });

    it('shows "—" for stake that is not on NodeRegistryV2', () => {
        const w = mountPage(ValidatorsIndex, props);

        expect(w.find('[data-test="stake-0x00affa6379fbd969cbe5906cdeee793960a500da"]').text()).toBe('—');
        expect(w.text()).not.toContain('40,000,000');
        expect(w.text()).not.toContain('99.5');
    });

    it('draws the reward share bar only from on-chain tier shares', () => {
        const none = mountPage(ValidatorsIndex, props);
        expect(none.find('[data-test="share-bar"]').exists()).toBe(false);

        const live = mountPage(ValidatorsIndex, {
            ...props,
            tiers: {
                guardian: { reward_share_bps: 3500, min_stake: '1000000' },
                sentinel: { reward_share_bps: 3000, min_stake: '100000' },
                light: { reward_share_bps: 1500, min_stake: '10000' },
                validator: { reward_share_bps: 2000, min_stake: '10000000' },
            },
        });
        expect(live.find('[data-test="share-bar"]').text()).toContain('35% Guardian');
    });
});

// ============================================================================
//  /validators/apply
// ============================================================================

describe('Validators/Apply', () => {
    const challenge = { message: 'TPIX Chain node application\n\nWallet: ' + WALLET, nonce: 'a'.repeat(32) };

    function connect(address = WALLET, signerAddress = address) {
        const wallet = useWalletStore();
        wallet.address = address;
        wallet.signer = {
            getAddress: vi.fn(async () => signerAddress),
            signMessage: vi.fn(async () => '0x' + '1'.repeat(130)),
        };
        return wallet;
    }

    async function fillAndSubmit(w) {
        await w.findAll('button[type="button"]').find(b => b.text().includes('Light Node')).trigger('click');
        await w.find('select').setValue('TH');
        await w.find('select').trigger('change');
        await w.find('form').trigger('submit');
        await flushPromises();
    }

    function serverAccepts() {
        axios.post.mockImplementation(async (url) => (url.endsWith('/challenge')
            ? { data: { success: true, data: challenge } }
            : { data: { success: true, data: { id: 1, status: 'pending' } } }));
    }

    it('will not take a typed wallet address — asks to connect instead', () => {
        const w = mountPage(ValidatorsApply);

        expect(w.find('[data-test="apply-connect"]').exists()).toBe(true);
        expect(w.find('input[placeholder="0x..."]').exists()).toBe(false);
        expect(w.find('[data-test="apply-submit"]').attributes('disabled')).toBeDefined();
    });

    it('signs the server message, then submits with the nonce and signature', async () => {
        const wallet = connect();
        serverAccepts();
        const w = mountPage(ValidatorsApply);

        await fillAndSubmit(w);

        expect(axios.post).toHaveBeenCalledTimes(2);
        expect(axios.post.mock.calls[0][0]).toBe('/api/v1/validators/apply/challenge');
        expect(axios.post.mock.calls[0][1]).toMatchObject({ wallet_address: WALLET, tier: 'light', endpoint: null });
        expect(wallet.signer.signMessage).toHaveBeenCalledWith(challenge.message);

        const [url, body] = axios.post.mock.calls[1];
        expect(url).toBe('/api/v1/validators/apply');
        expect(body).toMatchObject({ wallet_address: WALLET, tier: 'light', country_code: 'TH', nonce: challenge.nonce });
        expect(body.signature).toMatch(/^0x[0-9a-f]{130}$/);
        expect(body).not.toHaveProperty('latitude');
        expect(w.text()).toContain('Application Submitted!');
    });

    it('stops when the user cancels the signature', async () => {
        const wallet = connect();
        serverAccepts();
        wallet.signer.signMessage.mockRejectedValue(Object.assign(new Error('user rejected'), { code: 'ACTION_REJECTED' }));
        const w = mountPage(ValidatorsApply);

        await fillAndSubmit(w);

        expect(axios.post).toHaveBeenCalledTimes(1);   // ขอข้อความแล้ว แต่ไม่ได้ส่งใบ
        expect(w.text()).toContain('You cancelled the signature');
        expect(w.find('[data-test="apply-submit"]').attributes('disabled')).toBeUndefined();
    });

    it('refuses when the wallet signer is a different account', async () => {
        connect(WALLET, OTHER);
        serverAccepts();
        const w = mountPage(ValidatorsApply);

        await fillAndSubmit(w);

        expect(axios.post).not.toHaveBeenCalled();
        expect(w.text()).toContain('The wallet account changed');
    });

    it('a double click asks for only one signature', async () => {
        const wallet = connect();
        serverAccepts();
        const w = mountPage(ValidatorsApply);

        await w.findAll('button[type="button"]').find(b => b.text().includes('Light Node')).trigger('click');
        await w.find('select').setValue('TH');
        await w.find('select').trigger('change');
        w.find('form').trigger('submit');
        w.find('form').trigger('submit');
        await flushPromises();

        expect(wallet.signer.signMessage).toHaveBeenCalledTimes(1);
    });

    it('shows tier numbers from the chain, and "—" when they are unknown', () => {
        connect();
        const unknown = mountPage(ValidatorsApply, { tiers: {} });
        expect(unknown.text()).not.toContain('15-20%');
        expect(unknown.text()).toContain('APR —');

        const live = mountPage(ValidatorsApply, { tiers: { light: { min_stake: '10000', lock_days: 7, apr_cap_bps: 1500 } } });
        expect(live.text()).toContain('10,000 TPIX');
        expect(live.text()).toContain('APR cap 15%');
    });
});
