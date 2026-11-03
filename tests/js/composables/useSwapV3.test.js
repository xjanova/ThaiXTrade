/**
 * TPIX TRADE — useSwap: เลือกเส้นทาง PancakeSwap V2/V3 และส่งไม้ผ่าน V3 SmartRouter (โหนด/สัญญาปลอม)
 *
 * ทำไม (2026-11-03): สภาพคล่องย้ายไป V3 — บน V2 ไม้ $100 ของ PEPE ห่าง Binance 48% (V3 0.5%)
 * เทสต์ชุดนี้ล็อกว่า:
 *  - ใช้ทางที่ได้มากสุดจริง (เท่ากัน = V2) · พูล V3 ที่รับยอดไม่หมดไม่ถูกนับ · V3 ล่มยังใช้ V2 ได้
 *  - ไม้ V3 ส่งเข้า SmartRouter ด้วย minOut/deadline ชุดเดียวกับ V2 · BNB ขาออกถอนผ่าน unwrapWETH9
 *    BNB ขาเข้าคืนเศษด้วย refundETH · approve/allowance ผูกกับ router ของเส้นทาง
 *  - ด่านเดิมยังอยู่ครบบนทาง V3 (ค่าธรรมเนียมจาก signer เดิม · บันทึกไม้ · minOutFloor)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const wallet = { address: null, signer: null, chainId: null };
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const get = vi.fn();
const post = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a) => get(...a), post: (...a) => post(...a) } }));

/** สัญญาปลอมตามที่อยู่ (พิมพ์เล็ก) · ทุกการเรียกถูกจดพร้อม runner (signer/provider) ที่ใช้ */
const contracts = {};
const calls = [];
/** พูล V3 ปลอม: `${in}|${out}|${fee}` (พิมพ์เล็ก) → (amountIn) => amountOut | 'partial' */
const v3 = { pools: {}, down: false, quoterCalls: 0, nativeBalance: 0n };
vi.mock('ethers', async (importOriginal) => {
    const actual = await importOriginal();
    class FakeContract {
        constructor(address, _abi, runner) {
            const key = String(address).toLowerCase();
            return new Proxy({}, {
                get: (_t, name) => {
                    if (name === 'then') return undefined;
                    return async (...args) => {
                        calls.push({ address: key, name, args, runner });
                        const impl = contracts[key]?.[name];
                        if (!impl) throw new Error(`unexpected call ${String(name)} on ${key}`);
                        return impl(...args);
                    };
                },
            });
        }
    }
    class FakeProvider {
        async getBalance() { return v3.nativeBalance; }
        async call(tx) {
            if (v3.down) throw Object.assign(new Error('down'), { code: 'NETWORK_ERROR' });
            return fakeV3Call(tx);
        }
    }
    return { ...actual, Contract: FakeContract, JsonRpcProvider: FakeProvider };
});

const { id, Interface } = await import('ethers');
const {
    useSwap, pickBestRoute, receivedFromReceipt, PENDING_RECORDS_KEY, _resetFeeCollectorCacheForTests,
} = await import('@/Composables/useSwap');
const { PANCAKE_ROUTER_ADDRESS, WBNB_ADDRESS, NATIVE_TOKEN_ADDRESS } = await import('@/utils/web3');
const {
    PANCAKE_V3_SMART_ROUTER_ADDRESS, PANCAKE_V3_QUOTER_ADDRESS, MULTICALL3_ADDRESS, V3_ADDRESS_THIS,
    PANCAKE_V3_QUOTER_ABI, PANCAKE_V3_SMART_ROUTER_ABI, encodeV3Path, bestV3Route,
} = await import('@/utils/pancakeV3');

const quoterIface = new Interface(PANCAKE_V3_QUOTER_ABI);
const routerIface = new Interface(PANCAKE_V3_SMART_ROUTER_ABI);
const multicallIface = new Interface(['function aggregate3((address target, bool allowFailure, bytes callData)[] calls) payable returns ((bool success, bytes returnData)[] returnData)']);

