/**
 * TPIX TRADE — useSwap บน BSC (สัญญา/โหนดเป็นของปลอม)
 *
 * บั๊กที่เทสต์ชุดนี้กันไม่ให้กลับมา (ออดิท 2026-10-21):
 *  - ขั้นโอนค่าธรรมเนียมใช้ signer ปัจจุบันของ store → สลับบัญชีระหว่างรอ = เก็บจากอีกบัญชี
 *  - /swap/quote ล้มแล้วเดาอัตรา 0.3% เอง (แอดมินปิดคู่ก็ยังสวอปได้)
 *  - จำนวนผ่าน float → ยอดหลักพันล้านเกินยอดจริง → ธุรกรรมค่าธรรมเนียม revert เงียบ
 *  - บังคับเส้นทางผ่าน WBNB เสมอ (ค่า LP สองต่อ) · ผลกระทบราคาเป็นเลขแต่ง
 *  - บันทึกไม้หลังขั้นค่าธรรมเนียมเท่านั้น → ปิดแท็บระหว่างป๊อปอัพ = ไม้หาย
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
const providerState = { nativeBalance: 0n, fail: false };
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
        async getBalance() {
            if (providerState.fail) throw Object.assign(new Error('down'), { code: 'NETWORK_ERROR' });
            return providerState.nativeBalance;
        }
    }
    return { ...actual, Contract: FakeContract, JsonRpcProvider: FakeProvider };
});

const { id } = await import('ethers');
const {
    useSwap, toDecimalString, toUnits, splitPlatformFee, priceImpactPct,
    PENDING_RECORDS_KEY, _resetFeeCollectorCacheForTests,
} = await import('@/Composables/useSwap');
const { PANCAKE_ROUTER_ADDRESS, WBNB_ADDRESS } = await import('@/utils/web3');

const ROUTER = PANCAKE_ROUTER_ADDRESS.toLowerCase();
const WBNB = WBNB_ADDRESS.toLowerCase();
const OWNER = `0x${'a'.repeat(40)}`;
const OTHER = `0x${'b'.repeat(40)}`;
const COLLECTOR = `0x${'c'.repeat(40)}`;
const USDT = { symbol: 'USDT', address: '0x55d398326f99059fF775485246999027B3197955', decimals: 18 };
const BTCB = { symbol: 'BTC', address: '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c', decimals: 18 };
const SWAP_HASH = `0x${'1'.repeat(64)}`;
const FEE_HASH = `0x${'2'.repeat(64)}`;
const E18 = 10n ** 18n;

const topicFor = address => `0x${'0'.repeat(24)}${address.slice(2).toLowerCase()}`;
const queue = () => JSON.parse(localStorage.getItem(PENDING_RECORDS_KEY) || '[]');

function makeSigner(address) {
    return {
        getAddress: vi.fn(async () => address),
        sendTransaction: vi.fn(async () => ({ hash: FEE_HASH, wait: async () => ({ status: 1 }) })),
    };
}

/** ใบเสร็จของสวอปที่มี Transfer ของเหรียญปลายทางเข้ากระเป๋าเรา */
function swapReceipt(received) {
    return {
        status: 1,
        logs: [{
            address: BTCB.address,
            topics: [id('Transfer(address,address,uint256)'), topicFor(ROUTER), topicFor(OWNER)],
            data: `0x${received.toString(16).padStart(64, '0')}`,
        }],
    };
}

function mockBackend({ quote = { fee_rate: 0.3, slippage: 0.5 }, quoteError = null } = {}) {
    get.mockImplementation(async (url) => {
        if (url === '/api/v1/swap/quote') {
            if (quoteError) throw quoteError;
            return { data: { success: true, data: { quote } } };
        }
        if (url === '/api/v1/trading/fee-info') return { data: { data: { fee_collector: COLLECTOR } } };
        if (url === '/api/v1/fees') return { data: { swap: { feeWallet: COLLECTOR } } };
        throw new Error(`unexpected GET ${url}`);
    });
}

