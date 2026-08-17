/**
 * TPIX TRADE — อุปกรณ์ลากวางแบบ HTML5 ได้ไหม
 *
 * เจ้าของสั่งว่า "ฟังก์ชันไหนยังใช้ไม่ได้ ก็ต้องทำให้ปุ่มไม่พร้อมใช้ไปก่อน"
 * ที่จับลากบนแท็บเล็ตคือเคสตรงตัว: ปุ่มโชว์อยู่ แตะแล้วไม่มีอะไรเกิดขึ้นเลย
 * เพราะเบราว์เซอร์ไม่ยิงอีเวนต์ drag จากการแตะ
 *
 * เดิมตัดสินจากความกว้างจออย่างเดียว (< 1280px ล็อก) iPad Pro แนวนอน 1366px
 * จึงหลุดเกณฑ์มาแล้วโชว์ปุ่มที่ใช้ไม่ได้
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/**
 * สวมค่า matchMedia แล้วโหลดโมดูลใหม่ — ตัวโมดูลจำ MediaQueryList ไว้ตัวเดียว
 *
 * คืน mql ตัวจริงที่โมดูลถืออยู่ออกมาด้วย เพราะของจริงในเบราว์เซอร์คือวัตถุที่
 * อัปเดต `matches` ของตัวเองแล้วค่อยยิง change — ไม่ใช่การเรียก matchMedia ใหม่
 */
async function loadWith({ fine }) {
    vi.resetModules();

    const listeners = [];
    const mql = {
        matches: fine,
        media: '(pointer: fine)',
        addEventListener: (_, handler) => listeners.push(handler),
        removeEventListener: () => {},
    };

    window.matchMedia = (query) => (query === '(pointer: fine)' ? mql : { ...mql, matches: false, media: query });

    const mod = await import('@/Composables/usePointerCapability');

    return { ...mod.usePointerCapability(), mql, listeners };
}

describe('usePointerCapability', () => {
    const originalMatchMedia = window.matchMedia;

    beforeEach(() => vi.resetModules());
    afterEach(() => { window.matchMedia = originalMatchMedia; });

    it('เมาส์บนเดสก์ท็อป — ลากวางได้', async () => {
        const { hasFinePointer } = await loadWith({ fine: true });

        expect(hasFinePointer.value).toBe(true);
    });

    it('แท็บเล็ตจอสัมผัส — ลากวางไม่ได้แม้จอจะกว้าง', async () => {
        const { hasFinePointer } = await loadWith({ fine: false });

        expect(hasFinePointer.value).toBe(false);
    });

    /**
     * ต่อจอนอกเข้าแท็บเล็ต หรือถอดคีย์บอร์ด Surface ออก — ตัวชี้หลักเปลี่ยนกลางคัน
     * ต้องอัปเดตตาม ไม่ใช่ค้างค่าที่อ่านไว้ตอนเปิดหน้า
     */
    it('ตัวชี้เปลี่ยนกลางคันแล้วอัปเดตตาม', async () => {
        const { hasFinePointer, mql, listeners } = await loadWith({ fine: false });
        expect(hasFinePointer.value).toBe(false);

        // เบราว์เซอร์จริงแก้ matches ในวัตถุเดิม แล้วค่อยยิง change
        mql.matches = true;
        listeners.forEach(fn => fn());

        expect(hasFinePointer.value).toBe(true);
    });

    /** ระหว่าง SSR ไม่มี matchMedia — ต้องไม่ throw และถือว่าเป็นเมาส์ไว้ก่อน */
    it('ไม่มี matchMedia ก็ไม่พัง', async () => {
        vi.resetModules();
        window.matchMedia = undefined;

        const mod = await import('@/Composables/usePointerCapability');

        expect(() => mod.usePointerCapability()).not.toThrow();
        expect(mod.usePointerCapability().hasFinePointer.value).toBe(true);
    });
});
