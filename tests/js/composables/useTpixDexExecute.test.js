/**
 * TPIX TRADE — useTpixDex ตอนส่งธุรกรรมจริง (สัญญาบนเชนเป็นของปลอม)
 *
 *  - minOut ต้องผูกกับราคาที่ผู้ใช้เห็นบนจอ (quote.amounts) ไม่ใช่ราคาใหม่ตอนกดส่ง
 *  - ผู้ใช้กดปฏิเสธในกระเป๋า → ข้อความแปลตามภาษาของหน้า ไม่ใช่ error ดิบของ ethers
 *  - ผลกระทบราคาที่คำนวณไม่ได้ต้องเป็น null ("ไม่รู้") ไม่ใช่ 0 ("ไม่กระทบ")
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const wallet = { address: null, signer: null, chainId: null };
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const get = vi.fn();
const post = vi.fn();
vi.mock('axios', () => ({ default: { get: (...args) => get(...args), post: (...args) => post(...args) } }));

/** สัญญาปลอมตามที่อยู่ (พิมพ์เล็ก) → ฟังก์ชันของสัญญา · ทุกการเรียกถูกจดไว้ */
const contracts = {};
const calls = [];
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
    class FakeProvider {}
    return { ...actual, Contract: FakeContract, JsonRpcProvider: FakeProvider };
});

const { _resetDexConfigForTests } = await import('@/Config/dexContracts');
const { useTpixDex } = await import('@/Composables/useTpixDex');
const { setLocale } = await import('@/Composables/useTranslation');

const ADDR = {
    WTPIX: '0x1111111111111111111111111111111111111111',
    USDT: '0x2222222222222222222222222222222222222222',
    FACTORY: '0x3333333333333333333333333333333333333333',
    ROUTER: '0x4444444444444444444444444444444444444444',
};
const TX_HASH = `0x${'1'.repeat(64)}`;

const TPIX = { address: 'native', decimals: 18, symbol: 'TPIX' };
const USDT = { address: ADDR.USDT, decimals: 6, symbol: 'USDT' };

