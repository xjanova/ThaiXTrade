/**
 * TPIX TRADE — รายการคู่เทรด (คอลัมน์ซ้ายของหน้าเทรด) กับคู่บน TPIX DEX
 *
 * บั๊กที่ห้ามกลับมา:
 *  - /market/tickers มาจาก Binance ล้วน TPIX จึงไม่เคยมี → ใส่แถวปลอมราคาตายตัว 0.10 + ป้าย "เร็วๆ นี้"
 *    ให้คู่เดียวที่เทรดได้จริงบนเชน TPIX
 *  - คู่ดัชนี (ดูราคาอย่างเดียว) ขึ้นป้าย "เร็วๆ นี้" เหมือนจะเปิดเทรดในอนาคต
 *  - รอบรีเฟรช 30 วิ ใส่ราคา Binance ลงลิสต์ก่อนแล้วค่อยรอราคาพูล — แถว TPIX/USDT หายวูบระหว่างรอ
 *    (/dex/ticker ใช้ ~2.2 วิ บน prod สุ่มดูเว็บจริง 70 ครั้ง หาย 3 ครั้ง)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref, reactive, nextTick } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const axiosGet = vi.fn();
const wallet = reactive({ isConnected: false, chainId: null, supportedChains: [] });
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));
vi.mock('axios', () => ({ default: { get: (...a) => axiosGet(...a), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ router: { visit: vi.fn() } }));
vi.mock('@/Composables/useSparklines', () => ({ useSparklines: () => ({ series: ref({}), load: vi.fn() }) }));
vi.mock('@/Components/CoinIcon.vue', () => ({ default: { template: '<span />' } }));
vi.mock('@/Components/Trading/Sparkline.vue', () => ({ default: { template: '<span />' } }));

const MarketListPanel = (await import('@/Components/Trading/MarketListPanel.vue')).default;

const PAIRS = [
    {
        symbol: 'TPIX-USDT', base_asset: 'TPIX', quote_asset: 'USDT', execution_mode: 'onchain',
        network_chain_id: 4289, base_address: '0x0000000000000000000000000000000000000000',
    },
    { symbol: 'SUI-USDT', base_asset: 'SUI', quote_asset: 'USDT', execution_mode: 'index', network_chain_id: 4289 },
    { symbol: 'BTC-USDT', base_asset: 'BTC', quote_asset: 'USDT', execution_mode: 'onchain', network_chain_id: 56 },
];
const TICKERS = [
    { baseAsset: 'BTC', quoteAsset: 'USDT', price: '67000', priceChangePercent: '1.2', quoteVolume: '1000' },
    { baseAsset: 'SUI', quoteAsset: 'USDT', price: '3.21', priceChangePercent: '-0.5', quoteVolume: '500' },
];

let dexTicker;

function rowFor(wrapper, base) {
    // เฉพาะปุ่มในแถว — ปุ่มหมวด "TPIX" ก็ขึ้นต้นด้วยชื่อเหรียญเหมือนกัน
    return wrapper.findAll('.market-row button').find(b => b.text().startsWith(base) || b.text().includes(`${base}USDT`) || b.text().includes(`${base} `));
}

const mounted = [];
async function mountPanel() {
    const wrapper = mount(MarketListPanel, { props: { currentPair: 'BTC/USDT' } });
    mounted.push(wrapper);
    await flushPromises();
    return wrapper;
}

describe('MarketListPanel with TPIX DEX pairs', () => {
    beforeEach(() => {
        localStorage.removeItem('tpix.marketQuote');
        Object.assign(wallet, { isConnected: false, chainId: null, supportedChains: [] });
        dexTicker = vi.fn(async () => ({ data: { success: true, data: { price: 0.1234, change_24h: 2.5, quote_volume_24h: 42 } } }));
        axiosGet.mockReset().mockImplementation(async (url) => {
            if (url === '/api/v1/market/tickers') return { data: { success: true, data: TICKERS } };
            if (url === '/api/v1/market/pairs') return { data: { success: true, data: PAIRS } };
            if (url === '/api/v1/dex/ticker/TPIX-USDT') return dexTicker();
            throw new Error(`unexpected GET ${url}`);
        });
    });

    afterEach(() => {
        mounted.splice(0).forEach(w => w.unmount());
        vi.useRealTimers();
    });

    it('shows the live pool price for TPIX/USDT without a "Soon" badge', async () => {
        const wrapper = await mountPanel();

        expect(axiosGet).toHaveBeenCalledWith('/api/v1/dex/ticker/TPIX-USDT');
        expect(wrapper.text()).toContain('0.1234');
        expect(wrapper.text()).not.toContain('0.1000');
        const tpix = rowFor(wrapper, 'TPIX');
        expect(tpix.find('[data-test="badge-soon"]').exists()).toBe(false);
    });

    it('marks index pairs as price reference, not "coming soon"', async () => {
        const wrapper = await mountPanel();

        const sui = rowFor(wrapper, 'SUI');
        expect(sui.find('[data-test="badge-index"]').text()).toBe('Ref');
        expect(sui.find('[data-test="badge-soon"]').exists()).toBe(false);
    });

    it('shows "—" instead of a made-up price when the pool cannot be read', async () => {
        dexTicker = vi.fn(async () => { throw new Error('429'); });
        const wrapper = await mountPanel();

        const tpix = rowFor(wrapper, 'TPIX');
        expect(tpix.text()).toContain('—');
        expect(wrapper.text()).not.toContain('0.1000');
    });

    it('keeps a "Soon" placeholder without a price while the DEX pair does not exist yet', async () => {
        axiosGet.mockImplementation(async (url) => {
            if (url === '/api/v1/market/tickers') return { data: { success: true, data: TICKERS } };
            if (url === '/api/v1/market/pairs') return { data: { success: true, data: PAIRS.slice(1) } };
            throw new Error(`unexpected GET ${url}`);
        });
        const wrapper = await mountPanel();

        const tpix = rowFor(wrapper, 'TPIX');
        expect(tpix.find('[data-test="badge-soon"]').exists()).toBe(true);
        expect(tpix.text()).toContain('—');
    });

    it('keeps the TPIX/USDT row with its last price while a refresh waits for the pool price', async () => {
        // ปลอมแค่ interval — flushPromises ยังใช้ setImmediate/setTimeout จริงได้
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
        const wrapper = await mountPanel();
        expect(rowFor(wrapper, 'TPIX').text()).toContain('0.1234');

        // รอบรีเฟรชถัดไป ราคาพูลตอบช้า — ค้างไว้จนกว่าจะปล่อยเอง
        let release;
        dexTicker = vi.fn(() => new Promise((resolve) => { release = resolve; }));
        vi.advanceTimersByTime(30000);
        await flushPromises();

        expect(dexTicker).toHaveBeenCalled();
        const waiting = rowFor(wrapper, 'TPIX');
        expect(waiting).toBeTruthy();
        expect(waiting.text()).toContain('0.1234');

        release({ data: { success: true, data: { price: 0.2345, change_24h: 1, quote_volume_24h: 10 } } });
        await flushPromises();
        expect(rowFor(wrapper, 'TPIX').text()).toContain('0.2345');
    });
});

/*
 * กรองคู่ตามเชนที่กระเป๋าอยู่ + หมวด TPIX + บอกมาตรฐานเหรียญที่ปุ่มหมวด (เจ้าของสั่ง 2026-10-28)
 *
 * บั๊กที่ห้ามกลับมา:
 *  - กระเป๋าอยู่เชน TPIX แต่ลิสต์โชว์คู่ BSC กดแล้วระบบสลับกระเป๋าออกไป BSC เอง
 *  - ปุ่มหมวดบอกแค่ "USDT" ทั้งที่เป็น USDT ของ BSC (BEP20) ผู้ใช้โอน USDT เชนอื่นมาแล้วเทรดไม่ได้
 *  - เชน/โหมดของแถวเก็บตามเหรียญ ไม่ใช่ตามคู่ — คู่หลังในทะเบียนทับคู่แรก
 */
