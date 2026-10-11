/**
 * TPIX TRADE — หน้า /stability (TPIX Stability Reserve)
 *
 * กัน: คำเตือน "ไม่รับประกันราคา" หาย · หน้าอ้างว่า AI คุมราคา · กฎที่แสดงไม่ตรงกับค่าจริงจาก API ·
 *      ไม้ไม่มีลิงก์ไปตรวจบนเชน · โหลดไม่ได้แล้วจอว่างเปล่า · กราฟลากเส้นมั่วตอนไม่มีข้อมูล
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const get = vi.fn();

vi.mock('axios', () => ({ default: { get: (...a) => get(...a) } }));
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import Stability from '@/Pages/Stability.vue';
import StabilityBandChart from '@/Components/Stability/StabilityBandChart.vue';
import { setLocale } from '@/Composables/useTranslation';

const WALLET = '0x7777777777777777777777777777777777777777';
const TX = `0x${'b'.repeat(64)}`;

const payload = (overrides = {}) => ({
    success: true,
    data: {
        status: { active: false, env_enabled: true, switch_on: false, state: 'paused', pause_reason: 'tx_reverted', last_run_at: '2026-10-11T05:00:00Z' },
        rules: {
            band_pct: 15, lookback_days: 7, trade_interval_minutes: 30, opposite_cooldown_hours: 6, max_impact_pct: 1,
            max_trade_usdt: 50, max_trade_tpix: 500, daily_budget_usdt: 200, daily_budget_tpix: 2000, min_trade_usdt: 1,
            min_trade_tpix: 10, min_pool_usdt: 1000, min_pool_tpix: 10000, max_data_age_minutes: 15,
            min_history_coverage_pct: 60, slippage_bps: 50, schedule_minutes: 5, timezone: 'Asia/Bangkok',
        },
        wallet: { address: WALLET, explorer_url: `https://explorer.tpix.online/address/${WALLET}`, balances: { tpix: '10000', usdt: '1000.5' } },
        contracts: null,
        market: { pool_price: '0.1', reference_price: '0.1', band_low: '0.085', band_high: '0.115', position: 'inside', pool_tpix: '100000', pool_usdt: '10000', history_coverage_pct: 99.5 },
        budget_today: { usdt_spent: '25', usdt_limit: '200', tpix_sold: '0', tpix_limit: '2000' },
        usdt_backing: { mode: 'deposits_only', deposits_total: '1000', spent_total: '25', deposits_remaining: '975', bridge: { available: false } },
        deposits: [],
        chart: [
            { t: '2026-10-11T03:00:00Z', price: '0.1', reference: '0.1', low: '0.085', high: '0.115' },
            { t: '2026-10-11T04:00:00Z', price: '0.09', reference: '0.1', low: '0.085', high: '0.115' },
        ],
        transactions: [{ at: '2026-10-11T04:10:00Z', purpose: 'swap', side: 'buy', token_in: 'usdt', amount_in: '25', expected_out: '249', actual_out: '248.5', status: 'confirmed', tx_hash: TX, tx_url: `https://explorer.tpix.online/tx/${TX}` }],
        decisions: [{ at: '2026-10-11T05:00:00Z', action: 'none', reason_code: 'in_band', pool_price: '0.1', band_low: '0.085', band_high: '0.115', details: {} }],
        reports: [{ date: '2026-10-10', facts_en: 'Computed facts here.', facts_th: 'ตัวเลขจากระบบ', summary_en: 'AI wrote this summary.', summary_th: 'AI เขียนสรุปนี้', ai_written: true }],
        explorer: 'https://explorer.tpix.online',
        ...overrides,
    },
});

const mounted = [];
async function mountPage() {
    const w = mount(Stability);
    mounted.push(w);
    await flushPromises();
    return w;
}

beforeEach(() => {
    get.mockReset();
    setLocale('en');
});
afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

describe('Stability page', () => {
    it('loads from the read-only public API', async () => {
        get.mockResolvedValue({ data: payload() });
        await mountPage();
        expect(get).toHaveBeenCalledWith('/api/v1/stability');
    });

    it('shows the no-guarantee disclaimer and that AI does not control the price', async () => {
        get.mockResolvedValue({ data: payload() });
        const w = await mountPage();
        expect(w.find('[data-testid="disclaimer"]').text()).toContain('There is no price guarantee');
        expect(w.find('[data-testid="ai-note"]').text()).toContain('does not control the price');
    });

    it('prints the exact rules returned by the API', async () => {
        get.mockResolvedValue({ data: payload() });
        const w = await mountPage();
        const rules = w.find('[data-testid="rules"]').text();
        expect(rules).toContain('± 15%');
        expect(rules).toContain('at most 1% per trade');
        expect(rules).toContain('200 USDT spent and 2000 TPIX sold');
        expect(rules).toContain('at least 6 hours between a buy and a sell');
        expect(rules).toContain('Asia/Bangkok');
    });

    it('shows the public wallet and links every transaction to the explorer', async () => {
        get.mockResolvedValue({ data: payload() });
        const w = await mountPage();
        expect(w.find('[data-testid="wallet-address"]').text()).toBe(WALLET);
        const row = w.find('[data-testid="trade-row"]');
        expect(row.text()).toContain('Buy TPIX');
        expect(row.text()).toContain('248.5 TPIX');
        expect(row.find('a').attributes('href')).toBe(`https://explorer.tpix.online/tx/${TX}`);
    });

    it('explains paused state and decision reasons in plain words', async () => {
        get.mockResolvedValue({ data: payload() });
        const w = await mountPage();
        expect(w.find('[data-testid="status-badge"]').text()).toBe('Paused');
        expect(w.text()).toContain('Paused: a transaction failed on-chain');
        expect(w.find('[data-testid="decision-row"]').text()).toContain('Price is inside the band. No action needed.');
    });

    it('labels the AI-written summary separately from computed figures', async () => {
        get.mockResolvedValue({ data: payload() });
        const w = await mountPage();
        const report = w.find('[data-testid="report"]').text();
        expect(report).toContain('AI-written summary');
        expect(report).toContain('AI wrote this summary.');
        expect(report).toContain('Computed figures');
    });

    it('switches language live', async () => {
        get.mockResolvedValue({ data: payload() });
        const w = await mountPage();
        setLocale('th');
        await flushPromises();
        expect(w.find('[data-testid="disclaimer"]').text()).toContain('ไม่มีการรับประกันราคา');
        expect(w.find('[data-testid="report"]').text()).toContain('AI เขียนสรุปนี้');
    });

    it('says the wallet will be published when it is not set yet', async () => {
        get.mockResolvedValue({ data: payload({ wallet: { address: null, explorer_url: null, balances: null }, transactions: [] }) });
        const w = await mountPage();
        expect(w.find('[data-testid="wallet-not-set"]').exists()).toBe(true);
        expect(w.find('[data-testid="no-trades"]').exists()).toBe(true);
    });

    it('shows an error instead of a blank page when the API fails', async () => {
        get.mockRejectedValue(new Error('network'));
        const w = await mountPage();
        expect(w.find('[data-testid="load-error"]').exists()).toBe(true);
        // คำเตือนต้องอยู่เสมอ แม้โหลดข้อมูลไม่ได้
        expect(w.find('[data-testid="disclaimer"]').exists()).toBe(true);
    });
});

describe('StabilityBandChart', () => {
    it('shows an empty state instead of drawing made-up lines', () => {
        const w = mount(StabilityBandChart, { props: { points: [] } });
        expect(w.find('[data-testid="chart-empty"]').exists()).toBe(true);
        expect(w.find('svg').exists()).toBe(false);
    });

    it('draws the price line through every point', () => {
        const w = mount(StabilityBandChart, {
            props: { points: [
                { t: 'a', price: '0.1', reference: '0.1', low: '0.085', high: '0.115' },
                { t: 'b', price: '0.09', reference: '0.1', low: '0.085', high: '0.115' },
                { t: 'c', price: '0.12', reference: '0.1', low: '0.085', high: '0.115' },
            ] },
        });
        const d = w.find('[data-testid="price-line"]').attributes('d');
        expect(d.match(/[ML]/g)).toHaveLength(3);
    });
});
