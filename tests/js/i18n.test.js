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
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import th from '@/i18n/th.json';
import en from '@/i18n/en.json';

// อ่านข้อความดิบด้วย — JSON.parse กลืนคีย์ซ้ำไปแล้ว จับจากอ็อบเจ็กต์ไม่ได้
const i18nDir = resolve(process.cwd(), 'resources/js/i18n');
const thRaw = readFileSync(resolve(i18nDir, 'th.json'), 'utf8');
const enRaw = readFileSync(resolve(i18nDir, 'en.json'), 'utf8');
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
 * คีย์ซ้ำในไฟล์เดียวกันต้องไม่มี
 *
 * ⚠️ JSON.parse กลืนคีย์ซ้ำเงียบๆ — ตัวหลังทับตัวหน้าโดยไม่มีอะไรฟ้อง
 *    เทสต์ที่เทียบชุดคีย์ระหว่างสองภาษามองไม่เห็นเลย เพราะกว่าจะถึงมือเทสต์
 *    ก็เหลือคีย์เดียวไปแล้วทั้งสองไฟล์
 *
 * เกิดจริงมาแล้ว: เพิ่ม `trade.form.youReceive` ทับของเดิมที่แปลว่า "ได้รับประมาณ"
 * ป้ายในหน้าเทรดเลยเปลี่ยนความหมายจาก "ประมาณการ" เป็น "สุทธิ" ทั้งที่ตัวเลข
 * ยังเป็นประมาณการเหมือนเดิม
 *
 * ต้องอ่านจากข้อความดิบ ไม่ใช่จากอ็อบเจ็กต์ที่ parse แล้ว
 */
describe('translation files have no duplicate keys', () => {
    it.each([
        ['th.json', thRaw],
        ['en.json', enRaw],
    ])('%s defines every key once', (name, raw) => {
        const seen = new Map();
        const duplicates = [];
        const path = [];

        // เดินทีละบรรทัดเพื่อรู้ว่าคีย์อยู่ชั้นไหน — ชื่อเดียวกันคนละชั้นไม่ใช่ปัญหา
        const lines = raw.split('\n');
        lines.forEach((line, i) => {
            const open = (line.match(/\{/g) || []).length;
            const close = (line.match(/\}/g) || []).length;
            const match = line.match(/^\s*"([^"]+)"\s*:/);

            if (match) {
                const full = [...path, match[1]].join('.');
                if (seen.has(full)) duplicates.push(`${full} (บรรทัด ${seen.get(full)} และ ${i + 1})`);
                else seen.set(full, i + 1);

                if (open > close) path.push(match[1]);
            } else if (close > open) {
                path.pop();
            }
        });

        expect(duplicates, `พบคีย์ซ้ำใน ${name}`).toEqual([]);
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