describe('useTpixDex on-chain execution', () => {
    beforeEach(() => {
        get.mockReset().mockResolvedValue({ data: { success: true, data: { ...ADDR, ready: true, chainId: 4289 } } });
        post.mockReset().mockResolvedValue({ data: { success: true } });
        _resetDexConfigForTests();
        calls.length = 0;
        Object.keys(contracts).forEach((k) => delete contracts[k]);
        wallet.address = `0x${'a'.repeat(40)}`;
        wallet.signer = {};
        wallet.chainId = 4289;
    });

    afterEach(() => setLocale('en'));

    it('builds minOut from the quote on screen instead of re-quoting at send time', async () => {
        contracts[ADDR.ROUTER] = {
            getAmountsOut: async () => [10n ** 18n, 2_000_000n],   // ราคาใหม่ "ดีกว่า" — ต้องไม่ถูกใช้
            swapExactETHForTokens: async () => ({ hash: TX_HASH, wait: async () => ({ status: 1 }) }),
        };
        const dex = useTpixDex();
        const quote = { path: [ADDR.WTPIX, ADDR.USDT], amounts: [10n ** 18n, 1_000_000n], decimals: { from: 18, to: 6 } };

        const result = await dex.executeTradeSwap(TPIX, USDT, 1, quote, 1);

        const send = calls.find(c => c.name === 'swapExactETHForTokens');
        expect(send.args[0]).toBe(990_000n);   // 1.000000 USDT × (1 − 1%)
        expect(send.args[1]).toEqual([ADDR.WTPIX, ADDR.USDT]);
        expect(calls.some(c => c.name === 'getAmountsOut')).toBe(false);

        expect(result.url).toBe(`https://explorer.tpix.online/tx/${TX_HASH}`);
        // บันทึกไม้บนเชน 4289 — ค่าธรรมเนียมอยู่ในพูล จึงเป็น 0
        expect(post).toHaveBeenCalledWith('/api/v1/swap/execute', expect.objectContaining({
            chain_id: 4289,
            fee_amount: 0,
            tx_hash: TX_HASH,
        }));
    });

    it('tells the caller the hash as soon as the swap leaves the wallet, before it is mined', async () => {
        // หน้าเทรดผูกใบอนุญาตวางไม้กับ hash ตรงนี้ — ปิดแท็บระหว่างรอ เซิร์ฟเวอร์ก็ตัดสินตั๋วจากเชนได้
        const events = [];
        contracts[ADDR.ROUTER] = {
            swapExactETHForTokens: async () => ({
                hash: TX_HASH,
                wait: async () => { events.push('mined'); return { status: 1 }; },
            }),
        };
        const quote = { path: [ADDR.WTPIX, ADDR.USDT], amounts: [10n ** 18n, 1_000_000n], decimals: { from: 18, to: 6 } };

        await useTpixDex().executeTradeSwap(TPIX, USDT, 1, quote, 1, { onSent: hash => events.push(hash) });

        expect(events).toEqual([TX_HASH, 'mined']);
    });

    it('turns a wallet rejection into a translated message (never raw ethers text)', async () => {
        contracts[ADDR.ROUTER] = {
            swapExactETHForTokens: async () => {
                const err = new Error('user rejected action (action="sendTransaction", reason="rejected", code=ACTION_REJECTED)');
                err.code = 'ACTION_REJECTED';
                throw err;
            },
        };
        const quote = { path: [ADDR.WTPIX, ADDR.USDT], amounts: [10n ** 18n, 1_000_000n], decimals: { from: 18, to: 6 } };

        setLocale('th');
        const dex = useTpixDex();
        await expect(dex.executeTradeSwap(TPIX, USDT, 1, quote, 0.5)).rejects.toMatchObject({ isFriendly: true });

        expect(dex.error.value).toBe('คุณปฏิเสธรายการในกระเป๋า');
        expect(dex.error.value).not.toMatch(/ACTION_REJECTED|sendTransaction/);
        expect(post).not.toHaveBeenCalled();
    });

    it('reports an unknown price impact as null, not 0', async () => {
        contracts[ADDR.ROUTER] = { getAmountsOut: async () => [10n ** 18n, 1_000_000n] };
        contracts[ADDR.FACTORY] = { getPair: async () => { throw new Error('rpc down'); } };
        const dex = useTpixDex();

        const quote = await dex.getTradeQuote(TPIX, USDT, 1, 0.5);

        expect(quote.netOutput).toBe(1);
        expect(quote.priceImpact).toBeNull();
    });

    it('computes the price impact from the pool reserves when they can be read', async () => {
        const PAIR = '0x5555555555555555555555555555555555555555';
        contracts[ADDR.ROUTER] = { getAmountsOut: async () => [10n ** 18n, 900_000n] };
        contracts[ADDR.FACTORY] = { getPair: async () => PAIR };
        // พูล 100 TPIX : 100 USDT (6 ตำแหน่ง) → ราคากลาง 1 USDT/TPIX
        contracts[PAIR] = {
            token0: async () => ADDR.WTPIX,
            getReserves: async () => [100n * 10n ** 18n, 100_000_000n, 0n],
            totalSupply: async () => 1n,
        };
        const dex = useTpixDex();

        const quote = await dex.getTradeQuote(TPIX, USDT, 1, 0.5);

        // ได้ 0.9 แทน 1.0 → ผลกระทบ 10%
        expect(quote.priceImpact).toBeCloseTo(10, 4);
    });

    it('asks to connect instead of throwing when approving without a signer', async () => {
        wallet.signer = null;
        const dex = useTpixDex();

        const ok = await dex.approveToken(ADDR.USDT, 1, 6);

        expect(ok).toBe(false);
        expect(dex.error.value).toBe('Please connect your wallet first.');
    });
});
