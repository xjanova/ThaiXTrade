/**
 * TPIX TRADE — dexContracts (ที่อยู่สัญญา DEX แบบรันไทม์)
 *
 * ก่อน deploy ต้อง fail-closed: ที่อยู่เป็นศูนย์ + ready เท็จ → ห้ามมีทางเปิดเทรดได้
 * หลังทะเบียนตอบกลับ ที่อยู่ต้องมาจากเซิร์ฟเวอร์ ไม่ใช่ไฟล์ที่ build ติดมา
 * และถ้าเซิร์ฟเวอร์บอก ready=false แม้ที่อยู่ครบ ก็ยังต้องถือว่ายังไม่พร้อม
 * (เชนเคย regenesis แล้วสัญญาหายทั้งที่ที่อยู่ยังอยู่)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

const get = vi.fn();
vi.mock('axios', () => ({ default: { get: (...args) => get(...args) } }));

const { TPIX_DEX, loadDexConfig, isDexConfigured, _resetDexConfigForTests } = await import('@/Config/dexContracts');

const ADDR = {
    WTPIX: '0x1111111111111111111111111111111111111111',
    USDT: '0x2222222222222222222222222222222222222222',
    FACTORY: '0x3333333333333333333333333333333333333333',
    ROUTER: '0x4444444444444444444444444444444444444444',
};

describe('dexContracts', () => {
    beforeEach(() => {
        get.mockReset();
        _resetDexConfigForTests();
    });

    it('is not configured before the registry answers', () => {
        expect(TPIX_DEX.loaded).toBe(false);
        expect(isDexConfigured()).toBe(false);
    });

    it('takes addresses from the server registry and becomes configured', async () => {
        get.mockResolvedValue({ data: { success: true, data: { ...ADDR, ready: true, chainId: 4289, rpc: 'https://rpc.tpix.online' } } });

        await loadDexConfig();

        expect(get).toHaveBeenCalledWith('/api/v1/dex/config');
        expect(TPIX_DEX.ROUTER).toBe(ADDR.ROUTER);
        expect(TPIX_DEX.loaded).toBe(true);
        expect(isDexConfigured()).toBe(true);
    });

    it('stays closed when the server has addresses but says the code is gone', async () => {
        get.mockResolvedValue({ data: { success: true, data: { ...ADDR, ready: false, chainId: 4289 } } });

        await loadDexConfig();

        expect(TPIX_DEX.ROUTER).toBe(ADDR.ROUTER);
        expect(isDexConfigured()).toBe(false);
    });

    it('stays closed when the request fails', async () => {
        get.mockRejectedValue(new Error('network'));

        await loadDexConfig();

        expect(TPIX_DEX.loaded).toBe(true);
        expect(isDexConfigured()).toBe(false);
    });

    it('does not hit the server again within the cache window', async () => {
        get.mockResolvedValue({ data: { success: true, data: { ...ADDR, ready: true, chainId: 4289 } } });

        await loadDexConfig();
        await loadDexConfig();

        expect(get).toHaveBeenCalledTimes(1);
    });
});
