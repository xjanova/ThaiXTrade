/**
 * TPIX TRADE — ปุ่มที่ขยับเงินจริงในหลังบ้าน โผล่เฉพาะ super_admin
 *
 * เซิร์ฟเวอร์ตอบ 403 อยู่แล้ว หน้านี้กันแค่แอดมินระดับล่างกดแล้วไปชน 403
 *
 * กัน: ปุ่มเปิดรอบขาย/คืนเงิน/แก้เราเตอร์โผล่ให้ระดับล่าง · ไม่ส่ง prop แล้วเปิดปุ่มให้เอง
 *      · ฟอร์ม swap ส่งชื่อฟิลด์หรือค่า protocol ที่ controller ไม่รับ (เดิมบันทึกไม่ได้เลย)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

const inertia = vi.hoisted(() => ({
    patch: vi.fn(),
    submit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    usePage: () => ({ props: {} }),
    router: { post: vi.fn(), delete: vi.fn(), patch: inertia.patch },
    useForm: (data) => reactive({
        ...data,
        errors: {},
        processing: false,
        post(url) { inertia.submit(url, { ...this }); },
        put(url) { inertia.submit(url, { ...this }); },
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
}));
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

import SwapIndex from '@/Pages/Admin/Swap/Index.vue';
import FinanceIndex from '@/Pages/Admin/Finance/Index.vue';
import TokenSalesIndex from '@/Pages/Admin/TokenSales/Index.vue';
import SaleLaunchPanel from '@/Components/Admin/SaleLaunchPanel.vue';
import SaleCryptoPanel from '@/Components/Admin/SaleCryptoPanel.vue';

const global = { stubs: { teleport: true } };

const buttons = (wrapper) => wrapper.findAll('button').map((button) => button.text());

const swapConfig = {
    id: 7,
    name: 'PancakeSwap V2',
    chain_id: 3,
    chain: { name: 'BSC' },
    protocol: 'pancakeswap',
    router_address: '0x10ED43C718714eb63d5aA57B78B54704E256024E',
    factory_address: null,
    slippage_tolerance: '0.50',
    is_active: true,
};

function mountSwap(props = {}) {
    return mount(SwapIndex, { props: { configs: [swapConfig], chains: [], ...props }, global });
}

describe('หน้า Swap', () => {
    beforeEach(() => vi.clearAllMocks());

    it('แอดมินระดับล่างเห็นรายการแต่ไม่มีปุ่มแก้ไข', () => {
        const wrapper = mountSwap({ canManageMoney: false });

        expect(wrapper.text()).toContain('PancakeSwap V2');
        expect(buttons(wrapper)).not.toContain('Add Config');
        expect(wrapper.findAll('td svg')).toHaveLength(0);
    });

    it('ไม่ส่ง prop = ถือว่าไม่มีสิทธิ์', () => {
        expect(buttons(mountSwap())).not.toContain('Add Config');
    });

    it('แอดมินระดับล่างสลับเปิดปิดไม่ได้', async () => {
        const wrapper = mountSwap({ canManageMoney: false });
        const toggle = wrapper.find('td button');

        expect(toggle.attributes('disabled')).toBeDefined();

        await toggle.trigger('click');
        expect(inertia.patch).not.toHaveBeenCalled();
    });

    it('super_admin สลับเปิดปิดได้', async () => {
        await mountSwap({ canManageMoney: true }).find('td button').trigger('click');

        expect(inertia.patch).toHaveBeenCalledWith('/admin/swap/7/toggle', {}, expect.anything());
    });

    it('แก้แถวแล้วส่งชื่อฟิลด์และค่า protocol ตรงกับที่ controller รับ', async () => {
        const wrapper = mountSwap({ canManageMoney: true });

        await wrapper.findAll('td button')[1].trigger('click');
        await wrapper.find('form').trigger('submit');

        const [url, sent] = inertia.submit.mock.calls[0];

        expect(url).toBe('/admin/swap/7');
        expect(sent.protocol).toBe('pancakeswap');
        expect(sent.slippage_tolerance).toBe(0.5);
        expect(sent).not.toHaveProperty('default_slippage');
    });

    it('ตัวเลือก protocol ใช้ค่าที่ controller รับ', async () => {
        const wrapper = mountSwap({ canManageMoney: true });

        await wrapper.findAll('button').find((button) => button.text() === 'Add Config').trigger('click');

        const values = wrapper.findAll('option').map((option) => option.element.value).filter(Boolean);

        expect(values).toEqual(['uniswap_v2', 'uniswap_v3', 'pancakeswap', 'sushiswap', 'custom']);
    });
});

describe('หน้า Finance', () => {
    let wrapper;

    const payment = {
        id: 1,
        uuid: 'abc',
        wallet: '0x10ED43C718714eb63d5aA57B78B54704E256024E',
        amount_usd: 100,
        tpix_amount: 1000,
        price_per_tpix: 0.1,
        status: 'confirmed',
        tx_hash: 'stripe_cs_test_1',
        created_at: '2026-10-30T00:00:00+00:00',
    };

    afterEach(() => wrapper.unmount());

    it('แอดมินระดับล่างไม่เห็นปุ่มคืนเงิน', () => {
        wrapper = mount(FinanceIndex, { props: { recentPayments: [payment], canManageMoney: false } });

        expect(buttons(wrapper)).not.toContain('Refund');
    });

    it('super_admin เห็นปุ่มคืนเงินของรายการที่ยืนยันแล้ว', () => {
        wrapper = mount(FinanceIndex, { props: { recentPayments: [payment], canManageMoney: true } });

        expect(buttons(wrapper)).toContain('Refund');
    });
});

describe('หน้า Token Sales', () => {
    const sale = {
        id: 1,
        name: 'TPIX Public Sale',
        status: 'active',
        phases: [{ id: 2, name: 'Public Sale', price_usd: '0.05', allocation: 100, sold: 0, status: 'active' }],
    };

    const bankOrder = {
        id: 3,
        uuid: 'order-uuid',
        wallet_address: '0x10ED43C718714eb63d5aA57B78B54704E256024E',
        payment_currency: 'THB_BANK',
        payment_usd_value: '100',
        tpix_amount: 1000,
        status: 'pending',
        metadata: { method: 'bank_transfer', reference: 'TPIX-ABC234' },
        created_at: '2026-10-30T00:00:00+00:00',
    };

    async function openTab(canManageMoney, label) {
        const wrapper = mount(TokenSalesIndex, {
            props: { sales: [sale], transactions: { data: [bankOrder] }, canManageMoney },
            global,
        });

        await wrapper.findAll('button').find((button) => button.text().includes(label)).trigger('click');

        return wrapper;
    }

    it('แอดมินระดับล่างไม่เห็นปุ่มเพิ่มและแก้เฟส', async () => {
        const labels = buttons(await openTab(false, 'Sales & Phases'));

        expect(labels).not.toContain('+ Phase');
        expect(labels).not.toContain('แก้ไข');
    });

    it('แอดมินระดับล่างไม่เห็นปุ่มยืนยันเงินโอน แต่ยังเห็นรหัสอ้างอิง', async () => {
        const wrapper = await openTab(false, 'Transactions');

        expect(wrapper.text()).toContain('TPIX-ABC234');
        expect(buttons(wrapper)).not.toContain('ได้รับเงินแล้ว');
        expect(buttons(wrapper)).not.toContain('ปฏิเสธ');
    });

    it('super_admin เห็นปุ่มครบ', async () => {
        const labels = buttons(await openTab(true, 'Sales & Phases'));

        expect(labels).toContain('+ Phase');
        expect(labels).toContain('แก้ไข');

        const transactions = buttons(await openTab(true, 'Transactions'));

        expect(transactions).toContain('ได้รับเงินแล้ว');
        expect(transactions).toContain('ปฏิเสธ');
    });
});

describe('กล่องเปิดรอบขาย', () => {
    const launch = {
        sale_id: 1,
        armed: false,
        round_ends_at: '2026-12-31T16:59:00+00:00',
        tiered: true,
        readiness: { ready: true, checks: [{ key: 'wallet', ok: true, label: 'กระเป๋า', detail: 'พร้อม' }], blocking: [] },
        preview: [{ order: 1, name: 'Phase 1', price_usd: '0.05', days: 30, status: 'active', starts_at: '2026-10-30T00:00:00+00:00', ends_at: '2026-11-30T00:00:00+00:00' }],
    };

    it('แอดมินระดับล่างเห็นความพร้อมแต่ไม่มีคำสั่งใด ๆ', () => {
        const wrapper = mount(SaleLaunchPanel, { props: { launch, canManage: false } });

        expect(wrapper.text()).toContain('กระเป๋า');
        expect(wrapper.text()).toContain('Super Admin');
        expect(wrapper.find('input').exists()).toBe(false);

        const labels = buttons(wrapper);

        ['เปิดรอบขาย', 'บันทึก', 'ยกเลิกวันจบ'].forEach((label) => expect(labels).not.toContain(label));
        expect(wrapper.find('[role="switch"]').attributes('disabled')).toBeDefined();
    });

    it('ไม่ส่ง prop = ถือว่าไม่มีสิทธิ์', () => {
        expect(buttons(mount(SaleLaunchPanel, { props: { launch } }))).not.toContain('เปิดรอบขาย');
    });

    it('super_admin สั่งได้ครบ', () => {
        const wrapper = mount(SaleLaunchPanel, { props: { launch, canManage: true } });
        const labels = buttons(wrapper);

        ['เปิดรอบขาย', 'บันทึก', 'ยกเลิกวันจบ'].forEach((label) => expect(labels).toContain(label));
        expect(wrapper.find('[role="switch"]').attributes('disabled')).toBeUndefined();
    });
});

describe('กล่องชำระด้วย USDT', () => {
    const payment = (overrides = {}) => ({
        id: 1,
        reason: 'underpaid',
        amount: '9.000000',
        token_symbol: 'USDT',
        chain: 'BSC',
        from: '0xbbbb00000000000000000000000000000000bbbb',
        tx_hash: '0x' + 'ab'.repeat(32),
        explorer: 'https://bscscan.com',
        order: { reference: 'TPX-ABCDEFG', wallet: '0xbbbb00000000000000000000000000000000bbbb', pay_amount: '10.000100', amount_usd: 10, tpix_amount: 200, price_per_tpix: 0.05 },
        ...overrides,
    });
    const crypto = { accepted: true, receive_problem: null, receive_address: '0x5a1e', chains: [], review: [payment()] };

    it('แอดมินระดับล่างเห็นรายการแต่ไม่มีปุ่มสั่ง', () => {
        const wrapper = mount(SaleCryptoPanel, { props: { crypto, canManage: false } });

        expect(wrapper.find('[data-testid="crypto-review-row"]').text()).toContain('TPX-ABCDEFG');
        expect(buttons(wrapper)).toEqual([]);
        expect(wrapper.text()).toContain('เฉพาะ Super Admin');
    });

    it('ไม่ส่ง prop = ถือว่าไม่มีสิทธิ์', () => {
        expect(buttons(mount(SaleCryptoPanel, { props: { crypto } }))).toEqual([]);
    });

    it('super_admin สั่งได้ครบ แต่โอนผิดเหรียญมีแค่ปิดรายการ', () => {
        const wrapper = mount(SaleCryptoPanel, {
            props: { crypto: { ...crypto, review: [payment(), payment({ id: 2, reason: 'wrong_token' })] }, canManage: true },
        });

        expect(buttons(wrapper)).toEqual(['ออกเหรียญตามยอดที่ได้รับ', 'บันทึกว่าคืนเงิน/จัดการแล้ว', 'บันทึกว่าคืนเงิน/จัดการแล้ว']);
        expect(wrapper.text()).not.toContain('เฉพาะ Super Admin');
    });

    it('หน้า Token Sales ส่งสิทธิ์ลงกล่องนี้', async () => {
        for (const [canManageMoney, count] of [[false, 0], [true, 2]]) {
            const wrapper = mount(TokenSalesIndex, { props: { crypto, canManageMoney }, global });
            await wrapper.findAll('button').find((button) => button.text().includes('Sales & Phases')).trigger('click');

            expect(wrapper.findComponent(SaleCryptoPanel).findAll('button')).toHaveLength(count);
        }
    });
});
