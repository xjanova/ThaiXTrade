/**
 * TPIX TRADE — useTpixDex: กรณีที่เงินผู้ใช้เสี่ยงหลังส่งธุรกรรมไปแล้ว + ด่านที่เคยรั่ว
 *
 * บั๊กที่ห้ามกลับมา:
 *  - tx.wait() ล้มหลังส่งออกไปแล้ว → เดิมบอก "ไม่สำเร็จ ลองใหม่" ทั้งที่ไม้อาจลงเชน (กดซ้ำ = สวอปสองรอบ)
 *  - ผลกระทบราคาของเส้นทาง A→WTPIX→B ใช้ reserve ฮอปแรกเทียบผลลัพธ์ฮอปสุดท้าย (หน่วยไม่ตรง)
 *  - โหลด /dex/config ล้มครั้งเดียว = "ยังไม่ deploy" ไปทั้งหน้า
 *  - อ่านยอดไม่ได้กลายเป็น '0' (หน้า Swap ขึ้น "ยอดไม่พอ")
 *  - กระเป๋าฝังที่ยังล็อกได้ข้อความ "เชื่อมกระเป๋า" ที่ไม่มีปุ่มให้กด
 *  - เชน TPIX ค่าแก๊ส 0 แต่ไม่ได้บอกกระเป๋า → คนถือ TPIX 0 ส่งธุรกรรมไม่ได้
 *  - ถอนสภาพคล่องไม่คิดส่วนแบ่งโปรโตคอล (_mintFee) → ยอดขั้นต่ำสูงเกินจริงแล้ว revert
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const wallet = { address: null, signer: null, chainId: null, isLocked: false, openConnectModal: vi.fn() };
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const get = vi.fn();
const post = vi.fn();
vi.mock('axios', () => ({ default: { get: (...args) => get(...args), post: (...args) => post(...args) } }));

/** สัญญาปลอมตามที่อยู่ (พิมพ์เล็ก) → ฟังก์ชันของสัญญา · ทุกการเรียกถูกจดไว้ */
const contracts = {};
const calls = [];
/** RPC ของ read provider — null = ไม่มีเมธอด send (เหมือน provider ที่ถามค่าแก๊สไม่ได้) */
let rpc = null;
vi.mock('ethers', async (importOriginal) => {
    const actual = await importOriginal();
    class FakeContract {
        constructor(address) {
            const impl = contracts[String(address).toLowerCase()] || {};
            return new Proxy({}, {
                get: (_target, name) => {
                    if (name === 'then') return undefined;
                    return async (...args) => {
                        calls.push({ address: String(address).toLowerCase(), name, args });
                        if (!impl[name]) throw new Error(`unexpected call ${String(name)}`);
                        return impl[name](...args);
                    };
                },
            });
        }
    }
    class FakeProvider {
        send(method, params) {
            if (!rpc) throw new Error('no rpc');
            return rpc(method, params);
        }
    }
    return { ...actual, Contract: FakeContract, JsonRpcProvider: FakeProvider };
});

const { _resetDexConfigForTests } = await import('@/Config/dexContracts');
const {
    useTpixDex,
    protocolFeeMint,
    isPriceImpactBlocked,
    _resetGasCacheForTests,
    _resetTokenCheckForTests,
} = await import('@/Composables/useTpixDex');
const { setLocale } = await import('@/Composables/useTranslation');

const ADDR = {
    WTPIX: '0x1111111111111111111111111111111111111111',
    USDT: '0x2222222222222222222222222222222222222222',
    FACTORY: '0x3333333333333333333333333333333333333333',
    ROUTER: '0x4444444444444444444444444444444444444444',
};
const ABC = '0xabcabcabcabcabcabcabcabcabcabcabcabcabca';
const PAIR_TPIX_USDT = '0x5555555555555555555555555555555555555555';
const PAIR_ABC_TPIX = '0x6666666666666666666666666666666666666666';
const TX_HASH = `0x${'1'.repeat(64)}`;
const NEW_HASH = `0x${'2'.repeat(64)}`;

const TPIX = { address: 'native', decimals: 18, symbol: 'TPIX' };
const USDT = { address: ADDR.USDT, decimals: 6, symbol: 'USDT' };
const QUOTE = { path: [ADDR.WTPIX, ADDR.USDT], amounts: [10n ** 18n, 1_000_000n], decimals: { from: 18, to: 6 } };

