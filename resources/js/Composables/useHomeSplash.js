/**
 * TPIX TRADE — จอโหลดหน้าแรก ช่วง B: ส่งความคืบหน้าจริงให้หลอดดาวน์โหลด
 *
 * ช่วง A (ก่อน JS ก้อนหลักมาถึง) อยู่ใน resources/views/partials/home-splash/splash.js
 * — วัดไม่ได้จริง จึงคืบเองเข้าหา ~35% แล้วเปิด window.__tpixSplash ไว้ให้ไฟล์นี้รับช่วงต่อ
 *
 * พอ Vue ขึ้น ไฟล์นี้เอาค่าที่หลอดอยู่ ณ ตอนนั้นเป็นจุดตั้งต้น (base) แล้วแบ่ง "ที่เหลือถึง 100%"
 * ตามน้ำหนักของงานที่หน้าแรกต้องรอจริง (วัดจาก build: three.js ก้อนเดียว ~740 KB ใหญ่สุด):
 *
 *   chunk   — โค้ดหน้า 3D + three.js (dynamic import ใน Pages/Home.vue)
 *   world   — สร้างฉาก (เรขาคณิต/วัสดุ/พื้นผิวที่วาดเอง) ใน Home3D
 *   assets  — รูปที่ฉากโหลด (โลโก้บนเหรียญ การ์ดระบบนิเวศ ไอคอนจุดเด่น) ผ่าน THREE.DefaultLoadingManager
 *   compile — คอมไพล์ shader ล่วงหน้า (เครื่องช้า = ส่วนที่นานที่สุดหลังโหลดเสร็จ)
 *   fonts   — document.fonts.ready (ข้อความฮีโร่ไม่กระโดดตอนจอโหลดจางออก)
 *
 * ระหว่างรองานที่เริ่มแล้วแต่ยังไม่จบ หลอดได้ "เพดาน" ให้คืบไปได้ทีละนิด — ไม่ดูค้าง แต่ไม่วิ่งเลยของจริง
 *
 * ไม่มีจอโหลด (Inertia เปลี่ยนหน้ากลับมาหน้าแรก / หน้าอื่น / จอจบไปแล้ว) → ทุกฟังก์ชันไม่ทำอะไรเลย
 *
 * Developed by Xman Studio
 */

/** สัดส่วนของ "ที่เหลือ" ที่แต่ละงานครอบครอง (รวมเท่าไหร่ก็ได้ คิดเป็นสัดส่วน) */
export const STEP_WEIGHTS = Object.freeze({ chunk: 50, world: 10, assets: 15, compile: 20, fonts: 5 });

/** ฉาก 3D พร้อมแล้วแต่รูปประกอบ/ฟอนต์ยังไม่ครบ → รอเพิ่มได้ไม่เกินนี้ (ฉากใช้งานได้แล้ว ไม่ควรกั้นผู้ใช้) */
export const READY_GRACE_MS = 1200;

/** ระหว่างรอ หลอดคืบไปได้ไม่เกินสัดส่วนนี้ของงานที่ค้างอยู่ — งานจบจริงแล้วค่อยวิ่งที่เหลือ */
const CREEP_SHARE = 0.85;

function clamp01(v) {
    const n = Number(v);
    return Number.isFinite(n) ? Math.min(1, Math.max(0, n)) : 0;
}

/** แปลงความคืบหน้าช่วง B (0–1) เป็นเปอร์เซ็นต์บนหลอด: ต่อจาก base ที่ช่วง A ทิ้งไว้ ไปจนถึง 100 */
export function mapStageB(base, fraction) {
    const b = Math.min(99, Math.max(0, Number(base) || 0));
    return b + (100 - b) * clamp01(fraction);
}

/** ตัวคุมจอโหลดที่ยังรับค่าอยู่ (มีเฉพาะตอนเปิด / แบบเต็มหน้า) */
export function splashController(win = typeof window !== 'undefined' ? window : undefined) {
    const c = win?.__tpixSplash;
    return c && c.active && typeof c.set === 'function' ? c : null;
}

const NOOP = Object.freeze({
    active: false,
    start() {},
    progress() {},
    complete() {},
    track: (_step, promise) => promise,
    trackLoads: () => () => {},
    ready() {},
    finish() {},
    dismiss() {},
});

/**
 * ตัวนับความคืบหน้าช่วง B — แยกจาก Vue/three ให้เทสต์ได้ (ส่ง controller ปลอมเข้ามา)
 * @param {{ set(pct:number, ceil?:number):void, value():number, done():void, fail():void, active:boolean, onGone?(cb:Function):void } | null} controller
 */
