/**
 * TPIX TRADE — ตัวช่วย Launchpad + คำแปล TH/EN ของ launchpad ครบคู่
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import th from '@/i18n/launchpad/th.json';
import en from '@/i18n/launchpad/en.json';
import { t, setLocale } from '@/Composables/useTranslation';
import {
    ERROR_CODES, apiError, normalizeAmount, sourceUnits, saleState, formatDecimal, unitsToDecimal, soldPercent, estimateTokens, walletErrorKey,
} from '@/utils/launchpad';

function flatten(obj, prefix = '') {
    return Object.entries(obj).reduce((acc, [key, value]) => {
        const path = prefix ? `${prefix}.${key}` : key;
        if (value && typeof value === 'object' && !Array.isArray(value)) Object.assign(acc, flatten(value, path));
        else acc[path] = value;
        return acc;
    }, {});
}
const paramsOf = (text) => (String(text).match(/\{(\w+)\}/g) || []).sort().join(',');

describe('launchpad i18n', () => {
    const thFlat = flatten(th);
    const enFlat = flatten(en);

    it('has the same keys and placeholders in Thai and English, none empty', () => {
        expect(Object.keys(thFlat).filter((k) => !(k in enFlat))).toEqual([]);
        expect(Object.keys(enFlat).filter((k) => !(k in thFlat))).toEqual([]);
        expect(Object.entries({ ...thFlat, ...enFlat }).filter(([, v]) => String(v).trim() === '')).toEqual([]);
        expect(Object.keys(thFlat).filter((k) => paramsOf(thFlat[k]) !== paramsOf(enFlat[k]))).toEqual([]);
    });

    it('translates every API error code', () => {
        expect(ERROR_CODES.filter((c) => !enFlat[`errors.${c}`] || !thFlat[`errors.${c}`])).toEqual([]);
    });

    it('is reachable through t() under launchpad.* in both languages', () => {
        setLocale('th');
        expect(t('launchpad.buy.sign')).toBe('เซ็นคำสั่งซื้อ');
        setLocale('en');
        expect(t('launchpad.buy.sign')).toBe('Sign order');
        // ไฟล์หลักยังอยู่ครบ
        expect(t('common.cancel')).toBe('Cancel');
    });
});

describe('launchpad amounts', () => {
    it('accepts up to 6 decimals and nothing else', () => {
        expect(normalizeAmount('25.5')).toBe('25.5');
        expect(normalizeAmount(' 1,000 ')).toBe('1000');
        expect(normalizeAmount('007')).toBe('7');
        expect(normalizeAmount('0.000001')).toBe('0.000001');
        for (const bad of ['', '0', '0.0', '-1', '1e3', '1.1234567', 'abc', '1.']) expect(normalizeAmount(bad)).toBeNull();
    });

    it('scales the 6-decimal order amount to the source chain decimals exactly', () => {
        expect(sourceUnits('25500000', 18)).toBe(25_500_000n * 10n ** 12n);
        expect(sourceUnits('25500000', 6)).toBe(25_500_000n);
        expect(sourceUnits('25500000', 2)).toBe(2550n);
        expect(() => sourceUnits('25500001', 2)).toThrow('AMOUNT_NOT_REPRESENTABLE');
    });

    it('formats huge token amounts without float rounding', () => {
        expect(formatDecimal('100000000000.123456789', 4)).toBe('100,000,000,000.1234');
        expect(formatDecimal('666666.666666666666666666', 2)).toBe('666,666.66');
        expect(formatDecimal('12', 2)).toBe('12');
        expect(formatDecimal('oops')).toBe('—');
        expect(unitsToDecimal('100000000000000000000000000000', 18)).toBe('100000000000');
        expect(unitsToDecimal('1500000', 6)).toBe('1.5');
    });

    it('estimates tokens and sale progress with BigInt math', () => {
        expect(estimateTokens('1.5', '666666.666666666666666666')).toBe('999999.999999999999999999');
        expect(estimateTokens('bad', '1')).toBeNull();
        expect(soldPercent('25000000000000000000000000000', '100000000000000000000000000000')).toBe(25);
        expect(soldPercent('5', '0')).toBe(0);
    });
});

describe('launchpad state + errors', () => {
    it('tells upcoming / live / ended apart', () => {
        const now = Date.parse('2026-11-06T12:00:00Z');
        expect(saleState({ starts_at: '2026-11-07T00:00:00Z', ends_at: '2026-12-01T00:00:00Z' }, now)).toBe('upcoming');
        expect(saleState({ starts_at: '2026-11-01T00:00:00Z', ends_at: '2026-12-01T00:00:00Z' }, now)).toBe('live');
        expect(saleState({ starts_at: '2026-10-01T00:00:00Z', ends_at: '2026-11-06T12:00:00Z' }, now)).toBe('ended');
        expect(saleState({}, now)).toBe('ended');
    });

    it('maps API errors to translation keys and never to raw server text', () => {
        const err = (status, data) => ({ response: { status, data } });
        expect(apiError(err(422, { error: { code: 'SOLD_OUT', details: { remaining: '5' } } }))).toMatchObject({ key: 'launchpad.errors.SOLD_OUT', params: { remaining: '5' } });
        expect(apiError(err(500, { error: { code: 'SQLSTATE[HY000]' } })).key).toBe('launchpad.errors.SERVER_ERROR');
        expect(apiError(err(422, { message: 'x', errors: { amount: ['bad'] } })).key).toBe('launchpad.errors.INVALID_AMOUNT');
        expect(apiError(err(429, {})).key).toBe('launchpad.errors.TOO_MANY');
        expect(apiError(new Error('Network Error')).key).toBe('launchpad.errors.SERVER_ERROR');
    });

    it('maps wallet errors', () => {
        expect(walletErrorKey({ code: 4001 })).toBe('launchpad.buy.rejected');
        expect(walletErrorKey({ code: 'ACTION_REJECTED' })).toBe('launchpad.buy.rejected');
        expect(walletErrorKey(new Error('SWITCH_FAILED'))).toBe('launchpad.buy.switchFailed');
        expect(walletErrorKey({ shortMessage: 'insufficient funds for gas' })).toBe('launchpad.buy.insufficient');
        expect(walletErrorKey({})).toBe('launchpad.buy.txFailed');
    });
});
