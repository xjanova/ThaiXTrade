/**
 * TPIX TRADE — ตัวเลือกเชน (แถบหัว)
 *
 * บั๊กที่ห้ามกลับมา:
 *  - ฝัง 56 เป็นเชนปริยาย: คนที่ถูกพาไป TPIX (เชนปริยายจริง) เปิดเมนูเจอ "Switch to BSC (Recommended)"
 *    และป้าย Main บน BSC — สวนทางกับที่ระบบพาไปเอง
 *  - กระเป๋าอยู่บนเชนที่ยังไม่เปิด (coming_soon) แต่ป้ายขึ้นจุดเขียว "รองรับ"
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { reactive } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const wallet = reactive({
    isConnected: true,
    chainId: 4289,
    error: null,
    switchChain: vi.fn(),
    openConnectModal: vi.fn(),
});
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a) => axiosGet(...a), post: vi.fn() } }));

const ChainSelector = (await import('@/Components/Navigation/ChainSelector.vue')).default;

const CHAINS = [
    { chainId: 56, name: 'BNB Smart Chain', shortName: 'BSC', status: 'live', nativeCurrency: { symbol: 'BNB' } },
    { chainId: 4289, name: 'TPIX Chain', shortName: 'TPIX', status: 'live', nativeCurrency: { symbol: 'TPIX' } },
    { chainId: 1, name: 'Ethereum', shortName: 'ETH', status: 'coming_soon', nativeCurrency: { symbol: 'ETH' } },
];

function serverDefault(defaultChainId) {
    axiosGet.mockResolvedValue({ data: { success: true, data: CHAINS, meta: { default_chain_id: defaultChainId } } });
}

const mounted = [];
async function openSelector() {
    const wrapper = mount(ChainSelector, { attachTo: document.body });
    mounted.push(wrapper);
    await flushPromises();
    await wrapper.find('button').trigger('click');
    return wrapper;
}

describe('ChainSelector', () => {
    beforeEach(() => {
        wallet.isConnected = true;
        wallet.chainId = 4289;
        wallet.switchChain.mockReset().mockResolvedValue(undefined);
        serverDefault(4289);
    });

    afterEach(() => {
        mounted.splice(0).forEach(w => w.unmount());
    });

    it('does not push users off the default TPIX Chain towards BSC', async () => {
        const wrapper = await openSelector();

        expect(wrapper.find('[data-test="chain-recommend"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Switch to BSC');
        // ป้าย Main อยู่ที่เชนปริยายของเซิร์ฟเวอร์
        const badges = wrapper.findAll('[data-test="chain-main-badge"]');
        expect(badges).toHaveLength(1);
        expect(badges[0].element.closest('button').textContent).toContain('TPIX Chain');
    });

    it('recommends the server default chain by name when the wallet is elsewhere', async () => {
        wallet.chainId = 56;
        const wrapper = await openSelector();

        const recommend = wrapper.find('[data-test="chain-recommend"]');
        expect(recommend.text()).toBe('Switch to TPIX (Recommended)');

        await recommend.trigger('click');
        expect(wallet.switchChain).toHaveBeenCalledWith(4289);
    });

    it('still recommends BSC while the server says BSC is the default', async () => {
        serverDefault(56);
        const wrapper = await openSelector();

        expect(wrapper.find('[data-test="chain-recommend"]').text()).toBe('Switch to BSC (Recommended)');
    });

    it('flags a wallet sitting on a coming-soon chain instead of showing it as supported', async () => {
        wallet.chainId = 1;
        const wrapper = await openSelector();

        const warning = wrapper.find('[data-test="chain-unsupported"]');
        expect(warning.exists()).toBe(true);
        expect(warning.text()).toContain('Network not open for trading yet');
        expect(wrapper.find('[data-test="chain-recommend"]').text()).toBe('Switch to TPIX (Recommended)');
    });

    it('never offers a coming-soon chain as the recommendation', async () => {
        serverDefault(1);
        wallet.chainId = 56;
        const wrapper = await openSelector();

        expect(wrapper.find('[data-test="chain-recommend"]').exists()).toBe(false);
    });
});
