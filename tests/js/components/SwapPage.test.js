/**
 * TPIX TRADE — หน้า Swap (เงินจริงบน BSC และ TPIX Chain)
 *
 * บั๊กที่เคยเจอและห้ามกลับมา:
 *  - ปุ่มอนุมัติไม่สลับไป BSC ก่อน → อนุมัติไปลงเชนอื่น แล้วสวอป revert
 *  - "ได้รับอย่างน้อย" คิดจาก slippage ของ backend ไม่ใช่ที่ผู้ใช้เลือก
 *  - ช่อง slippage รับ 99% / ช่องว่าง (= 0 → revert)
 *  - คำขอราคาเก่าที่ตอบช้าทับคำขอใหม่ → ส่งธุรกรรมด้วยจำนวน/เส้นทางที่ไม่ได้อยู่บนจอ
 *
 * โหมด TPIX Chain (TPIX DEX บนเชน 4289):
 *  - DEX ไม่พร้อม = หน้าเป็น BSC แบบเดิม ปุ่ม TPIX กดไม่ได้ (fail-closed)
 *  - สลับไป 4289 ก่อนอนุมัติ/สวอป · BSC ยังเป็น 56 ตรง ๆ
 *  - ราคาดันพูลเกิน 10% / ยอดไม่พอ / decimals ไม่ตรงบนเชน = ไม่ส่ง
 *  - ลิงก์ธุรกรรมชี้ explorer ของเชน TPIX ไม่ใช่ BscScan
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

// ── TPIX DEX ────────────────────────────────────────────────────────────────
// สถานะ DEX config — ค่าปริยายของทุกเทสต์คือ "ไม่พร้อม" (หน้าต้องเป็น BSC แบบเดิม)
const dexConfig = reactive({ loaded: false, ready: false });
let dexReadyOnLoad = false;
const loadDexConfig = vi.fn(async () => {
    dexConfig.loaded = true;
    dexConfig.ready = dexReadyOnLoad;
});

vi.mock('@/Config/dexContracts', async (importOriginal) => ({
    ...(await importOriginal()),
    TPIX_DEX: dexConfig,
    loadDexConfig: (...args) => loadDexConfig(...args),
    isDexConfigured: () => dexConfig.ready === true,
}));

const dexState = {
    isLoadingQuote: ref(false),
    isExecuting: ref(false),
    isApproving: ref(false),
    error: ref(null),
    txHash: ref(null),
    txStatus: ref(null),
};
const dexFns = {
    getTradeQuote: vi.fn(),
    needsApproval: vi.fn(),
    approveToken: vi.fn(),
    executeTradeSwap: vi.fn(),
    getBalance: vi.fn(),
    readTokenMeta: vi.fn(),
};

// toDexAddress ตัวจริงยังต้องใช้ (utils/swapNetwork สร้างรายการเหรียญด้วยมัน)
vi.mock('@/Composables/useTpixDex', async (importOriginal) => ({
    ...(await importOriginal()),
    useTpixDex: () => ({
        ...dexState,
        getTradeQuote: (...a) => dexFns.getTradeQuote(...a),
        needsApproval: (...a) => dexFns.needsApproval(...a),
        approveToken: (...a) => dexFns.approveToken(...a),
        executeTradeSwap: (...a) => dexFns.executeTradeSwap(...a),
        getBalance: (...a) => dexFns.getBalance(...a),
        readTokenMeta: (...a) => dexFns.readTokenMeta(...a),
    }),
}));

const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a) => axiosGet(...a), post: vi.fn() } }));

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

// ── พูลบน TPIX DEX (รูปของ /api/v1/dex/pairs) ──────────────────────────────
const ZERO = '0x0000000000000000000000000000000000000000';
const TPIX_USDT = '0x2222222222222222222222222222222222222222';
const TPIX_ABC = '0x3333333333333333333333333333333333333333';
/** decimals จริงบนเชน (readTokenMeta) */
const TPIX_DECIMALS = { [TPIX_USDT]: 6, [TPIX_ABC]: 18 };
const TPIX_PAIRS = [
    { symbol: 'TPIX-USDT', base_asset: 'TPIX', quote_asset: 'USDT', base_address: ZERO, quote_address: TPIX_USDT, base_decimals: 18, quote_decimals: 6, is_active: true, chain_id: 4289 },
    { symbol: 'ABC-TPIX', base_asset: 'ABC', quote_asset: 'TPIX', base_address: TPIX_ABC, quote_address: ZERO, base_decimals: 18, quote_decimals: 18, is_active: true, chain_id: 4289 },
    { symbol: 'OLD-TPIX', base_asset: 'OLD', quote_asset: 'TPIX', base_address: '0x4444444444444444444444444444444444444444', quote_address: ZERO, base_decimals: 18, quote_decimals: 18, is_active: false, chain_id: 4289 },
];

