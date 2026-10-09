/**
 * TPIX TRADE — TradeHistory tests
 *
 * ตารางประวัติพังแบบ "เงียบ" ได้หลายทาง ซึ่งผู้ใช้จะตีความว่าไม้หาย:
 *  - 403 (ยังไม่เซ็นยืนยันกระเป๋า) ถูกกลืนเป็น "ยังไม่มีประวัติ"
 *  - เหรียญราคาต่ำโชว์ราคาเป็น 0.00 / มี $ หน้าจำนวนเหรียญ
 *  - ตัวกรองที่กดแล้วไม่มีผล
 *  - สลับกระเป๋าแล้วยังเห็นไม้ของใบเก่า
 *
 * ใช้ useMyTrades ตัวจริง (ปลอมแค่ axios) — ตารางกับป้ายบนกราฟต้องใช้ข้อมูลชุดเดียวกันจริงๆ
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { reactive } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const wallet = reactive({
    address: null,
    isConnected: false,
    verifyOwnership: vi.fn(),
    openConnectModal: vi.fn(),
});

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const get = vi.fn();
vi.mock('axios', () => ({ default: { get: (...args) => get(...args) } }));

const { useMyTrades } = await import('@/Composables/useMyTrades');
const TradeHistory = (await import('@/Components/Trading/TradeHistory.vue')).default;

const WALLET_A = '0x1111111111111111111111111111111111111111';
const WALLET_B = '0x2222222222222222222222222222222222222222';
const ISO = '2026-08-19T10:00:00.000Z';

const ROWS = [
    {
        id: 't1', type: 'swap', pair: 'BTC/USDT', side: 'buy', price: '65000.5', amount: '0.0015',
        total: '97.50075', fee: '0.0000045', tx_hash: '0xabc1234567890def', chain_id: 56, status: 'completed', created_at: ISO,
    },
    {
        id: 't2', type: 'swap', pair: 'PEPE/USDT', side: 'sell', price: '0.00000394', amount: '1250000',
        total: '4.925', fee: null, tx_hash: null, chain_id: null, status: 'completed', created_at: ISO,
    },
    {
        // แถวเก่าแปลกๆ: side ไม่ใช่ buy/sell, ราคาไม่รู้ ('0') — ต้องไม่ทำให้ตารางพัง
        id: 't3', type: 'deposit', pair: 'BTC/USDT', side: null, price: '0', amount: '2',
        total: null, fee: '0', tx_hash: null, chain_id: null, status: 'confirmed', created_at: ISO,
    },
];

function respondWith(rows) {
    get.mockResolvedValue({ data: { success: true, data: rows } });
}

function connect(address = WALLET_A) {
    wallet.address = address;
    wallet.isConnected = !!address;
}

/** ถอดทุกตัวหลังจบเทสต์ — ไม่งั้น watch ของตัวที่ค้างอยู่ยิงโหลดซ้อนเข้ามาในเทสต์ถัดไป */
const mounted = [];

async function mountHistory() {
    const wrapper = mount(TradeHistory);
    mounted.push(wrapper);
    await flushPromises();
    return wrapper;
}

