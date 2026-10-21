/**
 * TPIX TRADE — สลับบัญชีในกระเป๋า / กระเป๋าฝังที่ยังล็อก
 *
 * บั๊กที่ห้ามกลับมา:
 *  - accountsChanged: address เปลี่ยนทันทีแต่ signer เปลี่ยนตามทีหลัง → ธุรกรรมในช่วงนั้นเซ็นด้วยบัญชีเก่า
 *    แต่ระบุผู้รับ/บันทึกเป็นบัญชีใหม่ · ตอนนี้ปลด signer ทันทีจนกว่าของบัญชีใหม่จะพร้อม
 *  - รีเฟรชหน้าแล้วกระเป๋าฝังมีที่อยู่แต่ไม่มีผู้เซ็น → ต้องรู้ว่า "ล็อกอยู่" เพื่อพาไปปลดล็อก
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useWalletStore } from '@/Stores/walletStore';

vi.mock('axios', () => ({
    default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) },
}));

const OLD = '0x1111111111111111111111111111111111111111';
const NEW = '0x2222222222222222222222222222222222222222';

describe('walletStore account switching', () => {
    let store;
    let accounts;
    let handlers;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useWalletStore();
        accounts = [OLD];
        handlers = {};

        window.ethereum = {
            isMetaMask: true,
            request: vi.fn(async ({ method }) => {
                if (method === 'eth_accounts' || method === 'eth_requestAccounts') return [...accounts];
                if (method === 'eth_chainId') return '0x10c1';
                return null;
            }),
            on: (event, fn) => { handlers[event] = fn; },
            removeListener: vi.fn(),
        };
        localStorage.setItem('tpix_wallet', JSON.stringify({ address: OLD, walletType: 'metamask' }));
    });

    afterEach(() => {
        delete window.ethereum;
        localStorage.removeItem('tpix_wallet');
        vi.restoreAllMocks();
    });

    it('drops the old signer at once and attaches the new account\'s signer', async () => {
        expect(await store.tryReconnect()).toBe(true);
        expect((await store.signer.getAddress()).toLowerCase()).toBe(OLD);

        accounts = [NEW];
        handlers.accountsChanged([NEW]);

        // ช่วงรอผู้เซ็นใหม่: ห้ามมีผู้เซ็นของบัญชีเก่าค้างอยู่
        expect(store.address).toBe(NEW);
        expect(store.signer).toBeNull();

        // ethers รวมคำขอ RPC ด้วย timer — รอจนผู้เซ็นของบัญชีใหม่มาถึง
        await vi.waitFor(() => expect(store.signer).not.toBeNull());
        expect((await store.signer.getAddress()).toLowerCase()).toBe(NEW);
    });

    it('keeps the signer when the wallet re-announces the same account', async () => {
        await store.tryReconnect();
        const before = store.signer;

        handlers.accountsChanged([OLD.toUpperCase().replace('0X', '0x')]);

        expect(store.signer).toBe(before);
    });

    it('reports an embedded wallet restored from storage as locked until it is unlocked', async () => {
        localStorage.setItem('tpix_wallet', JSON.stringify({ address: OLD, walletType: 'tpix_wallet' }));

        await store.tryReconnect();

        expect(store.isConnected).toBe(true);
        expect(store.isLocked).toBe(true);
    });
});
