/**
 * TPIX TRADE - useBinanceData: วงจรชีวิตของ WebSocket + การโหลดภาพรวม
 *
 * บั๊กที่เทสต์ชุดนี้กันไว้:
 *  - ถอดคอมโพเนนต์/สลับคู่แล้ว onclose ของสายเก่ายังนัดต่อใหม่ → สายของคู่เก่าเปิดค้างตลอดไป
 *  - ต่อใหม่ทุก 5 วิไม่มี backoff, สายเงียบ (หลับ/NAT ตัด) ไม่เคยถูกจับได้
 *  - fetchInitialData กลืน error เอง → หน้าเทรดไม่เคยรู้ว่าคู่นี้ Binance ไม่มี
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { effectScope } from 'vue';
import {
    useBinanceData,
    decimalsUsed,
    decimalsForPrice,
    decimalsForPrices,
    formatMarketPrice,
} from '@/Composables/useBinanceData';

// ── WebSocket ปลอม: close() ยิง onclose แบบ async เหมือนเบราว์เซอร์จริง ──────────
class MockWebSocket {
    static instances = [];

    constructor(url) {
        this.url = url;
        this.readyState = 0;
        this.closeCalls = 0;
        this.onopen = null;
        this.onmessage = null;
        this.onerror = null;
        this.onclose = null;
        MockWebSocket.instances.push(this);
    }

    close(code = 1005) {
        this.closeCalls += 1;
        if (this.readyState === 3) return;
        this.readyState = 3;
        // เบราว์เซอร์ยิง onclose ทีหลังเสมอ — ถ้าโค้ดไม่ถอด handler ก่อน สายเก่าจะนัดต่อใหม่ตรงนี้
        setTimeout(() => this.onclose?.({ code }), 0);
    }

    serverOpen() {
        this.readyState = 1;
        this.onopen?.({});
    }

    serverSend(payload) {
        this.onmessage?.({ data: JSON.stringify(payload) });
    }

    serverDrop(code = 1006) {
        this.readyState = 3;
        this.onclose?.({ code });
    }
}

const openSockets = () => MockWebSocket.instances.filter(s => s.readyState !== 3);
const lastSocket = () => MockWebSocket.instances.at(-1);

/** ปล่อย microtask ที่ค้าง (await fetch/json ซ้อนกันหลายชั้น) — ไม่พึ่ง setTimeout ที่ถูกแกล้งอยู่ */
async function flush(rounds = 20) {
    for (let i = 0; i < rounds; i++) await Promise.resolve();
}

const okJson = body => ({ ok: true, status: 200, json: async () => body });

function marketFetch(price = '1.4035') {
    return vi.fn(async (url) => {
        if (url.includes('/ticker/24hr')) {
            return okJson({
                lastPrice: price, priceChange: '0.01', priceChangePercent: '0.70',
                highPrice: '1.5', lowPrice: '1.3', quoteVolume: '1000',
            });
        }
        if (url.includes('/depth')) return okJson({ asks: [['1.40360000', '10']], bids: [['1.40350000', '5']] });
        if (url.includes('/trades')) {
            return okJson([{ id: 7, price, qty: '3', time: 1_700_000_000_000, isBuyerMaker: false }]);
        }
        throw new Error(`unexpected url ${url}`);
    });
}

const invalidSymbolFetch = () => vi.fn(async () => ({
    ok: false,
    status: 400,
    json: async () => ({ code: -1121, msg: 'Invalid symbol.' }),
}));

function setup(getSymbol = () => 'XRPUSDT') {
    const scope = effectScope();
    const api = scope.run(() => useBinanceData(getSymbol));
    return { scope, api };
}

async function connected(getSymbol) {
    const ctx = setup(getSymbol);
    await ctx.api.fetchInitialData();
    ctx.api.connectWebSocket();
    return ctx;
}

let visibility = 'visible';

beforeEach(() => {
    vi.useFakeTimers();
    MockWebSocket.instances = [];
    vi.stubGlobal('WebSocket', MockWebSocket);
    vi.stubGlobal('fetch', marketFetch());
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    visibility = 'visible';
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete document.visibilityState;
});

