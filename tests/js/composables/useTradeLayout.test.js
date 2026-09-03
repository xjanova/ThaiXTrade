/**
 * TPIX TRADE — useTradeLayout tests
 * ผังการ์ดของหน้าเทรดต้องทนต่อค่าที่บันทึกไว้เก่า/เสีย โดยไม่ทำการ์ดหาย
 * และแถวที่วางการ์ดเคียงกันต้องไม่รับเกิน 2 ใบ ไม่ว่าผู้ใช้จะลากยังไง
 * Developed by Xman Studio
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useTradeLayout, TRADE_CARDS, COLUMNS, MAX_PER_ROW } from '@/Composables/useTradeLayout';

const ALL_IDS = TRADE_CARDS.map(c => c.id);

/** id ของการ์ดทุกใบในผัง (แบนราบข้ามคอลัมน์และแถว) */
function flatten(layout) {
    return COLUMNS.flatMap(col => layout.columns.value[col].flat());
}

/** ย้ายการ์ดหนึ่งใบด้วยท่าลากจริง — startDrag → drop → endDrag */
function move(layout, id, targetId, position) {
    layout.startDrag(id);
    layout.dropOnCard(targetId, position);
    layout.endDrag();
}

describe('useTradeLayout', () => {
    let layout;

    beforeEach(() => {
        layout = useTradeLayout();
        layout.reset();
    });

    it('starts with every card placed exactly once, one per row', () => {
        expect(flatten(layout).sort()).toEqual([...ALL_IDS].sort());
        COLUMNS.forEach(col => {
            layout.columns.value[col].forEach(row => expect(row).toHaveLength(1));
        });
    });

    it('moves a card to another column and keeps it unique', () => {
        move(layout, 'ai', 'chart', 'before');

        expect(layout.visible.value.center).toContain('ai');
        expect(layout.visible.value.left).not.toContain('ai');
        expect(flatten(layout).filter(id => id === 'ai')).toHaveLength(1);
    });

    it('drops after the target when the pointer is on the lower half', () => {
        // ทั้งสองใบอยู่คอลัมน์ far ตามผังเริ่มต้น (orders → trades)
        move(layout, 'trades', 'orders', 'after');

        const far = layout.visible.value.far;
        expect(far.indexOf('trades')).toBe(far.indexOf('orders') + 1);
    });

    it('appends to the end when dropped on empty column space', () => {
        layout.startDrag('book');
        layout.dropOnColumn('left');
        layout.endDrag();

        expect(layout.columns.value.left.at(-1)).toEqual(['book']);
    });

    it('never drops a card onto itself', () => {
        const before = JSON.stringify(layout.columns.value.right);

        move(layout, 'book', 'book', 'before');

        expect(JSON.stringify(layout.columns.value.right)).toBe(before);
    });

    // ── คอลัมน์ย่อย: การ์ด 2 ใบในแถวเดียวกัน ─────────────────────────────────
    it('places a card beside another one in the same row', () => {
        move(layout, 'book', 'form', 'right');

        expect(layout.columns.value.center).toEqual([['chart'], ['form', 'book']]);
        expect(layout.columns.value.right).toEqual([]);
    });

    it('drops on the left edge to land before the target inside its row', () => {
        move(layout, 'book', 'form', 'left');

        expect(layout.columns.value.center).toEqual([['chart'], ['book', 'form']]);
    });

    it('never puts more than two cards in one row — the third falls to a new row', () => {
        move(layout, 'book', 'form', 'right');
        move(layout, 'trades', 'form', 'right'); // แถวเต็มแล้ว

        expect(layout.columns.value.center).toEqual([['chart'], ['form', 'book'], ['trades']]);
        layout.columns.value.center.forEach(row => {
            expect(row.length).toBeLessThanOrEqual(MAX_PER_ROW);
        });
    });

    it('reorders inside a full row when the dragged card already sits there', () => {
        move(layout, 'book', 'form', 'right'); // ['form','book']
        move(layout, 'book', 'form', 'left'); // สลับที่กันเอง ไม่ตกไปแถวใหม่

        expect(layout.columns.value.center).toEqual([['chart'], ['book', 'form']]);
    });

    it('collapses the row when its last card leaves', () => {
        move(layout, 'book', 'form', 'right');
        move(layout, 'chart', 'market', 'before'); // แถว ['chart'] ต้องหายไปทั้งแถว

        expect(layout.columns.value.center).toEqual([['form', 'book']]);
        expect(layout.columns.value.left).toEqual([['chart'], ['market'], ['ai']]);
    });

    it('reports whether a row can still take another card', () => {
        expect(layout.canJoinRow('form', 'book')).toBe(true);

        move(layout, 'book', 'form', 'right');

        expect(layout.canJoinRow('form', 'trades')).toBe(false);
        // ใบที่อยู่ในแถวนั้นอยู่แล้วยังสลับที่ได้ ถึงแถวจะเต็ม
        expect(layout.canJoinRow('form', 'book')).toBe(true);
    });

    it('hidden cards drop out of the row without losing their slot', () => {
        move(layout, 'trades', 'orders', 'right'); // ['orders','trades']
        layout.toggleHidden('trades');

        expect(layout.visibleRows.value.far).toEqual([['orders']]);
        // ผังจริงยังจำที่เดิมไว้ — เลิกซ่อนแล้วต้องกลับไปข้าง orders ไม่ใช่ต่อท้าย
        expect(layout.columns.value.far).toEqual([['orders', 'trades']]);

        layout.toggleHidden('trades');
        expect(layout.visibleRows.value.far).toEqual([['orders', 'trades']]);
    });

    it('drops the split ratio once its card stops leading a row', () => {
        move(layout, 'book', 'form', 'right');
        layout.setRowSplit(['form', 'book'], 70);
        expect(layout.rowSplit.value.form).toBe(70);

        // form ไปอยู่ขวาของกราฟ → ไม่มีแถวไหนขึ้นต้นด้วย form อีก สัดส่วนต้องไม่ค้าง
        move(layout, 'form', 'chart', 'right');

        expect(layout.columns.value.center).toEqual([['chart', 'form'], ['book']]);
        expect(layout.rowSplit.value.form).toBeUndefined();
    });

    it('keeps the ratio while the card still leads a row of its own', () => {
        move(layout, 'book', 'form', 'right');
        layout.setRowSplit(['form', 'book'], 70);

        // form แยกออกมาเป็นแถวเดี่ยว — สัดส่วนไม่มีผลตอนอยู่ลำพัง แต่เก็บไว้ให้
        // กลับมาเหมือนเดิมถ้าผู้ใช้จับใบอื่นมาวางเคียงอีกครั้ง
        move(layout, 'form', 'market', 'before');

        expect(layout.rowSplit.value.form).toBe(70);
        expect(layout.splitPct(['form', 'ai'], 'form')).toBe(70);
    });

    it('clamps the split so neither card can be squeezed to nothing', () => {
        const row = ['form', 'book'];

        layout.setRowSplit(row, 5);
        expect(layout.splitPct(row, 'form')).toBe(25);

        layout.setRowSplit(row, 140);
        expect(layout.splitPct(row, 'form')).toBe(75);
        expect(layout.splitPct(row, 'book')).toBe(25);
    });

    it('refuses to hide essential cards', () => {
        layout.toggleHidden('form'); // essential (ฟอร์มซื้อขาย ซ่อนไม่ได้)
        expect(layout.hidden.value).not.toContain('form');

        layout.toggleHidden('trades');
        expect(layout.hidden.value).toContain('trades');
        expect(layout.visible.value.far).not.toContain('trades');
    });

    it('collapses and expands a card', () => {
        layout.toggleCollapsed('book');
        expect(layout.isCollapsed('book')).toBe(true);

        layout.toggleCollapsed('book');
        expect(layout.isCollapsed('book')).toBe(false);
    });

    it('reset restores the default placement and clears every custom ratio', () => {
        move(layout, 'book', 'form', 'right');
        layout.setRowSplit(['form', 'book'], 70);
        layout.pinRowHeights([['chart'], ['form', 'book']], [300, 200]);
        layout.toggleHidden('trades');

        layout.reset();

        expect(layout.columns.value.left).toEqual([['market'], ['ai']]);
        expect(layout.columns.value.center).toEqual([['chart'], ['form']]);
        expect(layout.columns.value.right).toEqual([['book']]);
        expect(layout.columns.value.far).toEqual([['orders'], ['trades']]);
        expect(layout.hidden.value).toEqual([]);
        expect(layout.rowSplit.value).toEqual({});
        expect(layout.rowGrow.value).toEqual({});
        expect(layout.fitScreen.value).toBe(true);
    });

    it('exposes a chart height in pixels for each preset', () => {
        layout.chartHeight.value = 'sm';
        expect(layout.chartHeightPx.value).toBe(340);

        layout.chartHeight.value = 'lg';
        expect(layout.chartHeightPx.value).toBe(600);
    });

    it('keeps every card in the stacked (narrow screen) order', () => {
        expect(layout.stackedOrder.value.sort()).toEqual([...ALL_IDS].sort());
    });

    // ── ความกว้างคอลัมน์: แถวคู่ต้องขอที่มากกว่าแถวเดี่ยว ────────────────────
    it('asks for enough width to fit both cards of a paired row', () => {
        const single = layout.columnMinWidth([['form'], ['chart']]);
        const paired = layout.columnMinWidth([['chart'], ['form', 'book']]);

        // แถวคู่ = ฟอร์ม 260 + สมุดคำสั่ง 200 + ช่องไฟ 12
        expect(paired).toBe(472);
        expect(paired).toBeGreaterThan(single);
    });

    it('does not fall back to a card height when measuring width', () => {
        // ฟอร์มมี min ความสูงเป็น 0 (สูงตามเนื้อหา) แต่ความกว้างต้องไม่เป็น 0 ตาม
        expect(layout.columnMinWidth([['form']])).toBe(260);
    });

    // ── โหมดพอดีหน้าจอ: แถวยืด/หดเอง แต่ต้องมีความสูงต่ำสุด ─────────────────
    it('gives growing rows a flex weight and a readable minimum height', () => {
        const chart = layout.rowStyle(['chart'], true);
        expect(chart.flex).toBe('8 1 0%');
        expect(chart.minHeight).toBe('240px');

        const trades = layout.rowStyle(['trades'], true);
        expect(parseInt(trades.minHeight, 10)).toBeGreaterThanOrEqual(120);
    });

    it('sizes the trade form to its content but caps it so the chart is not starved', () => {
        expect(layout.rowStyle(['form'], true)).toEqual({
            flex: '0 1 auto',
            maxHeight: '58%',
            minHeight: '0',
        });
    });

    it('a paired row adds up the weights and takes the tallest minimum', () => {
        // ฟอร์ม (0) + สมุดคำสั่ง (5) → แถวยืดได้ เพราะมีใบที่ยืดเป็นเพื่อน
        expect(layout.rowStyle(['form', 'book'], true)).toEqual({
            flex: '5 1 0%',
            minHeight: '200px',
        });
    });

    it('drops the height cap when only some cards in the row have one', () => {
        // ถ้าเอาเพดาน 58% ของฟอร์มมาครอบทั้งแถว สมุดคำสั่งที่ยืดได้จะถูกตัดตามไปด้วย
        const style = layout.rowStyle(['form', 'book'], true);
        expect(style.maxHeight).toBeUndefined();
    });

    it('falls back to fixed pixel heights when fit-to-screen is off', () => {
        expect(layout.rowStyle(['book'], false)).toEqual({ height: '400px' });
        expect(layout.rowStyle(['form'], false)).toEqual({});
        // ใบไหนขอสูงตามเนื้อหา ทั้งแถวก็ต้องตามเนื้อหา
        expect(layout.rowStyle(['form', 'book'], false)).toEqual({});
    });

    it('collapsed rows shrink to their header in both modes', () => {
        layout.toggleCollapsed('book');

        for (const packed of [true, false]) {
            const style = layout.rowStyle(['book'], packed);
            expect(style.height).toBe('auto');
            expect(style.minHeight).toBe('0');
        }
    });

    it('keeps a paired row open while one of its cards is still expanded', () => {
        layout.toggleCollapsed('book');

        expect(layout.rowStyle(['form', 'book'], true).height).toBeUndefined();
    });

    // ── ความกว้างของการ์ดภายในแถว ────────────────────────────────────────────
    it('splits a paired row by the stored ratio, minus a full gap each side', () => {
        layout.setRowSplit(['form', 'book'], 60);

        // แถวคู่มีช่องไฟ 2 ช่อง (การ์ด · เส้นแบ่ง · การ์ด) จึงลบ 12px เต็มจากทั้งสองใบ
        expect(layout.cardStyleInRow('form', ['form', 'book']).flexBasis).toBe('calc(60% - 12px)');
        expect(layout.cardStyleInRow('book', ['form', 'book']).flexBasis).toBe('calc(40% - 12px)');
    });

    it('gives a lone card the whole row', () => {
        expect(layout.cardStyleInRow('chart', ['chart']).flexBasis).toBe('100%');
    });

    it('stops a collapsed card from stretching to its neighbour height', () => {
        layout.toggleCollapsed('book');

        expect(layout.cardStyleInRow('book', ['form', 'book']).alignSelf).toBe('flex-start');
        expect(layout.cardStyleInRow('form', ['form', 'book']).alignSelf).toBeUndefined();
    });

    // ── ความสูงที่ผู้ใช้ลากปรับเอง ───────────────────────────────────────────
    it('pins every row in the column so untouched rows do not drift', () => {
        const rows = [['chart'], ['form']];
        layout.pinRowHeights(rows, [360, 240]);

        expect(layout.rowGrow.value.chart).toBeCloseTo(60);
        expect(layout.rowGrow.value.form).toBeCloseTo(40);
        expect(layout.rowStyle(['chart'], true).flex).toBe('60 1 0%');
        // ฟอร์มที่เดิมสูงตามเนื้อหา (grow 0) กลายเป็นแถวสัดส่วนหลังผู้ใช้ลาก
        expect(layout.rowStyle(['form'], true).flex).toBe('40 1 0%');
    });

    it('ignores a zero-height measurement instead of wiping the column', () => {
        layout.pinRowHeights([['chart'], ['form']], [0, 0]);

        expect(layout.rowGrow.value).toEqual({});
    });

    it('resetting row heights only clears the column that was reset', () => {
        layout.pinRowHeights([['chart'], ['form']], [360, 240]);
        layout.pinRowHeights([['orders'], ['trades']], [200, 200]);

        layout.resetRowHeights([['chart'], ['form']]);

        expect(layout.rowGrow.value.chart).toBeUndefined();
        expect(layout.rowGrow.value.form).toBeUndefined();
        expect(layout.rowGrow.value.orders).toBeCloseTo(50);
    });
});

