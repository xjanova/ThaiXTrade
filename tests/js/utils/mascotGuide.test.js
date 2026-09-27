/**
 * TPIX TRADE — บท/ตำแหน่งบับเบิ้ลของน้อง TPIX
 *
 * กัน 4 อาการ:
 *  1. บับเบิ้ลล้นจอ (เลยขอบขวา/ล่าง หรือลอดใต้แถบนำทาง)
 *  2. พิมพ์ทีละตัวแล้ววรรณยุกต์ไทยลอยเดี่ยว (ตัดตาม code unit แทน grapheme)
 *  3. ลิงก์จากคำตอบ AI พาออกนอกเว็บ / javascript:
 *  4. ชิปของบทแนะนำชี้ไปหน้าที่ไม่มีจริง
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
    SECTIONS, TOUR_ORDER, POKE_LINES, placeBubble, graphemes, mouthFor, typeSpeed, cleanReply, isInternalPath, dockHeight,
} from '@/Components/Home/mascotGuide';
import th from '@/i18n/th.json';
import en from '@/i18n/en.json';

const get = (obj, path) => path.split('.').reduce((o, k) => o?.[k], obj);

describe('placeBubble', () => {
    const base = { vw: 1440, vh: 900, top: 64, bubble: { w: 300, h: 140 } };

    it('sits on the left of the mascot when there is room', () => {
        const p = placeBubble({ ...base, head: { x: 1300, y: 500 }, box: { x: 1200, y: 400, w: 200, h: 300 } });
        expect(p.side).toBe('left');
        expect(p.x + base.bubble.w).toBeLessThanOrEqual(1200 + 200 * 0.3 + 0.001);
        expect(p.x).toBeGreaterThanOrEqual(8);
    });

    it('prefers the right side when the content is on the mascot’s right', () => {
        const p = placeBubble({ ...base, prefer: 'right', head: { x: 100, y: 500 }, box: { x: 20, y: 400, w: 200, h: 300 } });
        expect(p.side).toBe('right');
        expect(p.x + base.bubble.w).toBeLessThanOrEqual(base.vw - 8);
    });

    it('moves above the head when neither side fits (phones)', () => {
        const p = placeBubble({ vw: 375, vh: 700, top: 64, bubble: { w: 300, h: 120 }, head: { x: 300, y: 560 }, box: { x: 250, y: 520, w: 110, h: 170 } });
        expect(p.side).toBe('above');
        expect(p.x).toBeGreaterThanOrEqual(8);
        expect(p.x + 300).toBeLessThanOrEqual(375 - 8);
        expect(p.y + 120).toBeLessThanOrEqual(520 + 170 * 0.04 + 0.001);
    });

    it('never goes under the navigation bar', () => {
        const p = placeBubble({ ...base, head: { x: 1300, y: 10 }, box: { x: 1200, y: -40, w: 200, h: 300 } });
        expect(p.y).toBeGreaterThanOrEqual(64 + 8);
    });

    it('keeps the tail inside the bubble', () => {
        const p = placeBubble({ ...base, head: { x: 1300, y: 890 }, box: { x: 1200, y: 600, w: 200, h: 300 } });
        expect(p.tail).toBeGreaterThanOrEqual(18);
        expect(p.tail).toBeLessThanOrEqual(base.bubble.h - 18);
    });
});

describe('typing helpers', () => {
    it('splits Thai into visible characters, keeping tone marks with their consonant', () => {
        const g = graphemes('น้อง');
        expect(g.join('')).toBe('น้อง');
        // "น้" ต้องเป็นก้อนเดียว วรรณยุกต์ไม่หลุดออกมาเดี่ยวๆ
        expect(g).toContain('น้');
        expect(g.every((ch) => !/^[่-๋]$/.test(ch))).toBe(true);
    });

    it('closes the mouth on spaces and punctuation', () => {
        expect(mouthFor(' ')).toBe(0);
        expect(mouthFor('!')).toBe(0);
        expect(mouthFor('a')).toBe(1);
        expect(mouthFor('ก')).toBeGreaterThan(0);
    });

    it('types long answers faster but within bounds', () => {
        expect(typeSpeed(10)).toBe(30);
        expect(typeSpeed(10000)).toBe(95);
        expect(typeSpeed(120)).toBeGreaterThan(30);
    });

    it('strips markdown from AI answers without turning them into HTML', () => {
        expect(cleanReply('## หัวข้อ\n**ตัวหนา** และ `code`\n\n\n\nจบ')).toBe('หัวข้อ\nตัวหนา และ code\n\nจบ');
        expect(cleanReply('<b>x</b>')).toBe('<b>x</b>');
    });

    it('accepts only internal paths', () => {
        expect(isInternalPath('/trade')).toBe(true);
        expect(isInternalPath('/trade/BTC-USDT')).toBe(true);
        expect(isInternalPath('//evil.example')).toBe(false);
        expect(isInternalPath('https://evil.example')).toBe(false);
        expect(isInternalPath('javascript:alert(1)')).toBe(false);
        expect(isInternalPath('/a?b=1')).toBe(false);
    });

    it('docks smaller on phones', () => {
        expect(dockHeight(375)).toBeLessThan(dockHeight(1440));
    });
});

describe('mascot script', () => {
    const routes = readFileSync(resolve(process.cwd(), 'routes/web.php'), 'utf8');
    const routeExists = (href) => {
        const [, first, second] = href.split('/');
        if (second) return routes.includes(`'/${first}/${second}'`) || routes.includes(`prefix('${first}')`) && routes.includes(`'/${second}'`);
        return routes.includes(`'/${first}'`) || routes.includes(`prefix('${first}')`);
    };

    it('has a line for every tour stop, in both languages', () => {
        for (const key of TOUR_ORDER) {
            const sec = SECTIONS[key];
            expect(sec, key).toBeTruthy();
            expect(typeof get(th, sec.line)).toBe('string');
            expect(typeof get(en, sec.line)).toBe('string');
        }
        for (const k of POKE_LINES) {
            expect(typeof get(th, k)).toBe('string');
            expect(typeof get(en, k)).toBe('string');
        }
    });

    it('only links chips to pages that exist', () => {
        for (const sec of Object.values(SECTIONS)) {
            for (const chip of sec.chips) {
                expect(typeof get(en, chip.label), chip.label).toBe('string');
                if (chip.href) expect(routeExists(chip.href), chip.href).toBe(true);
            }
        }
    });
});
