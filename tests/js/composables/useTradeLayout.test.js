/**
 * TPIX TRADE — useTradeLayout tests
 * ผังการ์ดของหน้าเทรดต้องทนต่อค่าที่บันทึกไว้เก่า/เสีย โดยไม่ทำการ์ดหาย
 * Developed by Xman Studio
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { useTradeLayout, TRADE_CARDS, COLUMNS } from '@/Composables/useTradeLayout';

const ALL_IDS = TRADE_CARDS.map(c => c.id);

function flatten(layout) {
    return COLUMNS.flatMap(col => layout.columns.value[col]);
}

describe('useTradeLayout', () => {
    let layout;

    beforeEach(() => {
        layout = useTradeLayout();
        layout.reset();
    });

    it('starts with every card placed exactly once', () => {
        const ids = flatten(layout);
        expect(ids.sort()).toEqual([...ALL_IDS].sort());
    });

    it('moves a card to another column and keeps it unique', () => {
        layout.startDrag('ai');
        layout.dropOnCard('chart', 'before');
        layout.endDrag();

        expect(layout.columns.value.center).toContain('ai');
        expect(layout.columns.value.left).not.toContain('ai');
        expect(flatten(layout).filter(id => id === 'ai')).toHaveLength(1);
    });

    it('drops after the target when the pointer is on the lower half', () => {
        layout.startDrag('book');
        layout.dropOnCard('trades', 'after');
        layout.endDrag();

        const right = layout.columns.value.right;
        expect(right.indexOf('book')).toBe(right.indexOf('trades') + 1);
    });

    it('appends to the end when dropped on empty column space', () => {
        layout.startDrag('book');
        layout.dropOnColumn('left');
        layout.endDrag();

        expect(layout.columns.value.left.at(-1)).toBe('book');
    });

    it('never drops a card onto itself', () => {
        const before = [...layout.columns.value.right];

        layout.startDrag('book');
        layout.dropOnCard('book', 'before');
        layout.endDrag();

        expect(layout.columns.value.right).toEqual(before);
    });

    it('refuses to hide essential cards', () => {
        layout.toggleHidden('form'); // essential (ฟอร์มซื้อขาย ซ่อนไม่ได้)
        expect(layout.hidden.value).not.toContain('form');

        layout.toggleHidden('trades');
        expect(layout.hidden.value).toContain('trades');
        expect(layout.visible.value.right).not.toContain('trades');
    });

    it('collapses and expands a card', () => {
        layout.toggleCollapsed('book');
        expect(layout.isCollapsed('book')).toBe(true);

        layout.toggleCollapsed('book');
        expect(layout.isCollapsed('book')).toBe(false);
    });

    it('reset restores the default placement', () => {
        layout.startDrag('market');
        layout.dropOnColumn('right');
        layout.endDrag();
        layout.toggleHidden('trades');

        layout.reset();

        expect(layout.columns.value.left).toEqual(['market', 'ai']);
        expect(layout.columns.value.center).toEqual(['chart', 'form']);
        expect(layout.columns.value.right).toEqual(['orders', 'book', 'trades']);
        expect(layout.hidden.value).toEqual([]);
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

    // ── โหมดพอดีหน้าจอ: การ์ดยืด/หดเอง แต่ต้องมีความสูงต่ำสุด ────────────────
    it('gives growing cards a flex weight and a readable minimum height', () => {
        const chart = layout.cardStyle('chart', true);
        expect(chart.flex).toBe('8 1 0%');
        expect(chart.minHeight).toBe('240px');

        const trades = layout.cardStyle('trades', true);
        expect(parseInt(trades.minHeight, 10)).toBeGreaterThanOrEqual(120);
    });

    it('sizes the trade form to its content but caps it so the chart is not starved', () => {
        expect(layout.cardStyle('form', true)).toEqual({
            flex: '0 1 auto',
            maxHeight: '58%',
            minHeight: '0',
        });
    });

    it('falls back to fixed pixel heights when fit-to-screen is off', () => {
        expect(layout.cardStyle('book', false)).toEqual({ height: '400px' });
        expect(layout.cardStyle('form', false)).toEqual({});
    });

    it('collapsed cards shrink to their header in both modes', () => {
        layout.toggleCollapsed('book');

        for (const packed of [true, false]) {
            const style = layout.cardStyle('book', packed);
            expect(style.height).toBe('auto');
            expect(style.minHeight).toBe('0');
        }
    });
});
