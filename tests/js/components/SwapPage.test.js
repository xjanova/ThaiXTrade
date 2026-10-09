/**
 * TPIX TRADE — หน้า Swap (เงินจริงบน BSC)
 *
 * บั๊กที่เคยเจอและห้ามกลับมา:
 *  - ปุ่มอนุมัติไม่สลับไป BSC ก่อน → อนุมัติไปลงเชนอื่น แล้วสวอป revert
 *  - "ได้รับอย่างน้อย" คิดจาก slippage ของ backend ไม่ใช่ที่ผู้ใช้เลือก
 *  - ช่อง slippage รับ 99% / ช่องว่าง (= 0 → revert)
 *  - คำขอราคาเก่าที่ตอบช้าทับคำขอใหม่ → ส่งธุรกรรมด้วยจำนวน/เส้นทางที่ไม่ได้อยู่บนจอ
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { reactive, ref } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const wallet = reactive({
    address: '0x1111111111111111111111111111111111111111',
    isConnected: true,
    isBSC: true,
    chainId: 56,
    switchChain: vi.fn(),
});

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const swapState = {
    isLoadingQuote: ref(false),
    isExecuting: ref(false),
    isApproving: ref(false),
    error: ref(null),
    txHash: ref(null),
    txStatus: ref(null),
};
const getQuote = vi.fn();
const checkAllowance = vi.fn();
const approveToken = vi.fn();
const executeSwap = vi.fn();

vi.mock('@/Composables/useSwap', () => ({
    useSwap: () => ({
        ...swapState,
        getQuote: (...args) => getQuote(...args),
        checkAllowance: (...args) => checkAllowance(...args),
        approveToken: (...args) => approveToken(...args),
        executeSwap: (...args) => executeSwap(...args),
        getBalance: () => Promise.resolve('0'),
        reset: vi.fn(),
    }),
}));

vi.mock('@/Composables/usePlatformReadiness', () => ({
    usePlatformReadiness: () => ({
        load: vi.fn(),
        reload: vi.fn(),
        reasonFor: () => null,
        loadFailed: ref(false),
    }),
}));

// ด่าน KYC ของการเทรดมากับ page.props.kyc — แต่ละเทสต์ตั้งเองได้
const inertiaPage = { props: { kyc: { features: {} } } };
const routerVisit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    usePage: () => inertiaPage,
    router: { visit: (...a) => routerVisit(...a) },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/CoinIcon.vue', () => ({ default: { template: '<span />' } }));
vi.mock('@/Components/Wallet/WalletModal.vue', () => ({ default: { template: '<div />' } }));

const Swap = (await import('@/Pages/Swap.vue')).default;

/** ราคาปลอม: ได้ 2 เท่าของจำนวนที่จ่าย — แยกได้ชัดว่าตัวเลขบนจอมาจากคำขอไหน */
function quoteFor(amount, slippage = 0.5) {
    const netOutput = amount * 2;
    return {
        amountIn: amount,
        netOutput,
        amountOut: netOutput,
        exchangeRate: 2,
        feeRate: 0.3,
        feeAmount: amount * 0.003,
        priceImpact: 0.1,
        slippage,
        minimumReceived: netOutput * (1 - slippage / 100),
        path: ['0xa', '0xb'],
        rawAmountOut: 1000n,
        amountInSwapWei: 999n,
        feeWei: 1n,
    };
}

const mounted = [];

async function mountSwap() {
    const wrapper = mount(Swap);
    mounted.push(wrapper);
    await flushPromises();
    return wrapper;
}

const amountInput = wrapper => wrapper.find('input[placeholder="0.0"]');
const swapButton = wrapper => wrapper.find('[data-test="swap-button"]');

/** พิมพ์จำนวนแล้วรอ debounce ของการขอราคา */
async function typeAmount(wrapper, value) {
    await amountInput(wrapper).setValue(value);
    await vi.advanceTimersByTimeAsync(600);
    await flushPromises();
}

async function openSettings(wrapper) {
    await wrapper.find('button[aria-label="Slippage settings"]').trigger('click');
}

