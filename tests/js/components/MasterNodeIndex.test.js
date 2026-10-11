/**
 * TPIX TRADE — หน้า Master Node
 *
 * หน้านี้เป็นจุดที่ผู้ใช้ "จ่ายเงินจริง" ล็อกเข้าสัญญา ผิดที่นี่ = เสียเงินจริง
 * บั๊กที่เคยหลุดขึ้น production แล้วชุดนี้กันไม่ให้กลับมา:
 *
 *   1. ส่ง tier index เป็น "ลำดับการ์ดบนหน้าจอ" แทน enum บนสัญญา
 *      → กด Validator (10M) ได้ Guardian · กด Light (10K) tx revert ทิ้ง
 *      (พิสูจน์แล้วบนสัญญาจริง: เวอร์ชันเก่าผิด 4/4 ชั้น)
 *   2. ยัด endpoint ปลอมให้เอง ผู้ใช้ไม่เคยกรอกที่อยู่เครื่องจริง
 *   3. ปล่อยให้กดปุ่มทั้งที่สัญญาจะ revert แน่ ๆ (ชั้นเต็ม / ยังไม่ผ่าน KYC / สัญญายังไม่ deploy)
 *
 * NodeRegistryV2 รุ่นแก้เพดาน APR (2026-10) เพิ่มกติกาที่ต้องกันไว้ด้วย:
 *   4. registerNode ต้องส่ง msg.value == minStake เป๊ะ → ส่ง min_stake_wei เป็น BigInt ตรง ๆ
 *   5. แอดมินปิดรับลงทะเบียนได้ → ปุ่มต้องปิดพร้อมเหตุผล ไม่ใช่ปล่อยให้ revert
 *   6. ดับเบิลคลิก / สลับกระเป๋ากลางรายการ / ยกเลิกเซ็น / RPC ล่ม / wait() ล้มหลังส่ง tx
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import MasterNodeIndex from '@/Pages/MasterNode/Index.vue';
import { useWalletStore } from '@/Stores/walletStore';

// Head/Link ของ Inertia ต้องมี app instance จริง — ไม่เกี่ยวกับสิ่งที่ทดสอบ
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', template: '<div><slot /></div>' },
}));

// walletStore ก็ดึงจากโมดูลนี้ด้วย (formatAddress ฯลฯ) — mock เฉพาะตัวที่ยิงเข้ากระเป๋าจริง
vi.mock('@/utils/web3', async (importOriginal) => ({
    ...(await importOriginal()),
    addTPIXChainToWallet: vi.fn(),
}));

/**
 * เชนจำลอง — หน้าเว็บโหลด ethers แบบ dynamic import ทั้งตอนอ่านและตอนเขียน
 * ทุกเมธอดชี้ไปที่ฟิลด์ใน `chain` ตอนถูกเรียก เทสต์แต่ละตัวจึงสลับพฤติกรรมได้อิสระ
 */
const chain = vi.hoisted(() => {
    const ZERO = '0x0000000000000000000000000000000000000000';
    const s = { ZERO };

    s.reset = () => {
        s.readError = null;
        s.balanceOf = async () => 0n;
        s.nodeOf = async () => ({ operator: ZERO });
        s.pending = 0n;
        s.claimable = 0n;
        s.blockTimestamp = 1_800_000_000;
        s.receipt = null;
        s.staticCall = vi.fn(async () => undefined);
        s.registerNode = vi.fn(async () => s.makeTx('0xstake'));
        s.claimRewards = vi.fn(async () => s.makeTx('0xclaim'));
        s.deregisterNode = vi.fn(async () => s.makeTx('0xdereg'));
        s.withdrawSlashedStake = vi.fn(async () => s.makeTx('0xwithdraw'));
        s.giveConsent = vi.fn(async () => s.makeTx('0xconsent'));
        s.provider.getTransactionReceipt.mockClear();
    };

    s.makeTx = (hash, wait = async () => ({ status: 1 })) => ({ hash, wait: vi.fn(wait) });

    const read = async (fn) => {
        if (s.readError) throw s.readError;
        return fn();
    };

    s.provider = {
        getBalance: (addr) => read(() => s.balanceOf(addr)),
        getBlock: async () => ({ timestamp: s.blockTimestamp }),
        getTransactionReceipt: vi.fn(async () => s.receipt),
    };

    s.makeContract = () => ({
        getNodeInfo: (addr) => read(() => s.nodeOf(addr)),
        pendingReward: () => read(() => s.pending),
        claimableNow: () => read(() => s.claimable),
        registerNode: Object.assign((...a) => s.registerNode(...a), { staticCall: (...a) => s.staticCall(...a) }),
        claimRewards: (...a) => s.claimRewards(...a),
        deregisterNode: (...a) => s.deregisterNode(...a),
        withdrawSlashedStake: (...a) => s.withdrawSlashedStake(...a),
        giveConsent: (...a) => s.giveConsent(...a),
        isApproved: async () => false,
        getRecord: async () => null,
    });

    return s;
});

vi.mock('ethers', () => ({
    Contract: class { constructor() { return chain.makeContract(); } },
    JsonRpcProvider: class { constructor() { return chain.provider; } },
    Network: { from: (id) => ({ chainId: id }) },
}));

const OWNER = '0x1111111111111111111111111111111111111111';
const OTHER = '0x2222222222222222222222222222222222222222';
const EP = '203.0.113.10:8545';
const tpix = (n) => BigInt(n) * 10n ** 18n;

