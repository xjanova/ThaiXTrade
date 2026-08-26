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
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
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

// ค่าคอนฟิกชั้นตามที่สัญญาคืนมาจริง — enum NodeTier { Guardian=0, Sentinel=1, Light=2, Validator=3 }
const CHAIN_TIERS = [
    { tier: 0, name: 'Guardian', min_stake: '1000000', max_nodes: 100, active_nodes: 0, lock_days: 90, slash_percent: 1000, reward_share: 3500, source: 'chain' },
    { tier: 1, name: 'Sentinel', min_stake: '100000', max_nodes: 500, active_nodes: 0, lock_days: 30, slash_percent: 500, reward_share: 3000, source: 'chain' },
    { tier: 2, name: 'Light', min_stake: '10000', max_nodes: 0, active_nodes: 0, lock_days: 7, slash_percent: 0, reward_share: 1500, source: 'chain' },
    { tier: 3, name: 'Validator', min_stake: '10000000', max_nodes: 21, active_nodes: 0, lock_days: 180, slash_percent: 1500, reward_share: 2000, source: 'chain' },
];

function mountPage(props = {}) {
    return mount(MasterNodeIndex, {
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
}

describe('MasterNode — การซื้อโหนด', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        global.fetch = vi.fn(() => Promise.resolve({ ok: false }));
    });

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
            tiers: CHAIN_TIERS.map(t => (t.tier === 2 ? { ...t, min_stake: '25000' } : t)),
        }).vm;

        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(light.minStake).toBe(25000);
        expect(light.lockDays).toBe(7);
    });

    it('ตรวจรูปแบบ endpoint ตามที่สัญญาบังคับ', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;

        for (const good of ['203.0.113.10:8545', 'node1.tpix.online:30303', 'a.co:1', 'x'.repeat(95) + ':8545']) {
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
        const wrapper = mountPage();
        const vm = wrapper.vm;
        const wallet = useWalletStore();
        wallet.address = '0x1111111111111111111111111111111111111111';
        wallet.isConnected = true;
        vm.tpixBalance = 50_000;
        await wrapper.vm.$nextTick();

        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(vm.blockReason(light)).toMatch(/ที่อยู่โหนด/);

        vm.endpoint = '203.0.113.10:8545';
        await wrapper.vm.$nextTick();
        expect(vm.blockReason(light)).toBeNull();
    });

    it('ปิดปุ่มเมื่อชั้นเต็มโควตา แทนที่จะปล่อยให้ tx revert "Tier full"', async () => {
        const wrapper = mountPage({
            tiers: CHAIN_TIERS.map(t => (t.tier === 0 ? { ...t, active_nodes: 100 } : t)),
        });
        const vm = wrapper.vm;
        vm.tpixBalance = 5_000_000;
        vm.endpoint = '203.0.113.10:8545';
        await wrapper.vm.$nextTick();

        const guardian = vm.tierCards.find(t => t.short === 'Guardian');
        expect(guardian.isFull).toBe(true);
        expect(vm.blockReason(guardian)).toMatch(/เต็ม/);
    });

    it('ปิดปุ่ม Validator เมื่อยังไม่ผูกสัญญา KYC', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.tpixBalance = 20_000_000;
        vm.endpoint = '203.0.113.10:8545';
        await wrapper.vm.$nextTick();

        const validator = vm.tierCards.find(t => t.short === 'Validator');
        expect(vm.blockReason(validator)).toMatch(/KYC/);
    });

    it('ปิดปุ่มทุกชั้นเมื่อสัญญายังไม่ deploy', async () => {
        const wrapper = mountPage({ registryLive: false });
        const vm = wrapper.vm;
        vm.tpixBalance = 20_000_000;
        vm.endpoint = '203.0.113.10:8545';
        await wrapper.vm.$nextTick();

        for (const tier of vm.tierCards) {
            expect(vm.blockReason(tier)).toMatch(/ยังไม่ได้ติดตั้ง/);
        }
    });

    it('บอกเหตุผลตอนยอดไม่พอ พร้อมส่วนต่างที่ต้องเติม', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.tpixBalance = 9_000;
        vm.endpoint = '203.0.113.10:8545';
        await wrapper.vm.$nextTick();

        const light = vm.tierCards.find(t => t.short === 'Light');
        expect(vm.blockReason(light)).toContain('1,000');
    });
});

describe('MasterNode — แปลง error ของเชนเป็นภาษาคน', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        global.fetch = vi.fn(() => Promise.resolve({ ok: false }));
    });

    it('แปลง revert reason ของสัญญาเป็นข้อความที่อ่านรู้เรื่อง', () => {
        const vm = mountPage().vm;

        expect(vm.humanError({ reason: 'Still locked' })).toMatch(/ยังไม่พ้นระยะล็อก/);
        expect(vm.humanError({ shortMessage: "reverted with reason string 'Tier full'" })).toMatch(/เต็มโควตา/);
        expect(vm.humanError({ message: 'execution reverted: Insufficient stake' })).toMatch(/น้อยกว่าขั้นต่ำ/);
        expect(vm.humanError({ message: 'execution reverted: KYC not approved' })).toMatch(/KYC/);
    });

    it('ผู้ใช้กดยกเลิกในกระเป๋า ไม่ใช่ error ที่ต้องขู่', () => {
        const vm = mountPage().vm;

        expect(vm.humanError({ code: 'ACTION_REJECTED' })).toMatch(/ยกเลิก/);
        expect(vm.humanError({ message: 'user rejected action' })).toMatch(/ยกเลิก/);
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
    });
});

describe('MasterNode — สถานะโหนดที่ซื้อไปแล้ว', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        global.fetch = vi.fn(() => Promise.resolve({ ok: false }));
    });

    /** โหนดที่ยังทำงานอยู่ */
    function activeNode(overrides = {}) {
        return {
            operator: '0x1111111111111111111111111111111111111111',
            tierId: 2, statusId: 1, stake: 10000,
            registeredAt: 1700000000, unlockAt: 1700604800,
            totalRewards: 0, uptime: 100, nodeId: '0xab', endpoint: '203.0.113.10:8545',
            pendingUnclaimed: 0, pending: 5, claimable: 0,
            isLocked: false, unlockInSeconds: 0,
            ...overrides,
        };
    }

    it('มีโหนดที่ทำงานอยู่แล้ว = ห้ามซื้อซ้ำ (สัญญาจำกัด 1 โหนด/กระเป๋า)', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.myNode = activeNode();
        vm.tpixBalance = 20_000_000;
        vm.endpoint = '203.0.113.10:8545';
        await wrapper.vm.$nextTick();

        expect(vm.canRegister).toBe(false);
        expect(vm.blockReason(vm.tierCards[0])).toMatch(/มีโหนดที่ทำงานอยู่แล้ว/);
    });

    it('มีรางวัลค้างอยู่ = ต้องเคลมก่อนลงทะเบียนใหม่', async () => {
        const wrapper = mountPage();
        const vm = wrapper.vm;
        vm.myNode = activeNode({ statusId: 0, stake: 0, pendingUnclaimed: 12 });
        vm.tpixBalance = 20_000_000;
        vm.endpoint = '203.0.113.10:8545';
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
        vm.tpixBalance = 20_000_000;
        vm.endpoint = '203.0.113.10:8545';
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
});
