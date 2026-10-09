/**
 * TPIX TRADE - TradingChart: timeframe รัวๆ, สตรีม kline, คู่ที่ Binance ไม่มี, เหรียญราคาจิ๋ว
 *
 * บั๊กที่กันไว้:
 *  - เปลี่ยน timeframe ครั้งเดียว onclose ของสายเก่านัดต่อใหม่ → ปิดสายใหม่ เปิดอีกสาย วนทุก 5 วิ
 *  - กด timeframe รัวๆ → กราฟสองตัวซ้อนในกล่องเดียว / ประวัติ 1h แต่สตรีม 5m
 *  - คู่ที่ไม่มีจริง → วาดแท่งปลอมราบๆ ที่ ~$0.18 ให้ดูเหมือนราคาจริง
 *  - MA ปัด 2 ตำแหน่ง + ไม่ตั้ง priceFormat → PEPE แกนราคา 0.00 แท่งแบน
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

const { charts } = vi.hoisted(() => ({ charts: [] }));

vi.mock('lightweight-charts', () => {
    const makeSeries = (type, options) => {
        const series = {
            type,
            options,
            data: null,
            updates: [],
            setData: vi.fn((d) => { series.data = d; }),
            update: vi.fn((p) => { series.updates.push(p); }),
            applyOptions: vi.fn((o) => { Object.assign(series.options, o); }),
            createPriceLine: vi.fn(() => ({})),
            removePriceLine: vi.fn(),
        };
        return series;
    };

    return {
        createChart: vi.fn((el, options) => {
            const chart = {
                el,
                options,
                removed: false,
                series: [],
                addSeries: vi.fn((type, o = {}) => {
                    const s = makeSeries(type, { ...o });
                    chart.series.push(s);
                    return s;
                }),
                removeSeries: vi.fn(),
                priceScale: vi.fn(() => ({ applyOptions: vi.fn() })),
                timeScale: vi.fn(() => ({ fitContent: vi.fn() })),
                remove: vi.fn(() => { chart.removed = true; }),
            };
            charts.push(chart);
            return chart;
        }),
        ColorType: { Solid: 'solid' },
        CrosshairMode: { Normal: 0 },
        CandlestickSeries: 'candle',
        LineSeries: 'line',
        HistogramSeries: 'histogram',
        LineStyle: { Solid: 0, Dotted: 1, Dashed: 2 },
        createSeriesMarkers: vi.fn(() => ({ setMarkers: vi.fn() })),
    };
});

import TradingChart from '@/Components/Trading/TradingChart.vue';

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
        setTimeout(() => this.onclose?.({ code }), 0);
    }

    serverSend(payload) {
        this.onmessage?.({ data: JSON.stringify(payload) });
    }
}

const openSockets = () => MockWebSocket.instances.filter(s => s.readyState !== 3);
const liveCharts = () => charts.filter(c => !c.removed);

async function flush(rounds = 20) {
    for (let i = 0; i < rounds; i++) await Promise.resolve();
}

const HOUR_MS = 3_600_000;
const BASE_MS = Date.UTC(2026, 9, 1, 0, 0, 0);

/** แท่ง Binance [openTime, o, h, l, c, v] */
function klines(close, count = 30, stepMs = HOUR_MS) {
    return Array.from({ length: count }, (_, i) => {
        const c = String(close);
        return [BASE_MS + i * stepMs, c, c, c, c, '10'];
    });
}

/**
 * fetch ที่ "ค้างไว้" จนกว่าเทสต์จะปล่อย — จำลองผลโหลดกลับมาสลับลำดับ
 * คืน { fetch, release(interval, body?, ok?) }
 */
function gatedFetch() {
    const pending = [];
    const fn = vi.fn((url) => new Promise((resolve) => {
        const interval = new URL(url).searchParams.get('interval');
        pending.push({ interval, resolve });
    }));
    return {
        fetch: fn,
        release(interval, body = klines(70000), ok = true) {
            const idx = pending.findIndex(p => p.interval === interval);
            if (idx < 0) throw new Error(`no pending request for ${interval}`);
            const [{ resolve }] = pending.splice(idx, 1);
            resolve({ ok, status: ok ? 200 : 400, json: async () => body });
        },
    };
}

const candleSeries = chart => chart.series.find(s => s.type === 'candle');
const clickTimeframe = (wrapper, tf) => wrapper.findAll('button').find(b => b.text() === tf).trigger('click');

let wrapper;