// ค่าคอนฟิกชั้นตามที่สัญญาคืนมาจริง — enum NodeTier { Guardian=0, Sentinel=1, Light=2, Validator=3 }
const CHAIN_TIERS = [
    { tier: 0, name: 'Guardian', min_stake: '1000000', min_stake_wei: tpix(1_000_000).toString(), max_nodes: 100, active_nodes: 0, lock_days: 90, slash_percent: 1000, reward_share: 3500, apr_cap_bps: 1200, projected_apr_bps: 1200, source: 'chain' },
    { tier: 1, name: 'Sentinel', min_stake: '100000', min_stake_wei: tpix(100_000).toString(), max_nodes: 500, active_nodes: 0, lock_days: 30, slash_percent: 500, reward_share: 3000, apr_cap_bps: 900, projected_apr_bps: 900, source: 'chain' },
    { tier: 2, name: 'Light', min_stake: '10000', min_stake_wei: tpix(10_000).toString(), max_nodes: 0, active_nodes: 0, lock_days: 7, slash_percent: 0, reward_share: 1500, apr_cap_bps: 600, projected_apr_bps: 550, source: 'chain' },
    { tier: 3, name: 'Validator', min_stake: '10000000', min_stake_wei: tpix(10_000_000).toString(), max_nodes: 21, active_nodes: 0, lock_days: 180, slash_percent: 1500, reward_share: 2000, apr_cap_bps: 2000, projected_apr_bps: 2000, source: 'chain' },
];

/** stats ที่อ่านจากเชนได้ครบและพูลรางวัลมีเงินจริง */
const FUNDED_STATS = {
    total_nodes: 3, block_height: 814177, rpc_connected: true, registry_deployed: true,
    stats_available: true, reward_pool_known: true, reward_pool_available: '5000000', reward_pool_funded: '5000000',
};

const mounted = [];

function mountPage(props = {}) {
    const wrapper = mount(MasterNodeIndex, {
        props: {
            stats: { total_nodes: 0, block_height: 814177, rpc_connected: true, registry_deployed: true, reward_pool_available: '0' },
            tiers: CHAIN_TIERS,
            registryAddress: '0x1234567890abcdef1234567890abcdef12345678',
            registryLive: true,
            kycContract: null,
            rpcUrl: 'https://rpc.tpix.test',
            chainId: 4289,
            explorerUrl: 'https://explorer.tpix.test',
            ...props,
        },
    });
    mounted.push(wrapper);
    return wrapper;
}

/** dynamic import + promise หลายชั้น — กวาดให้หมดก่อนตรวจ */
async function settle(rounds = 6) {
    for (let i = 0; i < rounds; i++) await flushPromises();
}

