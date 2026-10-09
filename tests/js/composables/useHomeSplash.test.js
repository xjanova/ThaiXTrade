/**
 * TPIX TRADE — จอโหลดหน้าแรก ช่วง B (ความคืบหน้าจริงหลัง Vue ขึ้น)
 *
 * - ต่อจากจุดที่ช่วง A คืบมาได้ ไปถึง 100% (ไม่กระโดดกลับไปเริ่มที่ 0)
 * - น้ำหนักงาน (โค้ด 3D / สร้างฉาก / รูป / คอมไพล์ / ฟอนต์) แปลงเป็น % ถูกต้อง และไม่ถอยหลัง
 * - ฉากพร้อมแล้ว รูปที่ยังค้างรอได้แค่ช่วงสั้น ๆ (ไม่กั้นผู้ใช้)
 * - ฟัง three.js LoadingManager ตัวจริงได้ (ตัวนับของ manager สะสมตลอดอายุหน้า)
 * - ไม่มีจอโหลด (Inertia กลับมาหน้าแรก) = ไม่ทำอะไรเลย
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { LoadingManager } from 'three';
import {
    STEP_WEIGHTS,
    READY_GRACE_MS,
    mapStageB,
    createSplashTracker,
    useHomeSplash,
    whenSplashGone,
    resetHomeSplash,
    splashController,
} from '@/Composables/useHomeSplash';
import { bootSplash, splashValue } from '../utils/homeSplashDom';

/** ตัวคุมจอโหลดปลอม — จดทุกค่าที่ถูกส่งมา */
function fakeController(start = 30) {
    const goneCbs = [];
    const c = {
        active: true,
        gone: false,
        calls: [],
        set: vi.fn((pct, ceil) => c.calls.push({ pct, ceil })),
        value: () => start,
        done: vi.fn(() => {
            c.active = false;
        }),
        fail: vi.fn(() => {
            c.active = false;
        }),
        onGone: (cb) => goneCbs.push(cb),
        vanish() {
            c.active = false;
            c.gone = true;
            goneCbs.splice(0).forEach((cb) => cb());
        },
    };
    return c;
}

const lastSet = (c) => c.calls[c.calls.length - 1];
const TOTAL = Object.values(STEP_WEIGHTS).reduce((a, b) => a + b, 0);

afterEach(() => {
    vi.useRealTimers();
    delete window.__tpixSplash;
    resetHomeSplash();
    document.body.innerHTML = '';
    localStorage.setItem('tpix_locale', 'en');
});

describe('mapStageB', () => {
    it('continues from where stage A left the bar up to 100', () => {
        expect(mapStageB(30, 0)).toBe(30);
        expect(mapStageB(30, 1)).toBe(100);
        expect(mapStageB(30, 0.5)).toBe(65);
        expect(mapStageB(0, 0.25)).toBe(25);
    });

    it('clamps bad input instead of producing NaN or overshooting', () => {
        expect(mapStageB(30, -1)).toBe(30);
        expect(mapStageB(30, 7)).toBe(100);
        expect(mapStageB(30, Number.NaN)).toBe(30);
        expect(mapStageB(Number.NaN, 0.5)).toBe(50);
        expect(mapStageB(250, 0)).toBe(99);
    });
});

