/**
 * TPIX TRADE — AiRuleBacktest tests (ทดสอบกฎกับราคาย้อนหลังจากตัวสร้างกฎ)
 *
 * กัน:
 *  - ผลที่ขาดทุนหรือไม้น้อยเกินไปถูกนำเสนอเหมือนผลดี (ถ้อยคำต้องตรงไปตรงมาแบบเดียวกับเทมเพลตในแคตตาล็อก)
 *  - ผลของกฎชุดก่อนถูกอ่านเป็นผลของกฎที่เพิ่งแก้
 *  - กดซ้ำระหว่างรอแล้วยิงซ้ำ หรือผลที่กลับมาหลังแก้กฎถูกอ่านเป็นผลของกฎใหม่
 *  - ทดสอบได้ทั้งที่กฎยังผิด
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AiRuleBacktest from '@/Components/AiTrade/AiRuleBacktest.vue';
import { RULE_SCHEMA, validRules } from '../fixtures/aiRuleSchema';

function request(overrides = {}) {
    return {
        pair: 'BTC/USDT',
        timeframe: '4h',
        params: { rules: validRules(), order_size_usd: 100 },
        risk: { max_position_usd: 100, stop_loss_pct: 5, take_profit_pct: 12, max_daily_loss_usd: 50 },
        ...overrides,
    };
}

function result(level, summary = {}, extra = {}) {
    return {
        pair: 'BTC/USDT',
        timeframe: '4h',
        requested_days: 180,
        max_days: 730,
        macro_filter_applied: true,
        macro_data_missing: false,
        verdict: { level, min_closed: 10, beats_hold: false },
        summary: {
            bars: 1080, days: 180, trades: 24, closed: 12, wins: 5, losses: 7, win_rate: 41.7,
            realized_pnl: -8.4, unrealized_pnl: 0, edge_bps: 12.3, cost_bps: 36, max_drawdown: 9.1,
            max_drawdown_pct: 0.09, exposure_pct: 31.2, capital_return_pct: -8.4, buy_hold_pct: 14.2,
            decisions: { buy: 12, sell: 12, hold: 700, blocked: 356 },
            ...summary,
        },
        trades: [
            { time: 1_760_000_000_000, side: 'buy', price: 100, value: 100, pnl: null, reason: 'Entry conditions met: EMA 12 crosses above EMA 26' },
            { time: 1_760_100_000_000, side: 'sell', price: 97, value: 97, pnl: -3.4, reason: 'Stop loss reached' },
        ],
        ...extra,
    };
}

function mountPanel({ runner = vi.fn(), props = {} } = {}) {
    const wrapper = mount(AiRuleBacktest, {
        props: { request: request(), schema: RULE_SCHEMA, runner, ...props },
    });

    return { wrapper, runner };
}

const byId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`);

async function runWith(data, props = {}) {
    const runner = vi.fn(() => Promise.resolve({ ok: true, data }));
    const { wrapper } = mountPanel({ runner, props });

    await byId(wrapper, 'backtest-run').trigger('click');
    await flushPromises();

    return { wrapper, runner };
}

describe('AiRuleBacktest — running', () => {
    it('sends the form as it is now, for the custom strategy and the chosen period', async () => {
        const { runner } = await runWith(result('losing'));

        expect(runner).toHaveBeenCalledTimes(1);
        expect(runner.mock.calls[0][0]).toEqual({ strategy: 'custom', ...request(), days: 180 });
    });

    it('limits the period to what the timeframe can test', async () => {
        const { wrapper } = mountPanel();
        const options = () => byId(wrapper, 'backtest-days').findAll('option').map((o) => Number(o.element.value));

        // 4h: 9,000 แท่ง = 1,500 วัน → เพดานคือ max_days 730
        expect(options()).toEqual([30, 90, 180, 365, 730]);
        expect(Number(byId(wrapper, 'backtest-days').element.value)).toBe(180);

        // 15m: 9,000 แท่ง = 93 วัน
        await wrapper.setProps({ request: request({ timeframe: '15m' }) });
        expect(options()).toEqual([30, 90, 93]);
        expect(Number(byId(wrapper, 'backtest-days').element.value)).toBe(93);
        expect(wrapper.text()).toContain('The 15m timeframe can test at most 93 days per run');
    });

    it('does not fire twice while a run is in flight', async () => {
        let finish;
        const runner = vi.fn(() => new Promise((resolve) => { finish = resolve; }));
        const { wrapper } = mountPanel({ runner });

        await byId(wrapper, 'backtest-run').trigger('click');
        await byId(wrapper, 'backtest-run').trigger('click');

        expect(runner).toHaveBeenCalledTimes(1);
        expect(byId(wrapper, 'backtest-run').text()).toBe('Testing…');
        expect(byId(wrapper, 'backtest-run').attributes('disabled')).toBeDefined();

        finish({ ok: true, data: result('losing') });
        await flushPromises();

        expect(byId(wrapper, 'backtest-run').text()).toBe('Run backtest');
        expect(byId(wrapper, 'backtest-result').exists()).toBe(true);
    });

    it('refuses to run while the rules are invalid and says what to fix', async () => {
        const { wrapper, runner } = mountPanel({ props: { blockedReason: 'Level must be between 1 and 99' } });

        expect(byId(wrapper, 'backtest-run').attributes('disabled')).toBeDefined();
        expect(byId(wrapper, 'backtest-blocked').text()).toBe('Fix the rules before testing: Level must be between 1 and 99');

        await byId(wrapper, 'backtest-run').trigger('click');
        expect(runner).not.toHaveBeenCalled();
    });

    it('shows the server reason when the run is rejected', async () => {
        const runner = vi.fn(() => Promise.resolve({ ok: false, error: { code: 'INVALID_RULES', message: 'The entry side, condition 1: unknown condition type macd' } }));
        const { wrapper } = mountPanel({ runner });

        await byId(wrapper, 'backtest-run').trigger('click');
        await flushPromises();

        expect(byId(wrapper, 'backtest-error').text()).toBe('The entry side, condition 1: unknown condition type macd');
        expect(byId(wrapper, 'backtest-result').exists()).toBe(false);
    });

    it('recovers when the request itself blows up', async () => {
        const runner = vi.fn(() => Promise.reject(new Error('boom')));
        const { wrapper } = mountPanel({ runner });

        await byId(wrapper, 'backtest-run').trigger('click');
        await flushPromises();

        expect(byId(wrapper, 'backtest-error').text()).toBe('The backtest failed');
        expect(byId(wrapper, 'backtest-run').attributes('disabled')).toBeUndefined();
    });
});

describe('AiRuleBacktest — honest wording', () => {
    it('states a losing result plainly, with the numbers', async () => {
        const { wrapper } = await runWith(result('losing'));
        const verdict = byId(wrapper, 'backtest-verdict');

        expect(verdict.text()).toBe('⚠️ 180 days on BTC/USDT: -8.40% on allotted capital — 12.3 bps gross per trade does not beat the 36 bps trading cost');
        expect(verdict.classes().join(' ')).toContain('text-trading-red');
        expect(byId(wrapper, 'backtest-capital').classes()).toContain('text-trading-red');
    });

    it('refuses to conclude from a handful of trades, even profitable ones', async () => {
        const { wrapper } = await runWith(result('insufficient', { closed: 3, capital_return_pct: 22.5, realized_pnl: 22.5 }));
        const verdict = byId(wrapper, 'backtest-verdict').text();

        expect(verdict).toContain('Not enough to judge');
        expect(verdict).toContain('only 3 closed trades in 180 days');
        expect(verdict).toContain('at least 10');
        expect(verdict).not.toContain('22.5');
    });

    it('explains a negative expectancy', async () => {
        const { wrapper } = await runWith(result('negative_expectancy', { edge_bps: 60 }));

        expect(byId(wrapper, 'backtest-verdict').text()).toContain('losers are bigger than winners');
    });

    it('presents a positive result as a past result, not a promise, and without a green banner', async () => {
        const { wrapper } = await runWith(result('positive', { capital_return_pct: 20.9, edge_bps: 125.8 }, { verdict: { level: 'positive', min_closed: 10, beats_hold: true } }));
        const verdict = byId(wrapper, 'backtest-verdict');

        expect(verdict.text()).toContain('+20.90% on allotted capital');
        expect(verdict.text()).toContain('not a promise about the future');
        expect(verdict.classes().join(' ')).not.toContain('trading-green');
        expect(byId(wrapper, 'backtest-hold').text()).toContain('did better than holding');
    });

    it('always shows what simply holding would have done', async () => {
        const { wrapper } = await runWith(result('losing'));

        expect(byId(wrapper, 'backtest-hold').text()).toBe('Simply holding over the same period: +14.20% — these rules did worse than holding this time');
    });

    it('says so when the rules never traded', async () => {
        const { wrapper } = await runWith(result('insufficient', { trades: 0, closed: 0, win_rate: null, edge_bps: null, capital_return_pct: 0 }, { trades: [] }));

        expect(byId(wrapper, 'backtest-no-trades').text()).toContain('never opened a trade');
        expect(byId(wrapper, 'backtest-blocked-bars').text()).toBe('Entries were blocked on 356 of 1080 candles (market-trend filter · risk gate · loss / capital caps)');
    });

    it('lists what the backtest does not cover', async () => {
        const { wrapper } = await runWith(result('losing'));
        const notes = byId(wrapper, 'backtest-notes').text();

        expect(notes).toContain('no news gate and no AI market view');
        expect(notes).toContain('Past results do not guarantee future results');
        expect(notes).toContain('The market-trend filter (BTC daily) was applied');
    });

    it('warns when the market-trend filter could not be applied', async () => {
        const { wrapper } = await runWith(result('losing', {}, { macro_filter_applied: false, macro_data_missing: true }));

        expect(byId(wrapper, 'backtest-notes').text()).toContain('this result is unfiltered, unlike the live bot');
    });

    it('shows the latest trades with the reason the engine gave', async () => {
        const { wrapper } = await runWith(result('losing'));
        const trades = wrapper.findAll('[data-testid="backtest-trade"]');

        expect(trades).toHaveLength(2);
        expect(trades[0].text()).toContain('Sell');
        expect(trades[0].text()).toContain('-3.40');
        expect(trades[1].text()).toContain('Entry conditions met: EMA 12 crosses above EMA 26');
    });
});

describe('AiRuleBacktest — result belongs to the rules that were tested', () => {
    it('marks the result as stale once the rules change', async () => {
        const { wrapper } = await runWith(result('losing'));

        expect(byId(wrapper, 'backtest-stale').exists()).toBe(false);

        const changed = request();
        changed.params.rules.entry.blocks[0].fast = 9;
        await wrapper.setProps({ request: changed });

        expect(byId(wrapper, 'backtest-stale').text()).toContain('previous version');
        expect(byId(wrapper, 'backtest-result').classes()).toContain('opacity-50');
    });

    it('is not stale when the same rules arrive as a new object', async () => {
        const { wrapper } = await runWith(result('losing'));

        await wrapper.setProps({ request: request() });

        expect(byId(wrapper, 'backtest-stale').exists()).toBe(false);
    });

    it('marks the result as stale when the period changes', async () => {
        const { wrapper } = await runWith(result('losing'));

        await byId(wrapper, 'backtest-days').setValue('90');

        expect(byId(wrapper, 'backtest-stale').exists()).toBe(true);
    });

    it('labels an answer as stale when the rules changed while it was in flight', async () => {
        let finish;
        const runner = vi.fn(() => new Promise((resolve) => { finish = resolve; }));
        const { wrapper } = mountPanel({ runner });

        await byId(wrapper, 'backtest-run').trigger('click');

        const changed = request();
        changed.params.rules.entry.blocks[0].fast = 9;
        await wrapper.setProps({ request: changed });

        finish({ ok: true, data: result('losing') });
        await flushPromises();

        expect(byId(wrapper, 'backtest-result').exists()).toBe(true);
        expect(byId(wrapper, 'backtest-stale').exists()).toBe(true);
    });
});