describe('TradeHistory', () => {
    afterEach(() => {
        mounted.splice(0).forEach(w => w.unmount());
    });

    beforeEach(async () => {
        get.mockReset();
        wallet.verifyOwnership.mockReset();
        wallet.openConnectModal.mockReset();
        // ล้างแคชระดับโมดูลของ useMyTrades ด้วยการตัดกระเป๋า
        connect(null);
        await useMyTrades().load();
    });

    it('asks to connect a wallet and never calls the API when disconnected', async () => {
        const wrapper = await mountHistory();

        expect(wrapper.text()).toContain('Connect your wallet to see your trade history');
        expect(get).not.toHaveBeenCalled();

        await wrapper.find('button').trigger('click');
        expect(wallet.openConnectModal).toHaveBeenCalledTimes(1);
    });

    it('renders rows with sensible number formatting', async () => {
        connect();
        respondWith(ROWS);

        const wrapper = await mountHistory();
        const rows = wrapper.findAll('[data-test="history-row"]');
        expect(rows).toHaveLength(3);

        const [btc, pepe, odd] = rows.map(r => r.text());

        // ราคาเหรียญจิ๋วต้องไม่กลายเป็น 0.00
        expect(pepe).toContain('0.00000394');
        expect(btc).toContain('65,000.50');
        // จำนวนเหรียญไม่มี $ นำหน้า และไม่ปัดทิ้ง
        expect(btc).toContain('0.0015');
        expect(wrapper.text()).not.toMatch(/\$\s*0\.0015/);
        // มูลค่ารวม 2 ตำแหน่ง
        expect(btc).toContain('97.50');
        expect(pepe).toContain('4.93');
        // ราคาไม่รู้ = ขีด ไม่ใช่ $0
        expect(odd).toContain('—');
        expect(odd).not.toContain('$');
    });

    it('shows a neutral badge with the raw type for odd sides instead of crashing', async () => {
        connect();
        respondWith(ROWS);

        const wrapper = await mountHistory();
        const odd = wrapper.findAll('[data-test="history-row"]')[2];

        expect(odd.text()).toContain('deposit');
        expect(odd.text()).not.toContain('Buy');
        expect(odd.text()).not.toContain('Sell');
    });

    it('links the tx to the explorer of the row chain (BSC by default)', async () => {
        connect();
        respondWith(ROWS);

        const wrapper = await mountHistory();
        const link = wrapper.find('a[href*="/tx/"]');

        expect(link.attributes('href')).toBe('https://bscscan.com/tx/0xabc1234567890def');
        expect(link.attributes('rel')).toContain('noopener');
    });

    it('builds the pair filter from the data and actually filters', async () => {
        connect();
        respondWith(ROWS);

        const wrapper = await mountHistory();
        const pairSelect = wrapper.find('[data-test="history-pair-filter"]');
        const options = pairSelect.findAll('option').map(o => o.element.value);

        expect(options).toEqual(['all', 'BTC/USDT', 'PEPE/USDT']);

        await pairSelect.setValue('PEPE/USDT');
        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(1);

        await pairSelect.setValue('BTC/USDT');
        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(2);
    });

    it('filters by side and offers a way out when nothing matches', async () => {
        connect();
        respondWith(ROWS);

        const wrapper = await mountHistory();
        const sideSelect = wrapper.find('[data-test="history-side-filter"]');

        await sideSelect.setValue('sell');
        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(1);

        await wrapper.find('[data-test="history-pair-filter"]').setValue('BTC/USDT');
        expect(wrapper.find('[data-test="history-no-match"]').exists()).toBe(true);

        await wrapper.find('[data-test="history-no-match"] button').trigger('click');
        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(3);
    });

    it('shows the empty state only when there really are no trades', async () => {
        connect();
        respondWith([]);

        const wrapper = await mountHistory();

        expect(wrapper.find('[data-test="history-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No trades yet');
    });

    it('asks to verify the wallet on 403, guards double clicks, then reloads', async () => {
        connect();
        get.mockRejectedValueOnce({ response: { status: 403, data: { error: { code: 'WALLET_NOT_VERIFIED' } } } });

        const wrapper = await mountHistory();
        expect(wrapper.find('[data-test="history-verify"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('No trades yet');

        let finishSigning;
        wallet.verifyOwnership.mockImplementation(() => new Promise((resolve) => { finishSigning = resolve; }));
        respondWith(ROWS);

        const button = wrapper.find('[data-test="history-verify"] button');
        await button.trigger('click');
        await button.trigger('click');   // กดรัวระหว่างรอเซ็น
        expect(wallet.verifyOwnership).toHaveBeenCalledTimes(1);

        finishSigning(true);
        await flushPromises();

        expect(wrapper.find('[data-test="history-verify"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(3);
    });

    it('opens the connect modal when the wallet has no signer to verify with', async () => {
        connect();
        get.mockRejectedValueOnce({ response: { status: 403 } });
        wallet.verifyOwnership.mockResolvedValue(false);

        const wrapper = await mountHistory();
        await wrapper.find('[data-test="history-verify"] button').trigger('click');
        await flushPromises();

        expect(wallet.openConnectModal).toHaveBeenCalledTimes(1);
    });

    it('tells a load failure apart from an empty history and can retry', async () => {
        connect();
        get.mockRejectedValueOnce({ response: { status: 500 } });

        const wrapper = await mountHistory();
        expect(wrapper.find('[data-test="history-failed"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('No trades yet');

        respondWith(ROWS);
        await wrapper.find('[data-test="history-failed"] button').trigger('click');
        await flushPromises();

        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(3);
    });

    it('keeps existing rows on screen while refreshing (no spinner flash)', async () => {
        connect();
        respondWith(ROWS);
        const wrapper = await mountHistory();

        let resolveRefresh;
        get.mockImplementationOnce(() => new Promise((resolve) => { resolveRefresh = resolve; }));
        useMyTrades().load(true);   // แบบที่ Trade.vue เรียกหลังเทรดเสร็จ
        // load() ส่งบันทึกที่ค้างก่อนแล้วค่อยยิงประวัติ — รอให้ถึงจุดที่คำขอค้างอยู่จริง
        await flushPromises();
        expect(useMyTrades().isLoading.value).toBe(true);

        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(3);
        expect(wrapper.text()).not.toContain('Loading your trades');

        resolveRefresh({ data: { success: true, data: [ROWS[0]] } });
        await flushPromises();
        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(1);
    });

    it('reloads for the new wallet and never shows the previous wallet trades', async () => {
        connect(WALLET_A);
        respondWith(ROWS);
        const wrapper = await mountHistory();
        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(3);

        get.mockResolvedValueOnce({ data: { success: true, data: [] } });
        connect(WALLET_B);
        await flushPromises();

        expect(get).toHaveBeenLastCalledWith('/api/v1/trading/history', { params: { wallet_address: WALLET_B } });
        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(0);
        expect(wrapper.find('[data-test="history-empty"]').exists()).toBe(true);
    });

    it('clears everything on disconnect', async () => {
        connect();
        respondWith(ROWS);
        const wrapper = await mountHistory();

        connect(null);
        await flushPromises();

        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(0);
        expect(wrapper.text()).toContain('Connect your wallet to see your trade history');
        expect(useMyTrades().trades.value).toEqual([]);
    });
});