describe('createSplashTracker', () => {
    it('maps finished work onto the remaining range by weight', () => {
        const c = fakeController(30);
        const s = createSplashTracker(c);
        s.complete('chunk');
        expect(lastSet(c).pct).toBeCloseTo(mapStageB(30, STEP_WEIGHTS.chunk / TOTAL));
        s.complete('world');
        expect(lastSet(c).pct).toBeCloseTo(mapStageB(30, (STEP_WEIGHTS.chunk + STEP_WEIGHTS.world) / TOTAL));
    });

    it('gives a waiting step a ceiling to creep toward, but not all the way', () => {
        const c = fakeController(30);
        const s = createSplashTracker(c);
        s.start('chunk');
        const { pct, ceil } = lastSet(c);
        expect(pct).toBe(30);
        expect(ceil).toBeGreaterThan(30);
        expect(ceil).toBeLessThan(mapStageB(30, STEP_WEIGHTS.chunk / TOTAL));
    });

    it('never reports a lower value than before, and the ceiling stays above the floor', () => {
        const c = fakeController(20);
        const s = createSplashTracker(c);
        s.start('chunk');
        s.start('fonts');
        s.progress('assets', 0.8);
        s.progress('assets', 0.3); // ไฟล์ที่ manager รายงานย้อน — ห้ามถอย
        s.complete('chunk');
        s.progress('compile', 0.5);
        s.complete('fonts');
        s.progress('nonsense', 1); // งานที่ไม่รู้จักไม่มีผล
        const floors = c.calls.map((x) => x.pct);
        expect(floors.every((v, i) => i === 0 || v >= floors[i - 1])).toBe(true);
        c.calls.forEach(({ pct, ceil }) => expect(ceil).toBeGreaterThanOrEqual(pct));
    });

    it('ready() finishes at once when nothing is still loading', () => {
        const c = fakeController();
        const s = createSplashTracker(c);
        s.complete('chunk');
        s.ready();
        expect(c.done).toHaveBeenCalledTimes(1);
        s.ready();
        s.finish();
        expect(c.done).toHaveBeenCalledTimes(1);
    });

    it('ready() waits for late images only up to the grace period', () => {
        vi.useFakeTimers();
        const c = fakeController();
        const s = createSplashTracker(c);
        s.start('assets');
        s.ready();
        vi.advanceTimersByTime(READY_GRACE_MS - 1);
        expect(c.done).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(c.done).toHaveBeenCalledTimes(1);
    });

    it('ready() finishes as soon as the pending step completes', () => {
        vi.useFakeTimers();
        const c = fakeController();
        const s = createSplashTracker(c);
        s.start('fonts');
        s.ready();
        s.complete('fonts');
        expect(c.done).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(READY_GRACE_MS * 2);
        expect(c.done).toHaveBeenCalledTimes(1);
    });

    it('track() passes the result through and completes the step either way', async () => {
        const c = fakeController(0);
        const s = createSplashTracker(c);
        await expect(s.track('chunk', Promise.resolve('module'))).resolves.toBe('module');
        expect(lastSet(c).pct).toBeCloseTo((100 * STEP_WEIGHTS.chunk) / TOTAL);

        const err = new Error('chunk 404');
        await expect(s.track('fonts', Promise.reject(err))).rejects.toBe(err);
        expect(lastSet(c).pct).toBeCloseTo((100 * (STEP_WEIGHTS.chunk + STEP_WEIGHTS.fonts)) / TOTAL);
    });

    it('dismiss() closes without celebrating, once', () => {
        const c = fakeController();
        const s = createSplashTracker(c);
        s.dismiss();
        s.dismiss();
        s.finish();
        expect(c.fail).toHaveBeenCalledTimes(1);
        expect(c.done).not.toHaveBeenCalled();
        expect(s.active).toBe(false);
    });

    it('goes quiet when the splash ends by itself (hard timeout)', () => {
        const c = fakeController();
        const s = createSplashTracker(c);
        c.vanish();
        s.complete('chunk');
        s.ready();
        expect(c.set).not.toHaveBeenCalled();
        expect(c.done).not.toHaveBeenCalled();
    });

    it('without a splash every call is a harmless no-op', async () => {
        const s = createSplashTracker(null);
        expect(s.active).toBe(false);
        s.start('chunk');
        s.ready();
        s.finish();
        s.dismiss();
        await expect(s.track('chunk', Promise.resolve(42))).resolves.toBe(42);
        expect(typeof s.trackLoads({})).toBe('function');
    });
});