/** เชื่อมกระเป๋าแบบที่ walletStore ทำจริง: address + chainId + signer (signer ตาม address ปัจจุบัน) */
async function connect(address = OWNER) {
    const wallet = useWalletStore();
    wallet.chainId = 4289;
    wallet.signer = { getAddress: vi.fn(async () => wallet.address) };
    wallet.address = address;
    await settle();
    return wallet;
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

/** struct getNodeInfo ที่ ethers ถอดมาแล้ว (ตัวเลขเป็น BigInt) */
function nodeStruct(overrides = {}) {
    return {
        operator: OWNER, tier: 2n, status: 1n, stakedAmount: tpix(10_000),
        registeredAt: 1_700_000_000n, unlockAt: 1_700_604_800n, lastRewardAt: 0n,
        totalRewards: 0n, uptime: 10000n, nodeId: '0xab', endpoint: EP,
        rewardDebt: 0n, pendingUnclaimed: 0n,
        ...overrides,
    };
}

beforeAll(async () => {
    // อุ่นโมดูล ethers ที่ถูก mock ไว้ก่อน — import แรกช้ากว่า flushPromises รอบเดียว
    await import('ethers');
});

beforeEach(() => {
    setActivePinia(createPinia());
    chain.reset();
    global.fetch = vi.fn(() => Promise.resolve({ ok: false }));
    vi.spyOn(console, 'warn').mockImplementation(() => {});
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.restoreAllMocks();
});

describe('MasterNode — การซื้อโหนด', () => {
    it('ส่ง tier index ตาม enum บนสัญญา ไม่ใช่ลำดับการ์ดบนหน้าจอ', () => {
        const vm = mountPage().vm;

        // การ์ดเรียงบนหน้าจอแบบแพงสุด→ถูกสุด แต่ id ต้องเป็นเลข enum ของสัญญา
        const byName = Object.fromEntries(vm.tierCards.map(t => [t.short, t.id]));

        expect(byName.Guardian).toBe(0);
        expect(byName.Sentinel).toBe(1);
        expect(byName.Light).toBe(2);
        expect(byName.Validator).toBe(3);

        // ลำดับที่ "แสดง" ต้องยังเป็นแพงสุดก่อน — คนละเรื่องกับ index ที่ส่ง
        expect(vm.tierCards.map(t => t.short)).toEqual(['Validator', 'Guardian', 'Sentinel', 'Light']);
    });

    it('เอาจำนวนเงินขั้นต่ำจากเชน ไม่ใช่ค่า hardcode ในหน้าเว็บ', () => {
        const vm = mountPage({
            // สมมติแอดมินขยับ minStake ของ Light บนเชนเป็น 25,000
            tiers: CHAIN_TIERS.map(t => (t.tier === 2 ? { ...t, min_stake: '25000', min_stake_wei: tpix(25_000).toString() } : t)),
        }).vm;

        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(light.minStake).toBe(25000);
        expect(light.minStakeWei).toBe(tpix(25_000));
        expect(light.lockDays).toBe(7);
    });

    it('ตรวจรูปแบบ endpoint ตามที่สัญญาบังคับ', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;

        for (const good of [EP, 'node1.tpix.online:30303', 'a.co:1', 'x'.repeat(95) + ':8545']) {
            vm.endpoint = good;
            await wrapper.vm.$nextTick();
            expect(vm.endpointValid, `${good} ควรผ่าน`).toBe(true);
        }

        for (const bad of ['', '203.0.113.10', 'not a host:8545', '1.2.3.4:0', '1.2.3.4:70000', 'x'.repeat(100) + ':8545']) {
            vm.endpoint = bad;
            await wrapper.vm.$nextTick();
            expect(vm.endpointValid, `${bad || '(ว่าง)'} ควรไม่ผ่าน`).toBe(false);
        }
    });

    it('ไม่ให้กดซื้อจนกว่าจะกรอก endpoint — สัญญา revert "Endpoint required"', async () => {
        chain.balanceOf = async () => tpix(50_000);
        const wrapper = mountPage();
        const vm = wrapper.vm;
        await connect();

        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(vm.blockReason(light)).toMatch(/ที่อยู่โหนด/);

        vm.endpoint = EP;
        await wrapper.vm.$nextTick();
        expect(vm.blockReason(light)).toBeNull();
    });

    it('ปิดปุ่มเมื่อชั้นเต็มโควตา แทนที่จะปล่อยให้ tx revert "Tier full"', async () => {
        const wrapper = mountPage({
            tiers: CHAIN_TIERS.map(t => (t.tier === 0 ? { ...t, active_nodes: 100 } : t)),
        });
        const vm = wrapper.vm;
        vm.tpixBalanceWei = tpix(5_000_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        const guardian = vm.tierCards.find(t => t.short === 'Guardian');
        expect(guardian.isFull).toBe(true);
        expect(vm.blockReason(guardian)).toMatch(/เต็ม/);
    });

    it('ปิดปุ่ม Validator เมื่อยังไม่ผูกสัญญา KYC', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.tpixBalanceWei = tpix(20_000_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        const validator = vm.tierCards.find(t => t.short === 'Validator');
        expect(vm.blockReason(validator)).toMatch(/KYC/);
    });

    it('ปิดปุ่มทุกชั้นเมื่อสัญญายังไม่ deploy', async () => {
        const wrapper = mountPage({ registryLive: false });
        const vm = wrapper.vm;
        vm.tpixBalanceWei = tpix(20_000_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        for (const tier of vm.tierCards) {
            expect(vm.blockReason(tier)).toMatch(/ยังไม่ได้ติดตั้ง/);
        }
    });

    it('บอกเหตุผลตอนยอดไม่พอ พร้อมส่วนต่างที่ต้องเติม', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.tpixBalanceWei = tpix(9_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(vm.blockReason(light)).toContain('1,000');
    });

    it('เทียบยอดเป็น wei — ขาดแค่ 1 wei ก็ยังไม่พอ (float จะปัดให้ผ่าน)', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        // 9,999.999999999999999999 TPIX — Number() ปัดเป็น 10000 พอดี
        vm.tpixBalanceWei = tpix(10_000) - 1n;
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(light.canAfford).toBe(false);
        expect(vm.blockReason(light)).toMatch(/ต้องมีอีก 1 TPIX/);
    });

    it('ยังไม่รู้ยอด = ยังไม่ให้กด (ไม่ใช่ถือว่ายอดเป็น 0 หรือพอ)', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(vm.tpixBalanceWei).toBeNull();
        expect(vm.blockReason(light)).toMatch(/ยอด TPIX/);
    });
});

