/**
 * TPIX TRADE — useLimitOrders tests
 *
 * คำสั่งตั้งราคาของคู่บนเชน = "คำสั่งรอราคา" ที่เจ้าของต้องกดยืนยันตอนราคาถึง
 * สิ่งที่พังแล้วเป็นเรื่องเงิน:
 *  - แจ้ง "ถึงราคา" ผิดฝั่ง/ไม่เผื่อค่าธรรมเนียม → ชวนกดแล้วไม้ไม่ลง หรือไม่แจ้งเลย
 *  - ธุรกรรมออกจากกระเป๋าแล้วแต่แจ้งเซิร์ฟเวอร์ไม่ถึง → คำสั่งยังขึ้น "รอราคา" ชวนยืนยันซ้ำ = ไม้ลงสองรอบ
 *  - โชว์ข้อความดิบของ error ให้ผู้ใช้
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('axios', () => ({
    default: {
        get: (...args) => get(...args),
        post: (...args) => post(...args),
    },
}));

const {
    useLimitOrders,
    isLimitPriceReached,
    limitPairKey,
    LIMIT_COST_MARGIN,
    SUBMIT_QUEUE_KEY,
} = await import('@/Composables/useLimitOrders');

const WALLET = '0x1111111111111111111111111111111111111111';
const HASH = `0x${'a'.repeat(64)}`;
const OPEN = { id: 'l1', pair: 'BTC-USDT', side: 'buy', price: '50000', status: 'open' };

const queue = () => JSON.parse(localStorage.getItem(SUBMIT_QUEUE_KEY) || '[]');

describe('isLimitPriceReached', () => {
    it('buy triggers only when the market is below the limit by more than the fee margin', () => {
        const order = { side: 'buy', price: '50000' };

        expect(isLimitPriceReached(order, 51000)).toBe(false);
        // ราคาตลาดเท่าราคาที่ตั้งพอดี — หักค่าธรรมเนียมแล้วยังได้แย่กว่าที่ตั้ง จึงยังไม่แจ้ง
        expect(isLimitPriceReached(order, 50000)).toBe(false);
        expect(isLimitPriceReached(order, 50000 / (1 + LIMIT_COST_MARGIN) + 1)).toBe(false);
        expect(isLimitPriceReached(order, 50000 / (1 + LIMIT_COST_MARGIN) - 1)).toBe(true);
        expect(isLimitPriceReached(order, 40000)).toBe(true);
    });

    it('sell triggers only when the market is above the limit by more than the fee margin', () => {
        const order = { side: 'sell', price: '50000' };

        expect(isLimitPriceReached(order, 49000)).toBe(false);
        expect(isLimitPriceReached(order, 50000)).toBe(false);
        expect(isLimitPriceReached(order, 50000 * (1 + LIMIT_COST_MARGIN) - 1)).toBe(false);
        expect(isLimitPriceReached(order, 50000 * (1 + LIMIT_COST_MARGIN) + 1)).toBe(true);
    });

    it('never triggers without a usable price on either side', () => {
        for (const market of [0, null, undefined, NaN, '', -5]) {
            expect(isLimitPriceReached({ side: 'buy', price: '50000' }, market)).toBe(false);
        }
        for (const price of [0, null, undefined, 'abc', '']) {
            expect(isLimitPriceReached({ side: 'sell', price }, 60000)).toBe(false);
        }
        expect(isLimitPriceReached(null, 60000)).toBe(false);
    });

    it('works for tiny prices without float notation getting in the way', () => {
        expect(isLimitPriceReached({ side: 'sell', price: '0.00000394' }, 0.00000400)).toBe(true);
        expect(isLimitPriceReached({ side: 'sell', price: '0.00000394' }, 0.00000395)).toBe(false);
    });
});

describe('limitPairKey', () => {
    it('normalises both pair spellings to one key', () => {
        expect(limitPairKey('btc/usdt')).toBe('BTC-USDT');
        expect(limitPairKey(' BTC-USDT ')).toBe('BTC-USDT');
        expect(limitPairKey(null)).toBe('');
    });
});

describe('useLimitOrders', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        localStorage.clear();
    });

    it('loads the open orders of the wallet and clears them when disconnected', async () => {
        get.mockResolvedValue({ data: { success: true, data: [OPEN] } });
        const limit = useLimitOrders();

        await limit.load(WALLET);
        expect(get).toHaveBeenCalledWith('/api/v1/trading/limit-orders', { params: { wallet_address: WALLET } });
        expect(limit.orders.value).toEqual([OPEN]);

        await limit.load(null);
        expect(limit.orders.value).toEqual([]);
    });

    it('flags an unverified wallet (403) instead of looking like "no orders"', async () => {
        get.mockRejectedValue({ response: { status: 403 } });
        const limit = useLimitOrders();

        await limit.load(WALLET);

        expect(limit.orders.value).toEqual([]);
        expect(limit.needsVerification.value).toBe(true);
    });

    it('drops the result of a slow request once the wallet has changed', async () => {
        let finishFirst;
        get.mockImplementationOnce(() => new Promise((resolve) => { finishFirst = resolve; }));
        get.mockResolvedValueOnce({ data: { success: true, data: [] } });
        const limit = useLimitOrders();

        const first = limit.load(WALLET);
        await limit.load('0x2222222222222222222222222222222222222222');
        finishFirst({ data: { success: true, data: [OPEN] } });
        await first;

        // ผลของกระเป๋าใบก่อนต้องไม่ทับของใบปัจจุบัน
        expect(limit.orders.value).toEqual([]);
    });

    it('place() adds the new order to the watched list', async () => {
        post.mockResolvedValue({ data: { success: true, data: OPEN } });
        const limit = useLimitOrders();

        const result = await limit.place(WALLET, { pair: 'BTC-USDT', side: 'buy', price: '50000', amount: '100' });

        expect(result).toEqual({ ok: true, order: OPEN });
        expect(post).toHaveBeenCalledWith('/api/v1/trading/limit-orders', {
            wallet_address: WALLET, pair: 'BTC-USDT', side: 'buy', price: '50000', amount: '100',
        });
        expect(limit.orders.value).toEqual([OPEN]);
    });

    it('place() maps failures to reasons and never returns raw error text', async () => {
        const limit = useLimitOrders();
        const fail = (status, error) => post.mockRejectedValueOnce({ response: { status, data: { error } }, message: 'SQLSTATE[42S02] raw' });
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        fail(422, { code: 'PAIR_NOT_TRADABLE', message: 'คู่นี้ยังตั้งราคาไม่ได้' });
        expect(await limit.place(WALLET, {})).toEqual({ ok: false, reason: 'server', message: 'คู่นี้ยังตั้งราคาไม่ได้' });

        fail(403, { code: 'KYC_REQUIRED', message: 'x' });
        expect(await limit.place(WALLET, {})).toEqual({ ok: false, reason: 'kyc' });

        fail(403, { code: 'WALLET_NOT_VERIFIED', message: 'x' });
        expect(await limit.place(WALLET, {})).toEqual({ ok: false, reason: 'verify' });

        // รหัสที่ไม่รู้จัก — ข้อความของเซิร์ฟเวอร์/exception ต้องไม่หลุดออกไป
        fail(500, { code: 'LIMIT_ORDER_FAILED', message: 'SQLSTATE[HY000] leaked' });
        expect(await limit.place(WALLET, {})).toEqual({ ok: false, reason: 'failed' });

        post.mockRejectedValueOnce(new Error('Network Error'));
        expect(await limit.place(WALLET, {})).toEqual({ ok: false, reason: 'failed' });

        expect(limit.orders.value).toEqual([]);
        warn.mockRestore();
    });

    it('markSubmitted() stops the order from being offered again even before the server answers', async () => {
        get.mockResolvedValue({ data: { success: true, data: [OPEN] } });
        const limit = useLimitOrders();
        await limit.load(WALLET);

        let finishPost;
        post.mockImplementationOnce(() => new Promise((resolve) => { finishPost = resolve; }));
        const pending = limit.markSubmitted(WALLET, 'l1', HASH);

        expect(limit.orders.value[0].status).toBe('submitted');
        expect(limit.hasPendingSubmit('l1')).toBe(true);

        finishPost({ data: { success: true, data: { ...OPEN, status: 'submitted', tx_hash: HASH } } });
        expect(await pending).toBe(true);
        expect(limit.hasPendingSubmit('l1')).toBe(false);
        expect(post).toHaveBeenCalledWith('/api/v1/trading/limit-orders/l1/submitted', { wallet_address: WALLET, tx_hash: HASH });
    });

    it('keeps the transaction in a local queue when the server cannot be reached, and resends on the next load', async () => {
        const limit = useLimitOrders();

        post.mockRejectedValueOnce(new Error('Network Error'));
        expect(await limit.markSubmitted(WALLET, 'l1', HASH)).toBe(false);
        expect(queue()).toEqual([{ wallet: WALLET, id: 'l1', txHash: HASH }]);
        expect(limit.hasPendingSubmit('l1')).toBe(true);

        // เปิดหน้าใหม่ (composable ตัวใหม่) — เซิร์ฟเวอร์ยังบอกว่า open แต่คิวต้องถูกส่งก่อน
        const reopened = useLimitOrders();
        post.mockResolvedValueOnce({ data: { success: true, data: { ...OPEN, status: 'filled' } } });
        get.mockResolvedValueOnce({ data: { success: true, data: [] } });
        await reopened.load(WALLET);

        expect(post).toHaveBeenLastCalledWith('/api/v1/trading/limit-orders/l1/submitted', { wallet_address: WALLET, tx_hash: HASH });
        expect(queue()).toEqual([]);
        expect(reopened.hasPendingSubmit('l1')).toBe(false);
    });

    it('gives up on a queued submit the server has refused for good, but keeps one that may still succeed', async () => {
        const limit = useLimitOrders();

        for (const status of [404, 409, 422]) {
            post.mockRejectedValueOnce({ response: { status } });
            await limit.markSubmitted(WALLET, `gone-${status}`, HASH);
        }
        expect(queue()).toEqual([]);

        for (const status of [403, 500, 503]) {
            localStorage.clear();
            post.mockRejectedValueOnce({ response: { status } });
            await limit.markSubmitted(WALLET, 'l1', HASH);
            expect(queue()).toHaveLength(1);
        }
    });

    it('does not send another wallet\'s queued transactions', async () => {
        localStorage.setItem(SUBMIT_QUEUE_KEY, JSON.stringify([
            { wallet: '0x2222222222222222222222222222222222222222', id: 'theirs', txHash: HASH },
        ]));
        get.mockResolvedValue({ data: { success: true, data: [] } });
        const limit = useLimitOrders();

        await limit.load(WALLET);

        expect(post).not.toHaveBeenCalled();
        expect(queue()).toHaveLength(1);
    });

    it('ignores incomplete calls and survives a corrupted queue', async () => {
        const limit = useLimitOrders();

        expect(await limit.markSubmitted(WALLET, 'l1', null)).toBe(false);
        expect(await limit.markSubmitted(null, 'l1', HASH)).toBe(false);
        expect(post).not.toHaveBeenCalled();

        localStorage.setItem(SUBMIT_QUEUE_KEY, '{not json');
        expect(limit.hasPendingSubmit('l1')).toBe(false);
        get.mockResolvedValue({ data: { success: true, data: [OPEN] } });
        await limit.load(WALLET);
        expect(limit.orders.value).toEqual([OPEN]);
    });
});
