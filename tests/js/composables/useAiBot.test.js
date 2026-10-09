/**
 * TPIX TRADE — useAiBot tests
 *
 * ครอบคลุมบั๊กที่ผู้ใช้เจอจริงจาก state ระดับโมดูลของ AI TRADE:
 *  - สลับกระเป๋าแล้วข้อมูลของกระเป๋าเดิมค้าง (ไม้/เส้นทุน SL TP บนกราฟของคนก่อนหน้า)
 *  - รอบที่เซิร์ฟเวอร์ปฏิเสธถูกนับว่า "เดินสำเร็จ"
 *  - เซ็นยืนยันกระเป๋าผ่านแล้วไม่มีอะไรโหลดใหม่ → ต้องเซ็นซ้ำ
 *  - โหลดสถานะพลาดครั้งเดียวแล้วสถานะหาย (การ์ดพลิกเป็น "ยังไม่ได้เช่า")
 *  - ตัวเดินบอทฟรีต้องมีตัวเดียว แม้การ์ดกับหน้า /ai-trade จะขอพร้อมกัน
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { reactive, effectScope, nextTick } from 'vue';
import { flushPromises } from '@vue/test-utils';

// กระเป๋าต้อง reactive จริง — ตัวเฝ้าระดับโมดูลดู address / verifiedAt
const wallet = reactive({ address: null, verifiedAt: null });

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const get = vi.fn();
const post = vi.fn();

vi.mock('axios', () => ({
    default: {
        get: (...args) => get(...args),
        post: (...args) => post(...args),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

const { useAiBot } = await import('@/Composables/useAiBot');

const WALLET_A = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const WALLET_B = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/** คำตอบที่ปล่อยเองทีหลังได้ — ใช้จำลอง "คำตอบมาถึงช้า" */
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function httpError(status, code, message) {
    const err = new Error(message || code || 'error');
    err.response = { status, data: code ? { success: false, error: { code, message } } : { message: 'Too Many Attempts.' } };
    return err;
}

function statusPayload(bots = [], extra = {}) {
    return {
        data: {
            success: true,
            data: {
                credits: 0,
                is_active: true,
                subscription: { plan_code: 'free', execution: 'browser' },
                quota: { used_bots: bots.length, max_bots: 1 },
                bots,
                ...extra,
            },
        },
    };
}

const FREE_BOT = { id: 7, name: 'Grid', status: 'running', pair: 'BTC/USDT' };

