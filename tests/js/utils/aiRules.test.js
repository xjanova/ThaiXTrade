/**
 * TPIX TRADE — ตัวช่วยจัดการกฎของโหมด "วางกลยุทธ์เอง" (resources/js/utils/aiRules.js)
 *
 * กัน:
 *  - กฎที่ยังผิด (ไม่มีเงื่อนไขเข้า · ตัวเลขนอกช่วง · EMA กลับด้าน) หลุดไปถึงปุ่มบันทึก/ทดสอบโดยไม่มีคำเตือน
 *  - ค่าที่ผู้ใช้พิมพ์ถูกแก้ให้เงียบๆ ก่อนแสดง (ต้องเห็นค่าที่พิมพ์พร้อมคำเตือน)
 *  - บล็อกที่ระบบไม่รองรับแล้วหายไปจากจอ ทั้งที่บอทจะไม่เปิดไม้เพราะมัน
 *  - ธง invalid หรือคีย์แปลกปลอมถูกส่งกลับไปให้เซิร์ฟเวอร์
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import {
    emptyRules, normalizeRules, rulesPayload, rulesProblems, blockProblems, blocksFor, newBlock,
    maxBlocks, sameRules, cloneValue, timeframeMinutes, problemMessage,
} from '@/utils/aiRules';
import { RULE_SCHEMA, validRules } from '../fixtures/aiRuleSchema';

describe('normalizeRules', () => {
    it('turns nothing into an empty rule set with the default match modes', () => {
        expect(normalizeRules(null, RULE_SCHEMA)).toEqual(emptyRules());
        expect(normalizeRules('rsi < 30', RULE_SCHEMA)).toEqual(emptyRules());
        expect(emptyRules().entry.match).toBe('all');
        expect(emptyRules().exit.match).toBe('any');
    });

    it('fills missing fields with defaults in schema order', () => {
        const rules = normalizeRules({ entry: { blocks: [{ type: 'rsi', level: 35 }] } }, RULE_SCHEMA);

        expect(rules.entry.blocks[0]).toEqual({ type: 'rsi', op: 'below', period: 14, level: 35 });
        expect(Object.keys(rules.entry.blocks[0])).toEqual(['type', 'op', 'period', 'level']);
    });

    it('keeps what the user typed, even out of range', () => {
        const rules = normalizeRules({ entry: { blocks: [{ type: 'rsi', op: 'below', period: 14, level: 500 }] } }, RULE_SCHEMA);

        expect(rules.entry.blocks[0].level).toBe(500);
    });

    it('keeps a block the server no longer supports so the user can see and remove it', () => {
        const rules = normalizeRules({ entry: { blocks: [{ type: 'retired', x: 1 }] } }, RULE_SCHEMA);

        expect(rules.entry.blocks).toEqual([{ type: 'retired', x: 1 }]);
    });

    it('does not mutate what it was given', () => {
        const source = validRules();
        const copy = JSON.parse(JSON.stringify(source));

        normalizeRules(source, RULE_SCHEMA).entry.blocks[0].fast = 99;

        expect(source).toEqual(copy);
    });
});

describe('rulesPayload', () => {
    it('sends only entry and exit', () => {
        const payload = rulesPayload({ ...validRules(), invalid: true, script: 'x' }, RULE_SCHEMA);

        expect(Object.keys(payload)).toEqual(['entry', 'exit']);
        expect(payload).toEqual(validRules());
    });
});

describe('rulesProblems', () => {
    it('accepts a valid rule set', () => {
        expect(rulesProblems(validRules(), RULE_SCHEMA)).toEqual([]);
    });

    it('reports a rule set with no entry condition first', () => {
        const problems = rulesProblems({ exit: validRules().exit }, RULE_SCHEMA);

        expect(problems[0]).toMatchObject({ side: 'entry', code: 'no_entry' });
        expect(rulesProblems(null, RULE_SCHEMA)[0].code).toBe('no_entry');
    });

    it('reports numbers out of range, blanks and fractions in whole-number fields', () => {
        const check = (block) => blockProblems(block, 'entry', RULE_SCHEMA).map((p) => p.code);

        expect(check({ type: 'rsi', op: 'below', period: 14, level: 500 })).toEqual(['range']);
        expect(check({ type: 'rsi', op: 'below', period: 14, level: '' })).toEqual(['range']);
        expect(check({ type: 'rsi', op: 'below', period: 1, level: 30 })).toEqual(['range']);
        expect(check({ type: 'ema_cross', when: 'crosses_above', fast: 12.5, slow: 26 })).toEqual(['integer']);
        expect(check({ type: 'rsi', op: 'below', period: 14, level: 30.5 })).toEqual([]);
    });

    it('reports an option that is not on the list', () => {
        expect(blockProblems({ type: 'rsi', op: 'sideways', period: 14, level: 30 }, 'entry', RULE_SCHEMA))
            .toEqual([{ code: 'option', field: 'op' }]);
    });

    it('reports a fast EMA that is not shorter than the slow one', () => {
        expect(blockProblems({ type: 'ema_cross', when: 'crosses_above', fast: 26, slow: 26 }, 'entry', RULE_SCHEMA))
            .toEqual([{ code: 'ema_order' }]);
        expect(blockProblems({ type: 'ema_cross', when: 'crosses_above', fast: 12, slow: 26 }, 'entry', RULE_SCHEMA)).toEqual([]);
    });

    it('reports unknown blocks, blocks on the wrong side and too many blocks', () => {
        expect(blockProblems({ type: 'retired' }, 'entry', RULE_SCHEMA)).toEqual([{ code: 'unknown_type' }]);
        expect(blockProblems({ type: 'interval', bars: 6 }, 'exit', RULE_SCHEMA)).toEqual([{ code: 'wrong_side' }]);

        const rules = validRules();
        rules.exit.blocks = [1, 2, 3].map(() => ({ type: 'rsi', op: 'above', period: 14, level: 70 }));

        expect(rulesProblems(rules, RULE_SCHEMA)).toContainEqual({ side: 'exit', code: 'too_many', max: 2 });
    });

    it('says where a block problem is', () => {
        const rules = validRules();
        rules.exit.blocks[0].level = 0;

        expect(rulesProblems(rules, RULE_SCHEMA)).toEqual([
            { side: 'exit', index: 0, type: 'rsi', code: 'range', field: 'level', min: 1, max: 99 },
        ]);
    });
});

describe('problemMessage', () => {
    const t = (key, params = {}) => `${key}|${Object.values(params).filter((v) => v !== undefined).join(',')}`;

    it('names the field in the language in use', () => {
        const problem = { type: 'rsi', code: 'range', field: 'level', min: 1, max: 99 };

        expect(problemMessage(problem, RULE_SCHEMA, t, 'en')).toBe('aiTrade.rules.problemRange|Level,1,99');
        expect(problemMessage(problem, RULE_SCHEMA, t, 'th')).toBe('aiTrade.rules.problemRange|ระดับ,1,99');
    });

    it('covers every problem code the validators can produce', () => {
        ['unknown_type', 'wrong_side', 'range', 'integer', 'option', 'ema_order', 'no_entry', 'too_many'].forEach((code) => {
            expect(problemMessage({ code }, RULE_SCHEMA, t, 'en')).toContain('aiTrade.rules.');
        });
        expect(problemMessage({ code: 'something_else' }, RULE_SCHEMA, t, 'en')).toBe('');
    });
});

describe('schema helpers', () => {
    it('offers only the blocks allowed on each side', () => {
        expect(blocksFor(RULE_SCHEMA, 'entry').map((b) => b.type)).toEqual(['ema_cross', 'rsi', 'interval']);
        expect(blocksFor(RULE_SCHEMA, 'exit').map((b) => b.type)).toEqual(['ema_cross', 'rsi']);
        expect(blocksFor(null, 'entry')).toEqual([]);
    });

    it('creates a new block from the defaults', () => {
        expect(newBlock(RULE_SCHEMA, 'ema_cross')).toEqual({ type: 'ema_cross', when: 'crosses_above', fast: 12, slow: 26 });
        expect(newBlock(RULE_SCHEMA, 'nope')).toBeNull();
    });

    it('reads the block cap with a safe fallback', () => {
        expect(maxBlocks(RULE_SCHEMA, 'entry')).toBe(3);
        expect(maxBlocks(null, 'entry')).toBe(6);
    });

    it('knows how long a candle is', () => {
        expect(timeframeMinutes('4h')).toBe(240);
        expect(timeframeMinutes('2w')).toBeNull();
    });
});

describe('sameRules and cloneValue', () => {
    it('compares rule sets by content, not by reference or key order', () => {
        const reordered = { exit: validRules().exit, entry: { blocks: validRules().entry.blocks, match: 'all' } };

        expect(sameRules(validRules(), reordered, RULE_SCHEMA)).toBe(true);
        expect(sameRules(validRules(), JSON.parse(JSON.stringify(validRules())), RULE_SCHEMA)).toBe(true);

        const changed = validRules();
        changed.entry.blocks[0].fast = 9;
        expect(sameRules(validRules(), changed, RULE_SCHEMA)).toBe(false);
    });

    it('deep-copies objects and passes plain values through', () => {
        const source = validRules();
        const copy = cloneValue(source);

        copy.entry.blocks[0].fast = 99;

        expect(source.entry.blocks[0].fast).toBe(12);
        expect(cloneValue(5)).toBe(5);
        expect(cloneValue(null)).toBeNull();
        expect(cloneValue('x')).toBe('x');
    });
});
