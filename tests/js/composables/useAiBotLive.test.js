/**
 * TPIX TRADE — useAiBot: โหมดเงินจริง (บอทส่งคำสั่งเองจากกระเป๋าบอท)
 *
 * แยกไฟล์จาก useAiBot.test.js เพราะแคตตาล็อกเป็น state ระดับโมดูลที่โหลดครั้งเดียว —
 * ชุดนี้ต้องเริ่มจาก "ยังไม่รู้ว่าเปิดอะไรบ้าง" แล้วค่อยโหลดแคตตาล็อกที่เปิดโหมดจริง
 *
 * กันสามเรื่อง:
 *  - จอบอกว่าบอทใช้เงินจริงทั้งที่เซิร์ฟเวอร์ยังไม่ได้เปิด (หรือกลับกัน)
 *  - พอร์ตเงินจริงของกระเป๋าเดิมค้างหลังสลับกระเป๋า
 *  - กดปิดไม้แล้วข้อความดิบของระบบถึงผู้ใช้ / ปุ่มไม่ถูกล็อกระหว่างคำสั่งยังไม่จบ
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { reactive } from 'vue';
import { flushPromises } from '@vue/test-utils';

const wallet = reactive({ address: null, verifiedAt: null });

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const get = vi.fn();
const post = vi.fn();
const put = vi.fn();
const del = vi.fn();

vi.mock('axios', () => ({
    default: {
        get: (...args) => get(...args),
        post: (...args) => post(...args),
        put: (...args) => put(...args),
        delete: (...args) => del(...args),
    },
}));

const { useAiBot } = await import('@/Composables/useAiBot');

const WALLET_A = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const WALLET_B = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function httpError(status, code, message) {
    const err = new Error(message || code || 'error');
    err.response = { status, data: code ? { success: false, error: { code, message } } : { message: 'Server Error' } };
    return err;
}

function statusPayload(bots = []) {
    return {
        data: {
            success: true,
            data: {
                credits: 0,
                is_active: true,
                subscription: { plan_code: 'pro', execution: 'cloud' },
                quota: { used_bots: bots.length, max_bots: 3 },
                bots,
            },
        },
    };
}

function order(overrides = {}) {
    return { id: 1, bot_id: 7, side: 'sell', pair: 'BTC/USDT', status: 'queued', stage: 'queued', ...overrides };
}

function livePayload(overrides = {}) {
    return {
        data: {
            success: true,
            data: {
                execution: true,
                entries_paused: false,
                pairs: ['BTC/USDT'],
                limits: { min_order_usd: 10, max_order_usd: 500, slippage_bps: 100, entry_max_deviation_bps: 150 },
                positions: [],
                orders: [],
                realized_pnl: 0,
                fees_paid: 0,
                ...overrides,
            },
        },
    };
}

const LIVE_BOT = { id: 7, name: 'Grid', status: 'running', mode: 'live', pair: 'BTC/USDT', pending_order: null, live_supported: true };

describe('useAiBot — live trading', () => {
    let bot;

    beforeEach(async () => {
        get.mockReset();
        post.mockReset();
        put.mockReset();
        del.mockReset();
        bot = useAiBot();

        wallet.address = null;
        wallet.verifiedAt = null;
        await flushPromises();
    });

    // ── ธงจากแคตตาล็อก ───────────────────────────────────────────────────────

    it('treats live execution as off until the catalog says otherwise, then follows the catalog', async () => {
        // ยังไม่โหลดแคตตาล็อก — จอต้องเริ่มจาก "บอทยังไม่ส่งคำสั่งเอง"
        expect(bot.liveExecution.value).toBe(false);
        expect(bot.tpixPerCredit.value).toBeNull();

        get.mockResolvedValueOnce({
            data: {
                success: true,
                data: {
                    features: { live_trading: true, live_execution: true },
                    plans: [{ code: 'pro', credits_per_day: 30, price_tpix_per_day: 300 }],
                    // แพ็กเล็กสุดไม่ได้อยู่ลำดับแรก และแพ็กใหญ่ถูกกว่า + มีโบนัส
                    packs: [
                        { code: 'pack_1500', credits: 1500, price_tpix: 12000, bonus: 150 },
                        { code: 'pack_100', credits: 100, price_tpix: 1200, bonus: 0 },
                    ],
                },
            },
        });
        await bot.loadCatalog();

        expect(bot.liveEnabled.value).toBe(true);
        expect(bot.liveExecution.value).toBe(true);
        // เรตอ้างอิง = แพ็กเล็กสุด ไม่นับโบนัส: 1200 / 100
        expect(bot.tpixPerCredit.value).toBe(12);
        // สิ่งที่ถูกตัดจริงยังเป็นเครดิต
        expect(bot.costOf('pro', 7)).toBe(210);
    });

    // ── โหลดพอร์ตเงินจริง ────────────────────────────────────────────────────

    it('loads the live portfolio for the connected wallet', async () => {
        wallet.address = WALLET_A;
        get.mockResolvedValueOnce(livePayload({ orders: [order()], realized_pnl: 4.5 }));

        await bot.loadLive();

        expect(get).toHaveBeenCalledWith('/api/v1/ai-bot/live', { params: { wallet_address: WALLET_A } });
        expect(bot.live.value.realized_pnl).toBe(4.5);
        expect(bot.pendingLiveOrders.value).toHaveLength(1);
        expect(bot.isLoadingLive.value).toBe(false);
    });

    it('does not call the server without a wallet', async () => {
        await bot.loadLive();

        expect(get).not.toHaveBeenCalled();
        expect(bot.live.value).toBeNull();
    });

    it('drops a live response that arrives after the wallet changed', async () => {
        wallet.address = WALLET_A;
        const slow = deferred();
        get.mockReturnValue(slow.promise);

        const pending = bot.loadLive();
        wallet.address = WALLET_B;

        slow.resolve(livePayload({ positions: [{ bot_id: 7, pair: 'BTC/USDT' }] }));
        await pending;

        // ของที่ถืออยู่ของกระเป๋า A ต้องไม่ไปโผล่ใต้กระเป๋า B
        expect(bot.live.value).toBeNull();
        expect(bot.isLoadingLive.value).toBe(false);
    });

    it('lets the newest live request win when an older one answers late', async () => {
        wallet.address = WALLET_A;
        const old = deferred();
        get.mockReturnValueOnce(old.promise)
            .mockResolvedValueOnce(livePayload({ realized_pnl: 2 }));

        const first = bot.loadLive();
        await bot.loadLive({ force: true });

        old.resolve(livePayload({ realized_pnl: 1 }));
        await first;

        expect(bot.live.value.realized_pnl).toBe(2);
    });

    it('clears the live portfolio the moment the wallet changes or disconnects', async () => {
        wallet.address = WALLET_A;
        get.mockResolvedValue(livePayload({ positions: [{ bot_id: 7, pair: 'BTC/USDT' }] }));
        await bot.loadLive();
        expect(bot.live.value.positions).toHaveLength(1);

        wallet.address = WALLET_B;
        expect(bot.live.value).toBeNull();

        await bot.loadLive();
        wallet.address = null;
        expect(bot.live.value).toBeNull();
        expect(bot.liveError.value).toBeNull();
    });

    it('keeps the last portfolio and reports a localized error when a refresh fails', async () => {
        wallet.address = WALLET_A;
        get.mockResolvedValueOnce(livePayload({ positions: [{ bot_id: 7, pair: 'BTC/USDT' }] }));
        await bot.loadLive();

        get.mockRejectedValueOnce(httpError(500));
        await bot.loadLive({ force: true });

        // โหลดพลาดรอบเดียวต้องไม่ทำให้ของที่ถืออยู่ "หายไป" จากจอ
        expect(bot.live.value.positions).toHaveLength(1);
        expect(bot.liveError.value.message).toBe('Could not load the live portfolio');

        get.mockResolvedValueOnce(livePayload());
        await bot.loadLive({ force: true });
        expect(bot.liveError.value).toBeNull();
    });

    it('reloads the live portfolio by itself once the wallet signs', async () => {
        wallet.address = WALLET_A;
        get.mockRejectedValue(httpError(403, 'WALLET_NOT_VERIFIED', 'not verified'));
        await bot.loadLive();
        expect(bot.needsVerification.value).toBe(true);

        get.mockReset();
        get.mockImplementation((url) => (url.endsWith('/live')
            ? Promise.resolve(livePayload({ realized_pnl: 9 }))
            : Promise.resolve(statusPayload([]))));

        wallet.verifiedAt = Date.now();
        await flushPromises();

        expect(bot.live.value?.realized_pnl).toBe(9);
    });

    // ── ปิดไม้ ───────────────────────────────────────────────────────────────

    it('queues a close, marks the bot as busy and reloads the live portfolio', async () => {
        wallet.address = WALLET_A;
        const queued = order({ id: 31 });
        get.mockImplementation((url) => (url.endsWith('/live')
            ? Promise.resolve(livePayload({ orders: [queued] }))
            : Promise.resolve(statusPayload([LIVE_BOT]))));
        await bot.loadStatus();
        expect(bot.pendingOrderFor(7)).toBeNull();

        post.mockResolvedValueOnce({
            data: { success: true, data: { bot: { ...LIVE_BOT, pending_order: queued }, queued: true, order: queued } },
        });

        const result = await bot.closePosition(7);

        expect(post).toHaveBeenCalledWith('/api/v1/ai-bot/bots/7/close', { wallet_address: WALLET_A });
        expect(result.ok).toBe(true);
        // เข้าคิวเท่านั้น — ผู้เรียกใช้ธงนี้เลือกข้อความ ห้ามบอกว่าขายแล้ว
        expect(result.data.queued).toBe(true);
        expect(bot.bots.value[0].pending_order.id).toBe(31);
        expect(bot.pendingOrderFor(7)?.id).toBe(31);
        expect(get).toHaveBeenCalledWith('/api/v1/ai-bot/live', { params: { wallet_address: WALLET_A } });
        expect(bot.isWorking.value).toBe(false);
    });

    it('reloads the demo portfolio instead when a demo bot is closed immediately', async () => {
        wallet.address = WALLET_A;
        get.mockImplementation((url) => (url.endsWith('/demo')
            ? Promise.resolve({ data: { data: { account: { balance: 10000 }, positions: [] } } })
            : Promise.resolve(statusPayload([{ ...LIVE_BOT, mode: 'demo' }]))));
        await Promise.all([bot.loadStatus(), bot.loadDemo()]);
        get.mockClear();

        post.mockResolvedValueOnce({
            data: { success: true, data: { bot: { ...LIVE_BOT, mode: 'demo' }, queued: false, order: null } },
        });

        const result = await bot.closePosition(7);

        expect(result.data.queued).toBe(false);
        expect(get).toHaveBeenCalledWith('/api/v1/ai-bot/demo', { params: { wallet_address: WALLET_A } });
        expect(get).not.toHaveBeenCalledWith('/api/v1/ai-bot/live', expect.anything());
    });

    it('passes on the localized reason when the server refuses the close', async () => {
        wallet.address = WALLET_A;
        post.mockRejectedValueOnce(httpError(422, 'CLOSE_FAILED', 'ยอด BNB ไม่พอจ่ายค่าแก๊ส'));

        const result = await bot.closePosition(7);

        expect(result.ok).toBe(false);
        expect(result.error).toMatchObject({ code: 'CLOSE_FAILED', message: 'ยอด BNB ไม่พอจ่ายค่าแก๊ส' });
    });

    it.each([
        ['LIVE_EXECUTION_OFF', 503, 'Real-money order execution is switched off'],
        ['PRICE_UNAVAILABLE', 422, 'The market price for this pair is not available'],
        ['BOT_NOT_FOUND', 404, 'This bot was not found'],
    ])('uses its own wording for %s instead of the server text', async (code, status, expected) => {
        wallet.address = WALLET_A;
        post.mockRejectedValueOnce(httpError(status, code, 'RAW-SERVER-TEXT'));

        const result = await bot.closePosition(7);

        expect(result.ok).toBe(false);
        expect(result.error.code).toBe(code);
        expect(result.error.message).toContain(expected);
        expect(result.error.message).not.toContain('RAW-SERVER-TEXT');
        expect(bot.error.value.message).not.toContain('RAW-SERVER-TEXT');
    });

    it('never surfaces a raw server crash — falls back to a generic localized message', async () => {
        wallet.address = WALLET_A;
        post.mockRejectedValueOnce(httpError(500));

        const result = await bot.closePosition(7);

        expect(result.error.message).toBe('Something went wrong — please try again');
    });

    it('drops the close result when the wallet was switched mid-request', async () => {
        wallet.address = WALLET_A;
        const slow = deferred();
        post.mockReturnValue(slow.promise);

        const pending = bot.closePosition(7);
        wallet.address = WALLET_B;
        get.mockClear();

        slow.resolve({ data: { success: true, data: { bot: LIVE_BOT, queued: true, order: order() } } });
        const result = await pending;

        expect(result.ok).toBe(false);
        expect(result.error.code).toBe('WALLET_CHANGED');
        // ผลของกระเป๋าเดิมต้องไม่ทำให้ยิงโหลดพอร์ตใต้กระเป๋าใหม่
        expect(get).not.toHaveBeenCalled();
        expect(bot.live.value).toBeNull();
    });

    it('refuses to close without a wallet', async () => {
        const result = await bot.closePosition(7);

        expect(result.ok).toBe(false);
        expect(result.error.code).toBe('NO_WALLET');
        expect(post).not.toHaveBeenCalled();
    });

    // ── คำสั่งที่ยังไม่จบ ────────────────────────────────────────────────────

    it('trusts the live portfolio over a stale bot row once the order is final', async () => {
        wallet.address = WALLET_A;
        const stale = order({ id: 31, status: 'broadcasting' });
        get.mockImplementation((url) => (url.endsWith('/live')
            ? Promise.resolve(livePayload({ orders: [order({ id: 31, status: 'confirmed', stage: 'confirmed' })] }))
            : Promise.resolve(statusPayload([{ ...LIVE_BOT, pending_order: stale }]))));

        await bot.loadStatus();
        // ยังไม่รู้จากพอร์ตจริง — เชื่อสถานะบอทไว้ก่อน (เอียงไปทางกันกดซ้ำ)
        expect(bot.pendingOrderFor(7)?.id).toBe(31);

        await bot.loadLive();
        expect(bot.pendingOrderFor(7)).toBeNull();
        expect(bot.pendingLiveOrders.value).toEqual([]);
    });

    it('counts only queued, signing and broadcasting as in progress', () => {
        expect(['queued', 'signing', 'broadcasting'].every(status => bot.isOrderPending({ status }))).toBe(true);
        expect(['confirmed', 'failed', 'cancelled'].some(status => bot.isOrderPending({ status }))).toBe(false);
        expect(bot.isOrderPending(null)).toBe(false);
    });

    // ── ข้อผิดพลาดใหม่ของโหมด/ลบ/แก้บอท ───────────────────────────────────────

    it.each([
        ['setBotMode', () => post, bot => bot.setBotMode(7, 'demo'), 'LIVE_POSITION_OPEN', 'ปิดไม้ที่ถืออยู่ก่อนจึงสลับโหมดได้'],
        ['deleteBot', () => del, bot => bot.deleteBot(7), 'LIVE_POSITION_OPEN', 'ปิดไม้ที่ถืออยู่ก่อนจึงลบบอทได้'],
        ['updateBot', () => put, bot => bot.updateBot(7, { pair: 'DOGE/USDT' }), 'LIVE_PAIR_UNSUPPORTED', 'คู่นี้ยังเทรดด้วยเงินจริงไม่ได้'],
    ])('%s hands the localized 422 message to the screen', async (_name, verb, run, code, message) => {
        wallet.address = WALLET_A;
        verb().mockRejectedValueOnce(httpError(422, code, message));

        const result = await run(bot);

        expect(result.ok).toBe(false);
        expect(result.error).toMatchObject({ code, message });
    });
});