describe('useAiBot', () => {
    let bot;

    beforeEach(async () => {
        get.mockReset();
        post.mockReset();
        bot = useAiBot();

        // สลับไปไม่มีกระเป๋า = ล้าง state ระดับโมดูลของเทสต์ก่อนหน้า
        wallet.address = null;
        wallet.verifiedAt = null;
        await flushPromises();
    });

    // ── สลับกระเป๋า ─────────────────────────────────────────────────────────

    it('clears everything tied to the previous wallet the moment the wallet changes', async () => {
        wallet.address = WALLET_A;
        get.mockImplementation((url) => {
            if (url.endsWith('/demo')) return Promise.resolve({ data: { data: { account: { balance: 9900 } } } });
            if (url.endsWith('/trades')) return Promise.resolve({ data: { data: [{ id: 1, side: 'buy' }] } });
            if (url.endsWith('/wallet')) return Promise.resolve({ data: { data: { enabled: true, wallet: { address: '0x1' } } } });
            return Promise.resolve(statusPayload([{ ...FREE_BOT, position: { entry_price: 100 } }]));
        });

        await Promise.all([bot.loadStatus(), bot.loadDemo(), bot.loadTrades('BTC/USDT'), bot.loadBotWallet()]);
        expect(bot.trades.value.items).toHaveLength(1);

        wallet.address = WALLET_B;

        // ล้างทันที (ก่อนตัวเฝ้าของคอมโพเนนต์จะโหลดของกระเป๋าใหม่) — กราฟต้องไม่เหลือเส้นของคนก่อน
        expect(bot.status.value).toBeNull();
        expect(bot.bots.value).toEqual([]);
        expect(bot.demo.value).toBeNull();
        expect(bot.trades.value.items).toEqual([]);
        expect(bot.botWallet.value).toBeNull();
    });

    it('drops a response that arrives after the wallet changed', async () => {
        wallet.address = WALLET_A;
        const slow = deferred();
        get.mockReturnValue(slow.promise);

        const pending = bot.loadDemo();
        wallet.address = WALLET_B;

        slow.resolve({ data: { data: { account: { balance: 1234 } } } });
        await pending;

        expect(bot.demo.value).toBeNull();
    });

    it('lets the newest request win when an older one answers late', async () => {
        wallet.address = WALLET_A;
        const old = deferred();
        get.mockReturnValueOnce(old.promise)
            .mockResolvedValueOnce({ data: { data: [{ id: 2 }] } });

        const first = bot.loadTrades('BTC/USDT');
        await bot.loadTrades('ETH/USDT');

        old.resolve({ data: { data: [{ id: 1 }] } });
        await first;

        expect(bot.trades.value).toEqual({ pair: 'ETH/USDT', items: [{ id: 2 }] });
    });

    it('leaves no chart trades behind when there is no wallet', async () => {
        wallet.address = WALLET_A;
        get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
        await bot.loadTrades('BTC/USDT');

        wallet.address = null;
        await bot.loadTrades('BTC/USDT');

        expect(bot.trades.value.items).toEqual([]);
        expect(get).toHaveBeenCalledTimes(1);
    });

    // ── สถานะ + การยืนยันกระเป๋า ─────────────────────────────────────────────

    it('keeps the last known status when a refresh fails', async () => {
        wallet.address = WALLET_A;
        get.mockResolvedValueOnce(statusPayload([FREE_BOT]));
        await bot.loadStatus();

        get.mockRejectedValueOnce(httpError(429));
        await bot.loadStatus({ force: true });

        expect(bot.isActive.value).toBe(true);
        expect(bot.statusError.value.code).toBe('RATE_LIMITED');
        expect(bot.statusError.value.message).toContain('Too many requests');
    });

    it('asks the user to sign again when the wallet IP changed', async () => {
        wallet.address = WALLET_A;
        get.mockRejectedValueOnce(httpError(403, 'WALLET_IP_MISMATCH', 'IP changed'));

        await bot.loadStatus();

        expect(bot.needsVerification.value).toBe(true);
    });

    it('reloads by itself once the wallet signs — no second signature needed', async () => {
        wallet.address = WALLET_A;
        get.mockRejectedValue(httpError(403, 'WALLET_NOT_VERIFIED', 'not verified'));
        await Promise.all([bot.loadStatus(), bot.loadDemo()]);
        expect(bot.needsVerification.value).toBe(true);

        get.mockReset();
        get.mockImplementation((url) => (url.endsWith('/demo')
            ? Promise.resolve({ data: { data: { account: { balance: 10000 } } } })
            : Promise.resolve(statusPayload([]))));

        wallet.verifiedAt = Date.now();
        await nextTick();
        await flushPromises();

        expect(bot.needsVerification.value).toBe(false);
        expect(bot.status.value).not.toBeNull();
        expect(bot.demo.value?.account?.balance).toBe(10000);
    });

    it('fires one status request when several screens ask at once', async () => {
        wallet.address = WALLET_A;
        get.mockResolvedValue(statusPayload([]));

        await Promise.all([bot.loadStatus(), bot.loadStatus(), bot.loadStatus()]);

        expect(get).toHaveBeenCalledTimes(1);
    });

    // ── ตัวเดินบอทฟรี ────────────────────────────────────────────────────────

    it('reports a rejected tick instead of counting it as a live cycle', async () => {
        wallet.address = WALLET_A;
        get.mockImplementation((url) => (url.endsWith('/demo')
            ? Promise.resolve({ data: { data: null } })
            : Promise.resolve(statusPayload([FREE_BOT]))));
        await bot.loadStatus();

        post.mockRejectedValue(httpError(403, 'STRATEGY_LOCKED', 'Needs a higher plan'));

        const scope = effectScope();
        scope.run(() => bot.keepBrowserBotsRunning());
        await flushPromises();
        scope.stop();

        expect(post).toHaveBeenCalledWith('/api/v1/ai-bot/bots/7/tick', { wallet_address: WALLET_A });
        expect(bot.lastBrowserTick.value).toBeNull();
        expect(bot.tickErrorFor(7)).toMatchObject({ code: 'STRATEGY_LOCKED', message: 'Needs a higher plan' });
        expect(bot.browserTickError.value.bot).toBe('Grid');
    });

    it('names a rate-limited tick for what it is', async () => {
        wallet.address = WALLET_A;
        get.mockImplementation((url) => (url.endsWith('/demo')
            ? Promise.resolve({ data: { data: null } })
            : Promise.resolve(statusPayload([FREE_BOT]))));
        await bot.loadStatus();

        post.mockRejectedValue(httpError(429));

        const scope = effectScope();
        scope.run(() => bot.keepBrowserBotsRunning());
        await flushPromises();
        scope.stop();

        expect(bot.tickErrorFor(7).code).toBe('RATE_LIMITED');
        expect(bot.lastBrowserTick.value).toBeNull();
    });

    it('counts a successful tick as the latest cycle and clears the old error', async () => {
        wallet.address = WALLET_A;
        get.mockImplementation((url) => (url.endsWith('/demo')
            ? Promise.resolve({ data: { data: null } })
            : Promise.resolve(statusPayload([FREE_BOT]))));
        await bot.loadStatus();

        post.mockResolvedValue({ data: { success: true, data: { skipped: false, action: 'hold', reason: 'ok', bot: { ...FREE_BOT, last_reason: 'ok' } } } });

        const scope = effectScope();
        scope.run(() => bot.keepBrowserBotsRunning());
        await flushPromises();
        scope.stop();

        expect(bot.lastBrowserTick.value).not.toBeNull();
        expect(bot.tickErrorFor(7)).toBeNull();
        expect(bot.bots.value[0].last_reason).toBe('ok');
    });

    it('runs a single loop for every screen that asks, and stops when the last one leaves', async () => {
        wallet.address = WALLET_A;
        get.mockImplementation((url) => (url.endsWith('/demo')
            ? Promise.resolve({ data: { data: null } })
            : Promise.resolve(statusPayload([FREE_BOT]))));
        await bot.loadStatus();
        post.mockResolvedValue({ data: { success: true, data: { skipped: true } } });

        const card = effectScope();
        const page = effectScope();
        card.run(() => bot.keepBrowserBotsRunning());
        page.run(() => bot.keepBrowserBotsRunning());
        await flushPromises();

        // การ์ด + หน้า /ai-trade ขอพร้อมกัน → บอทถูกสั่งเดินครั้งเดียวต่อรอบ
        expect(post).toHaveBeenCalledTimes(1);
        expect(bot.browserLoopActive.value).toBe(true);

        card.stop();
        await nextTick();
        expect(bot.browserLoopActive.value).toBe(true);   // หน้ายังอยู่ ต้องเดินต่อ

        page.stop();
        await nextTick();
        expect(bot.browserLoopActive.value).toBe(false);
    });

    it('does not tick cloud-plan bots from the browser', async () => {
        wallet.address = WALLET_A;
        get.mockResolvedValue(statusPayload([FREE_BOT], { subscription: { plan_code: 'vip', execution: 'cloud' } }));
        await bot.loadStatus();

        const scope = effectScope();
        scope.run(() => bot.keepBrowserBotsRunning());
        await flushPromises();
        scope.stop();

        expect(post).not.toHaveBeenCalled();
        expect(bot.browserLoopActive.value).toBe(false);
    });

    // ── กระเป๋าบอท + โบนัสต้อนรับ ─────────────────────────────────────────────

    it('reloads the bot wallet after a withdrawal so the pending flag and balance are fresh', async () => {
        wallet.address = WALLET_A;
        post.mockResolvedValue({ data: { success: true, data: { transfer: { id: 1 }, transfers: [{ id: 1 }] } } });
        get.mockResolvedValue({ data: { data: { enabled: true, wallet: { address: '0x1', has_pending_withdraw: true } } } });

        const result = await bot.withdrawBotWallet('USDT', 5);

        expect(result.ok).toBe(true);
        expect(get).toHaveBeenCalledWith('/api/v1/ai-bot/wallet', { params: { wallet_address: WALLET_A } });
        expect(bot.botWallet.value.wallet.has_pending_withdraw).toBe(true);
    });

    it('offers the welcome bonus only until it has been claimed', async () => {
        wallet.address = WALLET_A;
        get.mockResolvedValueOnce(statusPayload([], { welcome_bonus: 100, welcome_claimed: false }));
        await bot.loadStatus();
        expect(bot.welcomeAvailable.value).toBe(true);

        // ใช้เครดิตหมดแล้ว (credits = 0) ปุ่มต้องไม่กลับมา
        get.mockResolvedValueOnce(statusPayload([], { credits: 0, welcome_bonus: 100, welcome_claimed: true }));
        await bot.loadStatus({ force: true });
        expect(bot.welcomeAvailable.value).toBe(false);
    });
});