describe('amount helpers', () => {
    it('turns any input into a plain decimal string without float', () => {
        expect(toDecimalString('1,500.25')).toBe('1500.25');
        expect(toDecimalString(1e-7)).toBe('0.0000001');
        expect(toDecimalString('1.5e3')).toBe('1500');
        expect(toDecimalString('0005.10')).toBe('5.1');
        expect(toDecimalString('abc')).toBeNull();
        expect(toDecimalString('-1')).toBeNull();
    });

    it('keeps full precision of a huge balance (MAX never exceeds the real balance)', () => {
        // float เคยให้ 5000000000.123456954956 (เกินยอดจริง) — ตอนนี้ตรงตัวหนังสือ
        expect(toUnits('5000000000.12345678', 18)).toBe(5000000000123456780000000000n);
        // เกินทศนิยมของเหรียญ = ตัดทิ้ง ไม่ปัดขึ้น
        expect(toUnits('1.2345679', 6)).toBe(1234567n);
    });

    it('splits the fee in BigInt, rounding the fee up by one unit so it is never zero', () => {
        expect(splitPlatformFee(100n * E18, 0.3)).toEqual({ feeWei: 3n * E18 / 10n, swapWei: 997n * E18 / 10n });
        expect(splitPlatformFee(300n, 0.3)).toEqual({ feeWei: 1n, swapWei: 299n });
        expect(splitPlatformFee(1000n, 0)).toEqual({ feeWei: 0n, swapWei: 1000n });
    });

    it('measures price impact against a tiny reference trade', () => {
        // ไม้จิ๋วได้อัตรา 2.0 · ไม้จริงได้ 1.8 → ดันราคา 10%
        expect(priceImpactPct(1000n, 1800n, 1n, 2n)).toBe(10);
        expect(priceImpactPct(1000n, 1800n, 1n, 0n)).toBeNull();
    });
});