function serverReady() {
    get.mockResolvedValue({ data: { success: true, data: { ...ADDR, ready: true, chainId: 4289 } } });
}

/** สวอปที่ส่งออกไปได้ แต่ wait() ทำตามที่กำหนด */
function routerThatSends(wait) {
    contracts[ADDR.ROUTER] = {
        swapExactETHForTokens: async () => ({ hash: TX_HASH, wait }),
    };
}

describe('useTpixDex after the transaction is broadcast', () => {
    beforeEach(() => {
        serverReady();
        post.mockReset().mockResolvedValue({ data: { success: true } });
        _resetDexConfigForTests();
        _resetGasCacheForTests();
        _resetTokenCheckForTests();
        rpc = null;
        calls.length = 0;
        Object.keys(contracts).forEach((k) => delete contracts[k]);
        Object.assign(wallet, { address: `0x${'a'.repeat(40)}`, signer: {}, chainId: 4289, isLocked: false });
        wallet.openConnectModal.mockReset();
    });

    afterEach(() => setLocale('en'));

    it('keeps an unconfirmed swap as pending with its explorer link instead of "failed, try again"', async () => {
        routerThatSends(async () => { throw new Error('request timed out'); });
        const dex = useTpixDex();

        const err = await dex.executeTradeSwap(TPIX, USDT, 1, QUOTE, 0.5).catch((e) => e);

        expect(err).toMatchObject({ isFriendly: true, txSent: true, txHash: TX_HASH });
        expect(err.txUrl).toBe(`https://explorer.tpix.online/tx/${TX_HASH}`);
        expect(dex.txStatus.value).toBe('pending');
        expect(dex.error.value).toMatch(/check it on the explorer/i);
        // ยังไม่รู้ผล = ยังไม่บันทึก (dex:sync บันทึกจากเชนเองเมื่อไม้ลงจริง)
        expect(post).not.toHaveBeenCalled();
    });

    it('reports an on-chain revert (status 0 receipt thrown by ethers v6) as a real failure', async () => {
        routerThatSends(async () => {
            const err = new Error('transaction execution reverted');
            err.code = 'CALL_EXCEPTION';
            err.receipt = { status: 0 };
            throw err;
        });
        const dex = useTpixDex();

        const err = await dex.executeTradeSwap(TPIX, USDT, 1, QUOTE, 0.5).catch((e) => e);

        expect(err.txSent).toBeUndefined();
        expect(dex.txStatus.value).toBe('failed');
        expect(dex.error.value).toBe('Swap failed on-chain.');
    });

    it('treats a repriced (sped-up) swap that confirmed as success under the new hash', async () => {
        routerThatSends(async () => {
            const err = new Error('transaction was replaced');
            err.code = 'TRANSACTION_REPLACED';
            err.reason = 'repriced';
            err.cancelled = false;
            err.receipt = { status: 1, hash: NEW_HASH };
            throw err;
        });
        const dex = useTpixDex();

        const result = await dex.executeTradeSwap(TPIX, USDT, 1, QUOTE, 0.5);

        expect(result.hash).toBe(NEW_HASH);
        expect(post).toHaveBeenCalledWith('/api/v1/swap/execute', expect.objectContaining({ tx_hash: NEW_HASH }));
    });

    it('says nothing was swapped when the wallet cancelled the transaction', async () => {
        routerThatSends(async () => {
            const err = new Error('transaction was replaced');
            err.code = 'TRANSACTION_REPLACED';
            err.reason = 'cancelled';
            err.cancelled = true;
            throw err;
        });
        const dex = useTpixDex();

        const err = await dex.executeTradeSwap(TPIX, USDT, 1, QUOTE, 0.5).catch((e) => e);

        expect(err.txSent).toBeUndefined();
        expect(dex.error.value).toMatch(/nothing was swapped/i);
    });

    it('never lets minOut fall below the minimum the user saw on screen', async () => {
        contracts[ADDR.ROUTER] = {
            swapExactETHForTokens: async () => ({ hash: TX_HASH, wait: async () => ({ status: 1 }) }),
        };
        const dex = useTpixDex();

        // ราคาใหม่ 1.0 USDT ที่ slippage 5% = 0.95 แต่ผู้ใช้เห็น "อย่างน้อย 0.98" ในพรีวิว
        await dex.executeTradeSwap(TPIX, USDT, 1, QUOTE, 5, { minOutFloor: 980_000n });

        const send = calls.find(c => c.name === 'swapExactETHForTokens');
        expect(send.args[0]).toBe(980_000n);
    });

    it('records the swap for the wallet that signed it even if the account switches mid-flight', async () => {
        contracts[ADDR.ROUTER] = {
            swapExactETHForTokens: async () => {
                wallet.address = `0x${'b'.repeat(40)}`;   // ผู้ใช้สลับบัญชีระหว่างรอเซ็น
                return { hash: TX_HASH, wait: async () => ({ status: 1 }) };
            },
        };
        const dex = useTpixDex();

        await dex.executeTradeSwap(TPIX, USDT, 1, QUOTE, 0.5);

        expect(post).toHaveBeenCalledWith('/api/v1/swap/execute', expect.objectContaining({
            wallet_address: `0x${'a'.repeat(40)}`,
        }));
    });
});