export function createSplashTracker(controller, opts = {}) {
    if (!controller) return NOOP;
    const weights = opts.weights ?? STEP_WEIGHTS;
    const grace = opts.grace ?? READY_GRACE_MS;
    const setTimer = opts.setTimer ?? ((fn, ms) => setTimeout(fn, ms));
    const clearTimer = opts.clearTimer ?? ((id) => clearTimeout(id));
    const total = Object.values(weights).reduce((a, b) => a + b, 0) || 1;
    // จุดตั้งต้น = ที่หลอดอยู่ตอนส่งไม้ (ช่วง A คืบมาได้เท่าไหร่) — ต่อจากตรงนั้น ไม่กระโดดกลับ
    const base = Math.min(99, Math.max(0, Number(controller.value?.()) || 0));
    const steps = new Map(); // งานที่เริ่มแล้ว → สัดส่วนที่เสร็จ (0–1)
    const unhooks = new Set();
    let isReady = false;
    let finished = false;
    let graceTimer = 0;

    const settled = () => [...steps.values()].every((f) => f >= 1);
    const live = () => !finished && controller.active;

    function push() {
        if (!live()) return;
        let got = 0;
        let could = 0;
        for (const [key, w] of Object.entries(weights)) {
            if (!steps.has(key)) continue;
            got += w * steps.get(key);
            could += w; // ถ้างานที่เริ่มแล้วจบหมด จะไปถึงตรงนี้
        }
        const now = got / total;
        const ceil = now + (could / total - now) * CREEP_SHARE;
        controller.set(mapStageB(base, now), mapStageB(base, ceil));
        if (isReady && settled()) api.finish();
    }

    function unhookAll() {
        [...unhooks].forEach((fn) => fn());
    }

    // จอโหลดจบเอง (ครบเวลา 12 วิ) → ถอดตัวดักของ three ออก ไม่ค้างไว้
    controller.onGone?.(() => {
        finished = true;
        clearTimer(graceTimer);
        unhookAll();
    });

    const api = {
        get active() {
            return live();
        },
        /** งานเริ่มแล้ว (ยังไม่มีตัวเลข) — หลอดได้เพดานให้คืบระหว่างรอ */
        start(step) {
            if (!(step in weights) || !live()) return;
            if (!steps.has(step)) steps.set(step, 0);
            push();
        },
        /** ความคืบหน้าของงาน 0–1 (ถอยไม่ได้) */
        progress(step, fraction) {
            if (!(step in weights) || !live()) return;
            steps.set(step, Math.max(steps.get(step) ?? 0, clamp01(fraction)));
            push();
        },
        complete(step) {
            api.progress(step, 1);
        },
        /** ผูกงานกับ promise: เริ่มเลย จบเมื่อ promise จบ (ล้มก็นับว่าจบ — ไม่ให้ค้างรอ) ส่งผลลัพธ์ต่อเหมือนเดิม */
        track(step, promise) {
            api.start(step);
            return Promise.resolve(promise).then(
                (value) => {
                    api.complete(step);
                    return value;
                },
                (error) => {
                    api.complete(step);
                    throw error;
                },
            );
        },
        /**
         * ฟังความคืบหน้าของ three.js LoadingManager (นับเป็นงาน assets)
         * นับเฉพาะชุดที่เริ่มหลังผูก (onStart บอกว่าก่อนหน้านี้โหลดไปแล้วกี่ไฟล์) — ตัวนับของ manager สะสมตลอดอายุหน้า
         * คืนฟังก์ชันถอดออก (คืน callback เดิมของ manager)
         */
        trackLoads(manager, step = 'assets') {
            if (!manager || !live()) return () => {};
            const prev = { onStart: manager.onStart, onProgress: manager.onProgress, onLoad: manager.onLoad };
            let batchBase = null;
            manager.onStart = (url, loaded, total) => {
                batchBase = loaded;
                api.start(step);
                prev.onStart?.(url, loaded, total);
            };
            manager.onProgress = (url, loaded, total) => {
                const from = batchBase ?? 0;
                if (total > from) api.progress(step, (loaded - from) / (total - from));
                prev.onProgress?.(url, loaded, total);
            };
            manager.onLoad = () => {
                api.complete(step);
                prev.onLoad?.();
            };
            const unhook = () => {
                if (!unhooks.delete(unhook)) return;
                Object.assign(manager, prev);
            };
            unhooks.add(unhook);
            return unhook;
        },
        /**
         * หน้าพร้อมใช้แล้ว (ฉาก 3D วาดได้) — งานที่ค้าง (รูป/ฟอนต์) รอได้อีกไม่เกิน grace แล้วจบ
         */
        ready() {
            if (!live() || isReady) return;
            isReady = true;
            if (settled()) {
                api.finish();
                return;
            }
            graceTimer = setTimer(() => api.finish(), grace);
            push();
        },
        /** ครบ 100% → น้องเชียร์ → จางออก */
        finish() {
            if (finished) return;
            finished = true;
            clearTimer(graceTimer);
            unhookAll();
            controller.done();
        },
        /** ปิดทันทีแบบไม่ฉลอง (ผู้ใช้เปลี่ยนหน้าไปก่อนโหลดเสร็จ) */
        dismiss() {
            if (finished) return;
            finished = true;
            clearTimer(graceTimer);
            unhookAll();
            controller.fail();
        },
    };
    return api;
}

let current = null;

/**
 * ตัวนับตัวเดียวทั้งหน้า — Pages/Home.vue สร้างตอน setup (= จุดส่งไม้จากช่วง A)
 * Home3D เรียกซ้ำได้ตัวเดิม · จอโหลดจบแล้ว/ไม่มีจอโหลด = ได้ตัวเปล่า ไม่ทำอะไร
 */
export function useHomeSplash() {
    if (current?.active) return current;
    const controller = splashController();
    current = controller ? createSplashTracker(controller) : null;
    return current ?? NOOP;
}

/** รอจอโหลดหายจากจอ (ไม่มีจอโหลด = ทันที) — น้อง TPIX รอทักทายหลังจอนี้หาย ผู้ใช้จะได้เห็นตั้งแต่คำแรก */
export function whenSplashGone(win = typeof window !== 'undefined' ? window : undefined) {
    const c = win?.__tpixSplash;
    if (!c || c.gone || typeof c.onGone !== 'function') return Promise.resolve();
    return new Promise((resolve) => c.onGone(resolve));
}

/** สำหรับเทสต์: ลืมตัวนับเดิม */
export function resetHomeSplash() {
    current = null;
}