describe('MasterNode — เพดาน APR', () => {
    it('ป้ายผลตอบแทนมาจากเพดานบนเชน + คาดการณ์ของโหนดใหม่', async () => {
        // พูลมีเงินจริง — คาดการณ์ถึงจะขึ้นเป็นตัวเลข % (พูลว่างดูเทสต์ "พูลรางวัลยังไม่ถูกเติมเงิน")
        const wrapper = mountPage({ stats: { ...FUNDED_STATS } });
        const light = wrapper.vm.tierCards.find(t => t.short === 'Light');

        expect(light.apyBadge).toBe('สูงสุด 6% ต่อปี');
        expect(light.apyNote).toBe('');
        expect(light.projectedAprBps).toBe(550);
        expect(light.projectedTone).toBe('good');

        await connect();
        expect(wrapper.text()).toContain('สูงสุด 6% ต่อปี');
        expect(wrapper.text()).toContain('5.5% ต่อปี');
        // ช่วงตัวเลขที่เคยเขียนมือไว้ต้องไม่โผล่เมื่ออ่านเชนได้แล้ว
        expect(wrapper.text()).not.toContain('4-6%');
    });

    it('ยังไม่ deploy = ใช้เพดานค่าตั้งต้นพร้อมป้ายบอก และไม่มีตัวเลขคาดการณ์ปลอม', () => {
        const configTiers = CHAIN_TIERS.map(t => ({ ...t, source: 'config', projected_apr_bps: null }));
        const vm = mountPage({ registryLive: false, tiers: configTiers }).vm;
        const light = vm.tierCards.find(t => t.short === 'Light');

        expect(light.apyBadge).toBe('สูงสุด 6% ต่อปี');
        expect(light.apyNote).toMatch(/ยังไม่ได้อ่านจากเชน/);
        expect(light.projectedAprBps).toBeNull();
    });

    it('ไม่มีข้อมูลเพดานเลย = ช่วงประมาณการพร้อมป้าย "ประมาณการ"', () => {
        const bare = CHAIN_TIERS.map(({ apr_cap_bps, projected_apr_bps, ...t }) => t);
        const vm = mountPage({ tiers: bare }).vm;
        const light = vm.tierCards.find(t => t.short === 'Light');

        expect(light.apyBadge).toBe('4-6% ต่อปี');
        expect(light.apyNote).toMatch(/ประมาณการ/);
    });

    it('หัวหน้าเว็บโชว์เพดานสูงสุดจากเชน ไม่ใช่ 20% ที่เขียนตายตัว', () => {
        const vm = mountPage({
            tiers: CHAIN_TIERS.map(t => (t.tier === 3 ? { ...t, apr_cap_bps: 2500 } : t)),
        }).vm;

        expect(vm.heroMaxApr).toBe('25%');
    });

    it('คำอธิบายการแบ่งรางวัลพูดถึงแบ่งเท่ากัน + เพดาน + uptime', async () => {
        const wrapper = mountPage();
        await connect();

        const text = wrapper.find('[data-test="reward-explainer"]').text();
        expect(text).toContain('เท่ากันทุกโหนด');
        expect(text).toContain('เพดาน');
        expect(text).toContain('6%');
        expect(text).toContain('uptime');
    });
});