/** ราคาปลอมของพูล TPIX: 1 → 0.05 */
function tpixQuoteFor(amount, priceImpact = 0.4) {
    const netOutput = amount * 0.05;
    return {
        amountIn: amount,
        swapInput: amount,
        netOutput,
        amountOut: netOutput,
        exchangeRate: 0.05,
        feeRate: 0.3,
        feeAmount: amount * 0.003,
        feeModel: 'in_pool',
        priceImpact,
        slippage: 0.5,
        minimumReceived: netOutput * 0.995,
        path: ['0xwtpix', TPIX_USDT],
        amounts: [1000n, 50n],
        rawAmountOut: 50n,
        feeWei: 0n,
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

        // TPIX DEX: ปริยาย = ยังไม่พร้อม (เทสต์ BSC เดิมต้องเห็นหน้าแบบเดิมทุกอย่าง)
        dexConfig.loaded = false;
        dexConfig.ready = false;
        dexReadyOnLoad = false;
        loadDexConfig.mockClear();
        Object.values(dexState).forEach((r) => { r.value = r === dexState.error || r === dexState.txHash || r === dexState.txStatus ? null : false; });
        dexFns.getTradeQuote.mockReset().mockImplementation(async (_f, _t, amount) => tpixQuoteFor(amount));
        dexFns.needsApproval.mockReset().mockResolvedValue(false);
        dexFns.approveToken.mockReset().mockResolvedValue(true);
        dexFns.executeTradeSwap.mockReset().mockResolvedValue({ hash: '0xtpixswap', status: 'confirmed' });
        dexFns.getBalance.mockReset().mockResolvedValue('100');
        dexFns.readTokenMeta.mockReset().mockImplementation(async (address) => ({ decimals: TPIX_DECIMALS[address] }));
        axiosGet.mockReset().mockImplementation(async (url) => {
            if (url === '/api/v1/dex/pairs') return { data: { success: true, data: TPIX_PAIRS } };
            throw new Error(`unexpected GET ${url}`);
        });
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

    describe('TPIX Chain network', () => {
        /** เปิดหน้าโดยที่เซิร์ฟเวอร์ยืนยันว่า DEX พร้อม */
        async function mountTpix() {
            dexReadyOnLoad = true;
            const wrapper = await mountSwap();
            await flushPromises();
            return wrapper;
        }

        const fromSymbol = wrapper => wrapper.find('[data-test="from-token"]').text();
        const toSymbol = wrapper => wrapper.find('[data-test="to-token"]').text();
        const notice = wrapper => wrapper.find('[data-test="swap-action-notice"]');

        /** กระเป๋าจริงสลับเชนแล้ว chainId/isBSC ตามไป — บันทึกลำดับที่เกิดขึ้น */
        function trackSwitches(order) {
            wallet.switchChain.mockImplementation(async (id) => {
                order.push(`switch:${id}`);
                wallet.chainId = id;
                wallet.isBSC = id === 56;
            });
        }

        it('stays on BSC exactly as before while the DEX is not ready (fail-closed)', async () => {
            const wrapper = await mountSwap();

            expect(loadDexConfig).toHaveBeenCalled();
            expect(axiosGet).not.toHaveBeenCalledWith('/api/v1/dex/pairs');
            expect(wrapper.find('[data-test="network-tpix"]').attributes('disabled')).toBeDefined();
            expect(wrapper.find('[data-test="tpix-coming-soon"]').exists()).toBe(true);
            expect(fromSymbol(wrapper)).toContain('BNB');

            await typeAmount(wrapper, '1');
            expect(getQuote).toHaveBeenCalled();
            expect(dexFns.getTradeQuote).not.toHaveBeenCalled();
        });

        it('opens on TPIX Chain (TPIX → USDT) built from the live DEX pairs', async () => {
            const wrapper = await mountTpix();

            expect(wrapper.find('[data-test="network-tpix"]').attributes('aria-pressed')).toBe('true');
            expect(wrapper.find('[data-test="tpix-coming-soon"]').exists()).toBe(false);
            expect(fromSymbol(wrapper)).toContain('TPIX');
            expect(toSymbol(wrapper)).toContain('USDT');

            await wrapper.find('[data-test="from-token"]').trigger('click');
            const list = wrapper.find('[data-test="token-list"]').text();
            expect(list).toContain('ABC');
            expect(list).not.toContain('OLD');   // คู่ที่ปิดอยู่ไม่ขึ้นรายการ
            expect(list).not.toContain('BNB');
        });

        it('quotes through the TPIX DEX with the decimals from the pair data', async () => {
            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');

            expect(getQuote).not.toHaveBeenCalled();
            expect(dexFns.getTradeQuote).toHaveBeenCalledTimes(1);
            const [from, to, amount] = dexFns.getTradeQuote.mock.calls[0];
            expect(from).toMatchObject({ address: 'native', decimals: 18 });
            expect(to).toMatchObject({ address: TPIX_USDT, decimals: 6 });
            expect(amount).toBe(10);

            expect(wrapper.text()).toContain('0.500000');
            expect(wrapper.text()).toContain('Pool fee (included in price)');
            // 0.5 × (1 − 0.5%)
            expect(wrapper.find('[data-test="min-received"]').text()).toContain('0.497500');
        });

        it('switches to TPIX Chain (4289) before swapping and links the TPIX explorer', async () => {
            const order = [];
            trackSwitches(order);
            const hash = `0x${'ab'.repeat(32)}`;
            dexFns.executeTradeSwap.mockImplementation(async () => {
                order.push('swap');
                dexState.txHash.value = hash;
                dexState.txStatus.value = 'confirmed';
                return { hash, status: 'confirmed' };
            });

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');
            await swapButton(wrapper).trigger('click');
            await flushPromises();

            expect(order).toEqual(['switch:4289', 'swap']);
            expect(executeSwap).not.toHaveBeenCalled();

            const [from, to, amount, quote, slippage] = dexFns.executeTradeSwap.mock.calls[0];
            expect(from.address).toBe('native');
            expect(to.address).toBe(TPIX_USDT);
            expect(amount).toBe(10);
            expect(quote.netOutput).toBe(0.5);
            expect(quote.amounts).toEqual([1000n, 50n]);   // minOut ผูกกับราคาบนจอ
            expect(slippage).toBe(0.5);

            expect(wrapper.find('[data-test="swap-tx-link"]').attributes('href')).toBe(`https://explorer.tpix.online/tx/${hash}`);
            expect(wrapper.text()).toContain('View on TPIX Explorer');
        });

        it('does not swap when the switch to TPIX Chain is cancelled', async () => {
            wallet.switchChain.mockRejectedValue({ code: 4001 });

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');
            await swapButton(wrapper).trigger('click');
            await flushPromises();

            expect(wallet.switchChain).toHaveBeenCalledWith(4289);
            expect(dexFns.executeTradeSwap).not.toHaveBeenCalled();
            expect(notice(wrapper).text()).toContain('this swap runs on TPIX Chain');
        });

        it('warns about the wrong network and switches to 4289 (not 56) from the banner', async () => {
            const order = [];
            trackSwitches(order);

            const wrapper = await mountTpix();
            expect(wrapper.find('[data-test="network-warning"]').text()).toContain('Please switch to TPIX Chain.');

            await wrapper.find('[data-test="network-warning"] button').trigger('click');
            await flushPromises();

            expect(order).toEqual(['switch:4289']);
            expect(wrapper.find('[data-test="network-warning"]').exists()).toBe(false);
        });

        it('blocks a swap that moves the pool price by more than 10%', async () => {
            wallet.chainId = 4289;
            dexFns.getTradeQuote.mockImplementation(async (_f, _t, amount) => tpixQuoteFor(amount, 12.5));

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');

            expect(swapButton(wrapper).attributes('disabled')).toBeDefined();
            expect(swapButton(wrapper).text()).toBe('Price impact too high');
            expect(wrapper.find('[data-test="price-impact-blocked"]').text()).toContain('12.50%');

            await swapButton(wrapper).trigger('click');
            await flushPromises();
            expect(dexFns.executeTradeSwap).not.toHaveBeenCalled();
        });

        it('treats an unknown price impact as blocked', async () => {
            dexFns.getTradeQuote.mockImplementation(async (_f, _t, amount) => tpixQuoteFor(amount, null));

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');

            expect(wrapper.find('[data-test="price-impact"]').text()).toBe('—%');
            expect(wrapper.find('[data-test="price-impact-blocked"]').text()).toContain("Couldn't check the price impact");
            expect(swapButton(wrapper).attributes('disabled')).toBeDefined();
        });

        it('refuses an amount above the on-chain balance', async () => {
            dexFns.getBalance.mockResolvedValue('3');

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');

            expect(swapButton(wrapper).text()).toBe('Insufficient TPIX balance');
            expect(swapButton(wrapper).attributes('disabled')).toBeDefined();
            await swapButton(wrapper).trigger('click');
            await flushPromises();
            expect(dexFns.executeTradeSwap).not.toHaveBeenCalled();
        });

        it('approves exactly the swap amount after switching to 4289', async () => {
            const order = [];
            trackSwitches(order);
            dexFns.needsApproval.mockResolvedValue(true);
            dexFns.approveToken.mockImplementation(async () => { order.push('approve'); return true; });

            const wrapper = await mountTpix();
            await wrapper.find('[data-test="flip-tokens"]').trigger('click');   // USDT → TPIX
            expect(fromSymbol(wrapper)).toContain('USDT');
            await typeAmount(wrapper, '2');

            await wrapper.find('[data-test="approve-button"]').trigger('click');
            await flushPromises();

            expect(order).toEqual(['switch:4289', 'approve']);
            const [address, amount, decimals] = dexFns.approveToken.mock.calls[0];
            expect(address).toBe(TPIX_USDT);
            expect(Number(amount)).toBe(2);
            expect(decimals).toBe(6);
            expect(wrapper.find('[data-test="approve-button"]').exists()).toBe(false);
            expect(swapButton(wrapper).exists()).toBe(true);
        });

        it('shows the localized wallet message when the approval is rejected', async () => {
            wallet.chainId = 4289;
            dexFns.needsApproval.mockResolvedValue(true);
            dexFns.approveToken.mockImplementation(async () => {
                dexState.error.value = 'Transaction rejected in your wallet.';
                return false;
            });

            const wrapper = await mountTpix();
            await wrapper.find('[data-test="flip-tokens"]').trigger('click');
            await typeAmount(wrapper, '2');
            await wrapper.find('[data-test="approve-button"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-test="swap-error"]').text()).toBe('Transaction rejected in your wallet.');
            expect(wrapper.find('[data-test="approve-button"]').exists()).toBe(true);
            expect(dexFns.executeTradeSwap).not.toHaveBeenCalled();
        });

        it('refuses a token whose decimals do not match the chain', async () => {
            dexFns.readTokenMeta.mockResolvedValue({ decimals: 18 });   // รายการบอก USDT = 6

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');

            expect(dexFns.getTradeQuote).not.toHaveBeenCalled();
            expect(notice(wrapper).text()).toContain("don't match TPIX Chain");
            expect(swapButton(wrapper).attributes('disabled')).toBeDefined();
        });

        it('drops a TPIX quote that returns after the user switched to BSC', async () => {
            let resolveQuote;
            dexFns.getTradeQuote.mockImplementation((_f, _t, amount) => new Promise((resolve) => {
                resolveQuote = () => resolve(tpixQuoteFor(amount));
            }));

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');   // ค้างอยู่
            await wrapper.find('[data-test="network-bsc"]').trigger('click');
            await flushPromises();

            resolveQuote();
            await flushPromises();

            expect(fromSymbol(wrapper)).toContain('BNB');
            expect(amountInput(wrapper).element.value).toBe('');
            expect(wrapper.text()).not.toContain('0.500000');
            expect(wrapper.find('[data-test="min-received"]').exists()).toBe(false);
        });

        it('fires one transaction on a double click and locks the network switch meanwhile', async () => {
            wallet.chainId = 4289;
            let finish;
            dexFns.executeTradeSwap.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');
            await swapButton(wrapper).trigger('click');
            await swapButton(wrapper).trigger('click');
            await flushPromises();

            expect(dexFns.executeTradeSwap).toHaveBeenCalledTimes(1);
            expect(wrapper.find('[data-test="network-bsc"]').attributes('disabled')).toBeDefined();

            finish({ hash: '0x1', status: 'confirmed' });
            await flushPromises();
            expect(wrapper.find('[data-test="network-bsc"]').attributes('disabled')).toBeUndefined();
        });

        it('keeps the identity verification gate on TPIX Chain', async () => {
            inertiaPage.props.kyc = { features: { trading: { required: true, passed: false } } };

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');

            expect(swapButton(wrapper).exists()).toBe(false);
            await wrapper.find('[data-test="kyc-button"]').trigger('click');
            expect(routerVisit).toHaveBeenCalledWith('/kyc');
            expect(dexFns.executeTradeSwap).not.toHaveBeenCalled();
        });

        it('quotes without a wallet but asks to connect instead of swapping', async () => {
            wallet.isConnected = false;

            const wrapper = await mountTpix();
            await typeAmount(wrapper, '10');

            expect(dexFns.getTradeQuote).toHaveBeenCalled();
            expect(dexFns.getBalance).not.toHaveBeenCalled();
            expect(swapButton(wrapper).exists()).toBe(false);
            expect(dexFns.executeTradeSwap).not.toHaveBeenCalled();
        });

        it('shows an empty state with retry when the DEX has no open pools', async () => {
            axiosGet.mockImplementation(async () => ({ data: { success: true, data: [] } }));

            const wrapper = await mountTpix();
            // พร้อมแต่ไม่มีอะไรให้สวอป → ไม่พาไปหน้าว่างเอง
            expect(fromSymbol(wrapper)).toContain('BNB');

            await wrapper.find('[data-test="network-tpix"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-test="tpix-empty"]').text()).toContain('No TPIX DEX pools');
            expect(swapButton(wrapper).exists()).toBe(false);

            // พูลเปิดภายหลัง → ลองใหม่แล้วฟอร์มกลับมาพร้อมคู่ TPIX → USDT
            axiosGet.mockImplementation(async () => ({ data: { success: true, data: TPIX_PAIRS } }));
            await wrapper.find('[data-test="tpix-retry"]').trigger('click');
            await flushPromises();
            expect(fromSymbol(wrapper)).toContain('TPIX');
            expect(toSymbol(wrapper)).toContain('USDT');
        });

        it('keeps BSC bound to chain 56 when the user picks BSC', async () => {
            wallet.chainId = 4289;
            wallet.isBSC = false;
            const order = [];
            trackSwitches(order);

            const wrapper = await mountTpix();
            await wrapper.find('[data-test="network-bsc"]').trigger('click');
            await flushPromises();
            expect(fromSymbol(wrapper)).toContain('BNB');

            await typeAmount(wrapper, '1');
            await swapButton(wrapper).trigger('click');
            await flushPromises();

            expect(order).toEqual(['switch:56']);
            expect(executeSwap).toHaveBeenCalledTimes(1);
            expect(dexFns.executeTradeSwap).not.toHaveBeenCalled();
        });
    });
});
