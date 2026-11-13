/**
 * TPIX TRADE — AiRuleBuilder tests (ตัวสร้างกฎของโหมด "วางกลยุทธ์เอง")
 *
 * กัน:
 *  - แก้กฎในฟอร์มแล้วไปแก้ของต้นฉบับ (เทมเพลตในแคตตาล็อกที่ทุกฟอร์มใช้ร่วมกัน)
 *  - เพิ่มเงื่อนไขได้เกินเพดาน หรือเลือกบล็อกที่ใช้กับฝั่งนั้นไม่ได้
 *  - ค่าที่ผิดช่วงถูกซ่อน/แก้ให้เงียบๆ แทนที่จะเตือน
 *  - บล็อกที่ระบบไม่รองรับแล้วหายไปจากจอ
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AiRuleBuilder from '@/Components/AiTrade/AiRuleBuilder.vue';
import { RULE_SCHEMA, validRules } from '../fixtures/aiRuleSchema';

function mountBuilder(modelValue = validRules(), props = {}) {
    return mount(AiRuleBuilder, { props: { modelValue, schema: RULE_SCHEMA, timeframe: '4h', ...props } });
}

/** กฎชุดล่าสุดที่คอมโพเนนต์ส่งออก */
function lastEmitted(wrapper) {
    const events = wrapper.emitted('update:modelValue') ?? [];

    return events.length ? events[events.length - 1][0] : null;
}

