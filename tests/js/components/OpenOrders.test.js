/**
 * TPIX TRADE — OpenOrders tests
 *
 * ตารางคำสั่งที่เปิดอยู่เดิมกลืนทุก error เป็น "ไม่มีคำสั่ง" และ "ยกเลิกทั้งหมด" ยิงทันทีไม่ถาม
 * เทสต์ชุดนี้กันอาการเหล่านั้นกลับมา:
 *  - 403 ต้องชวนเซ็นยืนยัน / error อื่นต้องมีปุ่มลองใหม่ — ไม่ใช่หน้าว่าง
 *  - หน้าว่างต้องอธิบายว่า market order บน BSC จับคู่ทันที
 *  - "ซ่อนคู่อื่น" ต้องกรองจริง
 *  - ยกเลิกล้มเหลวต้องขึ้น toast ที่แปลแล้ว (ห้ามข้อความดิบของ exception)
 *  - "ยกเลิกทั้งหมด" ต้องถามก่อน และไม่ยิงซ้ำซ้อนเมื่อกดรัว
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
const del = vi.fn();
vi.mock('axios', () => ({
    default: {
        get: (...args) => get(...args),
        delete: (...args) => del(...args),
    },
}));

const showToast = vi.fn();
vi.mock('@/Composables/useToasts', () => ({ showToast: (...args) => showToast(...args) }));

const OpenOrders = (await import('@/Components/Trading/OpenOrders.vue')).default;

const WALLET_A = '0x1111111111111111111111111111111111111111';
const WALLET_B = '0x2222222222222222222222222222222222222222';
const ISO = '2026-08-19T10:00:00.000Z';

const ORDERS = [
    {
        id: 'o1', pair: 'BTC-USDT', side: 'buy', type: 'limit', price: '60000', amount: '0.01',
        filled_amount: '0.0025', total: '600', status: 'open', created_at: ISO,
    },
    {
        id: 'o2', pair: 'ETH/USDT', side: 'sell', type: 'limit', price: '3000', amount: '0.5',
        filled_amount: '0', total: null, status: 'open', created_at: ISO,
    },
];

function respondWith(rows) {
    get.mockResolvedValue({ data: { success: true, data: rows } });
}

function connect(address = WALLET_A) {
    wallet.address = address;
    wallet.isConnected = !!address;
}

const mounted = [];

async function mountOrders(props = {}) {
    const wrapper = mount(OpenOrders, { props });
    mounted.push(wrapper);
    await flushPromises();
    return wrapper;
}

const rowCount = wrapper => wrapper.findAll('[data-test="orders-row"]').length;

describe('OpenOrders', () => {
    let confirmSpy;

    beforeEach(() => {
        get.mockReset();
        del.mockReset();
        showToast.mockReset();
        wallet.verifyOwnership.mockReset();
        wallet.openConnectModal.mockReset();
        connect(null);
        confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    afterEach(() => {
        mounted.splice(0).forEach(w => w.unmount());
        confirmSpy.mockRestore();
    });

    it('asks to connect and does not call the API when disconnected', async () => {
        const wrapper = await mountOrders();

        expect(wrapper.text()).toContain('Connect your wallet to see your open orders');
        expect(get).not.toHaveBeenCalled();
    });

    it('explains why the list is empty (market orders fill instantly on BSC)', async () => {
        connect();
        respondWith([]);

        const wrapper = await mountOrders();
        const empty = wrapper.find('[data-test="orders-empty"]');

        expect(empty.exists()).toBe(true);
        expect(empty.text()).toContain('Market orders on BSC fill instantly');
        expect(empty.text()).toContain('Limit orders are coming soon');
    });

    it('renders orders without a $ sign and with real fill progress', async () => {
        connect();
        respondWith(ORDERS);

        const wrapper = await mountOrders();
        const [btc, eth] = wrapper.findAll('[data-test="orders-row"]').map(r => r.text());

        expect(btc).toContain('BTC/USDT');
        expect(btc).toContain('60,000.00');
        expect(btc).toContain('25%');
        expect(btc).toContain('Limit');
        expect(btc).toContain('Buy');
        expect(btc).not.toContain('$');
        // total ไม่มาจาก API → คำนวณจากราคา × จำนวน
        expect(eth).toContain('1,500.00');
        expect(eth).toContain('Sell');
    });

    it('asks to verify the wallet on 403 instead of looking empty, then reloads', async () => {
        connect();
        get.mockRejectedValueOnce({ response: { status: 403, data: { error: { code: 'WALLET_NOT_VERIFIED' } } } });

        const wrapper = await mountOrders();
        expect(wrapper.find('[data-test="orders-verify"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="orders-empty"]').exists()).toBe(false);

        let finishSigning;
        wallet.verifyOwnership.mockImplementation(() => new Promise((resolve) => { finishSigning = resolve; }));
        respondWith(ORDERS);

        const button = wrapper.find('[data-test="orders-verify"] button');
        await button.trigger('click');
        await button.trigger('click');   // กดรัวระหว่างรอเซ็น
        expect(wallet.verifyOwnership).toHaveBeenCalledTimes(1);

        finishSigning(true);
        await flushPromises();

        expect(rowCount(wrapper)).toBe(2);
    });

    it('shows an error with retry on other failures', async () => {
        connect();
        get.mockRejectedValueOnce(new Error('Network Error'));

        const wrapper = await mountOrders();
        const failed = wrapper.find('[data-test="orders-failed"]');
        expect(failed.exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Network Error');

        respondWith(ORDERS);
        await failed.find('button').trigger('click');
        await flushPromises();

        expect(rowCount(wrapper)).toBe(2);
    });

    it('hides the "hide other pairs" checkbox without a current pair', async () => {
        connect();
        respondWith(ORDERS);

        const wrapper = await mountOrders();
        expect(wrapper.find('[data-test="orders-hide-other"]').exists()).toBe(false);
    });

    it('filters by the current pair when "hide other pairs" is ticked', async () => {
        connect();
        respondWith(ORDERS);

        const wrapper = await mountOrders({ currentPair: 'BTC/USDT' });
        expect(rowCount(wrapper)).toBe(2);

        await wrapper.find('[data-test="orders-hide-other"]').setValue(true);
        expect(rowCount(wrapper)).toBe(1);
        expect(wrapper.find('[data-test="orders-row"]').text()).toContain('BTC/USDT');

        await wrapper.setProps({ currentPair: 'SOL/USDT' });
        expect(rowCount(wrapper)).toBe(0);
        expect(wrapper.find('[data-test="orders-no-match"]').text()).toContain('SOL/USDT');
    });

    it('shows a translated toast (never the raw exception) when a cancel fails', async () => {
        connect();
        respondWith(ORDERS);
        del.mockRejectedValueOnce(Object.assign(new Error('SQLSTATE[HY000] secret detail'), { response: { status: 500 } }));

        const wrapper = await mountOrders();
        await wrapper.findAll('[data-test="orders-cancel"]')[0].trigger('click');
        await flushPromises();

        expect(showToast).toHaveBeenCalledTimes(1);
        const toast = showToast.mock.calls[0][0];
        expect(toast.type).toBe('error');
        expect(toast.text).toBe("Couldn't cancel the order. Please try again.");
        expect(toast.text).not.toContain('SQLSTATE');
        expect(rowCount(wrapper)).toBe(2);   // ยกเลิกไม่สำเร็จ = แถวยังอยู่
    });

    it('removes the row after a successful cancel', async () => {
        connect();
        respondWith(ORDERS);
        del.mockResolvedValueOnce({ data: { success: true } });

        const wrapper = await mountOrders();
        await wrapper.findAll('[data-test="orders-cancel"]')[0].trigger('click');
        await flushPromises();

        expect(del).toHaveBeenCalledWith('/api/v1/trading/order/o1', { data: { wallet_address: WALLET_A } });
        expect(rowCount(wrapper)).toBe(1);
        expect(showToast.mock.calls[0][0].type).toBe('success');
    });

    it('asks for confirmation before cancelling all, and does nothing when declined', async () => {
        connect();
        respondWith(ORDERS);
        confirmSpy.mockReturnValue(false);

        const wrapper = await mountOrders();
        await wrapper.find('[data-test="orders-cancel-all"]').trigger('click');
        await flushPromises();

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(confirmSpy.mock.calls[0][0]).toContain('Cancel 2 open order(s)?');
        expect(del).not.toHaveBeenCalled();
        expect(rowCount(wrapper)).toBe(2);
    });

    it('cancels all one at a time and ignores repeated clicks while running', async () => {
        connect();
        respondWith(ORDERS);

        const pending = [];
        del.mockImplementation(() => new Promise((resolve) => { pending.push(resolve); }));

        const wrapper = await mountOrders();
        const cancelAll = wrapper.find('[data-test="orders-cancel-all"]');

        await cancelAll.trigger('click');
        await cancelAll.trigger('click');
        await cancelAll.trigger('click');

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(del).toHaveBeenCalledTimes(1);   // ทีละคำสั่ง ไม่ยิงพร้อมกัน
        expect(cancelAll.attributes('disabled')).toBeDefined();

        respondWith([]);   // หลังยกเลิกครบ โหลดใหม่แล้วว่าง
        pending.shift()({ data: { success: true } });
        await flushPromises();
        expect(del).toHaveBeenCalledTimes(2);

        pending.shift()({ data: { success: true } });
        await flushPromises();

        expect(del).toHaveBeenCalledTimes(2);
        expect(showToast).toHaveBeenCalledWith({ text: 'Cancelled 2 order(s)', type: 'success' });
        expect(wrapper.find('[data-test="orders-empty"]').exists()).toBe(true);
    });

    it('stops cancelling all on 403 and asks to verify', async () => {
        connect();
        respondWith(ORDERS);
        del.mockRejectedValue({ response: { status: 403 } });

        const wrapper = await mountOrders();
        await wrapper.find('[data-test="orders-cancel-all"]').trigger('click');
        await flushPromises();

        expect(del).toHaveBeenCalledTimes(1);
        expect(showToast).toHaveBeenCalledWith({ text: 'Verify your wallet first, then cancel again.', type: 'error' });
        expect(wrapper.find('[data-test="orders-verify"]').exists()).toBe(true);
    });

    it('reloads for a new wallet and clears on disconnect', async () => {
        connect(WALLET_A);
        respondWith(ORDERS);
        const wrapper = await mountOrders();
        expect(rowCount(wrapper)).toBe(2);

        respondWith([]);
        connect(WALLET_B);
        await flushPromises();
        expect(get).toHaveBeenLastCalledWith('/api/v1/trading/orders', { params: { wallet_address: WALLET_B } });
        expect(rowCount(wrapper)).toBe(0);

        connect(null);
        await flushPromises();
        expect(wrapper.text()).toContain('Connect your wallet to see your open orders');
    });

    it('drops a slow response for the previous wallet', async () => {
        connect(WALLET_A);
        let resolveOld;
        get.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; }));
        const wrapper = await mountOrders();

        respondWith([]);
        connect(WALLET_B);
        await flushPromises();

        // คำตอบของกระเป๋า A มาช้า — ต้องไม่ทับตารางของ B
        resolveOld({ data: { success: true, data: ORDERS } });
        await flushPromises();

        expect(rowCount(wrapper)).toBe(0);
    });
});