const V2 = PANCAKE_ROUTER_ADDRESS.toLowerCase();
const SR = PANCAKE_V3_SMART_ROUTER_ADDRESS.toLowerCase();
const WBNB = WBNB_ADDRESS.toLowerCase();
const OWNER = `0x${'a'.repeat(40)}`;
const COLLECTOR = `0x${'c'.repeat(40)}`;
const USDT = { symbol: 'USDT', address: '0x55d398326f99059fF775485246999027B3197955', decimals: 18 };
const PEPE = { symbol: 'PEPE', address: '0x25d887Ce7a35172C62FeBFD67a1856F20FaEbB00', decimals: 18 };
const BNB = { symbol: 'BNB', address: NATIVE_TOKEN_ADDRESS, decimals: 18, native: true };
const SWAP_HASH = `0x${'1'.repeat(64)}`;
const FEE_HASH = `0x${'2'.repeat(64)}`;
const E18 = 10n ** 18n;
const NORMAL_SQRT = 2n ** 96n;
const LIMIT_SQRT = 4295128739n + 1n;   // ราคาวิ่งชนขอบ = พูลรับยอดไม่หมด

const lower = s => String(s).toLowerCase();
const topicFor = address => `0x${'0'.repeat(24)}${address.slice(2).toLowerCase()}`;
const pool = (a, b, fee, fn) => { v3.pools[`${lower(a)}|${lower(b)}|${fee}`] = fn; };

/** ราคาของพูลเดี่ยว → [amountOut, sqrtAfter] หรือ null (ไม่มีพูล) */
function quoteSingle(tokenIn, tokenOut, fee, amountIn) {
    const fn = v3.pools[`${lower(tokenIn)}|${lower(tokenOut)}|${Number(fee)}`];
    if (!fn) return null;
    const out = fn(amountIn);
    return out === 'partial' ? [amountIn / 2n, LIMIT_SQRT] : [out, NORMAL_SQRT];
}

/** ถอด packed path: token(20) fee(3) token(20) … */
function decodePath(hex) {
    const h = hex.slice(2);
    const tokens = [];
    const fees = [];
    for (let i = 0; ; ) {
        tokens.push(`0x${h.slice(i, i + 40)}`);
        i += 40;
        if (i >= h.length) break;
        fees.push(parseInt(h.slice(i, i + 6), 16));
        i += 6;
    }
    return { tokens, fees };
}

/** eth_call ปลอมของ Multicall3(QuoterV2.quoteExactInputSingle …) และ QuoterV2.quoteExactInput */
function fakeV3Call({ to, data }) {
    if (lower(to) === lower(MULTICALL3_ADDRESS)) {
        const [inner] = multicallIface.decodeFunctionData('aggregate3', data);
        const results = inner.map(({ callData }) => {
            v3.quoterCalls += 1;
            const [p] = quoterIface.decodeFunctionData('quoteExactInputSingle', callData);
            const q = quoteSingle(p.tokenIn, p.tokenOut, p.fee, p.amountIn);
            return q
                ? [true, quoterIface.encodeFunctionResult('quoteExactInputSingle', [q[0], q[1], 1, 100000])]
                : [false, '0x'];
        });
        return multicallIface.encodeFunctionResult('aggregate3', [results]);
    }
    if (lower(to) === lower(PANCAKE_V3_QUOTER_ADDRESS)) {
        const [path, amountIn] = quoterIface.decodeFunctionData('quoteExactInput', data);
        const { tokens, fees } = decodePath(path);
        let amount = amountIn;
        const sqrts = [];
        for (let i = 0; i < fees.length; i++) {
            const q = quoteSingle(tokens[i], tokens[i + 1], fees[i], amount);
            if (!q) throw Object.assign(new Error('revert'), { code: 'CALL_EXCEPTION' });
            [amount] = q;
            sqrts.push(q[1]);
        }
        return quoterIface.encodeFunctionResult('quoteExactInput', [amount, sqrts, fees.map(() => 1), 100000]);
    }
    throw new Error(`unexpected eth_call to ${to}`);
}

function makeSigner(address) {
    return {
        getAddress: vi.fn(async () => address),
        sendTransaction: vi.fn(async () => ({ hash: FEE_HASH, wait: async () => ({ status: 1 }) })),
    };
}

function mockBackend() {
    get.mockImplementation(async (url) => {
        if (url === '/api/v1/swap/quote') return { data: { success: true, data: { quote: { fee_rate: 0.3, slippage: 0.5 } } } };
        if (url === '/api/v1/trading/fee-info') return { data: { data: { fee_collector: COLLECTOR } } };
        throw new Error(`unexpected GET ${url}`);
    });
}

