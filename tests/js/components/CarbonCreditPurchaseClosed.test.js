/**
 * TPIX TRADE — หน้า /carbon-credits ตอนปิดการซื้อ
 *
 * กัน: ปุ่มซื้อเดิมยิง POST /purchase โดยไม่ส่งธุรกรรมอะไรเลย แล้วเซิร์ฟเวอร์ก็ออกเครดิตให้ฟรี
 *      ตอนนี้เซิร์ฟเวอร์ปิดไว้ (config/carbon.php) — หน้าต้องบอกว่ายังไม่เปิดขาย ไม่ใช่ให้กดแล้วเจอ error
 *      และถ้าเซิร์ฟเวอร์ไม่ได้ส่ง purchaseEnabled มา ต้องถือว่าปิด
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { reactive } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const WALLET = '0x1111111111111111111111111111111111111111';

const PROJECT = {
    id: 1,
    name: 'Nan Reforestation',
    project_type: 'reforestation',
    location: 'Nan',
    description: 'ปลูกป่าต้นน้ำน่าน',
    total_credits: 1000,
    available_credits: 1000,
    price_per_credit_usd: '12.00',
    standard: 'VCS',
};

const wallet = reactive({ address: WALLET, isConnected: true, openConnectModal: vi.fn() });

const carbon = reactive({
    projects: [PROJECT],
    myCredits: [],
    myRetirements: [],
    stats: null,
    isLoading: false,
    error: null,
    loadAll: vi.fn(),
    fetchProjects: vi.fn(),
    fetchMyCredits: vi.fn(),
    fetchMyRetirements: vi.fn(),
    purchaseCredits: vi.fn(),
    retireCredits: vi.fn(),
});

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));
vi.mock('@/Stores/carbonCreditStore', () => ({ useCarbonCreditStore: () => carbon }));
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const CarbonCredit = (await import('@/Pages/CarbonCredit.vue')).default;

const mounted = [];
async function mountPage(props) {
    const w = mount(CarbonCredit, { props, global: { stubs: { teleport: true } } });
    mounted.push(w);
    await flushPromises();
    return w;
}

const buyButton = (w) => w.find('[data-testid="carbon-buy"]');

beforeEach(() => {
    carbon.purchaseCredits.mockReset();
    wallet.openConnectModal.mockReset();
    wallet.isConnected = true;
});
afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

describe('CarbonCredit page — purchase switch', () => {
    it('ปิดอยู่: บอกว่ายังไม่เปิดขาย ปุ่มซื้อกดไม่ได้ และไม่ยิงคำสั่งซื้อ', async () => {
        const w = await mountPage({ purchaseEnabled: false });

        expect(w.find('[data-testid="carbon-purchase-closed"]').text()).toContain('not open yet');
        expect(buyButton(w).attributes('disabled')).toBeDefined();
        expect(buyButton(w).text()).toBe('Not on sale yet');

        await buyButton(w).trigger('click');
        await flushPromises();

        expect(w.text()).not.toContain('Purchase Carbon Credits');
        expect(carbon.purchaseCredits).not.toHaveBeenCalled();
    });

    it('เซิร์ฟเวอร์ไม่ได้ส่ง purchaseEnabled มา = ถือว่าปิด', async () => {
        const w = await mountPage({});

        expect(w.find('[data-testid="carbon-purchase-closed"]').exists()).toBe(true);
        expect(buyButton(w).attributes('disabled')).toBeDefined();
    });

    it('ยังไม่เชื่อมกระเป๋าแต่ปิดขาย: ไม่ชวนให้เชื่อมกระเป๋าเพื่อซื้อ', async () => {
        wallet.isConnected = false;
        const w = await mountPage({ purchaseEnabled: false });

        expect(buyButton(w).text()).toBe('Not on sale yet');
        await buyButton(w).trigger('click');
        expect(wallet.openConnectModal).not.toHaveBeenCalled();
    });

    it('เปิดอยู่: ไม่มีป้ายปิดขาย และปุ่มซื้อเปิดหน้าต่างสั่งซื้อได้', async () => {
        const w = await mountPage({ purchaseEnabled: true });

        expect(w.find('[data-testid="carbon-purchase-closed"]').exists()).toBe(false);
        expect(buyButton(w).attributes('disabled')).toBeUndefined();
        expect(buyButton(w).text()).toBe('Buy Credits');

        await buyButton(w).trigger('click');
        expect(w.text()).toContain('Purchase Carbon Credits');
    });
});