const byId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`);

describe('AiRuleBuilder — what it shows', () => {
    it('draws both sides with their blocks, labels and counts', () => {
        const wrapper = mountBuilder();

        expect(byId(wrapper, 'rule-group-entry').text()).toContain('Entry conditions');
        expect(byId(wrapper, 'rule-group-exit').text()).toContain('Exit conditions');
        expect(byId(wrapper, 'rule-count-entry').text()).toBe('1/3 conditions');
        expect(byId(wrapper, 'rule-count-exit').text()).toBe('1/2 conditions');
        expect(byId(wrapper, 'rule-type-entry-0').element.value).toBe('ema_cross');
        expect(byId(wrapper, 'rule-field-entry-0-fast').element.value).toBe('12');
        expect(byId(wrapper, 'rule-field-exit-0-level').element.value).toBe('70');
        expect(byId(wrapper, 'rule-block-entry-0').text()).toContain('Fast EMA (bars)');
        expect(byId(wrapper, 'rule-block-entry-0').text()).toContain('Compares two EMAs');
    });

    it('offers the scheduled-buy block on the entry side only', () => {
        const wrapper = mountBuilder();
        const options = (id) => byId(wrapper, id).findAll('option').map((o) => o.element.value);

        expect(options('rule-type-entry-0')).toEqual(['ema_cross', 'rsi', 'interval']);
        expect(options('rule-type-exit-0')).toEqual(['ema_cross', 'rsi']);
    });

    it('warns when there is no entry condition and explains an empty exit side', () => {
        const wrapper = mountBuilder(null);

        expect(byId(wrapper, 'rule-empty-entry').text()).toContain('will never open a trade');
        expect(byId(wrapper, 'rule-empty-exit').text()).toContain('take profit / stop loss');
    });

    it('shows how long a bar count is on the chosen timeframe', async () => {
        const rules = validRules();
        rules.entry.blocks = [{ type: 'interval', bars: 6 }];

        const wrapper = mountBuilder(rules, { timeframe: '4h' });
        expect(byId(wrapper, 'rule-block-entry-0').text()).toContain('≈ 24 h');

        await wrapper.setProps({ timeframe: '1d' });
        expect(byId(wrapper, 'rule-block-entry-0').text()).toContain('≈ 6 d');
    });

    it('keeps a block the server no longer supports visible, flagged and removable', async () => {
        const rules = validRules();
        rules.entry.blocks.push({ type: 'retired_block', x: 1 });

        const wrapper = mountBuilder(rules);

        expect(byId(wrapper, 'rule-block-entry-1').text()).toContain('retired_block');
        expect(byId(wrapper, 'rule-problem-entry-1').text()).toContain('no longer supported');
        expect(byId(wrapper, 'rule-type-entry-1').exists()).toBe(false);

        await byId(wrapper, 'rule-remove-entry-1').trigger('click');
        expect(lastEmitted(wrapper).entry.blocks).toHaveLength(1);
    });
});

describe('AiRuleBuilder — editing', () => {
    it('adds a block with its defaults and stops at the cap', async () => {
        const wrapper = mountBuilder();

        await byId(wrapper, 'rule-add-entry').trigger('click');
        expect(lastEmitted(wrapper).entry.blocks[1]).toEqual({ type: 'ema_cross', when: 'crosses_above', fast: 12, slow: 26 });

        await byId(wrapper, 'rule-add-entry').trigger('click');
        expect(lastEmitted(wrapper).entry.blocks).toHaveLength(3);
        expect(byId(wrapper, 'rule-add-entry').attributes('disabled')).toBeDefined();
        expect(byId(wrapper, 'rule-group-entry').text()).toContain('All 3 slots are used');

        const before = wrapper.emitted('update:modelValue').length;
        await byId(wrapper, 'rule-add-entry').trigger('click');
        expect(wrapper.emitted('update:modelValue')).toHaveLength(before);
    });

    it('removes a block', async () => {
        const wrapper = mountBuilder();

        await byId(wrapper, 'rule-remove-exit-0').trigger('click');

        expect(lastEmitted(wrapper).exit.blocks).toEqual([]);
        expect(lastEmitted(wrapper).entry.blocks).toHaveLength(1);
    });

    it('switching the block type starts from the new type defaults', async () => {
        const wrapper = mountBuilder();

        await byId(wrapper, 'rule-type-entry-0').setValue('rsi');

        expect(lastEmitted(wrapper).entry.blocks[0]).toEqual({ type: 'rsi', op: 'below', period: 14, level: 30 });
    });

    it('emits numbers for number fields and the chosen option for selects', async () => {
        const wrapper = mountBuilder();

        await byId(wrapper, 'rule-field-entry-0-fast').setValue('9');
        expect(lastEmitted(wrapper).entry.blocks[0].fast).toBe(9);

        await byId(wrapper, 'rule-field-entry-0-when').setValue('crosses_below');
        expect(lastEmitted(wrapper).entry.blocks[0]).toEqual({ type: 'ema_cross', when: 'crosses_below', fast: 9, slow: 26 });
    });

    it('changes the match mode of one side only', async () => {
        const wrapper = mountBuilder();

        await byId(wrapper, 'rule-match-entry').setValue('any');

        expect(lastEmitted(wrapper).entry.match).toBe('any');
        expect(lastEmitted(wrapper).exit.match).toBe('any');
        expect(byId(wrapper, 'rule-match-entry').findAll('option').map((o) => o.text()))
            .toEqual(['all of these are true', 'any of these is true']);
    });

    it('never mutates the rules it was given', async () => {
        const source = validRules();
        const snapshot = JSON.parse(JSON.stringify(source));
        const wrapper = mountBuilder(source);

        await byId(wrapper, 'rule-field-entry-0-fast').setValue('9');
        await byId(wrapper, 'rule-add-entry').trigger('click');
        await byId(wrapper, 'rule-remove-exit-0').trigger('click');

        expect(source).toEqual(snapshot);
    });

    it('hands out a copy — changing the emitted rules does not change the next emit', async () => {
        const wrapper = mountBuilder();

        await byId(wrapper, 'rule-field-entry-0-fast').setValue('9');
        lastEmitted(wrapper).entry.blocks[0].fast = 77;

        await byId(wrapper, 'rule-field-entry-0-slow').setValue('30');
        expect(lastEmitted(wrapper).entry.blocks[0]).toMatchObject({ fast: 9, slow: 30 });
    });

    it('emits only the entry and exit sides', async () => {
        const wrapper = mountBuilder({ ...validRules(), invalid: true });

        await byId(wrapper, 'rule-field-entry-0-fast').setValue('9');

        expect(Object.keys(lastEmitted(wrapper))).toEqual(['entry', 'exit']);
    });
});

describe('AiRuleBuilder — problems', () => {
    it('flags a value out of range and keeps what was typed', async () => {
        const wrapper = mountBuilder();

        await byId(wrapper, 'rule-field-exit-0-level').setValue('500');

        expect(lastEmitted(wrapper).exit.blocks[0].level).toBe(500);
        expect(byId(wrapper, 'rule-problem-exit-0').text()).toBe('Level must be between 1 and 99');
        expect(byId(wrapper, 'rule-field-exit-0-level').element.value).toBe('500');
    });

    it('flags an emptied number field instead of treating it as zero', async () => {
        const wrapper = mountBuilder();

        await byId(wrapper, 'rule-field-exit-0-level').setValue('');

        expect(lastEmitted(wrapper).exit.blocks[0].level).toBe('');
        expect(byId(wrapper, 'rule-problem-exit-0').exists()).toBe(true);
    });

    it('flags a fast EMA that is not shorter than the slow EMA', async () => {
        const wrapper = mountBuilder();

        expect(byId(wrapper, 'rule-problem-entry-0').exists()).toBe(false);

        await byId(wrapper, 'rule-field-entry-0-fast').setValue('40');

        expect(byId(wrapper, 'rule-problem-entry-0').text()).toBe('The fast EMA must be shorter than the slow EMA');
    });
});

describe('AiRuleBuilder — rules replaced from outside', () => {
    it('redraws when the parent swaps in another rule set', async () => {
        const wrapper = mountBuilder();
        const other = { entry: { match: 'any', blocks: [{ type: 'interval', bars: 6 }] }, exit: { match: 'any', blocks: [] } };

        await wrapper.setProps({ modelValue: other });

        expect(byId(wrapper, 'rule-type-entry-0').element.value).toBe('interval');
        expect(byId(wrapper, 'rule-match-entry').element.value).toBe('any');
        expect(byId(wrapper, 'rule-empty-exit').exists()).toBe(true);
    });

    it('draws nothing to pick from until the block list has loaded', async () => {
        const wrapper = mountBuilder(null, { schema: null });

        expect(byId(wrapper, 'rule-add-entry').attributes('disabled')).toBeDefined();

        await wrapper.setProps({ schema: RULE_SCHEMA });
        expect(byId(wrapper, 'rule-add-entry').attributes('disabled')).toBeUndefined();
    });
});
