/**
 * TPIX TRADE — i18n integrity + live locale switching
 *
 * เจ้าของย้ำว่า "ภาษาไทย/อังกฤษต้องเปลี่ยนได้ถูกต้องทั้งหน้า" — เทสต์ชุดนี้กัน 3 อาการ:
 *  1. คีย์มีในภาษาเดียว → อีกภาษาโชว์ชื่อคีย์ดิบ เช่น `trade.form.buy`
 *  2. คีย์ที่มีตัวแปร {param} ไม่ตรงกันสองภาษา → ตัวเลขหาย
 *  3. คอมโพเนนต์ฝังข้อความไว้ตรงๆ → สลับภาษาแล้วไม่เปลี่ยน
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import th from '@/i18n/th.json';
import en from '@/i18n/en.json';
import { t, setLocale } from '@/Composables/useTranslation';
import RecentTrades from '@/Components/Trading/RecentTrades.vue';

/** แผ่คีย์ซ้อนชั้นให้เป็น 'a.b.c' เพื่อเทียบสองภาษาได้ตรงๆ */
function flatten(obj, prefix = '') {
    return Object.entries(obj).reduce((acc, [key, value]) => {
        const path = prefix ? `${prefix}.${key}` : key;
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            Object.assign(acc, flatten(value, path));
        } else {
            acc[path] = value;
        }
        return acc;
    }, {});
}

const thFlat = flatten(th);
const enFlat = flatten(en);

/** ชื่อตัวแปรใน {param} ของข้อความหนึ่ง */
const paramsOf = (text) => (String(text).match(/\{(\w+)\}/g) || []).sort().join(',');

afterEach(() => setLocale('en'));

describe('i18n key integrity', () => {
    it('every Thai key exists in English', () => {
        const missing = Object.keys(thFlat).filter(k => !(k in enFlat));
        expect(missing).toEqual([]);
    });

    it('every English key exists in Thai', () => {
        const missing = Object.keys(enFlat).filter(k => !(k in thFlat));
        expect(missing).toEqual([]);
    });

    it('no translation is left empty', () => {
        const empty = [...Object.entries(thFlat), ...Object.entries(enFlat)]
            .filter(([, v]) => typeof v === 'string' && v.trim() === '')
            .map(([k]) => k);
        expect(empty).toEqual([]);
    });

    it('placeholders match between the two languages', () => {
        const mismatched = Object.keys(thFlat)
            .filter(k => k in enFlat)
            .filter(k => paramsOf(thFlat[k]) !== paramsOf(enFlat[k]));
        expect(mismatched).toEqual([]);
    });

    it('covers the whole trade board and AI trade console', () => {
        // กันการลบคีย์ทิ้งโดยไม่ตั้งใจ — ทุกกลุ่มต้องยังอยู่ครบ
        ['trade.card', 'trade.layout', 'trade.market', 'trade.book', 'trade.form',
            'trade.recent', 'trade.tabs', 'trade.notice', 'trade.status']
            .forEach(group => {
                expect(Object.keys(thFlat).some(k => k.startsWith(`${group}.`))).toBe(true);
            });

        expect(Object.keys(thFlat).filter(k => k.startsWith('aiTrade.')).length).toBeGreaterThan(60);
    });
});

describe('t() behaviour', () => {
    it('substitutes named parameters', () => {
        setLocale('en');
        expect(t('trade.form.buySymbol', { symbol: 'BTC' })).toBe('Buy BTC');

        setLocale('th');
        expect(t('trade.form.buySymbol', { symbol: 'BTC' })).toContain('BTC');
    });

    it('returns different text for the two languages', () => {
        setLocale('en');
        const english = t('trade.card.book');
        setLocale('th');
        const thai = t('trade.card.book');

        expect(english).toBe('Order Book');
        expect(thai).not.toBe(english);
    });
});

/**
 * คีย์ที่หาไม่เจอต้องไม่ทำให้ทั้งหน้าหายไป
 *
 * เกิดจริงมาแล้วในหน้า /ai-trade: `t(tierLabel[s.tier])` โดยที่ map ไม่มีคีย์ของ
 * ระดับ `free` → t(undefined) → `path.split` โยน TypeError → Vue ทิ้งทั้งต้นไม้
 * เหลือจอว่าง ป้ายเล็กๆ อันเดียวแลกกับทั้งหน้า
 */
describe('a missing key never takes the page down', () => {
    it.each([
        ['undefined', undefined],
        ['null', null],
        ['a number', 42],
        ['an empty string', ''],
        ['an object', {}],
    ])('survives %s as the key', (_label, key) => {
        expect(() => t(key)).not.toThrow();
        expect(typeof t(key)).toBe('string');
    });

    it('still falls back to the key itself when it is a real string', () => {
        expect(t('this.key.does.not.exist')).toBe('this.key.does.not.exist');
    });

    it('never renders the literal text "undefined"', () => {
        expect(t(undefined)).toBe('');
        expect(t(null)).toBe('');
    });
});

describe('components react to a language switch', () => {
    it('re-renders trading labels when the locale changes', async () => {
        setLocale('en');
        const wrapper = mount(RecentTrades, { props: { symbol: 'BTC/USDT' } });

        expect(wrapper.text()).toContain('All');
        expect(wrapper.text()).toContain('Time');

        setLocale('th');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('ทั้งหมด');
        expect(wrapper.text()).toContain('เวลา');
        expect(wrapper.text()).not.toContain('Time');
    });
});