describe('useBinanceData — stream lifecycle', () => {
    it('opens one combined stream for the loaded pair', async () => {
        const { scope } = await connected();

        expect(MockWebSocket.instances).toHaveLength(1);
        expect(lastSocket().url).toContain('xrpusdt@ticker');
        expect(lastSocket().url).toContain('xrpusdt@depth20@1000ms');
        expect(lastSocket().url).toContain('xrpusdt@trade');
        scope.stop();
    });

    it('disconnect detaches the handlers so the late onclose never reopens the old pair', async () => {
        const { api, scope } = await connected();
        const first = lastSocket();

        api.disconnectWebSocket();
        expect(first.closeCalls).toBe(1);
        expect(first.onclose).toBeNull();
        expect(first.onmessage).toBeNull();

        await vi.advanceTimersByTimeAsync(120_000);
        expect(MockWebSocket.instances).toHaveLength(1);
        scope.stop();
    });

    it('unmounting while a reconnect is pending stops it for good', async () => {
        const { scope } = await connected();
        lastSocket().serverDrop(1006); // หลุดเอง → นัดต่อใหม่ไว้แล้ว

        scope.stop(); // = คอมโพเนนต์ถูกถอด (สลับคู่ / ออกจากหน้า)
        await vi.advanceTimersByTimeAsync(300_000);

        expect(MockWebSocket.instances).toHaveLength(1);
    });

    it('reconnects after unexpected drops with 2s → 30s exponential backoff', async () => {
        const { scope } = await connected();

        for (const delay of [2_000, 4_000, 8_000, 16_000, 30_000, 30_000]) {
            const count = MockWebSocket.instances.length;
            lastSocket().serverDrop(1006);

            await vi.advanceTimersByTimeAsync(delay - 1);
            expect(MockWebSocket.instances).toHaveLength(count);

            await vi.advanceTimersByTimeAsync(1);
            expect(MockWebSocket.instances).toHaveLength(count + 1);
        }
        scope.stop();
    });

    it('real data resets the backoff', async () => {
        const { scope } = await connected();

        lastSocket().serverDrop();
        await vi.advanceTimersByTimeAsync(2_000);
        lastSocket().serverDrop();
        await vi.advanceTimersByTimeAsync(4_000);

        lastSocket().serverOpen();
        lastSocket().serverSend({ stream: 'xrpusdt@ticker', data: { c: '1.5', p: '0', P: '0', h: '1.6', l: '1.4', q: '1' } });

        const count = MockWebSocket.instances.length;
        lastSocket().serverDrop();
        await vi.advanceTimersByTimeAsync(2_000);
        expect(MockWebSocket.instances).toHaveLength(count + 1);
        scope.stop();
    });

    it('a stream that goes silent for 30s is replaced', async () => {
        const { api, scope } = await connected();
        const first = lastSocket();
        first.serverOpen();
        first.serverSend({ stream: 'xrpusdt@trade', data: { t: 8, p: '1.4036', q: '1', T: Date.now(), m: false } });
        expect(api.isLive.value).toBe(true);

        await vi.advanceTimersByTimeAsync(29_000);
        expect(first.closeCalls).toBe(0);

        await vi.advanceTimersByTimeAsync(10_000);
        expect(first.closeCalls).toBe(1);
        expect(first.onclose).toBeNull();
        expect(api.isLive.value).toBe(false);
        expect(MockWebSocket.instances.length).toBe(2);
        expect(openSockets()).toEqual([lastSocket()]);
        scope.stop();
    });

    it('isLive follows real data, not just an open socket', async () => {
        const { api, scope } = await connected();
        expect(api.isLive.value).toBe(false);

        lastSocket().serverOpen();
        expect(api.isLive.value).toBe(false); // เปิดสายได้แต่ยังไม่มีข้อมูล ≠ สด

        lastSocket().serverSend({ stream: 'xrpusdt@depth20@1000ms', data: { asks: [['1.4037', '1']], bids: [['1.4034', '2']] } });
        expect(api.isLive.value).toBe(true);
        expect(api.asks.value[0].price).toBe(1.4037);

        lastSocket().serverDrop();
        expect(api.isLive.value).toBe(false);
        scope.stop();
    });

    it('applies streamed trades newest first', async () => {
        const { api, scope } = await connected();
        lastSocket().serverSend({ stream: 'xrpusdt@trade', data: { t: 99, p: '1.4040', q: '2', T: Date.now(), m: true } });

        expect(api.trades.value[0]).toMatchObject({ id: 99, price: 1.404, isBuy: false });
        expect(api.trades.value[1].id).toBe(7);
        scope.stop();
    });
});

