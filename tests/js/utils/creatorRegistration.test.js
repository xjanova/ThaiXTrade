/**
 * TPIX TRADE — ตัวช่วยหน้าลงทะเบียนผู้สร้างเหรียญ
 *
 * กัน: เซ็นด้วยกระเป๋าผิดใบ (สลับบัญชีระหว่างเปิดกระเป๋า) · กระเป๋าไม่ตอบแล้วปุ่มค้าง
 *      · ใบที่ถูกปฏิเสธเด้งเข้าฟอร์มทันทีจนผู้สร้างไม่เห็นเหตุผล · เปอร์เซ็นต์แสดงผิด
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import {
    blankCreatorForm,
    creatorStep,
    describeCreatorError,
    formatBps,
    signWithWallet,
} from '@/utils/creatorRegistration';

const WALLET = '0xAbCdEf0000000000000000000000000000000001';

const walletStoreFor = (address, sign = vi.fn(() => Promise.resolve('0xsig'))) => ({
    address,
    signer: { getAddress: vi.fn(() => Promise.resolve(address)), signMessage: sign },
});

describe('creatorStep', () => {
    it('ยังไม่มีใบ → ขั้นกระเป๋า', () => {
        expect(creatorStep(null, false)).toBe('wallet');
    });

    it('ใบร่างที่ยังไม่บันทึกฟอร์ม → ฟอร์ม · บันทึกแล้ว → ตรวจทาน/เซ็น', () => {
        expect(creatorStep({ status: 'draft', form_hash: null }, false)).toBe('form');
        expect(creatorStep({ status: 'draft', form_hash: 'h' }, false)).toBe('review');
        expect(creatorStep({ status: 'draft', form_hash: 'h' }, true)).toBe('form');
    });

    it('ส่งแล้วหรืออนุมัติแล้วโชว์สถานะเสมอ', () => {
        expect(creatorStep({ status: 'submitted', form_hash: 'h' }, true)).toBe('status');
        expect(creatorStep({ status: 'approved', form_hash: 'h' }, true)).toBe('status');
    });

    it('ถูกปฏิเสธโชว์เหตุผลก่อน จนกว่าจะกดแก้ไข', () => {
        expect(creatorStep({ status: 'rejected', form_hash: 'h' }, false)).toBe('status');
        expect(creatorStep({ status: 'rejected', form_hash: 'h' }, true)).toBe('form');
    });
});

describe('formatBps', () => {
    it('แปลง bps เป็นเปอร์เซ็นต์', () => {
        expect(formatBps(1000)).toBe('10');
        expect(formatBps(1250)).toBe('12.5');
        expect(formatBps(0)).toBe('0');
        expect(formatBps('abc')).toBe('0');
    });
});

describe('blankCreatorForm', () => {
    it('ค่าตั้งต้นเป็นบุคคลธรรมดาสัญชาติไทย ยังไม่ติ๊กคำรับรอง', () => {
        const f = blankCreatorForm();
        expect(f.applicant_type).toBe('individual');
        expect(f.nationality).toBe('TH');
        expect(f.declare_true).toBe(false);
        expect(f.is_pep).toBe(false);
    });

    it('ใส่ค่าจากใบเดิมให้แก้ต่อ และไม่รับคีย์แปลกปลอม', () => {
        const f = blankCreatorForm({ project_name: 'Siam Farm', applicant_type: 'company', private_key: 'x' });
        expect(f.project_name).toBe('Siam Farm');
        expect(f.applicant_type).toBe('company');
        expect(f).not.toHaveProperty('private_key');
    });
});

describe('signWithWallet', () => {
    it('เซ็นข้อความจากเซิร์ฟเวอร์แล้วคืน nonce + ลายเซ็น', async () => {
        const store = walletStoreFor(WALLET);
        const result = await signWithWallet(store, WALLET.toLowerCase(), () => Promise.resolve({ message: 'm', nonce: 'n1' }));

        expect(store.signer.signMessage).toHaveBeenCalledWith('m');
        expect(result).toEqual({ nonce: 'n1', signature: '0xsig' });
    });

    it('กระเป๋าที่เชื่อมไม่ใช่ใบที่ลงทะเบียน → ไม่ขอข้อความ ไม่เซ็น', async () => {
        const store = walletStoreFor('0x0000000000000000000000000000000000000002');
        const getChallenge = vi.fn();

        await expect(signWithWallet(store, WALLET, getChallenge)).rejects.toMatchObject({ code: 'WALLET_CHANGED' });
        expect(getChallenge).not.toHaveBeenCalled();
        expect(store.signer.signMessage).not.toHaveBeenCalled();
    });

    it('สลับบัญชีระหว่างเซ็น → ไม่ส่งลายเซ็นนั้น', async () => {
        const store = walletStoreFor(WALLET, vi.fn(async () => {
            store.address = '0x0000000000000000000000000000000000000003';
            return '0xsig';
        }));

        await expect(signWithWallet(store, WALLET, () => Promise.resolve({ message: 'm', nonce: 'n' })))
            .rejects.toMatchObject({ code: 'WALLET_CHANGED' });
    });

    it('ไม่มีกระเป๋า → NO_SIGNER', async () => {
        await expect(signWithWallet({ signer: null }, WALLET, vi.fn())).rejects.toMatchObject({ code: 'NO_SIGNER' });
    });

    it('กระเป๋าไม่ตอบ → หมดเวลา ไม่ค้าง', async () => {
        const store = walletStoreFor(WALLET, vi.fn(() => new Promise(() => {})));

        await expect(signWithWallet(store, WALLET, () => Promise.resolve({ message: 'm', nonce: 'n' }), 20))
            .rejects.toMatchObject({ code: 'SIGN_TIMEOUT' });
    });
});

describe('describeCreatorError', () => {
    it('ผู้ใช้กดยกเลิกในกระเป๋า', () => {
        expect(describeCreatorError({ code: 4001 })).toBe('page.tokenCreator.errors.signCancelled');
        expect(describeCreatorError({ code: 'ACTION_REJECTED' })).toBe('page.tokenCreator.errors.signCancelled');
    });

    it('ใช้ข้อความที่เซิร์ฟเวอร์แปลมาแล้ว', () => {
        expect(describeCreatorError({ response: { status: 403, data: { error: { message: 'ลายเซ็นไม่ตรง' } } } })).toBe('ลายเซ็นไม่ตรง');
    });

    it('ไม่โชว์ข้อความดิบของระบบ', () => {
        expect(describeCreatorError({ response: { status: 500, data: { message: 'SQLSTATE[HY000]' } } })).toBe('page.tokenCreator.errors.failed');
        expect(describeCreatorError({ response: { status: 429 } })).toBe('page.tokenCreator.errors.rateLimited');
        expect(describeCreatorError({ response: { status: 419 } })).toBe('page.tokenCreator.errors.sessionExpired');
        expect(describeCreatorError(new Error('Network Error'))).toBe('page.tokenCreator.errors.network');
    });
});
