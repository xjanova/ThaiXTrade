/**
 * TPIX TRADE — การสลับเชนต้องไม่ไปหยิบ signer ของกระเป๋าอื่นมาใช้
 *
 * ชุดนี้เกิดจากบั๊กจริงระดับวิกฤต: switchChain เขียนไว้ว่า
 *   `_rawProvider || _getProvider(walletType.value || 'metamask')`
 * สำหรับ TPIX Wallet (กระเป๋าฝังในเว็บ) `_rawProvider` ไม่เคยถูกเซ็ต และ
 * _getProvider('tpix_wallet') ไม่ตรงเงื่อนไขใดเลย จึงตกมาที่ `window.ethereum`
 * ซึ่งคือส่วนขยาย MetaMask จากนั้น signer ถูกเขียนทับด้วยบัญชีของ MetaMask
 * ขณะที่ address ยังเป็นของกระเป๋าฝัง → ธุรกรรมถัดไปเซ็นด้วยบัญชีผิดใบ
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useWalletStore } from '@/Stores/walletStore';

vi.mock('axios', () => ({
    default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) },
}));

describe('walletStore.switchChain', () => {
    let store;
    let metamaskCalls;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useWalletStore();

        metamaskCalls = [];

        // ส่วนขยาย MetaMask ที่ "ติดตั้งอยู่ในเบราว์เซอร์" แต่ผู้ใช้ไม่ได้เชื่อม
        window.ethereum = {
            isMetaMask: true,
            request: vi.fn((args) => {
                metamaskCalls.push(args?.method);
                return Promise.resolve(null);
            }),
        };
    });

    afterEach(() => {
        delete window.ethereum;
        vi.restoreAllMocks();
    });

    /**
     * ⭐ หัวใจของชุดทดสอบนี้
     */
    it('ไม่แตะ window.ethereum เลยเมื่อผู้ใช้ใช้กระเป๋าฝัง', async () => {
        store.walletType = 'tpix_wallet';
        store.address = '0x1111111111111111111111111111111111111111';

        await expect(store.switchChain(56)).rejects.toThrow('EMBEDDED_WALLET_SINGLE_CHAIN');

        expect(metamaskCalls).toEqual([]);
        expect(window.ethereum.request).not.toHaveBeenCalled();
    });

    it('บอกเหตุผลเป็นข้อความที่ผู้ใช้อ่านรู้เรื่องเมื่อกระเป๋าฝังสลับเชนไม่ได้', async () => {
        store.walletType = 'tpix_wallet';
        store.address = '0x1111111111111111111111111111111111111111';

        await store.switchChain(56).catch(() => {});

        expect(store.error).toContain('TPIX Wallet');
    });

    it('กระเป๋าฝังสลับมา TPIX Chain (เชนของตัวเอง) ได้ตามปกติ', async () => {
        store.walletType = 'tpix_wallet';
        store.address = '0x1111111111111111111111111111111111111111';

        await expect(store.switchChain(4289)).resolves.toBeUndefined();

        expect(store.chainId).toBe(4289);
        expect(store.error).toBeNull();
        expect(metamaskCalls).toEqual([]);
    });

    /**
     * เดิม return เฉยๆ ทำให้ผู้ใช้เห็นเหมือนสำเร็จ และหน้า Bridge เดินต่อบนเชนผิด
     */
    it('ไม่มีกระเป๋าเชื่อมอยู่เลยต้องโยน error ไม่ใช่เงียบแล้วจบ', async () => {
        delete window.ethereum;

        store.walletType = null;
        store.address = null;

        await expect(store.switchChain(56)).rejects.toThrow('NO_WALLET_PROVIDER');
        expect(store.error).toBeTruthy();
    });

    it('ล้าง error เก่าทิ้งทุกครั้งที่เริ่มสลับใหม่', async () => {
        store.error = 'ข้อความเก่าค้างจากรอบก่อน';
        store.walletType = 'tpix_wallet';
        store.address = '0x1111111111111111111111111111111111111111';

        await store.switchChain(4289);

        expect(store.error).toBeNull();
    });
});