describe('useTpixDex gas, wallet lock and config', () => {
    beforeEach(() => {
        serverReady();
        post.mockReset().mockResolvedValue({ data: { success: true } });
        _resetDexConfigForTests();
        _resetGasCacheForTests();
        _resetTokenCheckForTests();
        rpc = null;
        calls.length = 0;
        Object.keys(contracts).forEach((k) => delete contracts[k]);
        Object.assign(wallet, { address: `0x${'a'.repeat(40)}`, signer: {}, chainId: 4289, isLocked: false });
        wallet.openConnectModal.mockReset();
    });

    it('tells the wallet the chain is zero-gas (legacy, gasPrice 0) when the chain says so', async () => {
        // ตรงกับที่ rpc1 ตอบจริง: eth_gasPrice 0x0 และบล็อกไม่มี baseFeePerGas
        rpc = async (method) => (method === 'eth_gasPrice' ? '0x0' : { number: '0x10' });
        contracts[ADDR.ROUTER] = {
            swapExactETHForTokens: async () => ({ hash: TX_HASH, wait: async () => ({ status: 1 }) }),
        };
        const dex = useTpixDex();

        await dex.executeTradeSwap(TPIX, USDT, 1, QUOTE, 0.5);

        const send = calls.find(c => c.name === 'swapExactETHForTokens');
        expect(send.args[4]).toEqual({ type: 0, gasPrice: 0n, value: 10n ** 18n });
    });

    it('lets the wallet decide fees once the chain has a base fee', async () => {
        rpc = async (method) => (method === 'eth_gasPrice' ? '0x3b9aca00' : { baseFeePerGas: '0x7' });
        contracts[ADDR.ROUTER] = {
            swapExactETHForTokens: async () => ({ hash: TX_HASH, wait: async () => ({ status: 1 }) }),
        };
        const dex = useTpixDex();

        await dex.executeTradeSwap(TPIX, USDT, 1, QUOTE, 0.5);

        const send = calls.find(c => c.name === 'swapExactETHForTokens');
        expect(send.args[4]).toEqual({ value: 10n ** 18n });
    });

    it('opens the unlock window for a locked TPIX Wallet instead of asking to connect', async () => {
        Object.assign(wallet, { signer: null, isLocked: true });
        const dex = useTpixDex();

        const result = await dex.executeSwap('native', ADDR.USDT, 1);

        expect(result).toBeNull();
        expect(dex.error.value).toBe('Unlock your TPIX Wallet to continue.');
        expect(wallet.openConnectModal).toHaveBeenCalledTimes(1);
    });

    it('asks the server again after a failed /dex/config load instead of staying "not deployed"', async () => {
        get.mockReset()
            .mockRejectedValueOnce(Object.assign(new Error('Too Many Requests'), { response: { status: 429 } }))
            .mockResolvedValue({ data: { success: true, data: { ...ADDR, ready: true, chainId: 4289 } } });
        contracts[ADDR.ROUTER] = { getAmountsOut: async () => [10n ** 18n, 1_000_000n] };
        const dex = useTpixDex();

        expect(await dex.getQuote('native', ADDR.USDT, 1, { from: 18, to: 6 })).toBeNull();
        const second = await dex.getQuote('native', ADDR.USDT, 1, { from: 18, to: 6 });

        expect(second?.amountOut).toBe('1.0');
        expect(get).toHaveBeenCalledTimes(2);
    });

    it('reports an unreadable balance as unknown (null), not zero', async () => {
        contracts[ADDR.USDT] = { balanceOf: async () => { throw new Error('rpc down'); }, decimals: async () => 6n };
        const dex = useTpixDex();

        expect(await dex.getBalance(ADDR.USDT)).toBeNull();
    });

    it('verifies token decimals against the chain before trading', async () => {
        contracts[ADDR.USDT] = { symbol: async () => 'USDT', decimals: async () => 6n, name: async () => 'Tether' };
        const dex = useTpixDex();

        expect(await dex.verifyTokenDecimals(USDT)).toBe(true);
        expect(await dex.verifyTokenDecimals({ ...USDT, decimals: 18 })).toBe('tpixDexError.tokenMismatch');
        expect(await dex.verifyTokenDecimals(TPIX)).toBe(true);
        expect(await dex.verifyTokenDecimals({ ...USDT, decimals: null })).toBe('tpixDexError.tokenMismatch');
    });
});

