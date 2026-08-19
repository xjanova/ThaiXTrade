/**
 * TPIX TRADE — useDragAutoScroll tests
 *
 * สิ่งที่เทสต์นี้กันจริงๆ ไม่ใช่ "เลื่อนได้ไหม" แต่คือ "หยุดได้ไหม" —
 * ลูป requestAnimationFrame ที่ไม่ตายจะเลื่อนหน้าจอต่อหลังผู้ใช้ปล่อยเมาส์ไปแล้ว
 * หรือเลื่อนหน้าอื่นหลังออกจากหน้าเทรด ซึ่งเป็นอาการที่หาต้นตอยากมาก
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { useDragAutoScroll } from '@/Composables/useDragAutoScroll';

/** กล่องเลื่อนจำลอง — jsdom ไม่คำนวณ layout ให้ ต้องกำหนดค่าเอง */
function makeScrollable({ height = 400, content = 2000, top = 100 } = {}) {
    const box = document.createElement('div');

    Object.defineProperty(box, 'clientHeight', { value: height, configurable: true });
    Object.defineProperty(box, 'scrollHeight', { value: content, configurable: true });

    box.scrollTop = 0;
    box.getBoundingClientRect = () => ({
        top,
        bottom: top + height,
        left: 0,
        right: 300,
        width: 300,
        height,
    });

    return box;
}

/** เดินลูป rAF ไปข้างหน้า n เฟรม */
function runFrames(n) {
    for (let i = 0; i < n; i += 1) vi.advanceTimersByTime(16);
}

describe('useDragAutoScroll', () => {
    let auto;

    beforeEach(() => {
        vi.useFakeTimers();

        // jsdom ไม่มี rAF ที่เดินเอง — ผูกกับ timer ปลอมเพื่อคุมจำนวนเฟรมได้
        vi.stubGlobal('requestAnimationFrame', cb => setTimeout(() => cb(performance.now()), 16));
        vi.stubGlobal('cancelAnimationFrame', id => clearTimeout(id));

        auto = useDragAutoScroll();
    });

    afterEach(() => {
        auto.stop();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('เลื่อนลงเมื่อลากค้างใกล้ขอบล่างของคอลัมน์', () => {
        const box = makeScrollable();

        // ขอบล่างอยู่ที่ 500 — จิ้มที่ 480 คือห่างขอบ 20px (อยู่ในเขต 72px)
        auto.onDragOver({ clientY: 480 }, box);
        runFrames(5);

        expect(box.scrollTop).toBeGreaterThan(0);
    });

    it('เลื่อนขึ้นเมื่อลากค้างใกล้ขอบบน', () => {
        const box = makeScrollable();
        box.scrollTop = 500;

        // ขอบบนอยู่ที่ 100 — จิ้มที่ 120 คือห่างขอบ 20px
        auto.onDragOver({ clientY: 120 }, box);
        runFrames(5);

        expect(box.scrollTop).toBeLessThan(500);
    });

    it('ยิ่งใกล้ขอบยิ่งเลื่อนเร็ว', () => {
        const near = makeScrollable();
        const far = makeScrollable();

        auto.onDragOver({ clientY: 498 }, near);   // ห่างขอบล่าง 2px
        runFrames(4);
        const fast = near.scrollTop;
        auto.stop();

        auto.onDragOver({ clientY: 440 }, far);    // ห่างขอบล่าง 60px
        runFrames(4);
        const slow = far.scrollTop;

        expect(fast).toBeGreaterThan(slow);
    });

    it('ไม่เลื่อนเมื่อตัวชี้อยู่กลางคอลัมน์', () => {
        const box = makeScrollable();

        auto.onDragOver({ clientY: 300 }, box);   // กลางพอดี ห่างขอบทั้งสองข้าง 200px
        runFrames(5);

        expect(box.scrollTop).toBe(0);
    });

    it('ไม่แตะคอลัมน์ที่เนื้อหาไม่ล้น (ตกไปเลื่อนหน้าต่างแทน)', () => {
        const box = makeScrollable({ height: 400, content: 400 });
        const scrollBy = vi.fn();
        vi.stubGlobal('scrollBy', scrollBy);

        auto.onDragOver({ clientY: window.innerHeight - 10 }, box);
        runFrames(3);

        expect(box.scrollTop).toBe(0);
        expect(scrollBy).toHaveBeenCalled();
    });

    /*
     * ข้อที่สำคัญที่สุด — การ์ดปลายทาง stopPropagation ตอน drop
     * ตัวดักที่ผูกกับคอลัมน์จึงไม่มีวันได้ยิน ต้องดักที่ document แบบ capture
     */
    it('หยุดทันทีเมื่อผู้ใช้ปล่อยเมาส์ แม้การ์ดจะ stopPropagation ตอน drop', () => {
        const box = makeScrollable();

        auto.onDragOver({ clientY: 480 }, box);
        runFrames(3);
        const moved = box.scrollTop;
        expect(moved).toBeGreaterThan(0);

        const drop = new Event('drop', { bubbles: true, cancelable: true });
        const card = document.createElement('div');
        card.addEventListener('drop', e => e.stopPropagation());
        document.body.appendChild(card);
        card.dispatchEvent(drop);

        runFrames(10);
        expect(box.scrollTop).toBe(moved);   // ไม่ขยับอีกเลยหลังปล่อย
    });

    it('หยุดเมื่อ dragend แม้ไม่มี drop (ลากไปปล่อยนอกจอ)', () => {
        const box = makeScrollable();

        auto.onDragOver({ clientY: 480 }, box);
        runFrames(3);
        const moved = box.scrollTop;

        document.dispatchEvent(new Event('dragend', { bubbles: true }));
        runFrames(10);

        expect(box.scrollTop).toBe(moved);
    });

    /*
     * ลากค้างที่ขอบล่างของคอลัมน์ที่เลื่อนจนสุดแล้ว — ถ้าไม่หยุดเอง
     * ลูปจะวิ่งทุกเฟรมโดยไม่ทำอะไรจนกว่าผู้ใช้จะปล่อยเมาส์
     */
    it('หยุดลูปเองเมื่อเลื่อนจนสุดทางแล้ว', () => {
        const box = makeScrollable({ height: 400, content: 410, top: 100 });

        // jsdom ไม่บังคับเพดาน scrollTop ให้ ต้องจำลองเอง
        let value = 0;
        Object.defineProperty(box, 'scrollTop', {
            get: () => value,
            set: v => { value = Math.min(10, Math.max(0, v)); },
            configurable: true,
        });

        auto.onDragOver({ clientY: 480 }, box);
        runFrames(20);

        expect(box.scrollTop).toBe(10);

        // ถึงเพดานแล้วลูปต้องตาย — ยืนยันด้วยว่าไม่มี timer ค้างอยู่
        expect(vi.getTimerCount()).toBe(0);
    });

    it('เลิกดักเหตุการณ์ที่ document เมื่อหยุด — ไม่ทิ้งขยะไว้', () => {
        const box = makeScrollable();
        const removeSpy = vi.spyOn(document, 'removeEventListener');

        auto.onDragOver({ clientY: 480 }, box);
        auto.stop();

        expect(removeSpy).toHaveBeenCalledWith('drop', expect.any(Function), true);
        expect(removeSpy).toHaveBeenCalledWith('dragend', expect.any(Function), true);

        removeSpy.mockRestore();
    });
});