describe('MasterNode — พูลรางวัลยังไม่ถูกเติมเงิน / อ่านเชนไม่ได้', () => {
    // สถานะจริงบน production (2026-10-11): สัญญา deploy แล้ว ไม่มีโหนด พูลรางวัล 0
    const UNFUNDED_STATS = {
        total_nodes: 0, block_height: 814177, rpc_connected: true, registry_deployed: true,
        stats_available: true, reward_pool_known: true, reward_pool_available: '0', reward_pool_funded: '0',
    };

    it('พูลว่าง → ป้ายเตือนตั้งแต่ยังไม่เชื่อมกระเป๋า และไม่โชว์ % สีเขียวเป็นตัวชูโรง', () => {
        const wrapper = mountPage({ stats: UNFUNDED_STATS });

        const banner = wrapper.find('[data-test="pool-unfunded"]');
        expect(banner.exists()).toBe(true);
        expect(banner.text()).toContain('จ่ายได้ 0 TPIX');
        expect(banner.text()).toContain('ไม่ใช่ผลตอบแทนที่รับประกัน');

        // หัวหน้าเว็บ: แทนที่ "สูงสุดต่อปี 20%" ด้วยสถานะพูล
        expect(wrapper.find('[data-test="hero-pool-unfunded"]').text()).toContain('ยังไม่เติมเงิน');
        expect(wrapper.text()).not.toContain('สูงสุดต่อปี');
    });

    it('พูลว่าง → คาดการณ์ถ้าเข้าตอนนี้ = จ่ายได้ 0 ไม่ใช่เลข %', async () => {
        const wrapper = mountPage({ stats: UNFUNDED_STATS });
        await connect();

        const light = wrapper.vm.tierCards.find(t => t.short === 'Light');
        expect(light.projectedAprBps).toBe(550); // ค่าจากสัญญายังเก็บไว้
        expect(light.projectedTone).toBe('warn');
        expect(light.projectedText).toContain('จ่ายได้ 0');

        const projections = wrapper.findAll('[data-test="tier-projection"]').map(n => n.text());
        expect(projections.some(t => t.includes('5.5% ต่อปี'))).toBe(false);
    });

    it('stats อ่านเชนไม่ได้ → "—" ไม่ใช่ "0 โหนด" และไม่ประกาศว่าพูลว่าง', () => {
        const wrapper = mountPage({
            stats: { ...UNFUNDED_STATS, stats_available: false, reward_pool_known: false },
        });
        const vm = wrapper.vm;

        expect(vm.poolState).toBe('unknown');
        expect(vm.poolUnfunded).toBe(false);
        expect(wrapper.find('[data-test="pool-unfunded"]').exists()).toBe(false);
        expect(vm.statNum(0)).toBe('—');

        // ไม่รู้ว่าพูลมีเงินไหม = โชว์คาดการณ์ได้ แต่ไม่เน้นสีเขียว
        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(light.projectedTone).toBe('muted');
    });

    it('ตรวจสัญญาไม่ได้ตอนโหลดหน้า → ไม่ขึ้น "รอติดตั้งสัญญา" และปิดปุ่มพร้อมเหตุผล', async () => {
        const wrapper = mountPage({ registryLive: false, registryUnknown: true });
        const vm = wrapper.vm;
        vm.tpixBalanceWei = tpix(20_000_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-test="registry-unknown"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('ระบบรอติดตั้งสัญญาบนเชน');
        for (const tier of vm.tierCards) {
            expect(vm.blockReason(tier)).toMatch(/อ่านสถานะสัญญาจากเชนไม่ได้/);
        }
    });

    it('ขั้นต่ำบนหัวหน้าเว็บมาจากชั้นที่ถูกที่สุดบนเชน ไม่ใช่ "10K" ที่เขียนตายตัว', () => {
        const vm = mountPage({
            tiers: CHAIN_TIERS.map(t => (t.tier === 2 ? { ...t, min_stake: '25000', min_stake_wei: tpix(25_000).toString() } : t)),
        }).vm;

        expect(vm.minEntryText).toBe('25K');
    });
});

describe('MasterNode — ปิดรับลงทะเบียน', () => {
    it('สัญญาปิดรับ → ปุ่มปิดพร้อมเหตุผล', async () => {
        const wrapper = mountPage({ registrationPaused: true });
        const vm = wrapper.vm;
        vm.tpixBalanceWei = tpix(50_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        for (const tier of vm.tierCards) {
            expect(vm.blockReason(tier)).toMatch(/ปิดรับลงทะเบียน/);
        }
    });

    it('ค่าจาก stats ที่ poll ใหม่ชนะค่าตอนโหลดหน้า', async () => {
        const wrapper = mountPage({ registrationPaused: null });
        const vm = wrapper.vm;
        expect(vm.isRegistrationPaused).toBe(false);

        vm.networkStats = { ...vm.networkStats, registration_paused: true };
        await wrapper.vm.$nextTick();
        expect(vm.isRegistrationPaused).toBe(true);
    });

    it('ไม่ทราบสถานะ (null) ไม่ถูกตีความว่าปิดรับ', () => {
        const vm = mountPage({ registrationPaused: null }).vm;
        expect(vm.isRegistrationPaused).toBe(false);
    });

    it('แสดงป้ายปิดรับบนหน้า และย้ำว่าเงินของคนที่อยู่แล้วยังออกได้', async () => {
        const wrapper = mountPage({ registrationPaused: true });
        await connect();

        const banner = wrapper.find('[data-test="registration-paused"]');
        expect(banner.exists()).toBe(true);
        expect(banner.text()).toMatch(/ถอนเงินต้นได้/);
    });
});

describe('MasterNode — แปลง error ของเชนเป็นภาษาคน', () => {
    it('แปลง revert reason ของสัญญาเป็นข้อความที่อ่านรู้เรื่อง', () => {
        const vm = mountPage().vm;

        expect(vm.humanError({ reason: 'Still locked' })).toMatch(/ยังไม่พ้นระยะล็อก/);
        expect(vm.humanError({ shortMessage: "reverted with reason string 'Tier full'" })).toMatch(/เต็มโควตา/);
        expect(vm.humanError({ message: 'execution reverted: Insufficient stake' })).toMatch(/น้อยกว่าขั้นต่ำ/);
        expect(vm.humanError({ message: 'execution reverted: KYC not approved' })).toMatch(/KYC/);
    });

    it('แปลง revert ใหม่ของ NodeRegistryV2 (ปิดรับ / ต้องวางเท่าขั้นต่ำพอดี)', () => {
        const vm = mountPage().vm;

        expect(vm.humanError({ reason: 'Registration paused' })).toMatch(/ปิดรับลงทะเบียน/);
        expect(vm.humanError({ message: 'execution reverted: Stake must equal tier minimum' })).toMatch(/เท่ากับขั้นต่ำ/);
    });

    it('ผู้ใช้กดยกเลิกในกระเป๋า ไม่ใช่ error ที่ต้องขู่', () => {
        const vm = mountPage().vm;

        expect(vm.humanError({ code: 'ACTION_REJECTED' })).toMatch(/ยกเลิก/);
        expect(vm.humanError({ message: 'user rejected action' })).toMatch(/ยกเลิก/);
        expect(vm.humanError({ info: { error: { code: 4001 } } })).toMatch(/ยกเลิก/);
    });

    it('ไม่โยนก้อน JSON ของ ethers ขึ้นจอ', () => {
        const vm = mountPage().vm;
        const ethersBlob = {
            message: 'could not coalesce error (error={ "code": -32603, "data": { "message": "..." } }, payload={...})',
            shortMessage: 'could not coalesce error',
        };

        const shown = vm.humanError(ethersBlob);
        expect(shown.length).toBeLessThan(80);
        expect(shown).not.toContain('payload');
        expect(shown).not.toContain('coalesce');
    });
});

describe('MasterNode — ส่งธุรกรรม', () => {
    async function readyToStake(tiers = CHAIN_TIERS) {
        chain.balanceOf = async () => tpix(20_000);
        const wrapper = mountPage({ tiers });
        const wallet = await connect();
        wrapper.vm.endpoint = EP;
        await wrapper.vm.$nextTick();
        const light = wrapper.vm.tierCards.find(t => t.short === 'Light');
        expect(wrapper.vm.blockReason(light)).toBeNull();
        return { wrapper, vm: wrapper.vm, wallet, light };
    }

    it('ส่ง msg.value = min_stake_wei เป๊ะเป็น BigInt + tier enum + gasPrice 0', async () => {
        // ขั้นต่ำที่มีเศษ wei — ถ้าผ่าน float/parseUnits ของเลขที่ปัดแล้วจะส่งผิดไป 1 wei แล้ว revert
        const odd = '10000000000000000000001';
        const { vm, light } = await readyToStake(
            CHAIN_TIERS.map(t => (t.tier === 2 ? { ...t, min_stake_wei: odd } : t)),
        );

        await vm.stakeAndRegister(light);
        await settle();

        expect(chain.staticCall).toHaveBeenCalledWith(2, EP, { value: BigInt(odd) });
        expect(chain.registerNode).toHaveBeenCalledTimes(1);
        const [tierArg, epArg, overrides] = chain.registerNode.mock.calls[0];
        expect(tierArg).toBe(2);
        expect(epArg).toBe(EP);
        expect(typeof overrides.value).toBe('bigint');
        expect(overrides.value).toBe(10000000000000000000001n);
        expect(overrides.gasPrice).toBe(0);
        expect(vm.stakeTxHash).toBe('0xstake');
        expect(vm.stakeError).toBe('');
    });

    it('ดับเบิลคลิกปุ่มวางค้ำ = ส่งรายการเดียว', async () => {
        const { vm, light } = await readyToStake();

        await Promise.all([vm.stakeAndRegister(light), vm.stakeAndRegister(light), vm.stakeAndRegister(light)]);

        expect(chain.staticCall).toHaveBeenCalledTimes(1);
        expect(chain.registerNode).toHaveBeenCalledTimes(1);
    });

    it('ดับเบิลคลิกปุ่มรับรางวัล = ส่งรายการเดียว', async () => {
        const wrapper = mountPage();
        await connect();
        wrapper.vm.myNode = {
            operator: OWNER, tierId: 2, statusId: 1, stake: 10000, registeredAt: 1, unlockAt: 1,
            totalRewards: 0, uptime: 100, nodeId: '0xab', endpoint: EP,
            pendingUnclaimed: 0, pending: 5, claimable: 5, isLocked: false, unlockInSeconds: 0,
        };

        await Promise.all([wrapper.vm.claimRewards(), wrapper.vm.claimRewards()]);

        expect(chain.claimRewards).toHaveBeenCalledTimes(1);
        expect(chain.claimRewards).toHaveBeenCalledWith({ gasPrice: 0 });
    });

    it('ระหว่างรายการหนึ่งยังไม่จบ ปุ่มเขียนอื่นทุกปุ่มไม่ทำงาน', async () => {
        const { vm, light } = await readyToStake();
        const gate = deferred();
        chain.staticCall = vi.fn(() => gate.promise);

        const staking = vm.stakeAndRegister(light);
        await settle();
        expect(vm.busy).toBe(true);

        vm.myNode = { operator: OWNER, statusId: 2, stake: 1, pending: 0, claimable: 1, pendingUnclaimed: 0, isLocked: false };
        await vm.withdrawSlashedStake();
        await vm.claimRewards();
        expect(chain.withdrawSlashedStake).not.toHaveBeenCalled();
        expect(chain.claimRewards).not.toHaveBeenCalled();

        gate.resolve();
        await staking;
    });

    it('สลับกระเป๋าก่อนส่ง tx → หยุด ไม่ส่ง และบอกเป็นภาษาไทย', async () => {
        const { vm, wallet, light } = await readyToStake();
        const gate = deferred();
        chain.staticCall = vi.fn(() => gate.promise);

        const staking = vm.stakeAndRegister(light);
        await settle();
        expect(chain.staticCall).toHaveBeenCalledTimes(1);

        wallet.address = OTHER;           // ผู้ใช้สลับบัญชีในกระเป๋าระหว่างรอ
        await settle();
        gate.resolve();
        await staking;
        await settle();

        expect(chain.registerNode).not.toHaveBeenCalled();
        expect(vm.flowNotice).toMatch(/สลับกระเป๋า/);
        expect(vm.flowNotice).toContain('0x1111');
        expect(vm.stakeTxHash).toBe('');
        expect(vm.isStaking).toBe(false);
    });

    it('signer เป็นคนละบัญชีกับตอนกดปุ่ม → ไม่ส่ง', async () => {
        // กระเป๋าบางตัวอัปเดต address ก่อน signer — address ยังเป็น OWNER แต่ signer เป็นอีกบัญชี
        const { vm, wallet, light } = await readyToStake();
        wallet.signer = { getAddress: vi.fn(async () => OTHER) };

        await vm.stakeAndRegister(light);

        expect(chain.registerNode).not.toHaveBeenCalled();
        expect(vm.flowNotice).toMatch(/สลับกระเป๋า/);
    });

    it('สลับกระเป๋าระหว่างรอเชนยืนยัน → ไม่แปะผลให้กระเป๋าใหม่', async () => {
        const { vm, wallet, light } = await readyToStake();
        const mined = deferred();
        chain.registerNode = vi.fn(async () => chain.makeTx('0xstake', () => mined.promise));

        const staking = vm.stakeAndRegister(light);
        await settle();
        expect(chain.registerNode).toHaveBeenCalledTimes(1);

        wallet.address = OTHER;
        await settle();
        mined.resolve({ status: 1 });
        await staking;
        await settle();

        expect(vm.stakeTxHash, 'การ์ด "ลงทะเบียนเรียบร้อย" ต้องไม่โผล่บนกระเป๋าใหม่').toBe('');
        expect(vm.flowNotice).toMatch(/0x1111.*สำเร็จบนเชนแล้ว/);
    });

    it('ผลอ่านที่มาช้าของกระเป๋าเดิมไม่เขียนทับกระเป๋าใหม่', async () => {
        const slowNode = deferred();
        const slowBalance = deferred();
        chain.nodeOf = (addr) => (addr === OWNER ? slowNode.promise : Promise.resolve({ operator: chain.ZERO }));
        chain.balanceOf = (addr) => (addr === OWNER ? slowBalance.promise : Promise.resolve(tpix(5)));

        const wrapper = mountPage();
        const wallet = await connect(OWNER);   // คำขอของ OWNER ค้างอยู่
        wallet.address = OTHER;
        await settle();
        expect(wrapper.vm.tpixBalanceWei).toBe(tpix(5));

        // ตอนนี้คำตอบของ OWNER เพิ่งมา — ต้องถูกทิ้ง
        slowNode.resolve(nodeStruct());
        slowBalance.resolve(tpix(999_999));
        await settle();

        expect(wrapper.vm.myNode).toBeNull();
        expect(wrapper.vm.tpixBalanceWei).toBe(tpix(5));
    });

    it('ผู้ใช้กดยกเลิกการเซ็น → ข้อความยกเลิก ไม่มีสปินเนอร์ค้าง', async () => {
        const { vm, light } = await readyToStake();
        chain.registerNode = vi.fn(async () => { throw Object.assign(new Error('user rejected action'), { code: 'ACTION_REJECTED' }); });

        await vm.stakeAndRegister(light);

        expect(vm.stakeError).toMatch(/ยกเลิก/);
        expect(vm.isStaking).toBe(false);
        expect(vm.pendingTx).toBeNull();
        expect(vm.busy).toBe(false);
    });

    it('ส่ง tx แล้วแต่ wait() ล้มเพราะเน็ต → โชว์ hash "รอการยืนยัน" แล้วตรวจซ้ำจนรู้ผล', async () => {
        const { wrapper, vm, light } = await readyToStake();
        chain.registerNode = vi.fn(async () => chain.makeTx('0xslow', async () => { throw new Error('network timeout'); }));

        await vm.stakeAndRegister(light);
        await settle();

        expect(vm.pendingTx).toMatchObject({ hash: '0xslow', kind: 'stake' });
        expect(vm.stakeError, 'ส่งไปแล้วห้ามบอกว่าล้มเหลว').toBe('');
        expect(vm.isStaking).toBe(false);
        const card = wrapper.find('[data-test="pending-tx"]');
        expect(card.exists()).toBe(true);
        expect(card.text()).toContain('รอการยืนยัน');
        expect(card.text()).toContain('0xslow');

        // ระหว่างยังไม่รู้ผล ห้ามส่งรายการใหม่ซ้อน
        await vm.stakeAndRegister(light);
        expect(chain.registerNode).toHaveBeenCalledTimes(1);

        // เชนกลับมา — receipt ยืนยันแล้ว
        chain.receipt = { status: 1 };
        await vm.checkPendingTx();
        await settle();

        expect(chain.provider.getTransactionReceipt).toHaveBeenCalledWith('0xslow');
        expect(vm.pendingTx).toBeNull();
        expect(vm.stakeTxHash).toBe('0xslow');
    });

    it('tx ถูกขุดแต่ revert → จบทันทีพร้อมเหตุผล ไม่ค้าง "รอการยืนยัน"', async () => {
        const { vm, light } = await readyToStake();
        chain.registerNode = vi.fn(async () => chain.makeTx('0xrev', async () => {
            throw Object.assign(new Error('execution reverted'), { code: 'CALL_EXCEPTION', receipt: { status: 0 }, reason: 'Tier full' });
        }));

        await vm.stakeAndRegister(light);

        expect(vm.pendingTx).toBeNull();
        expect(vm.stakeError).toMatch(/เต็มโควตา/);
    });

    it('ขั้นต่ำบนเชนเปลี่ยนระหว่างเปิดหน้าค้าง → โหลดชั้นใหม่ให้เอง', async () => {
        const { vm, light } = await readyToStake();
        chain.staticCall = vi.fn(async () => { throw Object.assign(new Error('x'), { reason: 'Stake must equal tier minimum' }); });
        global.fetch.mockClear();

        await vm.stakeAndRegister(light);

        expect(vm.stakeError).toMatch(/เท่ากับขั้นต่ำ/);
        expect(global.fetch).toHaveBeenCalledWith('/api/v1/masternode/tiers');
        expect(chain.registerNode).not.toHaveBeenCalled();
    });
});

describe('MasterNode — อ่านเชนไม่ได้', () => {
    it('RPC ล่ม → บอกตรง ๆ + ปุ่มลองใหม่ ไม่ใช่ทำเหมือน "ยังไม่มีโหนด"', async () => {
        chain.readError = new Error('could not detect network');
        const wrapper = mountPage();
        await connect();

        const vm = wrapper.vm;
        expect(vm.chainReadError).toBe(true);
        const card = wrapper.find('[data-test="chain-read-error"]');
        expect(card.exists()).toBe(true);
        expect(card.text()).toContain('อ่านข้อมูลจากเชนไม่ได้');

        vm.tpixBalanceWei = tpix(50_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();
        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(vm.blockReason(light)).toMatch(/อ่านข้อมูลจากเชนไม่ได้/);

        // เชนกลับมา → กดลองใหม่แล้วหายเอง
        chain.readError = null;
        chain.balanceOf = async () => tpix(50_000);
        await wrapper.find('[data-test="retry-chain-read"]').trigger('click');
        await settle();

        expect(vm.chainReadError).toBe(false);
        expect(wrapper.find('[data-test="chain-read-error"]').exists()).toBe(false);
        expect(vm.blockReason(light)).toBeNull();
    });

    it('ล็อกเทียบกับเวลาของเชน ไม่ใช่นาฬิกาเครื่องผู้ใช้', async () => {
        // เชนบอกว่าพ้นล็อกแล้ว แม้นาฬิกาเครื่อง (2026) จะยังไม่ถึงวันปลดล็อก
        chain.blockTimestamp = 2_000_000_000;
        chain.nodeOf = async () => nodeStruct({ unlockAt: 1_900_000_000n });
        const wrapper = mountPage();
        await connect();

        expect(wrapper.vm.myNode.isLocked).toBe(false);
    });
});

describe('MasterNode — สถานะโหนดที่ซื้อไปแล้ว', () => {
    /** โหนดที่ยังทำงานอยู่ */
    function activeNode(overrides = {}) {
        return {
            operator: OWNER,
            tierId: 2, statusId: 1, stake: 10000,
            registeredAt: 1700000000, unlockAt: 1700604800,
            totalRewards: 0, uptime: 100, nodeId: '0xab', endpoint: EP,
            pendingUnclaimed: 0, pending: 5, claimable: 0,
            isLocked: false, unlockInSeconds: 0,
            ...overrides,
        };
    }

    it('มีโหนดที่ทำงานอยู่แล้ว = ห้ามซื้อซ้ำ (สัญญาจำกัด 1 โหนด/กระเป๋า)', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.myNode = activeNode();
        vm.tpixBalanceWei = tpix(20_000_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        expect(vm.canRegister).toBe(false);
        expect(vm.blockReason(vm.tierCards[0])).toMatch(/มีโหนดที่ทำงานอยู่แล้ว/);
    });

    it('มีรางวัลค้างอยู่ = ต้องเคลมก่อนลงทะเบียนใหม่', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.myNode = activeNode({ statusId: 0, stake: 0, pendingUnclaimed: 12 });
        vm.tpixBalanceWei = tpix(20_000_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        expect(vm.canRegister).toBe(true);
        expect(vm.blockReason(vm.tierCards[2])).toMatch(/รับรางวัลค้าง/);
    });

    it('ซ่อนการ์ดโหนดหลังปิดโหนดเสร็จ ไม่ค้างการ์ดเปล่า', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;

        vm.myNode = activeNode();
        await wrapper.vm.$nextTick();
        expect(vm.showNodeCard).toBe(true);

        // หลัง deregister: struct ยังอยู่บนเชนแต่ status=Inactive, stake=0
        vm.myNode = activeNode({ statusId: 0, stake: 0, pending: 0, pendingUnclaimed: 0 });
        await wrapper.vm.$nextTick();
        expect(vm.showNodeCard).toBe(false);
    });

    it('โหนดที่ถูกปรับยังต้องเห็นการ์ดเพื่อกดถอนเงินต้นที่เหลือ', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.myNode = activeNode({ statusId: 2 });
        await wrapper.vm.$nextTick();

        expect(vm.showNodeCard).toBe(true);
    });

    it('โหนดที่ถูกปรับยังลงทะเบียนใหม่ไม่ได้จนกว่าจะถอนเงินต้นที่เหลือ', async () => {
        // สัญญาบังคับ status == Inactive — Slashed(2) ยังไม่ใช่ จะ revert "Already registered"
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.myNode = activeNode({ statusId: 2, pendingUnclaimed: 0 });
        vm.tpixBalanceWei = tpix(20_000_000);
        vm.endpoint = EP;
        await wrapper.vm.$nextTick();

        expect(vm.blockReason(vm.tierCards[2])).toMatch(/ถอนเงินต้นที่เหลือ/);
    });

    it('เตือนเมื่อพูลรางวัลยังไม่ถูกเติมเงิน', async () => {
        const wrapper = mountPage();
        expect(wrapper.vm.poolUnfunded).toBe(true);

        const funded = mountPage({
            stats: { registry_deployed: true, rpc_connected: true, reward_pool_available: '1000000' },
        });
        expect(funded.vm.poolUnfunded).toBe(false);
    });

    it('พูลว่าง → ปุ่มรับรางวัลปิด พร้อมบอกว่ารางวัลไม่หาย และกดไปก็ไม่ส่ง tx', async () => {
        chain.nodeOf = async () => nodeStruct();
        chain.pending = tpix(3);
        chain.claimable = 0n;
        const wrapper = mountPage();
        await connect();

        const vm = wrapper.vm;
        expect(vm.myNode.pending).toBe(3);
        const claimBtn = wrapper.findAll('button').find(b => b.text().includes('รับรางวัล'));
        expect(claimBtn.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('ไม่หาย');

        await vm.claimRewards();
        expect(chain.claimRewards).not.toHaveBeenCalled();
        expect(vm.claimMessage).toMatch(/ไม่หาย/);
    });

    it('โชว์สัญญาณชีพล่าสุดจากโปรแกรม PC — อ่านไม่ได้ก็ซ่อนเงียบ ๆ', async () => {
        chain.nodeOf = async () => nodeStruct();
        global.fetch = vi.fn((url) => (String(url).startsWith('/api/v1/node/status/')
            ? Promise.resolve({ ok: true, json: async () => ({ exists: true, last_heartbeat: 1_790_000_000, is_active: true }) })
            : Promise.resolve({ ok: false })));
        const wrapper = mountPage();
        await connect();

        const line = wrapper.find('[data-test="heartbeat"]');
        expect(line.exists()).toBe(true);
        expect(line.text()).toContain('ส่งสัญญาณล่าสุด');
        expect(line.text()).toContain('ทีมงาน');

        const failing = mountPage();
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await failing.vm.fetchHeartbeat();
        await settle();
        expect(failing.vm.heartbeat).toBeNull();
    });
});