describe('useTpixDex quotes', () => {
    beforeEach(() => {
        serverReady();
        _resetDexConfigForTests();
        _resetGasCacheForTests();
        rpc = null;
        calls.length = 0;
        Object.keys(contracts).forEach((k) => delete contracts[k]);
        Object.assign(wallet, { address: `0x${'a'.repeat(40)}`, signer: {}, chainId: 4289, isLocked: false });
    });

    it('measures price impact across every hop of an A → WTPIX → B route', async () => {
        // ไม่มีคู่ ABC/USDT ตรง → router ต้องวิ่งผ่าน WTPIX
        contracts[ADDR.ROUTER] = {
            getAmountsOut: async (_in, path) => {
                if (path.length === 2) throw new Error('PAIR_NOT_FOUND');
                return [10n ** 18n, 2n * 10n ** 18n, 1_800_000n];   // 1 ABC → 2 TPIX → 1.8 USDT
            },
        };
        contracts[ADDR.FACTORY] = {
            getPair: async (a, b) => {
                const set = [a.toLowerCase(), b.toLowerCase()].sort().join();
                if (set === [ABC, ADDR.WTPIX].sort().join()) return PAIR_ABC_TPIX;
                if (set === [ADDR.WTPIX, ADDR.USDT].sort().join()) return PAIR_TPIX_USDT;
                return '0x0000000000000000000000000000000000000000';
            },
        };
        // ราคากลาง: 1 ABC = 2 TPIX · 1 TPIX = 1 USDT → 1 ABC = 2 USDT · ได้จริง 1.8 → ผลกระทบ 10%
        contracts[PAIR_ABC_TPIX] = {
            token0: async () => ABC,
            getReserves: async () => [1000n * 10n ** 18n, 2000n * 10n ** 18n, 0n],
            totalSupply: async () => 1n,
        };
        contracts[PAIR_TPIX_USDT] = {
            token0: async () => ADDR.WTPIX,
            getReserves: async () => [1000n * 10n ** 18n, 1_000_000_000n, 0n],
            totalSupply: async () => 1n,
        };
        const dex = useTpixDex();

        const quote = await dex.getTradeQuote({ address: ABC, decimals: 18 }, USDT, 1, 0.5);

        expect(quote.path).toEqual([ABC, ADDR.WTPIX, ADDR.USDT]);
        expect(quote.priceImpact).toBeCloseTo(10, 4);
    });

    it('remembers the quote on screen so the trade page can hold the send to it', async () => {
        contracts[ADDR.ROUTER] = { getAmountsOut: async () => [10n ** 18n, 1_000_000n] };
        contracts[ADDR.FACTORY] = { getPair: async () => { throw new Error('rpc down'); } };
        const dex = useTpixDex();

        const quote = await dex.getTradeQuote(TPIX, USDT, 1, 2);

        expect(quote.minOutWei).toBe(980_000n);   // 1.0 × (1 − 2%)
        expect(dex.recentQuote(TPIX, USDT, 1, 2)).toBe(quote);
        // คนละจำนวน / คนละ slippage = ไม่ใช่ราคาที่ผู้ใช้เห็นสำหรับไม้นี้
        expect(dex.recentQuote(TPIX, USDT, 2, 2)).toBeNull();
        expect(dex.recentQuote(TPIX, USDT, 1, 0.5)).toBeNull();
    });

    it('blocks a trade whose price impact is unknown (shared gate for /swap and /trade)', () => {
        expect(isPriceImpactBlocked(null)).toBe(true);
        expect(isPriceImpactBlocked(undefined)).toBe(true);
        expect(isPriceImpactBlocked(NaN)).toBe(true);
        expect(isPriceImpactBlocked(10.01)).toBe(true);
        expect(isPriceImpactBlocked(9.99)).toBe(false);
    });
});