describe('useSwap on BSC', () => {
    let signerA;

    beforeEach(() => {
        get.mockReset();
        post.mockReset().mockResolvedValue({ data: { success: true } });
        mockBackend();
        _resetFeeCollectorCacheForTests();
        calls.length = 0;
        Object.keys(contracts).forEach(k => delete contracts[k]);
        providerState.nativeBalance = 0n;
        providerState.fail = false;
        localStorage.removeItem(PENDING_RECORDS_KEY);

        signerA = makeSigner(OWNER);
        wallet.address = OWNER;
        wallet.signer = signerA;
        wallet.chainId = 56;
    });

    afterEach(() => vi.useRealTimers());

    describe('getQuote', () => {
        it('refuses to quote when /swap/quote fails — no guessed 0.3% fee (fail-closed)', async () => {
            mockBackend({ quoteError: { response: { status: 404, data: { error: { code: 'PAIR_DISABLED' } } } } });
            contracts[ROUTER] = { getAmountsOut: async () => [1n, 1n] };
            const swap = useSwap();

            expect(await swap.getQuote(USDT, BTCB, '100')).toBeNull();
            expect(swap.error.value).toBe("This pair isn't open for trading right now.");
            expect(calls.some(c => c.name === 'getAmountsOut')).toBe(false);

            mockBackend({ quoteError: { response: { status: 429 } } });
            expect(await swap.getQuote(USDT, BTCB, '100')).toBeNull();
            expect(swap.error.value).toContain("Couldn't confirm the platform fee");
        });

        it('picks the better of the direct and the WBNB route', async () => {
            contracts[ROUTER] = {
                getAmountsOut: async (amountIn, path) => [amountIn, path.length === 2 ? amountIn / 1000n : amountIn / 1100n],
            };
            const swap = useSwap();
            const q = await swap.getQuote(USDT, BTCB, '100');

            expect(q.route).toBe('direct');
            expect(q.path).toHaveLength(2);
            expect(q.grossWei).toBe(100n * E18);
            expect(q.feeWei).toBe(3n * E18 / 10n);
            expect(q.amountInSwapWei).toBe(997n * E18 / 10n);

            contracts[ROUTER].getAmountsOut = async (amountIn, path) => [amountIn, path.length === 3 ? amountIn / 900n : amountIn / 1000n];
            expect((await swap.getQuote(USDT, BTCB, '100')).route).toBe('wbnb');
        });

        it('reports the real pool price impact instead of the backend estimate', async () => {
            // ไม้จริงได้ 1.8/หน่วย ไม้จิ๋วได้ 2/หน่วย → 10%
            contracts[ROUTER] = {
                getAmountsOut: async (amountIn, path) => {
                    if (path.length === 3) throw Object.assign(new Error('revert'), { code: 'CALL_EXCEPTION' });
                    const big = amountIn > 10n ** 19n;
                    return [amountIn, big ? amountIn * 18n / 10n : amountIn * 2n];
                },
            };
            mockBackend({ quote: { fee_rate: 0.3, slippage: 0.5, price_impact: 0.1 } });
            const q = await useSwap().getQuote(USDT, BTCB, '100');

            expect(q.priceImpact).toBe(10);
        });

        it('waives the percentage fee for a trade paid with TPIX credit', async () => {
            contracts[ROUTER] = { getAmountsOut: async amountIn => [amountIn, amountIn] };
            const q = await useSwap().getQuote(USDT, BTCB, '100', { waiveFee: true });

            expect(q.waived).toBe(true);
            expect(q.feeWei).toBe(0n);
            expect(q.amountInSwapWei).toBe(100n * E18);
        });

        it('says the network is unreachable instead of "no liquidity" when every node is down', async () => {
            contracts[ROUTER] = {
                getAmountsOut: async () => { throw Object.assign(new Error('timeout'), { code: 'TIMEOUT' }); },
            };
            const swap = useSwap();

            expect(await swap.getQuote(USDT, BTCB, '100')).toBeNull();
            expect(swap.error.value).toBe("Couldn't reach the BSC network — please try again.");
            // ลองครบทุกโหนด (หลัก + สำรอง) × ทั้งสองเส้นทาง
            expect(calls.filter(c => c.name === 'getAmountsOut').length).toBe(4);
        });
    });

    describe('executeSwap', () => {
        /** สวอป USDT → BTCB 100 USDT · เศษต่างๆ ตั้งค่าได้ */
        function setupChain({ balance = 100n * E18, received = 15n * 10n ** 14n, swapWait } = {}) {
            const order = [];
            contracts[USDT.address.toLowerCase()] = {
                balanceOf: async () => balance,
                transfer: async (to, amount) => {
                    order.push('fee');
                    return { hash: FEE_HASH, wait: async () => ({ status: 1 }), to, amount };
                },
            };
            contracts[ROUTER] = {
                getAmountsOut: async amountIn => [amountIn, amountIn / 66000n],
                swapExactTokensForTokens: async () => {
                    order.push('swap');
                    return { hash: SWAP_HASH, wait: swapWait || (async () => swapReceipt(received)) };
                },
            };
            return order;
        }

        it('charges the fee from the account that swapped even if the wallet switched account meanwhile', async () => {
            const signerB = makeSigner(OTHER);
            setupChain({
                swapWait: async () => {
                    // ผู้ใช้สลับบัญชีใน MetaMask ระหว่างรอ → store ได้ signer ใหม่
                    wallet.signer = signerB;
                    wallet.address = OTHER;
                    return swapReceipt(15n * 10n ** 14n);
                },
            });
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100');

            const result = await swap.executeSwap(USDT, BTCB, '100', quote, 0.5);

            const fee = calls.find(c => c.name === 'transfer');
            expect(fee.runner).toBe(signerA);
            expect(fee.args).toEqual([COLLECTOR, 3n * E18 / 10n]);
            expect(calls.find(c => c.name === 'swapExactTokensForTokens').runner).toBe(signerA);
            expect(result.feeCollected).toBe(true);
            expect(post).toHaveBeenCalledWith('/api/v1/swap/execute', expect.objectContaining({
                wallet_address: OWNER,
                fee_collected: true,
                fee_amount: 0.3,
            }));
        });

        it('refuses to start when the signer is not the connected account', async () => {
            setupChain();
            wallet.signer = makeSigner(OTHER);
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100');

            await expect(swap.executeSwap(USDT, BTCB, '100', quote, 0.5)).rejects.toMatchObject({ isFriendly: true });
            expect(swap.error.value).toContain('account changed');
            expect(calls.some(c => c.name === 'swapExactTokensForTokens')).toBe(false);
        });

        it('queues the record right after the swap lands, before the fee prompt', async () => {
            setupChain();
            let queuedDuringFee = null;
            contracts[USDT.address.toLowerCase()].transfer = async () => {
                queuedDuringFee = queue();
                return { hash: FEE_HASH, wait: async () => ({ status: 1 }) };
            };
            const stages = [];
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100');

            await swap.executeSwap(USDT, BTCB, '100', quote, 0.5, { onStage: s => stages.push(s) });

            // ปิดแท็บตอนป๊อปอัพค่าธรรมเนียม = ยังมีแถวชั่วคราวรอส่งในเครื่อง
            expect(queuedDuringFee).toHaveLength(1);
            expect(queuedDuringFee[0]).toMatchObject({ tx_hash: SWAP_HASH, fee_collected: false, fee_amount: 0 });
            expect(stages).toEqual(['swapping', 'fee', 'recording']);
            // ส่งสำเร็จ → ลบออกจากคิว
            expect(queue()).toHaveLength(0);
        });

        it('keeps the final record in the queue when the server is unreachable', async () => {
            setupChain();
            post.mockRejectedValue({ response: { status: 403 } });
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100');

            await swap.executeSwap(USDT, BTCB, '100', quote, 0.5);

            expect(queue()).toHaveLength(1);
            expect(queue()[0]).toMatchObject({ fee_collected: true, fee_amount: 0.3 });
        });

        it('records the amount actually received from the receipt, not the quote', async () => {
            setupChain({ received: 14n * 10n ** 14n });   // quote บอก ~0.00151 · ได้จริง 0.0014
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100');

            const result = await swap.executeSwap(USDT, BTCB, '100', quote, 0.5, { pair: 'BTC/USDT', side: 'buy' });

            expect(result.amountOut).toBeCloseTo(0.0014, 10);
            const sent = post.mock.calls[0][1];
            expect(sent.to_amount).toBeCloseTo(0.0014, 10);
            expect(sent.price).toBeCloseTo(100 / 0.0014, 4);
        });

        it('trims rounding dust above the real balance instead of failing the fee transfer', async () => {
            const balance = 100n * E18 - 5n;   // ขาดไป 5 wei
            setupChain({ balance });
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100');

            await swap.executeSwap(USDT, BTCB, '100', quote, 0.5);

            const sent = calls.find(c => c.name === 'swapExactTokensForTokens').args[0];
            const fee = calls.find(c => c.name === 'transfer').args[1];
            expect(sent + fee).toBe(balance);
        });

        it('does not send anything when the amount is really above the balance', async () => {
            setupChain({ balance: 50n * E18 });
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100');

            await expect(swap.executeSwap(USDT, BTCB, '100', quote, 0.5)).rejects.toMatchObject({ isFriendly: true });
            expect(swap.error.value).toBe('Insufficient balance for this swap.');
            expect(calls.some(c => c.name === 'swapExactTokensForTokens')).toBe(false);
        });

        it('counts a sped-up fee transaction as collected', async () => {
            setupChain();
            contracts[USDT.address.toLowerCase()].transfer = async () => ({
                hash: FEE_HASH,
                wait: async () => { throw Object.assign(new Error('replaced'), { code: 'TRANSACTION_REPLACED', cancelled: false }); },
            });
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100');

            const result = await swap.executeSwap(USDT, BTCB, '100', quote, 0.5);
            expect(result.feeCollected).toBe(true);
        });

        it('sends the fee ticket and no fee transfer for a waived (TPIX credit) trade', async () => {
            setupChain();
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100', { waiveFee: true });

            await swap.executeSwap(USDT, BTCB, '100', quote, 0.5, { feeTicket: '9b2c6a10-0000-4000-8000-000000000001' });

            expect(calls.some(c => c.name === 'transfer')).toBe(false);
            expect(post).toHaveBeenCalledWith('/api/v1/swap/execute', expect.objectContaining({
                fee_ticket: '9b2c6a10-0000-4000-8000-000000000001',
                fee_amount: 0,
                fee_collected: true,
            }));
        });

        it('reports a timed-out swap as sent-but-unconfirmed with its hash', async () => {
            setupChain({ swapWait: async () => { throw Object.assign(new Error('timeout'), { code: 'TIMEOUT' }); } });
            const swap = useSwap();
            const quote = await swap.getQuote(USDT, BTCB, '100');

            await expect(swap.executeSwap(USDT, BTCB, '100', quote, 0.5))
                .rejects.toMatchObject({ txSent: true, txHash: SWAP_HASH });
            expect(post).not.toHaveBeenCalled();
        });
    });

    describe('ensureFeeCollector', () => {
        it('falls back to /api/v1/fees when fee-info is rate limited', async () => {
            get.mockImplementation(async (url) => {
                if (url === '/api/v1/trading/fee-info') throw { response: { status: 429 } };
                if (url === '/api/v1/fees') return { data: { swap: { feeWallet: COLLECTOR.toUpperCase().replace('0X', '0x') } } };
                throw new Error(url);
            });

            await expect(useSwap().ensureFeeCollector()).resolves.toBe(true);
        });

        it('tells "try again" apart from "not configured"', async () => {
            get.mockImplementation(async () => { throw new Error('offline'); });
            await expect(useSwap().ensureFeeCollector()).rejects.toThrow("Couldn't reach the fee service");

            _resetFeeCollectorCacheForTests();
            get.mockImplementation(async (url) => (url === '/api/v1/fees'
                ? { data: { swap: { feeWallet: '' } } }
                : { data: { data: { fee_collector: '' } } }));
            await expect(useSwap().ensureFeeCollector()).rejects.toThrow('Swap is temporarily unavailable');
        });
    });
});
