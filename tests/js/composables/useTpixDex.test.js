/**
 * TPIX TRADE — useTpixDex (สวอป/สภาพคล่องบน TPIX DEX)
 *
 * เน้นด่านที่กันเงินผู้ใช้: DEX ยังไม่พร้อม / กระเป๋าอยู่ผิดเชน → ต้องไม่ส่งอะไรออกไป
 * และการแปลงที่อยู่ native (0x0 ในฐานข้อมูล) ให้เป็น sentinel ที่ router เข้าใจ
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

const wallet = { address: null, signer: null, chainId: null };
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const get = vi.fn();
const post = vi.fn();
vi.mock('axios', () => ({ default: { get: (...args) => get(...args), post: (...args) => post(...args) } }));

const { _resetDexConfigForTests } = await import('@/Config/dexContracts');
const { useTpixDex, toDexAddress, POOL_FEE_PCT } = await import('@/Composables/useTpixDex');

const ADDR = {
    WTPIX: '0x1111111111111111111111111111111111111111',
    USDT: '0x2222222222222222222222222222222222222222',
    FACTORY: '0x3333333333333333333333333333333333333333',
    ROUTER: '0x4444444444444444444444444444444444444444',
};

function serverSays(ready) {
    get.mockResolvedValue({ data: { success: true, data: { ...ADDR, ready, chainId: 4289 } } });
}

describe('toDexAddress', () => {
    it('maps every spelling of native TPIX to the sentinel', () => {
        expect(toDexAddress('0x0000000000000000000000000000000000000000')).toBe('native');
        expect(toDexAddress('0x' + 'eE'.repeat(20))).toBe('native');
        expect(toDexAddress('native')).toBe('native');
        expect(toDexAddress('')).toBe('native');
    });

    it('lower-cases real token addresses', () => {
        expect(toDexAddress('0xABCabcABCabcABCabcABCabcABCabcABCabcABCa')).toBe('0xabcabcabcabcabcabcabcabcabcabcabcabcabca');
    });
});

describe('useTpixDex guards', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        _resetDexConfigForTests();
        wallet.address = null;
        wallet.signer = null;
        wallet.chainId = null;
    });

    it('pool fee is the UniV2 0.3% and lives in the pool', () => {
        expect(POOL_FEE_PCT).toBe(0.3);
    });

    it('refuses to quote before the DEX is deployed', async () => {
        serverSays(false);
        const dex = useTpixDex();

        const quote = await dex.getQuote('native', ADDR.USDT, 10, { from: 18, to: 6 });

        expect(quote).toBeNull();
        expect(dex.error.value).toMatch(/not deployed/i);
    });

    it('refuses to swap without a connected wallet', async () => {
        serverSays(true);
        const dex = useTpixDex();

        const result = await dex.executeSwap('native', ADDR.USDT, 10);

        expect(result).toBeNull();
        expect(dex.error.value).toMatch(/connect your wallet/i);
    });

    it('refuses to swap when the wallet sits on another chain', async () => {
        serverSays(true);
        wallet.address = '0x' + 'a'.repeat(40);
        wallet.signer = {};
        wallet.chainId = 56;
        const dex = useTpixDex();

        const result = await dex.executeSwap('native', ADDR.USDT, 10);

        expect(result).toBeNull();
        expect(dex.error.value).toMatch(/TPIX Chain/);
    });

    it('executeTradeSwap surfaces the guard message as a friendly error', async () => {
        serverSays(false);
        const dex = useTpixDex();

        await expect(dex.executeTradeSwap(
            { address: 'native', decimals: 18, symbol: 'TPIX' },
            { address: ADDR.USDT, decimals: 6, symbol: 'USDT' },
            5,
            null,
        )).rejects.toMatchObject({ isFriendly: true });
        expect(post).not.toHaveBeenCalled();
    });

    it('adding liquidity with TPIX on both sides is rejected', async () => {
        serverSays(true);
        wallet.address = '0x' + 'a'.repeat(40);
        wallet.signer = {};
        wallet.chainId = 4289;
        const dex = useTpixDex();

        const result = await dex.addLiquidity('native', 1, 1);

        expect(result).toBeNull();
        expect(dex.error.value).toMatch(/token/i);
    });
});
