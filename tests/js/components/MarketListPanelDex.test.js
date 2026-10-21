/**
 * TPIX TRADE — รายการคู่เทรด (คอลัมน์ซ้ายของหน้าเทรด) กับคู่บน TPIX DEX
 *
 * บั๊กที่ห้ามกลับมา:
 *  - /market/tickers มาจาก Binance ล้วน TPIX จึงไม่เคยมี → ใส่แถวปลอมราคาตายตัว 0.10 + ป้าย "เร็วๆ นี้"
 *    ให้คู่เดียวที่เทรดได้จริงบนเชน TPIX
 *  - คู่ดัชนี (ดูราคาอย่างเดียว) ขึ้นป้าย "เร็วๆ นี้" เหมือนจะเปิดเทรดในอนาคต
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const axiosGet = vi.fn();
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
    return wrapper.findAll('button').find(b => b.text().startsWith(base) || b.text().includes(`${base}USDT`) || b.text().includes(`${base} `));
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
});