describe('removing liquidity', () => {
    beforeEach(() => {
        serverReady();
        _resetDexConfigForTests();
        _resetGasCacheForTests();
        rpc = null;
        calls.length = 0;
        Object.keys(contracts).forEach((k) => delete contracts[k]);
        Object.assign(wallet, { address: `0x${'a'.repeat(40)}`, signer: {}, chainId: 4289, isLocked: false });
    });

    it('protocolFeeMint follows the pair contract (_mintFee) formula', () => {
        // √k โต 1% จาก kLast → feeTo ได้ ≈ 1/6 ของส่วนที่โต
        const supply = 1_000_000n;
        const mint = protocolFeeMint(10_100n, 10_100n, supply, 10_000n * 10_000n);
        expect(mint).toBe((supply * (10_100n - 10_000n)) / (10_100n * 5n + 10_000n));
        expect(protocolFeeMint(10_000n, 10_000n, supply, 0n)).toBe(0n);           // feeTo ปิด / kLast = 0
        expect(protocolFeeMint(9_000n, 9_000n, supply, 10_000n * 10_000n)).toBe(0n);   // k ไม่โต
    });

    it('lowers the withdrawal minimums by the LP the pool mints to feeTo first', async () => {
        const supply = 1000n * 10n ** 18n;
        const reserveUsdt = 110_000_000n;        // 110 USDT
        const reserveTpix = 1100n * 10n ** 18n;  // 1100 TPIX — √k โต 10% จาก kLast (100 USDT × 1000 TPIX)
        contracts[ADDR.FACTORY] = {
            getPair: async () => PAIR_TPIX_USDT,
            feeTo: async () => `0x${'f'.repeat(40)}`,
        };
        contracts[PAIR_TPIX_USDT] = {
            token0: async () => ADDR.USDT,
            getReserves: async () => [reserveUsdt, reserveTpix, 0n],
            totalSupply: async () => supply,
            balanceOf: async () => 100n * 10n ** 18n,   // ถือ 10% ของพูล
            kLast: async () => 100_000_000n * (1000n * 10n ** 18n),
            allowance: async () => 10n ** 30n,
        };
        contracts[ADDR.ROUTER] = {
            removeLiquidityETH: async () => ({ hash: TX_HASH, wait: async () => ({ status: 1 }) }),
        };
        const dex = useTpixDex();

        const result = await dex.removeLiquidity(ADDR.USDT, 100, { slippagePct: 0.5, tokenDecimals: 6 });

        expect(result.hash).toBe(TX_HASH);
        const send = calls.find(c => c.name === 'removeLiquidityETH');
        const [, lpToBurn, tokenMin, tpixMin] = send.args;
        expect(lpToBurn).toBe(100n * 10n ** 18n);

        const feeMint = protocolFeeMint(reserveUsdt, reserveTpix, supply, 100_000_000n * (1000n * 10n ** 18n));
        expect(feeMint > 0n).toBe(true);
        const supplyAtBurn = supply + feeMint;
        // ยอดขั้นต่ำคิดจาก supply หลัง _mintFee — ต้องไม่เกินยอดที่พูลจ่ายจริง
        expect(tokenMin).toBe(((reserveUsdt * lpToBurn) / supplyAtBurn) * 9950n / 10000n);
        expect(tpixMin).toBe(((reserveTpix * lpToBurn) / supplyAtBurn) * 9950n / 10000n);
        expect(tokenMin <= (reserveUsdt * lpToBurn) / supplyAtBurn).toBe(true);
    });
});
