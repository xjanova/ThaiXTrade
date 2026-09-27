/**
 * TPIX TRADE — เลื่อนทีละสถานี (หน้าแรก 3D)
 *
 * เจ้าของสั่ง: "สกรอครั้งเดียวหรือปัดครั้งเดียวก็เปลี่ยนซีนไปอยู่ในจุดที่เหมาะสมทันที
 *              เพราะคนใช้จะคิดว่าหมดแล้วไม่ยอมปัดต่อ ถ้าปัดหรือสกรอยาก"
 *
 * - ล้อเมาส์ 1 ครั้ง / ปัดนิ้ว 1 ครั้ง / ↓ PageDown Space = ไปสถานีถัดไป (ย้อนก็เช่นกัน)
 * - ทัชแพดส่ง wheel ต่อเนื่องเป็นวินาทีจากแรงเฉื่อย → ล็อกไว้จนกว่า event เงียบไป 280 ms
 *   ไม่งั้นปัดทีเดียวจะข้ามไปหลายสถานี
 * - สถานีสุดท้ายเลื่อนลงต่อ = ปล่อยให้เลื่อนปกติไป footer · จาก footer เลื่อนขึ้นมาใกล้ๆ = ดูดกลับสถานีสุดท้าย
 * - ในกล่องที่เลื่อนเองได้ (แผงข้อมูลยาว/บับเบิ้ลน้อง) ปล่อยให้กล่องนั้นเลื่อนก่อน
 * - ผู้ใช้ซูมจอด้วยสองนิ้วอยู่ = กำลังเลื่อนดูส่วนที่ซูม ไม่ใช่เปลี่ยนสถานี → ปล่อยเลื่อนปกติ
 * - ปัดทัชแพดแนวนอน (ปัดย้อนหน้าบน macOS) มี deltaY ปนมานิดหน่อย → ไม่นับ
 *
 * Developed by Xman Studio
 */

/** กล่องที่ผู้ใช้กำลังเลื่อนอยู่ในนั้นยังเลื่อนไปทางนั้นได้อีกไหม */
export function canScrollInside(el, dir, stopAt = null) {
    if (typeof Element === 'undefined' || !(el instanceof Element)) return false;
    for (let n = el; n && n !== stopAt && n !== document.body && n !== document.documentElement; n = n.parentElement) {
        const style = getComputedStyle(n);
        if (!/(auto|scroll)/.test(style.overflowY)) continue;
        if (n.scrollHeight <= n.clientHeight + 1) continue;
        if (dir > 0 && n.scrollTop + n.clientHeight < n.scrollHeight - 1) return true;
        if (dir < 0 && n.scrollTop > 0) return true;
    }
    return false;
}

/** ตัดสินปลายทางจากตำแหน่งปัจจุบัน — แยกเป็นฟังก์ชันบริสุทธิ์ให้เทสต์ได้ */
export function snapTarget({ scroll, centers, dir, vh }) {
    const n = centers.length;
    const last = centers[n - 1];
    // อยู่ใน footer (เลยสถานีสุดท้ายไปแล้ว)
    if (scroll > last + 4) {
        if (dir < 0 && scroll < last + vh * 0.6) return n - 1; // เลื่อนขึ้นใกล้ๆ → กลับสถานีสุดท้าย
        return null; // เลื่อนปกติ
    }
    // สถานีที่ใกล้ที่สุด
    let near = 0;
    for (let i = 1; i < n; i++) if (Math.abs(centers[i] - scroll) < Math.abs(centers[near] - scroll)) near = i;
    // ค้างอยู่ระหว่างสถานี (เช่นลากแถบเลื่อน) → ไปสถานีถัดไปตามทิศ ไม่ใช่เด้งกลับ
    const off = scroll - centers[near];
    let base = near;
    if (dir > 0 && off > 8) base = near;
    else if (dir > 0 && off < -8) base = near - 1;
    else if (dir < 0 && off < -8) base = near;
    else if (dir < 0 && off > 8) base = near + 1;
    const next = base + dir;
    if (next > n - 1) return null; // สถานีสุดท้าย → ลง footer ตามปกติ
    if (next < 0) return scroll > 1 ? 0 : null;
    return next;
}

const ease = (x) => (x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2);

/** ผู้ใช้ซูมหน้าจอ (pinch) อยู่ไหม */
const zoomed = () => (typeof window !== 'undefined' && window.visualViewport ? window.visualViewport.scale > 1.01 : false);

/**
 * เลื่อนทันที — เว็บนี้ตั้ง scroll-behavior: smooth ทั้ง html
 * ถ้าเรียก scrollTo ธรรมดาทุกเฟรม เบราว์เซอร์จะเริ่มเลื่อนนุ่มใหม่ทุกเฟรม กระตุกและไปไม่ถึง
 */
function jump(y) {
    window.scrollTo({ top: y, behavior: 'instant' });
}

/**
 * @param {{ getCenters: ()=>number[], onSnap?: (index:number)=>void, reduced?: boolean }} opts
 */
