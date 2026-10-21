/**
 * ตัวช่วยหน้าแอดมิน KYC ผู้สมัคร Validator
 * ปุ่มต้องตรงกับ require() ของสัญญา และ calldata ต้องเป็นสิ่งที่สัญญารับจริง
 */
import { describe, it, expect } from 'vitest';
import * as ethers from 'ethers';
import {
    availableActions, resolveSubmitHash, encodeKycCall, humanWalletError, isBytes32, KYC_WRITE_ABI,
} from '@/utils/validatorKyc';

const APPLICANT = '0xabc0000000000000000000000000000000000001';
const HASH = '0x' + 'ab'.repeat(32);

describe('availableActions', () => {
    it('follows the contract state machine', () => {
        expect(availableActions('ConsentGiven')).toEqual(['submit']);
        expect(availableActions('Submitted')).toEqual(['approve', 'reject']);
        for (const s of ['None', 'Approved', 'Rejected', 'Revoked', 'Unknown']) {
            expect(availableActions(s)).toEqual([]);
        }
    });
});

describe('resolveSubmitHash', () => {
    it('uses the site KYC hash when the site submission is approved', () => {
        const r = resolveSubmitHash({ site_kyc: { status: 'approved', doc_hash: HASH.toUpperCase().replace('0X', '0x'), uuid: 'u-1' } });
        expect(r).toEqual({ hash: HASH, uuid: 'u-1', manual: false, error: null });
    });

    it('needs a valid manual hash and a note otherwise', () => {
        const row = { site_kyc: { status: 'pending', doc_hash: null } };
        expect(resolveSubmitHash(row, '0x1234', 'x').error).toBeTruthy();
        expect(resolveSubmitHash(row, '0x' + '0'.repeat(64), 'x').error).toBeTruthy();
        expect(resolveSubmitHash(row, HASH, '   ').error).toMatch(/เหตุผล/);
        expect(resolveSubmitHash(row, HASH, 'ตรวจที่สำนักงาน')).toEqual({ hash: HASH, uuid: null, manual: true, error: null });
    });
});

describe('encodeKycCall', () => {
    const iface = new ethers.Interface(KYC_WRITE_ABI);

    it('encodes the three owner calls', () => {
        const submit = iface.parseTransaction({ data: encodeKycCall(ethers, 'submit', APPLICANT, { hash: HASH }) });
        expect(submit.name).toBe('submitKYC');
        expect(submit.args[0].toLowerCase()).toBe(APPLICANT);
        expect(submit.args[1]).toBe(HASH);

        expect(iface.parseTransaction({ data: encodeKycCall(ethers, 'approve', APPLICANT) }).name).toBe('approveKYC');

        const reject = iface.parseTransaction({ data: encodeKycCall(ethers, 'reject', APPLICANT, { reason: '  เอกสารไม่ชัด  ' }) });
        expect(reject.args[1]).toBe('เอกสารไม่ชัด');
    });

    it('refuses calls the contract would revert', () => {
        expect(() => encodeKycCall(ethers, 'submit', APPLICANT, { hash: '0x' + '0'.repeat(64) })).toThrow('KYC_HASH_REQUIRED');
        expect(() => encodeKycCall(ethers, 'reject', APPLICANT, { reason: ' ' })).toThrow('REJECT_REASON_REQUIRED');
        expect(() => encodeKycCall(ethers, 'drop', APPLICANT)).toThrow(/UNKNOWN_ACTION/);
    });

    it('matches the selectors the server decodes', () => {
        // ValidatorKycContract::selector() ฝั่ง PHP ใช้ signature เดียวกัน
        expect(iface.getFunction('submitKYC').selector).toBe(ethers.id('submitKYC(address,bytes32)').slice(0, 10));
        expect(iface.getFunction('approveKYC').selector).toBe(ethers.id('approveKYC(address)').slice(0, 10));
        expect(iface.getFunction('rejectKYC').selector).toBe(ethers.id('rejectKYC(address,string)').slice(0, 10));
    });
});

describe('humanWalletError', () => {
    it('never shows raw JSON', () => {
        expect(humanWalletError({ code: 4001 })).toMatch(/ยกเลิก/);
        expect(humanWalletError({ code: 'ACTION_REJECTED' })).toMatch(/ยกเลิก/);
        expect(humanWalletError(new Error('NOT_OWNER'))).toMatch(/owner/);
        expect(humanWalletError(new Error('WALLET_SWITCHED'))).toMatch(/สลับ/);
        expect(humanWalletError({ reason: 'Not submitted' })).toBe('สัญญาปฏิเสธ: Not submitted');
        expect(humanWalletError({ message: '{"jsonrpc":"2.0","error":{}}' })).toBe('ทำรายการไม่สำเร็จ — ลองใหม่อีกครั้ง');
    });

    it('isBytes32 rejects the zero hash', () => {
        expect(isBytes32(HASH)).toBe(true);
        expect(isBytes32('0x' + '0'.repeat(64))).toBe(false);
        expect(isBytes32(null)).toBe(false);
    });
});