beforeEach(() => {
    vi.useFakeTimers();
    charts.length = 0;
    MockWebSocket.instances = [];
    vi.stubGlobal('WebSocket', MockWebSocket);
    vi.spyOn(console, 'warn').mockImplementation(() => {});
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('TradingChart — timeframe switching', () => {
    it('rapid switches leave exactly one chart and a stream that matches the history on screen', async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'BTC/USDT' } });
        await flush();

        await clickTimeframe(wrapper, '5m');
        await clickTimeframe(wrapper, '15m');
        await flush();

        // ผลกลับมาสลับลำดับ: อันล่าสุดก่อน แล้วอันเก่าตามมาทีหลัง
        net.release('15m', klines(70000, 30, 15 * 60_000));
        await flush();
        net.release('1h');
        net.release('5m');
        await flush();

        expect(liveCharts()).toHaveLength(1);
        expect(openSockets()).toHaveLength(1);
        expect(openSockets()[0].url).toContain('btcusdt@kline_15m');
    });

    it('a timeframe clicked during the first load is honoured, not dropped', async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'BTC/USDT' } });
        await flush();

        await clickTimeframe(wrapper, '5m'); // ของเดิม: if (chart) → คลิกนี้หายเงียบ
        await flush();
        net.release('1h');
        await flush();
        net.release('5m', klines(70000, 30, 5 * 60_000));
        await flush();

        expect(liveCharts()).toHaveLength(1);
        const step = candleSeries(liveCharts()[0]).data[1].time - candleSeries(liveCharts()[0]).data[0].time;
        expect(step).toBe(300);
        expect(openSockets()[0].url).toContain('@kline_5m');
    });

    it('switching timeframe does not leave the old stream reconnecting forever', async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'BTC/USDT' } });
        await flush();
        net.release('1h');
        await flush();

        const first = MockWebSocket.instances[0];
        await clickTimeframe(wrapper, '4H');
        await flush();
        net.release('4h');
        await flush();

        expect(first.onclose).toBeNull();
        // สายใหม่ส่งแท่งมาเรื่อยๆ (ไม่ให้ watchdog ความเงียบมาปนผล) — ดูแค่ว่าสายเก่าไม่ฟื้น
        const second = MockWebSocket.instances[1];
        for (let i = 0; i < 6; i++) {
            second.serverSend({ k: { t: BASE_MS + 29 * HOUR_MS, o: '1', h: '1', l: '1', c: '1', v: '1' } });
            await vi.advanceTimersByTimeAsync(10_000);
        }
        // ของเดิม: A ปิด → นัดต่อใหม่ → ปิด B เปิด C → วนทุก 5 วิ
        expect(MockWebSocket.instances).toHaveLength(2);
        expect(openSockets()).toEqual([second]);
    });

    it('unmounting closes the kline stream for good', async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'BTC/USDT' } });
        await flush();
        net.release('1h');
        await flush();

        wrapper.unmount();
        wrapper = null;
        await vi.advanceTimersByTimeAsync(300_000);

        expect(MockWebSocket.instances).toHaveLength(1);
        expect(openSockets()).toHaveLength(0);
        expect(liveCharts()).toHaveLength(0);
    });

    it('a load that finishes after unmount never builds a chart', async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'BTC/USDT' } });
        await flush();

        wrapper.unmount();
        wrapper = null;
        net.release('1h');
        await flush();

        expect(charts).toHaveLength(0);
        expect(MockWebSocket.instances).toHaveLength(0);
    });
});

describe('TradingChart — pairs without data', () => {
    it('shows an empty state instead of fake candles for a pair Binance does not list', async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'FOO/USDT' } });
        await flush();
        net.release('1h', { code: -1121, msg: 'Invalid symbol.' }, false);
        await flush();

        expect(charts).toHaveLength(0);
        expect(MockWebSocket.instances).toHaveLength(0);
        expect(wrapper.text()).toContain('No chart data for this pair');
    });

    it('retry rebuilds the chart once data comes back', async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'BTC/USDT' } });
        await flush();
        net.release('1h', [], true);
        await flush();
        expect(wrapper.text()).toContain('No chart data for this pair');

        await wrapper.findAll('button').find(b => b.text() === 'Try again').trigger('click');
        await flush();
        net.release('1h');
        await flush();

        expect(liveCharts()).toHaveLength(1);
        expect(wrapper.text()).not.toContain('No chart data for this pair');
    });

    it('TPIX pairs keep their flat placeholder line (unchanged path)', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) })));
        wrapper = mount(TradingChart, { props: { symbol: 'TPIX/USDT', isTpix: true, ticker: { price: 0.18 } } });
        await flush();

        expect(liveCharts()).toHaveLength(1);
        expect(candleSeries(liveCharts()[0]).data).toHaveLength(300);
        expect(MockWebSocket.instances).toHaveLength(0);
    });
});

describe('TradingChart — precision and time zone', () => {
    it('gives tiny-price pairs real precision and keeps MA decimals', async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'PEPE/USDT' } });
        await flush();
        net.release('1h', klines(0.00000394, 40));
        await flush();

        const chart = liveCharts()[0];
        const candles = candleSeries(chart);
        expect(candles.options.priceFormat).toEqual({ type: 'price', precision: 10, minMove: 1e-10 });

        const ma = chart.series.find(s => s.options.title === 'MA 20');
        expect(ma.options.priceFormat.precision).toBe(10);
        ma.data.forEach(p => expect(p.value).toBeCloseTo(0.00000394, 12));
    });

    it('accepts an explicit pricePrecision', async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'XRP/USDT', pricePrecision: 4 } });
        await flush();
        net.release('1h', klines(1.4035));
        await flush();

        expect(candleSeries(liveCharts()[0]).options.priceFormat).toEqual({ type: 'price', precision: 4, minMove: 1e-4 });
    });

    it('shows the header price with enough decimals for XRP', async () => {
        vi.stubGlobal('fetch', gatedFetch().fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'XRP/USDT', ticker: { price: 1.4035, priceChangePercent: 0.5 } } });
        expect(wrapper.text()).toContain('$1.4035');
    });

    it("shifts candle times into the viewer's local time zone", async () => {
        const net = gatedFetch();
        vi.stubGlobal('fetch', net.fetch);
        wrapper = mount(TradingChart, { props: { symbol: 'BTC/USDT' } });
        await flush();
        net.release('1h');
        await flush();

        const shift = -new Date().getTimezoneOffset() * 60;
        expect(candleSeries(liveCharts()[0]).data[0].time).toBe(BASE_MS / 1000 + shift);

        // แท่งสดจากสตรีมต้องเลื่อนเท่ากัน ไม่งั้นแท่งใหม่หลุดไปอีกโซนเวลา
        const lastOpen = BASE_MS + 29 * HOUR_MS;
        openSockets()[0].serverSend({ k: { t: lastOpen, o: '1', h: '1', l: '1', c: '1', v: '1' } });
        expect(candleSeries(liveCharts()[0]).updates.at(-1).time).toBe(lastOpen / 1000 + shift);
    });
});