export function createSnapScroll(opts) {
    let anim = null; // { from, to, t0, dur, index }
    let lockUntil = 0;
    let touch = null; // { y0, x0, dir, active }

    const vh = () => window.innerHeight;
    const isTyping = (el) => !!el?.closest?.('input, textarea, select, [contenteditable="true"]');

    function go(index) {
        const centers = opts.getCenters();
        if (!centers.length) return;
        const i = Math.max(0, Math.min(centers.length - 1, index));
        const to = centers[i];
        const from = window.scrollY;
        if (Math.abs(to - from) < 2) return;
        opts.onSnap?.(i);
        if (opts.reduced) {
            // กระโดดทันทีก็ต้องล็อก ไม่งั้นแรงเฉื่อยทัชแพด (wheel ทุก ~16ms) พาไหลทะลุทุกสถานี
            lockUntil = performance.now() + 400;
            jump(to);
            return;
        }
        // ระยะไกล = บินนานขึ้นเล็กน้อย ให้กล้องได้ "เดินทาง" จริง
        const dur = Math.min(1400, Math.max(750, 700 + (Math.abs(to - from) / vh()) * 260));
        anim = { from, to, t0: performance.now(), dur, index: i };
        lockUntil = performance.now() + dur + 120;
    }

    /** เรียกทุกเฟรมจากลูปของหน้า 3D */
    function step() {
        if (!anim) return;
        const k = Math.min(1, (performance.now() - anim.t0) / anim.dur);
        jump(anim.from + (anim.to - anim.from) * ease(k));
        if (k >= 1) anim = null;
    }

    function move(dir) {
        const target = snapTarget({ scroll: window.scrollY, centers: opts.getCenters(), dir, vh: vh() });
        if (target === null) return false;
        go(target);
        return true;
    }

    function onWheel(e) {
        if (e.ctrlKey || !e.deltaY || zoomed()) return; // ctrl+ล้อ = ซูมหน้า
        if (Math.abs(e.deltaX) >= Math.abs(e.deltaY)) return; // ปัดแนวนอน
        const dir = Math.sign(e.deltaY);
        if (canScrollInside(e.target, dir)) return;
        const now = performance.now();
        if (anim || now < lockUntil) {
            // ยังบินอยู่หรือแรงเฉื่อยของทัชแพดยังไม่หมด: กินเหตุการณ์ไว้ ต่อเวลาล็อก
            if (window.scrollY <= opts.getCenters().at(-1) + 4 || anim) {
                e.preventDefault();
                lockUntil = Math.max(lockUntil, now + 280);
            }
            return;
        }
        const target = snapTarget({ scroll: window.scrollY, centers: opts.getCenters(), dir, vh: vh() });
        if (target === null) return; // footer: เลื่อนปกติ
        e.preventDefault();
        go(target);
    }

    function onTouchStart(e) {
        if (e.touches.length !== 1 || zoomed()) {
            touch = null;
            return;
        }
        const t = e.touches[0];
        touch = { y0: t.clientY, x0: t.clientX, target: e.target, dir: 0, active: null };
    }

    function onTouchMove(e) {
        if (!touch) return;
        const t = e.touches[0];
        const dy = touch.y0 - t.clientY;
        const dx = touch.x0 - t.clientX;
        if (touch.active === null) {
            if (Math.abs(dy) < 6 && Math.abs(dx) < 6) return;
            // ปัดแนวนอน (เช่นเลื่อนรายการในแผง) ไม่ใช่เรื่องของเรา
            if (Math.abs(dx) > Math.abs(dy)) {
                touch.active = false;
                return;
            }
            const dir = Math.sign(dy);
            touch.dir = dir;
            const snap = !canScrollInside(touch.target, dir) && snapTarget({ scroll: window.scrollY, centers: opts.getCenters(), dir, vh: vh() }) !== null;
            touch.active = snap;
        }
        if (touch.active && e.cancelable) e.preventDefault();
    }

    function onTouchEnd(e) {
        if (!touch) return;
        const t = e.changedTouches[0];
        const dy = touch.y0 - t.clientY;
        const was = touch;
        touch = null;
        if (!was.active || anim || Math.abs(dy) < 36) return;
        move(Math.sign(dy));
    }

    function onKey(e) {
        if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || isTyping(e.target)) return;
        const map = { ArrowDown: 1, PageDown: 1, ' ': e.shiftKey ? -1 : 1, ArrowUp: -1, PageUp: -1 };
        if (e.key === 'Home' || e.key === 'End') {
            const centers = opts.getCenters();
            if (e.key === 'End' || window.scrollY > centers.at(-1) + 4) return;
            e.preventDefault();
            go(0);
            return;
        }
        const dir = map[e.key];
        if (!dir) return;
        // ปุ่ม/ลิงก์ที่โฟกัสอยู่: Space ใช้กดปุ่ม ไม่ใช่เลื่อน
        if (e.key === ' ' && e.target?.closest?.('button, a[href]')) return;
        if (anim) {
            e.preventDefault();
            return;
        }
        if (move(dir)) e.preventDefault();
    }

    function attach() {
        window.addEventListener('wheel', onWheel, { passive: false });
        window.addEventListener('touchstart', onTouchStart, { passive: true });
        window.addEventListener('touchmove', onTouchMove, { passive: false });
        window.addEventListener('touchend', onTouchEnd, { passive: true });
        window.addEventListener('keydown', onKey);
    }

    function detach() {
        window.removeEventListener('wheel', onWheel);
        window.removeEventListener('touchstart', onTouchStart);
        window.removeEventListener('touchmove', onTouchMove);
        window.removeEventListener('touchend', onTouchEnd);
        window.removeEventListener('keydown', onKey);
        anim = null;
    }

    return {
        attach,
        detach,
        step,
        go,
        next: () => move(1),
        get busy() {
            return !!anim;
        },
    };
}