describe('useBinanceData — initial snapshot', () => {
    it('upper-cases the symbol for Binance REST (lowercase URL /trade/btc-usdt)', async () => {
        const { api, scope } = setup(() => 'btcusdt');
        await api.fetchInitialData();

        expect(fetch.mock.calls.every(([url]) => url.includes('symbol=BTCUSDT'))).toBe(true);
        scope.stop();
    });

    it('rejects on an unlisted pair, records why, and refuses to open a dead stream', async () => {
        vi.stubGlobal('fetch', invalidSymbolFetch());
        const { api, scope } = setup(() => 'FOOUSDT');

        await expect(api.fetchInitialData()).rejects.toThrow('Invalid symbol.');
        expect(api.errorCode.value).toBe('invalid-symbol');
        expect(api.isLoading.value).toBe(false);

        api.connectWebSocket();
        expect(MockWebSocket.instances).toHaveLength(0);
        scope.stop();
    });

    it('rejects on a network failure as well (Trade.vue catch must run)', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('Failed to fetch'); }));
        const { api, scope } = setup();

        await expect(api.fetchInitialData()).rejects.toThrow('Failed to fetch');
        expect(api.errorCode.value).toBe('network');
        expect(api.error.value).toBe('Failed to fetch');
        expect(api.ticker.value.price).toBe(0);
        scope.stop();
    });

    it('a load cancelled by disconnect resolves quietly and its late connect is ignored', async () => {
        let release;
        const gate = new Promise((resolve) => { release = resolve; });
        const real = marketFetch();
        vi.stubGlobal('fetch', vi.fn(async (url) => { await gate; return real(url); }));
        const { api, scope } = setup();

        const pending = api.fetchInitialData();
        api.disconnectWebSocket(); // สลับไปคู่ TPIX ระหว่างรอ Binance
        release();

        await expect(pending).resolves.toBeUndefined();
        api.connectWebSocket(); // บรรทัดถัดไปใน startFeeds ของรอบเก่า
        expect(MockWebSocket.instances).toHaveLength(0);
        expect(api.ticker.value.price).toBe(0);
        scope.stop();
    });

    it('a slower, older load never overwrites the newer pair', async () => {
        let symbol = 'BTCUSDT';
        const gates = {};
        const prices = { BTCUSDT: '70000.01', ETHUSDT: '2500.12' };
        vi.stubGlobal('fetch', vi.fn(async (url) => {
            const sym = url.match(/symbol=([A-Z]+)/)[1];
            await (gates[sym] ||= (() => { let r; const p = new Promise((res) => { r = res; }); p.release = r; return p; })());
            return marketFetch(prices[sym])(url);
        }));
        const { api, scope } = setup(() => symbol);

        const btc = api.fetchInitialData();
        symbol = 'ETHUSDT';
        const eth = api.fetchInitialData();

        gates.ETHUSDT.release();
        await eth;
        gates.BTCUSDT.release();
        await btc;

        expect(api.ticker.value.price).toBe(2500.12);
        scope.stop();
    });

    it('refetches the REST snapshot when the tab becomes visible, and stops listening after disconnect', async () => {
        const { api, scope } = await connected();
        const callsAfterLoad = fetch.mock.calls.length;
        await vi.advanceTimersByTimeAsync(10_000);

        visibility = 'hidden';
        document.dispatchEvent(new Event('visibilitychange'));
        visibility = 'visible';
        document.dispatchEvent(new Event('visibilitychange'));
        await flush();

        expect(fetch.mock.calls.length).toBe(callsAfterLoad + 3);

        api.disconnectWebSocket();
        await vi.advanceTimersByTimeAsync(10_000);
        document.dispatchEvent(new Event('visibilitychange'));
        await flush();
        expect(fetch.mock.calls.length).toBe(callsAfterLoad + 3);
        scope.stop();
    });

    it('returning to the tab reconnects a stale stream immediately', async () => {
        const { scope } = await connected();
        const first = lastSocket();

        // นาฬิกาเดินไปไกลแบบไม่มี timer ทำงาน = เครื่องหลับ
        vi.setSystemTime(Date.now() + 10 * 60_000);
        document.dispatchEvent(new Event('visibilitychange'));

        expect(first.onclose).toBeNull();
        expect(MockWebSocket.instances).toHaveLength(2);
        scope.stop();
    });
});

describe('price precision helpers', () => {
    it('counts the decimals a price really uses', () => {
        expect(decimalsUsed(1.4035)).toBe(4);
        expect(decimalsUsed(0.1 + 0.2)).toBe(1);
        expect(decimalsUsed(70000)).toBe(0);
        expect(decimalsUsed(0.00000394)).toBe(8);
    });

    it('scales decimals with the price magnitude', () => {
        expect(decimalsForPrice(67234.5)).toBe(2);
        expect(decimalsForPrice(1.4035)).toBe(4);
        expect(decimalsForPrice(0.15234)).toBe(5);
        expect(decimalsForPrice(0.00001234)).toBe(9);
        expect(decimalsForPrice(0.00000394)).toBe(10);
        expect(decimalsForPrice(0)).toBe(2);
    });

    it('uses one decimal count for a column so neighbours stay distinguishable', () => {
        expect(decimalsForPrices([1.4035, 1.4036, 1.404])).toBe(4);
        expect(decimalsForPrices([70000, 70100])).toBe(2);
        expect(decimalsForPrices([], 0.00000394)).toBe(10);
    });

    it('formats prices without collapsing sub-cent coins to zero', () => {
        expect(formatMarketPrice(1.4035, 4)).toBe('1.4035');
        expect(formatMarketPrice(67234.5)).toBe('67,234.50');
        expect(formatMarketPrice(0.00000394, 8)).toBe('0.00000394');
        expect(formatMarketPrice(Number.NaN)).toBe('—');
    });
});