describe('Swap page', () => {
    beforeEach(() => {
        // หลอกเฉพาะ setTimeout/Date — flushPromises ยังใช้ setImmediate ตัวจริงได้
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
        localStorage.removeItem('tpix_trade_settings');

        wallet.isConnected = true;
        wallet.isBSC = true;
        wallet.chainId = 56;
        wallet.switchChain.mockReset().mockImplementation(async () => {
            wallet.isBSC = true;
            wallet.chainId = 56;
        });

        Object.values(swapState).forEach((r) => { r.value = r === swapState.error || r === swapState.txHash || r === swapState.txStatus ? null : false; });
        getQuote.mockReset().mockImplementation(async (_from, _to, amount, opts = {}) => quoteFor(amount, opts.slippage ?? 0.5));
        checkAllowance.mockReset().mockResolvedValue(true);
        approveToken.mockReset().mockResolvedValue({ hash: '0xapprove' });
        executeSwap.mockReset().mockResolvedValue({ hash: '0xswap', status: 'confirmed' });
    });

    afterEach(() => {
        mounted.splice(0).forEach(w => w.unmount());
        vi.useRealTimers();
        inertiaPage.props.kyc = { features: {} };
        routerVisit.mockReset();
    });

    describe('identity verification gate', () => {
        it('sends the user to verify instead of swapping when trading needs KYC', async () => {
            inertiaPage.props.kyc = { features: { trading: { required: true, passed: false } } };

            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');

            expect(swapButton(wrapper).exists()).toBe(false);
            await wrapper.find('[data-test="kyc-button"]').trigger('click');

            expect(routerVisit).toHaveBeenCalledWith('/kyc');
            expect(executeSwap).not.toHaveBeenCalled();
        });

        it('swaps normally once the account has passed', async () => {
            inertiaPage.props.kyc = { features: { trading: { required: true, passed: true } } };

            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');

            expect(wrapper.find('[data-test="kyc-button"]').exists()).toBe(false);
            expect(swapButton(wrapper).exists()).toBe(true);
        });
    });

    describe('approve', () => {
        it('switches the wallet to BSC before approving', async () => {
            wallet.isBSC = false;
            wallet.chainId = 4289;
            checkAllowance.mockResolvedValue(false);
            const order = [];
            wallet.switchChain.mockImplementation(async (id) => {
                order.push(`switch:${id}`);
                wallet.isBSC = true;
                wallet.chainId = 56;
            });
            approveToken.mockImplementation(async () => { order.push('approve'); });

            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');
            await wrapper.find('[data-test="approve-button"]').trigger('click');
            await flushPromises();

            expect(order).toEqual(['switch:56', 'approve']);
        });

        it('does not approve when the network switch is rejected', async () => {
            wallet.isBSC = false;
            checkAllowance.mockResolvedValue(false);
            wallet.switchChain.mockRejectedValue({ code: 4001 });

            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');
            await wrapper.find('[data-test="approve-button"]').trigger('click');
            await flushPromises();

            expect(approveToken).not.toHaveBeenCalled();
            expect(wrapper.find('[data-test="swap-action-notice"]').text()).toContain('Network switch was cancelled');
        });
    });

    describe('slippage', () => {
        it('quotes with the page slippage and re-quotes when it changes', async () => {
            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');

            expect(getQuote.mock.calls.at(-1)[3]).toEqual({ slippage: 0.5 });
            expect(wrapper.find('[data-test="min-received"]').text()).toContain('1.990000');

            await openSettings(wrapper);
            const threePct = wrapper.findAll('button').find(b => b.text() === '3%');
            await threePct.trigger('click');
            await vi.advanceTimersByTimeAsync(600);
            await flushPromises();

            expect(getQuote.mock.calls.at(-1)[3]).toEqual({ slippage: 3 });
            // 2 × (1 − 3%) — ตรงกับ minOut ที่จะส่งเข้า router
            expect(wrapper.find('[data-test="min-received"]').text()).toContain('1.940000');
        });

        it('rejects out-of-range input and never executes with it', async () => {
            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');
            await openSettings(wrapper);

            const input = wrapper.find('[data-test="slippage-input"]');
            await input.setValue('99');

            expect(wrapper.text()).toContain('Enter a slippage between 0.01% and 50%.');
            expect(swapButton(wrapper).attributes('disabled')).toBeDefined();
            expect(swapButton(wrapper).text()).toBe('Invalid slippage');

            await swapButton(wrapper).trigger('click');
            expect(executeSwap).not.toHaveBeenCalled();

            // ออกจากช่อง → ตัดที่เพดาน 50 แล้วขึ้นคำเตือนสีเหลือง
            await input.trigger('blur');
            expect(input.element.value).toBe('50');
            expect(wrapper.find('[data-test="slippage-high-warning"]').exists()).toBe(true);
        });

        it('restores the last valid value when the field is emptied', async () => {
            const wrapper = await mountSwap();
            await openSettings(wrapper);

            const input = wrapper.find('[data-test="slippage-input"]');
            await input.setValue('');
            expect(wrapper.text()).toContain('Enter a slippage between');

            await input.trigger('blur');
            expect(input.element.value).toBe('0.5');
            expect(wrapper.text()).not.toContain('Enter a slippage between');
        });

        it('warns above 5% but still allows the swap', async () => {
            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');
            await openSettings(wrapper);
            await wrapper.find('[data-test="slippage-input"]').setValue('8');
            await vi.advanceTimersByTimeAsync(600);
            await flushPromises();

            expect(wrapper.find('[data-test="slippage-high-warning"]').exists()).toBe(true);

            await swapButton(wrapper).trigger('click');
            await flushPromises();
            expect(executeSwap).toHaveBeenCalledTimes(1);
            expect(executeSwap.mock.calls[0][4]).toBe(8);
        });
    });

    describe('quote sequencing', () => {
        it('ignores a slow response from an older request', async () => {
            const resolvers = [];
            getQuote.mockImplementation((_f, _t, amount) => new Promise((resolve) => {
                resolvers.push(() => resolve(quoteFor(amount)));
            }));

            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');   // คำขอ #1 ค้าง
            await typeAmount(wrapper, '5');   // คำขอ #2 ค้าง

            resolvers[1]();   // ใหม่กลับมาก่อน
            await flushPromises();
            resolvers[0]();   // เก่ากลับมาทีหลัง — ต้องถูกทิ้ง
            await flushPromises();

            expect(wrapper.text()).toContain('10.000000');
            expect(wrapper.text()).not.toContain('2.000000');

            await swapButton(wrapper).trigger('click');
            await flushPromises();
            expect(executeSwap).toHaveBeenCalledTimes(1);
            expect(executeSwap.mock.calls[0][2]).toBe(5);
            expect(executeSwap.mock.calls[0][3].netOutput).toBe(10);
        });

        it('re-quotes instead of executing when the amount changed during the network switch', async () => {
            wallet.isBSC = false;
            wallet.chainId = 4289;

            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');

            // ผู้ใช้พิมพ์จำนวนใหม่ระหว่างที่ป๊อปอัพสลับเชนยังเปิดอยู่
            wallet.switchChain.mockImplementation(async () => {
                await amountInput(wrapper).setValue('3');
                wallet.isBSC = true;
                wallet.chainId = 56;
            });

            await swapButton(wrapper).trigger('click');
            await flushPromises();

            expect(executeSwap).not.toHaveBeenCalled();
            expect(getQuote.mock.calls.at(-1)[2]).toBe(3);
            expect(wrapper.find('[data-test="swap-action-notice"]').text()).toContain('The price was updated');
            expect(wrapper.text()).toContain('6.000000');
        });

        it('re-quotes a stale quote right before executing', async () => {
            const wrapper = await mountSwap();
            await typeAmount(wrapper, '1');
            const quotesBefore = getQuote.mock.calls.length;

            await vi.advanceTimersByTimeAsync(31000);
            await swapButton(wrapper).trigger('click');
            await flushPromises();

            expect(getQuote.mock.calls.length).toBe(quotesBefore + 1);
            expect(executeSwap).toHaveBeenCalledTimes(1);
        });

        it('executes with the quote, amount and slippage that are on screen', async () => {
            const wrapper = await mountSwap();
            await typeAmount(wrapper, '2');

            await swapButton(wrapper).trigger('click');
            await flushPromises();

            const [from, to, amount, quote, slippage] = executeSwap.mock.calls[0];
            expect(from.symbol).toBe('BNB');
            expect(to.symbol).toBe('USDT');
            expect(amount).toBe(2);
            expect(quote.netOutput).toBe(4);
            expect(slippage).toBe(0.5);
        });
    });
});
