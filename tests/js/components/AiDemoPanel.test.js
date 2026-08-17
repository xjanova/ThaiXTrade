/**
 * TPIX TRADE — AiDemoPanel tests
 *
 * พอร์ตทดลองคือด่านแรกก่อนผู้ใช้ตัดสินใจจ่ายเงินเช่าบอท ตัวเลขที่โชว์ผิด
 * = ผู้ใช้ตัดสินใจบนข้อมูลผิด ชุดนี้จึงเน้นเรื่องเลขและการเปิดเผยสมมติฐาน
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AiDemoPanel from '@/Components/Trading/AiDemoPanel.vue';

// PageArt ดึงไฟล์ภาพจริง — ไม่เกี่ยวกับสิ่งที่ทดสอบ
vi.mock('@/Components/PageArt.vue', () => ({
    default: { name: 'PageArt', template: '<div />' },
}));

/** พอร์ตทดลองตัวอย่าง — ทับค่าเฉพาะส่วนที่แต่ละเทสต์สนใจได้ */
function makeDemo(overrides = {}) {
    return {
        account: {
            balance: 9000,
            starting_balance: 10000,
            resets_used_today: 1,
            resets_per_day: 3,
            fee_rate: 0.1,
            slippage_bps: 8,
        },
        positions: [],
        trades: [],
        summary: {
            realized_pnl: 0,
            total_fees: 0,
            trade_count: 0,
            closed_count: 0,
            wins: 0,
            losses: 0,
            win_rate: null,
        },
        ...overrides,
    };
}

function mountPanel(demo = makeDemo(), props = {}) {
    return mount(AiDemoPanel, {
        props: { demo, resetsLeft: 2, ...props },
    });
}

describe('AiDemoPanel', () => {
    it('บอกยอดเครดิตทดลองและทุนตั้งต้น', () => {
        const text = mountPanel().text();

        expect(text).toContain('9,000.00');
        expect(text).toContain('10,000');
    });

    it('รวมต้นทุนของที่ถืออยู่เข้ากับเงินสดเป็นมูลค่าพอร์ต', () => {
        const demo = makeDemo({
            account: { ...makeDemo().account, balance: 6000 },
            positions: [
                { id: 1, pair: 'BTC/USDT', quantity: 0.05, entry_price: 60000, cost_basis: 3000, entry_count: 1 },
                { id: 2, pair: 'ETH/USDT', quantity: 0.4, entry_price: 2500, cost_basis: 1000, entry_count: 2 },
            ],
        });

        // 6,000 เงินสด + 3,000 + 1,000 ต้นทุนที่ถือ = 10,000
        expect(mountPanel(demo).text()).toContain('10,000.00');
    });

    it('แสดงกำไรเป็นสีเขียวพร้อมเครื่องหมายบวก', () => {
        const demo = makeDemo({
            summary: { ...makeDemo().summary, realized_pnl: 250.5, closed_count: 4, wins: 3, losses: 1, win_rate: 75 },
        });

        const wrapper = mountPanel(demo);

        expect(wrapper.text()).toContain('+$250.50');
        expect(wrapper.text()).toContain('+2.50%');   // 250.50 / 10,000 = 2.505 → ปัดเหลือ 2.50
        expect(wrapper.text()).toContain('75%');
        expect(wrapper.text()).toContain('3W / 1L');
        expect(wrapper.html()).toContain('text-trading-green');
    });

    it('แสดงขาดทุนเป็นสีแดง', () => {
        const demo = makeDemo({
            summary: { ...makeDemo().summary, realized_pnl: -420.25, closed_count: 3, wins: 1, losses: 2, win_rate: 33.3 },
        });

        const wrapper = mountPanel(demo);

        expect(wrapper.text()).toContain('-$420.25');
        expect(wrapper.html()).toContain('text-trading-red');
    });

    it('ยังไม่มีไม้ปิด → ไม่โชว์อัตราชนะปลอมๆ', () => {
        expect(mountPanel().text()).toContain('—');
    });

    it('⭐ เปิดเผยค่าธรรมเนียมและ slippage ที่ใช้จำลอง', () => {
        // ผลทดลองที่สวยเกินจริงคือสิ่งที่ทำให้ผู้ใช้เช่าแล้วผิดหวัง
        const text = mountPanel().text();

        expect(text).toContain('0.1%');
        expect(text).toContain('8');
    });

    it('แสดงเหตุผลที่บอทเทรดในประวัติแต่ละไม้', () => {
        const demo = makeDemo({
            trades: [{
                id: 1, pair: 'BTC/USDT', side: 'buy', price: 61234.5, quantity: 0.016,
                gross_value: 999, fee: 1, slippage_cost: 0.78, realized_pnl: null,
                strategy: 'momentum', reason: 'EMA crossed up with volume confirmation',
                risk_level: 'calm', created_at: '2026-08-17T09:00:00+00:00',
            }],
        });

        const text = mountPanel(demo).text();

        expect(text).toContain('EMA crossed up with volume confirmation');
        expect(text).toContain('61,234.50');
    });

    it('ติดป้ายระดับความเสี่ยงให้ไม้ที่เทรดตอนตลาดผิดปกติ', () => {
        const demo = makeDemo({
            trades: [{
                id: 2, pair: 'BTC/USDT', side: 'sell', price: 58000, quantity: 0.016,
                gross_value: 928, fee: 0.93, slippage_cost: 0.74, realized_pnl: -72.5,
                strategy: 'momentum', reason: 'Market panic — exiting everything',
                risk_level: 'panic', created_at: '2026-08-17T10:00:00+00:00',
            }],
        });

        const wrapper = mountPanel(demo);

        expect(wrapper.text()).toContain('Panic');
        expect(wrapper.text()).toContain('-$72.50');
    });

    it('ราคาเหรียญถูกต้องแสดงทศนิยมมากพอ ไม่ปัดเป็นศูนย์', () => {
        const demo = makeDemo({
            trades: [{
                id: 3, pair: 'PEPE/USDT', side: 'buy', price: 0.00000812, quantity: 1000000,
                gross_value: 8.12, fee: 0.01, slippage_cost: 0, realized_pnl: null,
                strategy: 'grid', reason: 'Grid level hit', risk_level: 'calm',
                created_at: '2026-08-17T10:00:00+00:00',
            }],
        });

        expect(mountPanel(demo).text()).toContain('0.00000812');
    });

    it('ปุ่มล้างพอร์ตส่ง event เมื่อผู้ใช้ยืนยัน', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const wrapper = mountPanel();
        await wrapper.findAll('button').at(0).trigger('click');

        expect(wrapper.emitted('reset')).toBeTruthy();
    });

    it('ยกเลิกการยืนยัน = ไม่ล้างพอร์ต', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        const wrapper = mountPanel();
        await wrapper.findAll('button').at(0).trigger('click');

        expect(wrapper.emitted('reset')).toBeFalsy();
    });

    it('ล้างครบโควตาแล้วต้องกดปุ่มไม่ได้', () => {
        const wrapper = mountPanel(makeDemo(), { resetsLeft: 0 });

        expect(wrapper.findAll('button').at(0).attributes('disabled')).toBeDefined();
    });

    it('ไม่มีข้อมูลเลยก็ต้องไม่พัง', () => {
        const wrapper = mount(AiDemoPanel, { props: { demo: null } });

        expect(wrapper.text()).toBeTruthy();
    });
});