describe('MarketListPanel chain filter and quote tabs', () => {
    beforeEach(() => {
        localStorage.removeItem('tpix.marketQuote');
        Object.assign(wallet, { isConnected: false, chainId: null, supportedChains: [] });
        axiosGet.mockReset().mockImplementation(async (url) => {
            if (url === '/api/v1/market/tickers') return { data: { success: true, data: TICKERS } };
            if (url === '/api/v1/market/pairs') return { data: { success: true, data: PAIRS } };
            if (url === '/api/v1/dex/ticker/TPIX-USDT') return { data: { success: true, data: { price: 0.12 } } };
            throw new Error(`unexpected GET ${url}`);
        });
    });

    afterEach(() => {
        mounted.splice(0).forEach(w => w.unmount());
    });

    const bases = (wrapper) => wrapper.findAll('.market-row').map(r => r.text());

    it('shows only BSC pairs while the wallet is on BSC, labelling USDT as BEP20', async () => {
        Object.assign(wallet, { isConnected: true, chainId: 56 });
        const wrapper = await mountPanel();

        const rows = bases(wrapper);
        expect(rows.some(r => r.includes('BTC'))).toBe(true);
        expect(rows.some(r => r.includes('TPIX'))).toBe(false);
        expect(rows.some(r => r.includes('SUI'))).toBe(false);
        expect(wrapper.find('[data-test="quote-tab-USDT"] [data-test="quote-network"]').text()).toBe('BEP20');
        expect(wrapper.find('[data-test="chain-scope"]').text()).toContain('BSC');
    });

    it('shows only TPIX Chain pairs while the wallet is on TPIX Chain', async () => {
        Object.assign(wallet, { isConnected: true, chainId: 4289 });
        const wrapper = await mountPanel();

        const rows = bases(wrapper);
        expect(rows.some(r => r.includes('TPIX'))).toBe(true);
        expect(rows.some(r => r.includes('SUI'))).toBe(true);
        expect(rows.some(r => r.includes('BTC'))).toBe(false);
        expect(wrapper.find('[data-test="quote-tab-USDT"] [data-test="quote-network"]').text()).toBe('TPIX Chain');
    });

    it('re-filters when the wallet switches chain', async () => {
        Object.assign(wallet, { isConnected: true, chainId: 56 });
        const wrapper = await mountPanel();
        expect(bases(wrapper).some(r => r.includes('BTC'))).toBe(true);

        wallet.chainId = 4289;
        await nextTick();
        expect(bases(wrapper).some(r => r.includes('BTC'))).toBe(false);
        expect(bases(wrapper).some(r => r.includes('TPIX'))).toBe(true);
    });

    it('shows every chain with a per-row chain badge when no wallet is connected', async () => {
        const wrapper = await mountPanel();

        const btc = wrapper.findAll('.market-row').find(r => r.text().includes('BTC'));
        expect(btc.find('[data-test="badge-chain"]').text()).toBe('BSC');
        // USDT อยู่สองเชน — บอกมาตรฐานเดียวที่ปุ่มก็ผิดอีกเชน
        expect(wrapper.find('[data-test="quote-tab-USDT"] [data-test="quote-network"]').exists()).toBe(false);
    });

    it('always offers a TPIX tab and explains why it is empty', async () => {
        Object.assign(wallet, { isConnected: true, chainId: 4289 });
        const wrapper = await mountPanel();

        await wrapper.find('[data-test="quote-tab-TPIX"]').trigger('click');
        expect(wrapper.findAll('.market-row')).toHaveLength(0);
        expect(wrapper.find('[data-test="market-empty"]').text()).toContain('TPIX');
    });

    it('lists pairs priced in TPIX under the TPIX tab', async () => {
        axiosGet.mockImplementation(async (url) => {
            if (url === '/api/v1/market/tickers') return { data: { success: true, data: TICKERS } };
            if (url === '/api/v1/market/pairs') {
                return { data: { success: true, data: [...PAIRS, {
                    symbol: 'IDTC-TPIX', base_asset: 'IDTC', quote_asset: 'TPIX', execution_mode: 'onchain', network_chain_id: 4289,
                }] } };
            }
            if (url.startsWith('/api/v1/dex/ticker/')) return { data: { success: true, data: { price: 2 } } };
            throw new Error(`unexpected GET ${url}`);
        });
        Object.assign(wallet, { isConnected: true, chainId: 4289 });
        const wrapper = await mountPanel();

        await wrapper.find('[data-test="quote-tab-TPIX"]').trigger('click');
        const rows = bases(wrapper);
        expect(rows).toHaveLength(1);
        expect(rows[0]).toContain('IDTC');
    });

    it('tells the user when the wallet chain has no pairs at all', async () => {
        Object.assign(wallet, { isConnected: true, chainId: 137 });
        const wrapper = await mountPanel();

        expect(wrapper.findAll('.market-row')).toHaveLength(0);
        expect(wrapper.find('[data-test="market-empty"]').text()).toContain('POL');
    });
});