/** V2 thin (ได้ 1/2 ของ V3) — ราคา PEPE ต่อ USDT: V3 = 200,000 · V2 = 100,000 */
function thinV2ThickV3() {
    contracts[V2] = { getAmountsOut: async (amountIn, path) => [amountIn, path.length === 2 ? amountIn * 100000n : amountIn * 90000n] };
    pool(USDT.address, PEPE.address, 2500, a => a * 200000n);
    pool(USDT.address, PEPE.address, 10000, a => a * 150000n);
}

describe('pancakeV3 helpers', () => {
    it('packs a V3 path as token · fee(uint24) · token', () => {
        const hex = a => a.slice(2).toLowerCase();
        expect(encodeV3Path([USDT.address, WBNB_ADDRESS, PEPE.address], [100, 2500]).toLowerCase())
            .toBe(`0x${hex(USDT.address)}000064${hex(WBNB_ADDRESS)}0009c4${hex(PEPE.address)}`);
        expect(() => encodeV3Path([USDT.address, PEPE.address], [3000])).toThrow();   // ไม่ใช่ระดับของ PancakeSwap
        expect(() => encodeV3Path([USDT.address, PEPE.address], [])).toThrow();
    });

    it('picks the best fee tier for each leg of the WBNB route and the better of direct vs WBNB', async () => {
        v3.pools = {};
        pool(USDT.address, PEPE.address, 10000, a => a * 100n);          // ตรง: 100/หน่วย
        pool(USDT.address, WBNB, 100, a => a / 600n);                    // ขาแรกดีสุดที่ 0.01%
        pool(USDT.address, WBNB, 2500, a => a / 700n);
        pool(WBNB, PEPE.address, 500, a => a * 60000n);
        pool(WBNB, PEPE.address, 2500, a => a * 66000n);                 // ขาสองดีสุดที่ 0.25%
        const provider = { call: async tx => fakeV3Call(tx) };

        const r = await bestV3Route(provider, 600n * E18, USDT.address, PEPE.address, WBNB_ADDRESS);

        expect(r.tokens.map(lower)).toEqual([lower(USDT.address), WBNB, lower(PEPE.address)]);
        expect(r.fees).toEqual([100, 2500]);
        expect(r.amountOut).toBe(E18 * 66000n);
    });

    it('ignores a pool that cannot absorb the whole trade', async () => {
        v3.pools = {};
        pool(USDT.address, PEPE.address, 100, () => 'partial');          // "ได้มาก" แต่รับยอดไม่หมด
        pool(USDT.address, PEPE.address, 2500, a => a * 10n);
        const provider = { call: async tx => fakeV3Call(tx) };

        const r = await bestV3Route(provider, 100n * E18, USDT.address, PEPE.address, WBNB_ADDRESS);
        expect(r.fees).toEqual([2500]);
    });

    it('pickBestRoute takes the larger output and V2 on a tie', () => {
        const ok = amountOut => ({ status: 'fulfilled', value: { amountOut } });
        expect(pickBestRoute([ok(10n), ok(9n), ok(11n)])).toBe(2);
        expect(pickBestRoute([ok(10n), ok(9n), ok(10n)])).toBe(0);
        expect(pickBestRoute([{ status: 'rejected' }, { status: 'fulfilled', value: null }, ok(0n)])).toBe(-1);
    });

    it('reads BNB received from the router the swap went through', () => {
        const receipt = {
            logs: [{
                address: WBNB_ADDRESS,
                topics: [id('Withdrawal(address,uint256)'), topicFor(SR)],
                data: `0x${(5n * E18).toString(16).padStart(64, '0')}`,
            }],
        };
        expect(receivedFromReceipt(receipt, BNB, OWNER, PANCAKE_V3_SMART_ROUTER_ADDRESS)).toBe(5n * E18);
        expect(receivedFromReceipt(receipt, BNB, OWNER)).toBeNull();   // ไม่ใช่ V2 router
    });
});