describe('trackLoads (three.js LoadingManager)', () => {
    /** โหลดไฟล์ปลอมผ่าน manager แบบเดียวกับ ImageLoader ของ three */
    const begin = (m, ...urls) => urls.forEach((u) => m.itemStart(u));
    const end = (m, ...urls) => urls.forEach((u) => m.itemEnd(u));

    it('counts only the batch that starts after hooking', () => {
        const manager = new LoadingManager();
        // หน้านี้เคยโหลดไป 3 ไฟล์แล้ว (ตัวนับสะสม) — ต้องไม่ทำให้ชุดใหม่ดูเหมือนเสร็จไปแล้ว 3/7
        begin(manager, 'old1', 'old2', 'old3');
        end(manager, 'old1', 'old2', 'old3');

        const c = fakeController(0);
        const s = createSplashTracker(c, { weights: { assets: 1 } });
        s.trackLoads(manager);
        begin(manager, 'a', 'b', 'c', 'd');
        expect(lastSet(c).pct).toBe(0);
        end(manager, 'a');
        expect(lastSet(c).pct).toBeCloseTo(25);
        end(manager, 'b', 'c');
        expect(lastSet(c).pct).toBeCloseTo(75);
        end(manager, 'd');
        expect(lastSet(c).pct).toBeCloseTo(100);
    });

    it('a failed image still counts as done (the scene uses a fallback)', () => {
        const manager = new LoadingManager();
        const c = fakeController(0);
        const s = createSplashTracker(c, { weights: { assets: 1 } });
        s.trackLoads(manager);
        begin(manager, 'a', 'b');
        manager.itemError('a');
        end(manager, 'a', 'b');
        expect(lastSet(c).pct).toBeCloseTo(100);
    });

    it('restores the manager callbacks when unhooked or finished', () => {
        const onLoad = vi.fn();
        const manager = new LoadingManager(onLoad);
        const s = createSplashTracker(fakeController());
        const unhook = s.trackLoads(manager);
        expect(manager.onLoad).not.toBe(onLoad);
        unhook();
        expect(manager.onLoad).toBe(onLoad);

        s.trackLoads(manager);
        s.finish();
        expect(manager.onLoad).toBe(onLoad);
    });

    it('still calls the callbacks someone else had set', () => {
        const onProgress = vi.fn();
        const manager = new LoadingManager(undefined, onProgress);
        createSplashTracker(fakeController()).trackLoads(manager);
        begin(manager, 'a');
        end(manager, 'a');
        expect(onProgress).toHaveBeenCalledWith('a', 1, 1);
    });
});

describe('useHomeSplash / whenSplashGone', () => {
    it('shares one tracker across the page while the splash is up', () => {
        window.__tpixSplash = fakeController();
        const a = useHomeSplash();
        const b = useHomeSplash();
        expect(a).toBe(b);
        expect(a.active).toBe(true);
        a.finish();
        expect(useHomeSplash().active).toBe(false);
    });

    it('is a no-op when there is no splash (Inertia visit back to home)', () => {
        expect(splashController()).toBeNull();
        const s = useHomeSplash();
        expect(s.active).toBe(false);
        expect(() => s.finish()).not.toThrow();
    });

    it('whenSplashGone resolves at once without a splash, otherwise after it is removed', async () => {
        await expect(whenSplashGone()).resolves.toBeUndefined();
        const c = fakeController();
        window.__tpixSplash = c;
        let done = false;
        const p = whenSplashGone().then(() => {
            done = true;
        });
        await Promise.resolve();
        expect(done).toBe(false);
        c.vanish();
        await p;
        expect(done).toBe(true);
    });
});

describe('stage A → stage B hand-off (real inline controller)', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'requestAnimationFrame', 'cancelAnimationFrame', 'Date', 'performance'] });
    });

    it('picks up where the creep was, fills by real progress, then removes itself', async () => {
        const samples = [];
        const sample = (ms) => {
            for (let t = 0; t < ms; t += 16) {
                vi.advanceTimersByTime(16);
                const v = splashValue();
                if (Number.isFinite(v)) samples.push(v);
            }
        };

        bootSplash();
        sample(1500); // JS ก้อนหลักกำลังโหลด
        const handoff = splashValue();
        expect(handoff).toBeGreaterThan(3);

        const s = useHomeSplash(); // Pages/Home.vue setup
        const chunk = s.track('chunk', new Promise((r) => setTimeout(() => r('Home3D'), 1200)));
        s.start('fonts');
        sample(1300);
        await expect(chunk).resolves.toBe('Home3D');
        s.complete('world');
        const manager = new LoadingManager();
        s.trackLoads(manager);
        manager.itemStart('/tpixlogo.webp');
        manager.itemStart('/images/art/card-explorer.webp');
        s.start('compile');
        sample(400);
        manager.itemEnd('/tpixlogo.webp');
        s.complete('compile');
        s.ready(); // ฉากวาดได้ — รูปการ์ดใบหนึ่งยังค้าง
        sample(300);
        expect(window.__tpixSplash.active).toBe(true);
        manager.itemEnd('/images/art/card-explorer.webp');
        s.complete('fonts');
        expect(window.__tpixSplash.active).toBe(false);

        sample(1000);
        expect(document.getElementById('tpix-splash')).toBeNull();
        expect(samples.every((v, i) => i === 0 || v >= samples[i - 1])).toBe(true);
        expect(Math.max(...samples)).toBe(100);
        expect(manager.onLoad).toBeUndefined(); // ถอดตัวดักออกแล้ว
    });
});
