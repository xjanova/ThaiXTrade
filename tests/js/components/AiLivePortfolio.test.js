/**
 * TPIX TRADE — AiLivePortfolio tests
 *
 * แผงนี้คุมเงินจริง: ปุ่ม "ปิดไม้ทันที" ขายทุกอย่างที่บอทถืออยู่ และคำสั่งเป็นงานอะซิงก์
 * (เข้าคิว → เชนยืนยัน 1–3 นาที) ชุดนี้จึงกันสี่เรื่อง:
 *  - กดปิดไม้โดยไม่ถามยืนยัน / กดซ้ำแล้วได้คำสั่งขายซ้อน
 *  - บอกว่า "ขายแล้ว" ทั้งที่คำสั่งยังแค่เข้าคิว
 *  - ข้อความดิบของระบบหลุดถึงผู้ใช้
 *  - ตัวจับเวลาถามสถานะซ้ำค้างอยู่หลังออกจากหน้า/สลับกระเป๋า
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref, computed, nextTick } from 'vue';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

const WALLET_A = '0x1111111111111111111111111111111111111111';
const WALLET_B = '0x2222222222222222222222222222222222222222';

const state = {
    live: ref(null),
    isLoadingLive: ref(false),
    liveError: ref(null),
    wallet: ref(WALLET_A),
    isConnected: ref(true),
    isWorking: ref(false),
    bots: ref([]),
};

const isOrderPending = (order) => ['queued', 'signing', 'broadcasting'].includes(order?.status);
const pendingLiveOrders = computed(() => (state.live.value?.orders ?? []).filter(isOrderPending));

const loadLive = vi.fn(() => Promise.resolve(state.live.value));
const loadStatus = vi.fn(() => Promise.resolve());
const loadBotWallet = vi.fn(() => Promise.resolve());
const closePosition = vi.fn();

vi.mock('@/Composables/useAiBot', () => ({
    useAiBot: () => ({
        ...state,
        pendingLiveOrders,
        isOrderPending,
        pendingOrderFor: (botId) => pendingLiveOrders.value.find(o => o.bot_id === botId)
            ?? state.bots.value.find(b => b.id === botId)?.pending_order
            ?? null,
        loadLive,
        loadStatus,
        loadBotWallet,
        closePosition,
    }),
}));

const Panel = (await import('@/Components/Trading/AiLivePortfolio.vue')).default;

function deferred() {
    let resolve;
    const promise = new Promise((res) => { resolve = res; });
    return { promise, resolve };
}

function position(overrides = {}) {
    return {
        bot_id: 7,
        bot_name: 'Grid BTC',
        pair: 'BTC/USDT',
        quantity: 0.0125,
        entry_price: 60000,
        cost_basis: 750,
        price: 62000,
        value: 775,
        unrealized_pnl: 25,
        opened_at: '2026-11-13T08:00:00Z',
        ...overrides,
    };
}

function order(overrides = {}) {
    return {
        id: 1,
        bot_id: 7,
        side: 'buy',
        pair: 'BTC/USDT',
        asset: 'USDT',
        amount: 750,
        ref_price: 60000,
        manual: false,
        status: 'confirmed',
        stage: 'confirmed',
        tx_hash: '0xabc',
        tx_url: 'https://bscscan.com/tx/0xabc',
        failure_code: null,
        failure_reason: null,
        reason: 'grid level',
        created_at: '2026-11-13T08:00:00Z',
        expires_at: null,
        confirmed_at: '2026-11-13T08:02:00Z',
        ...overrides,
    };
}

function livePayload(overrides = {}) {
    return {
        execution: true,
        entries_paused: false,
        pairs: ['BTC/USDT'],
        limits: { min_order_usd: 10, max_order_usd: 500, slippage_bps: 100, entry_max_deviation_bps: 150 },
        positions: [],
        orders: [],
        realized_pnl: 0,
        fees_paid: 0,
        ...overrides,
    };
}

const closeButton = (wrapper, index = 0) => wrapper.findAll('[data-testid="live-close"]').at(index);

beforeEach(() => {
    state.live.value = null;
    state.isLoadingLive.value = false;
    state.liveError.value = null;
    state.wallet.value = WALLET_A;
    state.isConnected.value = true;
    state.isWorking.value = false;
    state.bots.value = [];

    loadLive.mockClear();
    loadStatus.mockClear();
    loadBotWallet.mockClear();
    closePosition.mockReset();
    vi.restoreAllMocks();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('AiLivePortfolio — what the user sees', () => {
    it('loads the live portfolio for the connected wallet on mount', () => {
        mount(Panel);

        expect(loadLive).toHaveBeenCalledTimes(1);
    });

    it('renders positions with value and coloured unrealized P&L', () => {
        state.live.value = livePayload({
            positions: [
                position(),
                position({ bot_id: 8, bot_name: 'DCA ETH', pair: 'ETH/USDT', quantity: 0.4, entry_price: 2500, value: 950, unrealized_pnl: -50 }),
            ],
            realized_pnl: 12.5,
            fees_paid: 1.25,
        });

        const wrapper = mount(Panel);
        const rows = wrapper.findAll('[data-testid="live-position"]');

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('BTC/USDT');
        expect(rows[0].text()).toContain('Grid BTC');
        expect(rows[0].text()).toContain('0.0125 BTC');
        expect(rows[0].text()).toContain('$60,000.00');
        expect(rows[0].text()).toContain('$775.00');
        expect(rows[0].find('[data-testid="live-unrealized"]').text()).toContain('+$25.00');
        expect(rows[0].find('[data-testid="live-unrealized"]').classes()).toContain('text-trading-green');
        expect(rows[1].find('[data-testid="live-unrealized"]').text()).toContain('-$50.00');
        expect(rows[1].find('[data-testid="live-unrealized"]').classes()).toContain('text-trading-red');

        expect(wrapper.find('[data-testid="live-realized"]').text()).toBe('+$12.50');
        expect(wrapper.find('[data-testid="live-fees"]').text()).toBe('$1.25');
    });

    it('does not invent a value for a position the server could not price', () => {
        state.live.value = livePayload({ positions: [position({ price: null, value: null, unrealized_pnl: null })] });

        const wrapper = mount(Panel);

        expect(wrapper.find('[data-testid="live-unrealized"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No price yet');
    });

    it('renders each order with its stage, side, amount and explorer link', () => {
        state.live.value = livePayload({
            orders: [
                order({ id: 3, side: 'sell', status: 'signing', stage: 'approving', amount: 0.0125, asset: 'BTC', tx_hash: null, tx_url: null, manual: true }),
                order({ id: 2, status: 'broadcasting', stage: 'broadcasting', tx_url: 'https://bscscan.com/tx/0xdef' }),
                order({ id: 1 }),
                order({ id: 0, status: 'cancelled', stage: 'cancelled', tx_hash: null, tx_url: null }),
            ],
        });

        const wrapper = mount(Panel);
        const rows = wrapper.findAll('[data-testid="live-order"]');
        const stages = rows.map(r => r.find('[data-testid="live-order-stage"]').text());

        expect(stages).toEqual(['Approving token', 'Awaiting confirmation', 'Confirmed', 'Cancelled']);
        expect(rows[0].text()).toContain('Sell');
        expect(rows[0].text()).toContain('0.0125 BTC');
        expect(rows[0].text()).toContain('manual');
        expect(rows[0].find('a').exists()).toBe(false);   // ยังไม่มีธุรกรรม = ยังไม่มีลิงก์

        const link = rows[1].find('a');
        expect(link.attributes('href')).toBe('https://bscscan.com/tx/0xdef');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
    });

    it('never calls a queued order sold or bought', () => {
        state.live.value = livePayload({
            orders: [order({ id: 5, side: 'sell', status: 'queued', stage: 'queued', tx_hash: null, tx_url: null, confirmed_at: null })],
        });

        const text = mount(Panel).text();

        expect(text).toContain('Queued');
        expect(text).not.toMatch(/\b(sold|bought|filled|executed)\b/i);
    });

    it('refuses to turn a non-https explorer url into a link', () => {
        state.live.value = livePayload({ orders: [order({ tx_url: 'javascript:alert(1)' })] });

        expect(mount(Panel).find('[data-testid="live-order"] a').exists()).toBe(false);
    });

    it('shows why a failed order failed', () => {
        state.live.value = livePayload({
            orders: [order({ status: 'failed', stage: 'failed', failure_code: 'price_moved', failure_reason: 'The price moved too far — nothing was traded', tx_url: null })],
        });

        const wrapper = mount(Panel);

        expect(wrapper.find('[data-testid="live-order-stage"]').text()).toBe('Failed');
        expect(wrapper.find('[data-testid="live-order-failure"]').text()).toBe('The price moved too far — nothing was traded');
    });

    it('falls back to its own wording when a failed order carries no reason — never the raw code', () => {
        state.live.value = livePayload({
            orders: [order({ status: 'failed', stage: 'failed', failure_code: 'simulation_failed', failure_reason: null, tx_url: null })],
        });

        const wrapper = mount(Panel);

        expect(wrapper.find('[data-testid="live-order-failure"]').text()).toBe('The order failed — nothing was traded');
        expect(wrapper.text()).not.toContain('simulation_failed');
    });

    it('shows an empty state when there is nothing yet', () => {
        state.live.value = livePayload();

        const wrapper = mount(Panel);

        expect(wrapper.find('[data-testid="live-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="live-position"]').exists()).toBe(false);
    });

    it('tells the user when new entries are paused', () => {
        state.live.value = livePayload({ entries_paused: true });

        expect(mount(Panel).find('[data-testid="live-entries-paused"]').text()).toContain('New entries are paused');
    });

    it('keeps the content on screen during a refresh — only a small indicator', () => {
        state.live.value = livePayload({ positions: [position()] });
        state.isLoadingLive.value = true;

        const wrapper = mount(Panel);

        expect(wrapper.find('[data-testid="live-refreshing"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="live-position"]')).toHaveLength(1);
    });

    it('says the last refresh failed without hiding what it already has, and never shows the server text', () => {
        state.live.value = livePayload({ positions: [position()] });
        state.liveError.value = { code: 'REQUEST_FAILED', message: 'SQLSTATE[HY000] raw text' };

        const wrapper = mount(Panel);

        expect(wrapper.text()).toContain('The last refresh failed');
        expect(wrapper.text()).not.toContain('SQLSTATE');
        expect(wrapper.findAll('[data-testid="live-position"]')).toHaveLength(1);
    });

    it('shows a localized error when the first load fails', () => {
        state.liveError.value = { code: 'REQUEST_FAILED', message: 'Exception: boom' };

        const text = mount(Panel).text();

        expect(text).toContain('Could not load the live portfolio');
        expect(text).not.toContain('Exception');
    });
});

describe('AiLivePortfolio — closing a position', () => {
    it('asks for confirmation and does nothing when the user declines', async () => {
        state.live.value = livePayload({ positions: [position()] });
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

        const wrapper = mount(Panel);
        await closeButton(wrapper).trigger('click');

        expect(confirm).toHaveBeenCalledTimes(1);
        // ข้อความยืนยันต้องบอกว่าขายทั้งหมดเป็น USDT ที่ราคา DEX และใช้เวลา 1–3 นาที
        expect(confirm.mock.calls[0][0]).toContain('BTC/USDT');
        expect(confirm.mock.calls[0][0]).toContain('Grid BTC');
        expect(confirm.mock.calls[0][0]).toContain('USDT at the DEX price');
        expect(confirm.mock.calls[0][0]).toContain('1–3 minutes');
        expect(closePosition).not.toHaveBeenCalled();
    });

    it('sends the close once confirmed and reports it as queued, not sold', async () => {
        state.live.value = livePayload({ positions: [position()] });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        closePosition.mockResolvedValue({ ok: true, data: { queued: true, order: order({ id: 9, status: 'queued', stage: 'queued' }) } });

        const wrapper = mount(Panel);
        await closeButton(wrapper).trigger('click');
        await flushPromises();

        expect(closePosition).toHaveBeenCalledWith(7);

        const message = wrapper.find('[data-testid="live-message"]').text();
        expect(message).toContain('Sell order queued');
        expect(message).toContain('nothing is sold yet');
    });

    it('sends a single request when the button is clicked twice', async () => {
        state.live.value = livePayload({ positions: [position()] });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const slow = deferred();
        closePosition.mockReturnValue(slow.promise);

        const wrapper = mount(Panel);
        await closeButton(wrapper).trigger('click');
        await closeButton(wrapper).trigger('click');

        expect(closePosition).toHaveBeenCalledTimes(1);
        expect(closeButton(wrapper).attributes('disabled')).toBeDefined();
        expect(closeButton(wrapper).text()).toBe('Sending request...');

        slow.resolve({ ok: true, data: { queued: true } });
        await flushPromises();

        expect(closeButton(wrapper).text()).toBe('Close position now');
    });

    it('locks every close button while one close is in flight', async () => {
        state.live.value = livePayload({ positions: [position(), position({ bot_id: 8, bot_name: 'DCA ETH', pair: 'ETH/USDT' })] });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const slow = deferred();
        closePosition.mockReturnValue(slow.promise);

        const wrapper = mount(Panel);
        await closeButton(wrapper, 0).trigger('click');
        await closeButton(wrapper, 1).trigger('click');

        expect(closePosition).toHaveBeenCalledTimes(1);
        expect(closeButton(wrapper, 1).attributes('disabled')).toBeDefined();

        slow.resolve({ ok: true, data: { queued: true } });
        await flushPromises();
    });

    it('does not offer a second close while that bot still has an order in progress', async () => {
        state.live.value = livePayload({
            positions: [position()],
            orders: [order({ id: 4, side: 'sell', status: 'queued', stage: 'queued', tx_url: null })],
        });
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);

        const wrapper = mount(Panel);

        expect(closeButton(wrapper).attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="live-position"]').text()).toContain('Order in progress');

        await closeButton(wrapper).trigger('click');

        expect(confirm).not.toHaveBeenCalled();
        expect(closePosition).not.toHaveBeenCalled();
    });

    it('locks the close for a bot whose status reports a pending order the panel has not loaded yet', () => {
        state.live.value = livePayload({ positions: [position()] });
        state.bots.value = [{ id: 7, pending_order: { id: 44, status: 'queued' } }];

        expect(closeButton(mount(Panel)).attributes('disabled')).toBeDefined();
    });

    it('leaves another bot closable while a different bot has an order in progress', () => {
        state.live.value = livePayload({
            positions: [position(), position({ bot_id: 8, bot_name: 'DCA ETH', pair: 'ETH/USDT' })],
            orders: [order({ id: 4, bot_id: 7, status: 'broadcasting', stage: 'broadcasting' })],
        });

        const wrapper = mount(Panel);

        expect(closeButton(wrapper, 0).attributes('disabled')).toBeDefined();
        expect(closeButton(wrapper, 1).attributes('disabled')).toBeUndefined();
    });

    it('shows the localized reason when the server refuses the close', async () => {
        state.live.value = livePayload({ positions: [position()] });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        closePosition.mockResolvedValue({ ok: false, error: { code: 'CLOSE_FAILED', message: 'Not enough BNB for gas' } });

        const wrapper = mount(Panel);
        await closeButton(wrapper).trigger('click');
        await flushPromises();

        const message = wrapper.find('[data-testid="live-message"]');
        expect(message.text()).toBe('Not enough BNB for gas');
        expect(message.attributes('role')).toBe('alert');
        // ล้มแล้วต้องกดใหม่ได้
        expect(closeButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('never shows raw exception text when the close blows up unexpectedly', async () => {
        state.live.value = livePayload({ positions: [position()] });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        closePosition.mockRejectedValue(new Error('TypeError: cannot read properties of undefined'));

        const wrapper = mount(Panel);
        await closeButton(wrapper).trigger('click');
        await flushPromises();

        const message = wrapper.find('[data-testid="live-message"]').text();
        expect(message).toBe('Something went wrong — please try again');
        expect(wrapper.text()).not.toContain('TypeError');
    });

    it('drops the result of a close that answers after the wallet was switched', async () => {
        state.live.value = livePayload({ positions: [position()] });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const slow = deferred();
        closePosition.mockReturnValue(slow.promise);

        const wrapper = mount(Panel);
        await closeButton(wrapper).trigger('click');

        // สลับกระเป๋าระหว่างรอ — composable ล้างพอร์ตของกระเป๋าเดิมทันที
        state.wallet.value = WALLET_B;
        state.live.value = null;
        await nextTick();

        slow.resolve({ ok: true, data: { queued: true } });
        await flushPromises();

        expect(wrapper.find('[data-testid="live-message"]').exists()).toBe(false);
    });

    it('survives being unmounted while the close request is still in flight', async () => {
        state.live.value = livePayload({ positions: [position()] });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const slow = deferred();
        closePosition.mockReturnValue(slow.promise);
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});

        const wrapper = mount(Panel);
        await closeButton(wrapper).trigger('click');
        wrapper.unmount();

        slow.resolve({ ok: true, data: { queued: true } });
        await flushPromises();

        expect(warn).not.toHaveBeenCalled();
        expect(error).not.toHaveBeenCalled();
    });
});

describe('AiLivePortfolio — polling while an order is in progress', () => {
    const pendingOrder = () => order({ id: 4, side: 'sell', status: 'broadcasting', stage: 'broadcasting' });

    it('re-polls every 15 seconds while an order is in progress', async () => {
        vi.useFakeTimers();
        state.live.value = livePayload({ positions: [position()], orders: [pendingOrder()] });

        mount(Panel);
        expect(loadLive).toHaveBeenCalledTimes(1);   // โหลดตอนเปิด

        await vi.advanceTimersByTimeAsync(15000);
        expect(loadLive).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(15000);
        expect(loadLive).toHaveBeenCalledTimes(3);
    });

    it('does not poll at all when no order is in progress', async () => {
        vi.useFakeTimers();
        state.live.value = livePayload({ positions: [position()], orders: [order()] });

        mount(Panel);
        await vi.advanceTimersByTimeAsync(60000);

        expect(loadLive).toHaveBeenCalledTimes(1);
    });

    it('stops polling once every order is final and refreshes the bots and the bot wallet', async () => {
        vi.useFakeTimers();
        state.live.value = livePayload({ positions: [position()], orders: [pendingOrder()] });

        mount(Panel);
        await vi.advanceTimersByTimeAsync(15000);
        expect(loadLive).toHaveBeenCalledTimes(2);

        // เชนยืนยันแล้ว
        state.live.value = livePayload({ orders: [order({ id: 4, side: 'sell' })] });
        await nextTick();

        expect(loadStatus).toHaveBeenCalledWith({ force: true });
        expect(loadBotWallet).toHaveBeenCalledWith({ force: true });

        await vi.advanceTimersByTimeAsync(60000);
        expect(loadLive).toHaveBeenCalledTimes(2);
    });

    it('starts polling when an order shows up after the panel was opened', async () => {
        vi.useFakeTimers();
        state.live.value = livePayload({ positions: [position()] });

        mount(Panel);
        await vi.advanceTimersByTimeAsync(30000);
        expect(loadLive).toHaveBeenCalledTimes(1);

        state.live.value = livePayload({ positions: [position()], orders: [pendingOrder()] });
        await nextTick();
        await vi.advanceTimersByTimeAsync(15000);

        expect(loadLive).toHaveBeenCalledTimes(2);
    });

    it('clears the timer on unmount', async () => {
        vi.useFakeTimers();
        state.live.value = livePayload({ positions: [position()], orders: [pendingOrder()] });

        const wrapper = mount(Panel);
        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(60000);

        expect(loadLive).toHaveBeenCalledTimes(1);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('clears the timer and the old result when the wallet changes, then loads the new wallet', async () => {
        vi.useFakeTimers();
        state.live.value = livePayload({ positions: [position()], orders: [pendingOrder()] });

        mount(Panel);
        expect(vi.getTimerCount()).toBe(1);

        state.wallet.value = WALLET_B;
        state.live.value = null;   // composable ล้างของกระเป๋าเดิม
        await nextTick();

        expect(vi.getTimerCount()).toBe(0);
        expect(loadLive).toHaveBeenCalledTimes(2);   // โหลดของกระเป๋าใหม่
        // สลับกระเป๋าไม่ใช่ "คำสั่งจบ" — ไม่ต้องโหลดสถานะซ้ำจากเหตุนี้
        expect(loadStatus).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(60000);
        expect(loadLive).toHaveBeenCalledTimes(2);
    });

    it('reloads when a bot reports an order the panel does not know yet', async () => {
        state.live.value = livePayload({ positions: [position()] });

        mount(Panel);
        expect(loadLive).toHaveBeenCalledTimes(1);

        // วอร์กเกอร์ส่งคำสั่งเอง — สถานะบอทรู้ก่อนแผงนี้
        state.bots.value = [{ id: 7, pending_order: { id: 77, status: 'queued' } }];
        await nextTick();

        expect(loadLive).toHaveBeenCalledTimes(2);
        expect(loadLive).toHaveBeenLastCalledWith({ force: true });
    });
});
