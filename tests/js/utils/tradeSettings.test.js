/**
 * TPIX TRADE — ค่าตั้งค่าธุรกรรมจากหน้าตั้งค่าต้องมีผลจริง
 *
 * เดิมหน้าตั้งค่าบันทึก slippage / ระยะเวลาจำกัด ไว้เฉยๆ ไม่มีใครอ่าน
 * ค่าในเครื่องแก้มือได้ จึงต้องตรวจขอบเขตทุกครั้ง — ค่าเสียต้องตกไปใช้ค่าปริยาย
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
    TRADE_SETTINGS_KEY,
    preferredSlippage,
    parseSlippage,
    txDeadlineSeconds,
    parseDeadlineMinutes,
} from '@/utils/tradeSettings';

const save = value => localStorage.setItem(TRADE_SETTINGS_KEY, JSON.stringify(value));

beforeEach(() => localStorage.removeItem(TRADE_SETTINGS_KEY));

describe('preferredSlippage', () => {
    it('is null when the user never saved settings (callers keep their own default)', () => {
        expect(preferredSlippage()).toBeNull();
    });

    it('returns the saved value as a number', () => {
        save({ slippageTolerance: '1.0' });
        expect(preferredSlippage()).toBe(1);
    });

    it.each([['abc'], ['0'], ['-1'], ['80'], [''], [null]])('ignores the unusable value %s', (value) => {
        save({ slippageTolerance: value });
        expect(preferredSlippage()).toBeNull();
    });

    it('survives corrupt storage', () => {
        localStorage.setItem(TRADE_SETTINGS_KEY, '{not json');
        expect(preferredSlippage()).toBeNull();
    });

    it('accepts a trailing percent sign typed by hand', () => {
        expect(parseSlippage('0.3%')).toBe(0.3);
    });
});

describe('txDeadlineSeconds', () => {
    it('defaults to 20 minutes', () => {
        expect(txDeadlineSeconds()).toBe(1200);
    });

    it('uses the saved minutes', () => {
        save({ txDeadline: '5' });
        expect(txDeadlineSeconds()).toBe(300);
    });

    it.each([
        ['0', 20],
        ['abc', 20],
        ['-3', 20],
        ['0.4', 1],
        ['999', 180],
    ])('clamps %s to %i minutes', (value, minutes) => {
        expect(parseDeadlineMinutes(value)).toBe(minutes);
    });
});
