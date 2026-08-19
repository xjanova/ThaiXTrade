/**
 * TPIX TRADE — useDragAutoScroll
 * เลื่อนคอลัมน์ให้เองเมื่อลากการ์ดไปค้างไว้ที่ขอบบน/ล่าง
 *
 * ทำไมต้องเขียนเอง: HTML5 drag and drop ไม่เลื่อนกล่องที่ overflow ให้ (เบราว์เซอร์
 * เลื่อนให้เฉพาะหน้าต่างหลักและไม่สม่ำเสมอ) โหมด "พอดีจอ" ของหน้าเทรดทำให้แต่ละ
 * คอลัมน์เป็นกล่องเลื่อนของตัวเอง — การ์ดที่อยู่นอกสายตาจึงลากไปวางไม่ได้เลย
 * ต้องเลิกลาก เลื่อนมือ แล้วเริ่มลากใหม่ ซึ่งพอถึงตอนนั้นก็มองไม่เห็นปลายทางแทน
 *
 * Developed by Xman Studio
 */

import { getCurrentInstance, onUnmounted } from 'vue';

/** ระยะจากขอบที่เริ่มเลื่อน (px) — กว้างพอให้เล็งง่ายบนทัชแพด */
const EDGE = 72;

/** ความเร็วสูงสุดต่อเฟรม (px) — เร็วกว่านี้แล้วเลยจุดที่ตั้งใจวาง */
const MAX_SPEED = 18;

export function useDragAutoScroll() {
    let frameId = null;
    let target = null;      // element ที่เลื่อน หรือ window
    let velocity = 0;
    let listening = false;

    function scrollTopOf(box) {
        return box === window ? window.scrollY : box.scrollTop;
    }

    function tick() {
        if (!target || velocity === 0) {
            stop();
            return;
        }

        const before = scrollTopOf(target);

        if (target === window) window.scrollBy(0, velocity);
        else target.scrollTop += velocity;

        /*
         * ถึงสุดทางแล้วต้องหยุดลูป ไม่ใช่หมุนเปล่าจนกว่าจะปล่อยเมาส์
         * (ลากค้างที่ขอบล่างของคอลัมน์สั้นๆ = rAF วิ่งฟรีทุกเฟรม)
         */
        if (scrollTopOf(target) === before) {
            stop();
            return;
        }

        frameId = requestAnimationFrame(tick);
    }

    /**
     * หยุดทุกอย่างและถอดตัวดักออก
     *
     * ต้องเรียกได้ซ้ำโดยไม่พัง เพราะถูกเรียกจากทั้ง drop, dragend, onUnmounted
     * และจากตัว tick เองตอนสุดทาง
     */
    function stop() {
        if (frameId !== null) cancelAnimationFrame(frameId);
        frameId = null;
        target = null;
        velocity = 0;

        if (listening) {
            document.removeEventListener('drop', stop, true);
            document.removeEventListener('dragend', stop, true);
            listening = false;
        }
    }

    /*
     * ดักจบการลากที่ระดับ document แบบ capture
     *
     * การ์ดปลายทาง stopPropagation ตอน drop (กันคอลัมน์รับซ้ำ) ตัวดักที่ผูกกับ
     * คอลัมน์จึงไม่ได้ยินว่าลากจบแล้ว — ลูปจะเลื่อนต่อหลังผู้ใช้ปล่อยเมาส์ไปแล้ว
     * capture ทำให้ได้ยินก่อนใครและไม่โดน stopPropagation ตัดทาง
     */
    function listen() {
        if (listening) return;

        document.addEventListener('drop', stop, true);
        document.addEventListener('dragend', stop, true);
        listening = true;
    }

    /** ยิ่งใกล้ขอบยิ่งเร็ว — ที่ขอบพอดีได้ความเร็วเต็ม */
    function speedFor(distance) {
        const ratio = Math.min(1, Math.max(0, (EDGE - distance) / EDGE));

        return Math.max(1, Math.round(ratio * MAX_SPEED));
    }

    /**
     * เรียกจาก @dragover ของคอลัมน์ — ส่ง element ของคอลัมน์มาด้วย
     *
     * ถ้าคอลัมน์นั้นไม่ใช่กล่องเลื่อน (จอแคบใช้ display:contents ทำให้ไม่มีกล่อง
     * และโหมดเลื่อนทั้งหน้าไม่ได้ตั้ง overflow) ให้ตกไปเลื่อนทั้งหน้าต่างแทน
     */
    function onDragOver(event, element) {
        const scrollable = element && element.scrollHeight > element.clientHeight + 1;
        const box = scrollable ? element : window;

        const rect = scrollable
            ? element.getBoundingClientRect()
            : { top: 0, bottom: window.innerHeight };

        const fromTop = event.clientY - rect.top;
        const fromBottom = rect.bottom - event.clientY;

        let next = 0;
        if (fromTop < EDGE && fromTop >= 0) next = -speedFor(fromTop);
        else if (fromBottom < EDGE && fromBottom >= 0) next = speedFor(fromBottom);

        if (next === 0) {
            stop();
            return;
        }

        target = box;
        velocity = next;
        listen();

        if (frameId === null) frameId = requestAnimationFrame(tick);
    }

    /*
     * ออกจากหน้าเทรดกลางคัน = ลูปต้องตาย ไม่งั้นเลื่อนหน้าอื่นต่อ
     *
     * เช็ก instance ก่อนเพราะเทสต์เรียก composable นี้นอกคอมโพเนนต์ —
     * onUnmounted ที่ไม่มีเจ้าของจะเตือนทุกครั้งจนกลบผลเทสต์จริง
     * (เทสต์เก็บกวาดเองด้วยการเรียก stop() ใน afterEach)
     */
    if (getCurrentInstance()) onUnmounted(stop);

    return { onDragOver, stop };
}