/**
 * ผังที่บันทึกไว้ในเครื่องต้องกู้กลับมาได้ทุกรูปแบบ — รวมถึงของเวอร์ชันก่อน
 * และค่าที่ถูกแก้มือจนเสีย โดยห้ามทำการ์ดหายแม้แต่ใบเดียว
 *
 * ต้อง resetModules ทุกครั้งเพราะ useTradeLayout เป็น singleton ระดับโมดูล
 * — โหลดค่าจาก localStorage ครั้งเดียวตอน import แล้วจำไว้ตลอด
 */
describe('useTradeLayout — restoring a saved layout', () => {
    beforeEach(() => {
        vi.resetModules();
        localStorage.clear();
    });

    async function restore(key, value) {
        localStorage.setItem(key, JSON.stringify(value));
        const mod = await import('@/Composables/useTradeLayout');
        return mod.useTradeLayout();
    }

    it('migrates the previous flat layout instead of resetting the user', async () => {
        const layout = await restore('tpix.tradeLayout.v3', {
            columns: { left: ['chart'], center: ['form', 'book'], right: ['market'], far: ['orders', 'trades', 'ai'] },
            hidden: ['trades'],
            chartHeight: 'lg',
        });

        // การ์ดใบละแถวเหมือนที่ผู้ใช้เคยเห็น แต่อยู่ในคอลัมน์ที่เขาจัดไว้เอง
        expect(layout.columns.value.left).toEqual([['chart']]);
        expect(layout.columns.value.center).toEqual([['form'], ['book']]);
        expect(layout.columns.value.far).toEqual([['orders'], ['trades'], ['ai']]);
        expect(layout.hidden.value).toEqual(['trades']);
        expect(layout.chartHeightPx.value).toBe(600);
        expect(flatten(layout).sort()).toEqual([...ALL_IDS].sort());
    });

    it('restores paired rows untouched', async () => {
        const layout = await restore('tpix.tradeLayout.v4', {
            columns: { left: [['market'], ['ai']], center: [['chart'], ['form', 'book']], right: [], far: [['orders'], ['trades']] },
            rowSplit: { form: 65 },
        });

        expect(layout.columns.value.center).toEqual([['chart'], ['form', 'book']]);
        expect(layout.splitPct(['form', 'book'], 'form')).toBe(65);
    });

    it('cuts an over-long saved row down to two cards instead of squeezing them', async () => {
        const layout = await restore('tpix.tradeLayout.v4', {
            columns: { left: [], center: [['chart', 'form', 'book', 'market']], right: [], far: [] },
        });

        expect(layout.columns.value.center.slice(0, 2)).toEqual([['chart', 'form'], ['book', 'market']]);
        expect(flatten(layout).sort()).toEqual([...ALL_IDS].sort());
    });

    it('drops a card that was saved twice and keeps cards the save never mentioned', async () => {
        const layout = await restore('tpix.tradeLayout.v4', {
            columns: { left: [['chart'], ['chart', 'ghost']], center: [], right: [], far: [] },
        });

        expect(flatten(layout).filter(id => id === 'chart')).toHaveLength(1);
        expect(flatten(layout)).not.toContain('ghost');
        expect(flatten(layout).sort()).toEqual([...ALL_IDS].sort());
    });

    it('throws away tampered row weights but keeps the sound ones', async () => {
        const layout = await restore('tpix.tradeLayout.v4', {
            columns: { left: [['market'], ['ai']], center: [['chart'], ['form']], right: [['book']], far: [['orders'], ['trades']] },
            rowGrow: { chart: 60, form: 'wide', market: NaN, ai: -5, book: Infinity },
        });

        expect(layout.rowGrow.value).toEqual({ chart: 60 });
        // แถวที่ค่าเสียถูกทิ้ง ต้องกลับไปใช้น้ำหนักตั้งต้น ไม่ใช่ยุบหาย
        expect(layout.rowStyle(['form'], true).flex).toBe('0 1 auto');
        expect(layout.rowStyle(['market'], true).flex).toBe('5 1 0%');
    });

    it('falls back to the default layout when the saved value is not JSON', async () => {
        localStorage.setItem('tpix.tradeLayout.v4', '{ this is not json');
        const mod = await import('@/Composables/useTradeLayout');
        const layout = mod.useTradeLayout();

        expect(layout.columns.value.center).toEqual([['chart'], ['form']]);
        expect(flatten(layout).sort()).toEqual([...ALL_IDS].sort());
    });
});
