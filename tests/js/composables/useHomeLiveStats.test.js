/**
 * TPIX TRADE — ข้อมูลสดของหน้าแรกแบบธรรมดา (useHomeLiveStats)
 *
 * - ทุกตัวเลขมาจาก API จริง: ราคา / บล็อก / validator / สัญญา Masternode / รอบขาย
 * - API ระดับโหนดโหมด config (สัญญายังไม่ deploy) ไม่ส่งเพดาน APR → เติมจากค่าคงที่ตามชื่อระดับ
 * - API ตัวไหนล้ม ช่องนั้นต้องรู้ว่าล้ม (หน้าจะแสดง "—") แต่ช่องอื่นยังทำงาน
 * - ระดับโหนดเรียงจาก stake น้อยไปมากเสมอ ไม่ว่าสัญญาจะเรียง index แบบไหน
 * - หยุดรีเฟรชและยกเลิกคำขอค้างเมื่อออกจากหน้า
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import { useHomeLiveStats } from '@/Composables/useHomeLiveStats';

function ok(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, data }) });
}

function routes(overrides = {}) {
    const table = {
        '/api/v1/tpix/price': () => ok({ price: 0.1, change_24h: 0, source: 'dex' }),
        '/api/v1/validators/stats': () => ok({ block_height: 2761100, active_validators: 4, total_validators: 4, block_time: 2, chain_id: 4289 }),
        '/api/v1/masternode/stats': () => ok({ registry_deployed: true, registry_address: '0xAbC0000000000000000000000000000000000001', explorer_url: 'https://explorer.tpix.online' }),
        // ลำดับตาม enum ในสัญญา: Guardian=0, Sentinel=1, Light=2, Validator=3
        '/api/v1/masternode/tiers': () => ok([
            { name: 'Guardian', min_stake: '1000000', lock_days: 90, max_nodes: 100, active_nodes: 0, apr_cap_bps: 1200, source: 'chain' },
            { name: 'Sentinel', min_stake: '100000', lock_days: 30, max_nodes: 500, active_nodes: 0, apr_cap_bps: 900, source: 'chain' },
            { name: 'Light', min_stake: '10000', lock_days: 7, max_nodes: 0, active_nodes: 0, apr_cap_bps: 600, source: 'chain' },
            { name: 'Validator', min_stake: '10000000', lock_days: 180, max_nodes: 21, active_nodes: 0, apr_cap_bps: 2000, source: 'chain' },
        ]),
        '/api/v1/token-sale': () => ok({
            phases: [{ name: 'Private Sale', price_usd: 0.05, status: 'completed' }],
            payment_methods: { card: true, bank: false },
        }),
        ...overrides,
    };
    return vi.fn((url, opts) => (table[url] ? table[url](url, opts) : Promise.reject(new Error(`unexpected ${url}`))));
}

function mountStats() {
    let api;
    const Host = defineComponent({
        setup() {
            api = useHomeLiveStats();
            return () => h('div');
        },
    });
    const wrapper = mount(Host);
    return { wrapper, api: () => api };
}

describe('useHomeLiveStats', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('loads every live figure from the real APIs', async () => {
        vi.stubGlobal('fetch', routes());
        const { api } = mountStats();
        await flushPromises();

        const s = api();
        expect(s.price.value).toEqual({ price: 0.1, change_24h: 0, source: 'dex' });
        expect(s.chain.value.block_height).toBe(2761100);
        expect(s.chain.value.active_validators).toBe(4);
        expect(s.node.value.registry_deployed).toBe(true);
        expect(s.node.value.registry_address).toMatch(/^0x/);
        expect(s.sale.value.payment_methods.card).toBe(true);
        expect(s.failed.value).toEqual({ price: false, chain: false, node: false, sale: false });
    });

    it('sorts tiers by stake no matter how the contract indexes them', async () => {
        vi.stubGlobal('fetch', routes());
        const { api } = mountStats();
        await flushPromises();

        expect(api().tiers.value.map(t => t.name)).toEqual(['Light', 'Sentinel', 'Guardian', 'Validator']);
        expect(api().tiers.value.every(t => t.source === 'chain')).toBe(true);
    });

    it('fills the APR cap from the contract constants when the API runs in config mode', async () => {
        vi.stubGlobal('fetch', routes({
            '/api/v1/masternode/tiers': () => ok([
                { name: 'Validator', min_stake: '10000000', lock_days: 180, max_nodes: 21, active_nodes: 0, source: 'config' },
                { name: 'Light', min_stake: '10000', lock_days: 7, max_nodes: 0, active_nodes: 0, source: 'config' },
            ]),
        }));
        const { api } = mountStats();
        await flushPromises();

        const byName = Object.fromEntries(api().tiers.value.map(t => [t.name, t]));
        expect(byName.Light.apr_cap_bps).toBe(600);
        expect(byName.Validator.apr_cap_bps).toBe(2000);
        expect(byName.Light.source).toBe('config');
    });

    it('marks only the failing source as failed', async () => {
        vi.stubGlobal('fetch', routes({
            '/api/v1/tpix/price': () => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }),
        }));
        const { api } = mountStats();
        await flushPromises();

        expect(api().failed.value.price).toBe(true);
        expect(api().price.value).toBeNull();
        expect(api().failed.value.chain).toBe(false);
        expect(api().chain.value.block_height).toBe(2761100);
    });

    it('keeps the last good value when a refresh fails', async () => {
        let calls = 0;
        vi.stubGlobal('fetch', routes({
            '/api/v1/tpix/price': () => (++calls === 1
                ? ok({ price: 0.1, change_24h: 0, source: 'dex' })
                : Promise.reject(new Error('offline'))),
        }));
        const { api } = mountStats();
        await flushPromises();

        vi.advanceTimersByTime(30000);
        await flushPromises();

        expect(calls).toBe(2);
        expect(api().price.value.price).toBe(0.1);
        expect(api().failed.value.price).toBe(false);
    });

    it('treats a missing token sale as "no sale", not as an error', async () => {
        vi.stubGlobal('fetch', routes({ '/api/v1/token-sale': () => ok(null) }));
        const { api } = mountStats();
        await flushPromises();

        expect(api().sale.value).toBeNull();
        expect(api().failed.value.sale).toBe(false);
    });

    it('turns a request that never answers into a failure instead of an endless skeleton', async () => {
        vi.stubGlobal('fetch', routes({
            '/api/v1/validators/stats': (url, opts) => new Promise((_, reject) => {
                opts.signal.addEventListener('abort', () => reject(opts.signal.reason));
            }),
        }));
        const { api } = mountStats();
        await flushPromises();
        expect(api().loaded.value.chain).toBe(false);

        vi.advanceTimersByTime(12000);
        await flushPromises();

        expect(api().loaded.value.chain).toBe(true);
        expect(api().failed.value.chain).toBe(true);
        expect(api().failed.value.price).toBe(false);
    });

    it('stops polling and aborts in-flight requests after unmount', async () => {
        let hanging;
        const fetchMock = routes({
            '/api/v1/token-sale': (url, opts) => new Promise((_, reject) => {
                hanging = opts.signal;
                opts.signal.addEventListener('abort', () => reject(opts.signal.reason));
            }),
        });
        vi.stubGlobal('fetch', fetchMock);
        const { wrapper, api } = mountStats();
        await flushPromises();

        wrapper.unmount();
        await nextTick();
        await flushPromises();
        expect(hanging.aborted).toBe(true);
        // ยกเลิกเพราะออกจากหน้า ไม่ใช่ความล้มเหลวของ API
        expect(api().failed.value.sale).toBe(false);

        const before = fetchMock.mock.calls.length;
        vi.advanceTimersByTime(120000);
        expect(fetchMock.mock.calls.length).toBe(before);
    });
});