describe('useSwap with PancakeSwap V3', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset().mockResolvedValue({ data: { success: true } });
        mockBackend();
        _resetFeeCollectorCacheForTests();
        calls.length = 0;
        Object.keys(contracts).forEach(k => delete contracts[k]);
        v3.pools = {};
        v3.down = false;
        v3.quoterCalls = 0;
        v3.nativeBalance = 0n;
        localStorage.removeItem(PENDING_RECORDS_KEY);

        wallet.address = OWNER;
        wallet.signer = makeSigner(OWNER);
        wallet.chainId = 56;
    });

    afterEach(() => vi.useRealTimers());

    describe('getQuote', () => {
        it('routes through V3 when it pays more than V2', async () => {
            thinV2ThickV3();
            const q = await useSwap().getQuote(USDT, PEPE, '100');

            expect(q.protocol).toBe('v3');
            expect(q.router).toBe(PANCAKE_V3_SMART_ROUTER_ADDRESS);
            expect(q.fees).toEqual([2500]);
            expect(q.route).toBe('direct');
            expect(q.rawAmountOut).toBe((997n * E18 / 10n) * 200000n);
            // ทุกระดับค่าธรรมเนียม (ตรง 4 + ขาแรกผ่าน WBNB 4) ในคำขอเดียว
            expect(v3.quoterCalls).toBe(8);
        });

        it('stays on V2 when V2 pays more — and on a tie', async () => {
            contracts[V2] = { getAmountsOut: async amountIn => [amountIn, amountIn * 200000n] };
            pool(USDT.address, PEPE.address, 2500, a => a * 200000n);

            const q = await useSwap().getQuote(USDT, PEPE, '100');
            expect(q.protocol).toBe('v2');
            expect(q.router).toBe(PANCAKE_ROUTER_ADDRESS);
            expect(q.fees).toBeNull();
        });

        it('still quotes on V2 when the V3 quoter is unreachable', async () => {
            contracts[V2] = { getAmountsOut: async amountIn => [amountIn, amountIn * 100000n] };
            v3.down = true;

            const q = await useSwap().getQuote(USDT, PEPE, '100');
            expect(q.protocol).toBe('v2');
        });

        it('quotes a pair with no V2 pool at all through V3', async () => {
            contracts[V2] = { getAmountsOut: async () => { throw Object.assign(new Error('revert'), { code: 'CALL_EXCEPTION' }); } };
            pool(USDT.address, PEPE.address, 2500, a => a * 200000n);

            const q = await useSwap().getQuote(USDT, PEPE, '100');
            expect(q.protocol).toBe('v3');
        });

        it('measures V3 price impact against a tiny trade on the same pools', async () => {
            contracts[V2] = { getAmountsOut: async amountIn => [amountIn, 1n] };
            // ไม้จิ๋วได้ 200,000/หน่วย · ไม้จริงได้ 180,000/หน่วย → ดันราคา 10%
            pool(USDT.address, PEPE.address, 2500, a => (a > E18 ? a * 180000n : a * 200000n));

            const q = await useSwap().getQuote(USDT, PEPE, '100');
            expect(q.protocol).toBe('v3');
            expect(q.priceImpact).toBe(10);
        });

        it('says "no liquidity" when neither V2 nor V3 has a pool', async () => {
            contracts[V2] = { getAmountsOut: async () => { throw Object.assign(new Error('revert'), { code: 'CALL_EXCEPTION' }); } };
            const swap = useSwap();

            expect(await swap.getQuote(USDT, PEPE, '100')).toBeNull();
            expect(swap.error.value).toBe('Not enough liquidity for this pair on PancakeSwap.');
        });
    });

    describe('executeSwap through the V3 SmartRouter', () => {
        /** สวอป USDT → PEPE ผ่าน V3 · allowance ปริยาย = ไม่จำกัดให้ SmartRouter */
        function setupV3({ allowance = { [SR]: 10n ** 30n }, received = 19940000n * E18 } = {}) {
            thinV2ThickV3();
            contracts[lower(USDT.address)] = {
                balanceOf: async () => 100n * E18,
                allowance: async (_owner, spender) => allowance[lower(spender)] ?? 0n,
                approve: async () => ({ hash: '0xapprove', wait: async () => ({ status: 1 }) }),
                transfer: async () => ({ hash: FEE_HASH, wait: async () => ({ status: 1 }) }),
            };
            contracts[SR] = {
                multicall: async () => ({
                    hash: SWAP_HASH,
                    wait: async () => ({
                        status: 1,
                        logs: [{
                            address: PEPE.address,
                            topics: [id('Transfer(address,address,uint256)'), topicFor(`0x${'9'.repeat(40)}`), topicFor(OWNER)],
                            data: `0x${received.toString(16).padStart(64, '0')}`,
                        }],
                    }),
                }),
            };
        }

        const multicallCall = () => calls.find(c => c.address === SR && c.name === 'multicall');
        const decodeSteps = steps => steps.map(data => routerIface.parseTransaction({ data }));

        it('sends exactInput to the SmartRouter with the same minOut and deadline rules as V2', async () => {
            setupV3();
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, PEPE, '100');
            const cap = Math.floor(Date.now() / 1000) + 600;

            const result = await swap.executeSwap(USDT, PEPE, '100', quote, 0.5, { deadlineCap: cap });

            const call = multicallCall();
            expect(call.runner).toBe(wallet.signer);
            const [deadline, steps, overrides] = call.args;
            expect(Number(deadline)).toBe(cap);
            expect(overrides).toEqual({});
            const [step] = decodeSteps(steps);
            expect(steps).toHaveLength(1);
            expect(step.name).toBe('exactInput');
            const p = step.args[0];
            expect(p.path).toBe(encodeV3Path(quote.path, [2500]));
            expect(lower(p.recipient)).toBe(OWNER);
            expect(p.amountIn).toBe(997n * E18 / 10n);
            expect(p.amountOutMinimum).toBe(quote.minOutWei);
            // ไม่มีการเรียก V2 router ตอนส่งไม้
            expect(calls.some(c => c.address === V2 && c.name.startsWith('swap'))).toBe(false);

            // ด่านเดิม: ค่าธรรมเนียมจาก signer เดียวกัน + บันทึกไม้ที่เซิร์ฟเวอร์อ่านจากเชน
            const fee = calls.find(c => c.name === 'transfer');
            expect(fee.args).toEqual([COLLECTOR, 3n * E18 / 10n]);
            expect(result.amountOut).toBe(19940000);
            expect(post).toHaveBeenCalledWith('/api/v1/swap/execute', expect.objectContaining({
                tx_hash: SWAP_HASH, fee_tx_hash: FEE_HASH, fee_collected: true,
            }));
        });

        it('never lets the V3 minOut fall below what the user saw on screen', async () => {
            setupV3();
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, PEPE, '100');
            const floor = quote.minOutWei + 1000n;

            await swap.executeSwap(USDT, PEPE, '100', quote, 0.5, { minOutFloor: floor });

            const [step] = decodeSteps(multicallCall().args[1]);
            expect(step.args[0].amountOutMinimum).toBe(floor);
        });

        it('unwraps WBNB to the wallet when the trade pays out BNB', async () => {
            contracts[V2] = { getAmountsOut: async amountIn => [amountIn, 1n] };
            pool(USDT.address, WBNB, 100, a => a / 600n);
            contracts[lower(USDT.address)] = {
                balanceOf: async () => 600n * E18,
                allowance: async () => 10n ** 30n,
                transfer: async () => ({ hash: FEE_HASH, wait: async () => ({ status: 1 }) }),
            };
            contracts[SR] = {
                multicall: async () => ({
                    hash: SWAP_HASH,
                    wait: async () => ({
                        status: 1,
                        logs: [{
                            address: WBNB_ADDRESS,
                            topics: [id('Withdrawal(address,uint256)'), topicFor(SR)],
                            data: `0x${(99n * E18 / 100n).toString(16).padStart(64, '0')}`,
                        }],
                    }),
                }),
            };
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BNB, '600');

            const result = await swap.executeSwap(USDT, BNB, '600', quote, 0.5);

            const steps = decodeSteps(multicallCall().args[1]);
            expect(steps.map(s => s.name)).toEqual(['exactInput', 'unwrapWETH9']);
            // router ถือ WBNB ไว้ก่อน แล้วถอนเป็น BNB ไม่ต่ำกว่า minOut ให้กระเป๋า
            expect(lower(steps[0].args[0].recipient)).toBe(V3_ADDRESS_THIS);
            expect(steps[1].args[0]).toBe(steps[0].args[0].amountOutMinimum);
            expect(lower(steps[1].args[1])).toBe(OWNER);
            expect(result.amountOut).toBe(0.99);
        });

        it('pays BNB as value and refunds what the pool did not use', async () => {
            contracts[V2] = { getAmountsOut: async amountIn => [amountIn, 1n] };
            pool(WBNB, PEPE.address, 2500, a => a * 120000000n);
            v3.nativeBalance = 10n * E18;
            contracts[SR] = {
                multicall: async () => ({
                    hash: SWAP_HASH,
                    wait: async () => ({
                        status: 1,
                        logs: [{
                            address: PEPE.address,
                            topics: [id('Transfer(address,address,uint256)'), topicFor(`0x${'9'.repeat(40)}`), topicFor(OWNER)],
                            data: `0x${(E18 * 100n).toString(16).padStart(64, '0')}`,
                        }],
                    }),
                }),
            };
            const swap = useSwap();
            const quote = await swap.getQuote(BNB, PEPE, '1');

            await swap.executeSwap(BNB, PEPE, '1', quote, 0.5);

            const [, steps, overrides] = multicallCall().args;
            expect(decodeSteps(steps).map(s => s.name)).toEqual(['exactInput', 'refundETH']);
            expect(overrides.value).toBe(quote.amountInSwapWei);
            expect(lower(decodeSteps(steps)[0].args[0].path.slice(0, 42))).toBe(WBNB);
        });

        it('refuses to send when the SmartRouter has no allowance (approval was for V2)', async () => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
            setupV3({ allowance: { [V2]: 10n ** 30n } });
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, PEPE, '100');

            const sending = swap.executeSwap(USDT, PEPE, '100', quote, 0.5);
            const settled = expect(sending).rejects.toMatchObject({ isFriendly: true });
            await vi.advanceTimersByTimeAsync(5000);
            await settled;

            expect(swap.error.value).toBe('Approve this token for the PancakeSwap route first, then try again.');
            expect(multicallCall()).toBeUndefined();
        });

        it('waits for a lagging node to see a fresh approval before giving up', async () => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
            setupV3();
            let reads = 0;
            contracts[lower(USDT.address)].allowance = async () => (++reads >= 2 ? 10n ** 30n : 0n);
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, PEPE, '100');

            const sending = swap.executeSwap(USDT, PEPE, '100', quote, 0.5);
            await vi.advanceTimersByTimeAsync(2000);
            await sending;

            expect(multicallCall()).toBeDefined();
        });

        it('refuses a quote whose route does not match the trade or the router', async () => {
            setupV3();
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, PEPE, '100');

            await expect(swap.executeSwap(USDT, PEPE, '100', { ...quote, router: `0x${'e'.repeat(40)}` }, 0.5))
                .rejects.toMatchObject({ isFriendly: true });
            await expect(swap.executeSwap(USDT, PEPE, '100', { ...quote, path: [USDT.address, WBNB_ADDRESS] }, 0.5))
                .rejects.toMatchObject({ isFriendly: true });
            await expect(swap.executeSwap(USDT, PEPE, '100', { ...quote, fees: [3000] }, 0.5))
                .rejects.toMatchObject({ isFriendly: true });
            // ผ่านเหรียญกลางอื่นที่ไม่ใช่ WBNB (getQuote ไม่เคยสร้างเส้นทางแบบนี้)
            await expect(swap.executeSwap(USDT, PEPE, '100', { ...quote, path: [USDT.address, `0x${'d'.repeat(40)}`, PEPE.address], fees: [100, 2500] }, 0.5))
                .rejects.toMatchObject({ isFriendly: true });
            expect(swap.error.value).toBe('Price quote unavailable — please re-enter the amount.');
            expect(multicallCall()).toBeUndefined();
        });
    });

    describe('approvals follow the route', () => {
        it('checks and approves the SmartRouter for a V3 quote', async () => {
            contracts[lower(USDT.address)] = {
                allowance: async (_o, spender) => (lower(spender) === V2 ? 10n ** 30n : 0n),
                approve: async () => ({ hash: '0xapprove', wait: async () => ({ status: 1 }) }),
            };
            const swap = useSwap();

            expect(await swap.checkAllowance(USDT.address, '100', 18)).toBe(true);
            expect(await swap.checkAllowance(USDT.address, '100', 18, PANCAKE_V3_SMART_ROUTER_ADDRESS)).toBe(false);

            await swap.approveToken(USDT.address, PANCAKE_V3_SMART_ROUTER_ADDRESS);
            expect(lower(calls.find(c => c.name === 'approve').args[0])).toBe(SR);
        });

        it('never approves an address that is not one of our routers', async () => {
            contracts[lower(USDT.address)] = { approve: async () => ({ hash: '0xapprove', wait: async () => ({ status: 1 }) }) };
            const swap = useSwap();

            await expect(swap.approveToken(USDT.address, `0x${'e'.repeat(40)}`)).rejects.toMatchObject({ isFriendly: true });
            expect(calls.some(c => c.name === 'approve')).toBe(false);
            expect(await swap.checkAllowance(USDT.address, '1', 18, `0x${'e'.repeat(40)}`)).toBe(false);
        });
    });
});
