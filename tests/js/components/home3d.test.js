/**
 * TPIX TRADE — ตรรกะหน้าแรก 3D ที่ไม่ต้องใช้ WebGL
 *
 * - journeyF / pathParam / panelVisibility: การเลื่อนหน้า → ตำแหน่งกล้อง/ความชัดของแผง
 * - governor: ตัดสิน "เครื่องช้า" จากเวลาเฟรมจริง แต่ไม่นับช่วงที่เบราว์เซอร์หยุดวาด
 * - sprite animator ของน้อง TPIX: ท่า/คลิป/การพลิกตัว
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';

// engine.js import three + RoomEnvironment — ใน jsdom ไม่มี WebGL ใช้แค่ฟังก์ชันบริสุทธิ์
vi.mock('three', () => ({}));
vi.mock('three/examples/jsm/environments/RoomEnvironment.js', () => ({ RoomEnvironment: class {} }));

import { STATIONS, journeyF, pathParam, panelVisibility, cameraFor } from '@/Components/Home3D/stations';
import { createGovernor, QUALITY_STEPS } from '@/Components/Home3D/engine';
import { createSpriteAnimator, canPlayAlphaVideo, ACTION_TIME } from '@/Components/Home/spriteStage';
import { SECTIONS } from '@/Components/Home/mascotGuide';

describe('journey', () => {
    const centers = [0, 1000, 2000, 3000];

    it('is exactly at a station when the scroll is at its centre', () => {
        centers.forEach((c, i) => expect(journeyF(c, centers)).toBeCloseTo(i));
    });

    it('moves monotonically with the scroll', () => {
        let prev = -Infinity;
        for (let y = -500; y <= 3600; y += 50) {
            const f = journeyF(y, centers);
            expect(f).toBeGreaterThanOrEqual(prev);
            prev = f;
        }
    });

    it('holds the camera still around a station so the panel can be read', () => {
        expect(pathParam(1.1, 4)).toBe(1);
        expect(pathParam(1.29, 4)).toBe(1);
        expect(pathParam(1.5, 4)).toBeCloseTo(1.5);
        expect(pathParam(-0.4, 4)).toBe(0);
        expect(pathParam(9, 4)).toBe(3);
    });

    it('shows one panel at a time', () => {
        expect(panelVisibility(2, 2)).toBe(1);
        expect(panelVisibility(2.6, 2)).toBe(0);
        const v = panelVisibility(2.4, 2);
        expect(v).toBeGreaterThan(0);
        expect(v).toBeLessThan(1);
        // ครึ่งทางระหว่างสถานี: สองแผงไม่ชัดพร้อมกัน
        expect(panelVisibility(2.5, 2) + panelVisibility(2.5, 3)).toBeLessThanOrEqual(1e-9);
    });

    it('frames every station on the side away from its text panel', () => {
        for (const st of STATIONS) {
            const { pos, look } = cameraFor(st);
            if (st.panel === 'left') expect(look[0]).toBeLessThan(st.pos[0]);
            if (st.panel === 'right') expect(look[0]).toBeGreaterThan(st.pos[0]);
            expect(pos[2]).toBeGreaterThan(st.pos[2]);
        }
    });

    it('has a mascot line for every station', () => {
        STATIONS.forEach((st) => expect(SECTIONS[st.key], st.key).toBeTruthy());
    });
});

describe('governor', () => {
    const feed = (g, dt, n) => {
        let last = null;
        for (let i = 0; i < n; i++) last = g.push(dt) ?? last;
        return last;
    };

    it('says ok at 60 fps', () => {
        expect(feed(createGovernor({ warmup: 0 }), 1 / 60, 60)).toBe('ok');
    });

    it('asks to degrade at 20 fps', () => {
        expect(feed(createGovernor({ warmup: 0 }), 1 / 20, 60)).toBe('degrade');
    });

    it('ignores gaps when the browser stopped drawing (hidden tab)', () => {
        const g = createGovernor({ warmup: 0 });
        for (let i = 0; i < 59; i++) g.push(1 / 60);
        expect(g.push(3)).toBeNull();
        // หน้าต่างถูกล้าง → ยังไม่ครบ 60 เฟรม ยังไม่ตัดสิน
        expect(feed(g, 1 / 60, 30)).toBeNull();
    });

    it('waits for the warm-up before judging', () => {
        const g = createGovernor({ warmup: 1 });
        expect(feed(g, 1 / 20, 20)).toBeNull();
    });

    it('has quality steps from sharp to light', () => {
        expect(QUALITY_STEPS[0]).toBeGreaterThan(QUALITY_STEPS.at(-1));
    });
});

describe('sprite animator', () => {
    // dt ถูกตัดที่ 0.1 วิ/เฟรม (กันกระโดดหลังแท็บถูกซ่อน) → เดินทีละเฟรม
    const run = (a, seconds) => {
        for (let t = 0; t < seconds; t += 0.05) a.update(0.05);
    };

    it('returns to idle after a timed gesture, or to talk while speaking', () => {
        const a = createSpriteAnimator();
        a.setAction('wave');
        run(a, ACTION_TIME.wave + 0.1);
        expect(a.current()).toBe('idle');
        a.setSpeaking(true);
        a.setAction('cheer');
        run(a, ACTION_TIME.cheer + 0.1);
        expect(a.current()).toBe('talk');
        a.setSpeaking(false);
        a.update(0.05);
        expect(a.current()).toBe('idle');
    });

    it('uses a motion clip only when it is ready, otherwise the still pose', () => {
        const ready = new Set();
        const a = createSpriteAnimator({ hasClip: (k) => ready.has(k) });
        a.setSpeaking(true);
        let s = a.update(0.016);
        expect(s.clip).toBeNull();
        expect(s.pose).toBe('idle');
        ready.add('talk');
        s = a.update(0.016);
        expect(s.clip).toBe('talk');
    });

    it('does not play clips when the visitor asked for less motion', () => {
        const a = createSpriteAnimator({ reduced: true, hasClip: () => true });
        expect(a.update(0.016).clip).toBeNull();
    });

    it('mirrors to face content on her right, and when flying right', () => {
        const a = createSpriteAnimator();
        expect(a.update(0.016).mirror).toBe(false);
        a.setFacing(1);
        expect(a.update(0.016).mirror).toBe(true);
        a.setFacing(-1);
        a.setAction('fly', { dir: 1, hold: 5 });
        expect(a.update(0.016).mirror).toBe(true);
        a.setAction('fly', { dir: -1, hold: 5 });
        expect(a.update(0.016).mirror).toBe(false);
    });

    it('dozes into a sleep that lasts until someone wakes her', () => {
        const a = createSpriteAnimator();
        a.setAction('doze');
        run(a, ACTION_TIME.doze + 0.1);
        expect(a.current()).toBe('sleep');
        expect(a.update(0.05).pose).toBe('sleep');
        run(a, 120);
        expect(a.current()).toBe('sleep');
        a.setAction('wake');
        run(a, ACTION_TIME.wake + 0.1);
        expect(a.current()).toBe('idle');
    });

    it('lets a play-once clip finish instead of cutting it at the default time', () => {
        const a = createSpriteAnimator({ hasClip: (k) => k === 'yawn', clipDuration: () => 6 });
        a.setAction('yawn');
        run(a, ACTION_TIME.yawn + 0.5);
        expect(a.current()).toBe('yawn');
        expect(a.update(0.016).clip).toBe('yawn');
        run(a, 6);
        expect(a.current()).toBe('idle');
    });

    it('falls back to the still pose for idle moves when there is no clip', () => {
        const a = createSpriteAnimator();
        a.setAction('stretch');
        expect(a.update(0.016)).toMatchObject({ clip: null, pose: 'stretch' });
        a.setAction('look');
        expect(a.update(0.016)).toMatchObject({ clip: null, pose: 'idle' });
    });

    it('skips transparent video on Safari and iOS (they draw a black box)', () => {
        const safari = { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15' };
        const ios = { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0 Mobile/15E148 Safari/604.1' };
        const saveData = { userAgent: 'Chrome/120', connection: { saveData: true } };
        expect(canPlayAlphaVideo(safari)).toBe(false);
        expect(canPlayAlphaVideo(ios)).toBe(false);
        expect(canPlayAlphaVideo(saveData)).toBe(false);
    });
});

describe('snap scroll (one scroll or swipe = one stop)', async () => {
    const { snapTarget } = await import('@/Components/Home3D/snapScroll');
    const centers = [0, 1000, 2000, 3000];
    const vh = 900;

    it('goes to the next or previous stop from a stop', () => {
        expect(snapTarget({ scroll: 0, centers, dir: 1, vh })).toBe(1);
        expect(snapTarget({ scroll: 1000, centers, dir: 1, vh })).toBe(2);
        expect(snapTarget({ scroll: 2000, centers, dir: -1, vh })).toBe(1);
    });

    it('never bounces back when left between two stops (e.g. dragged the scrollbar)', () => {
        expect(snapTarget({ scroll: 1400, centers, dir: 1, vh })).toBe(2);
        expect(snapTarget({ scroll: 1400, centers, dir: -1, vh })).toBe(1);
        expect(snapTarget({ scroll: 1700, centers, dir: -1, vh })).toBe(1);
    });

    it('lets the last stop scroll on into the footer, and pulls back up near it', () => {
        expect(snapTarget({ scroll: 3000, centers, dir: 1, vh })).toBeNull();
        expect(snapTarget({ scroll: 3300, centers, dir: -1, vh })).toBe(3);
        expect(snapTarget({ scroll: 4200, centers, dir: -1, vh })).toBeNull();
    });

    it('does nothing at the very top when scrolling up', () => {
        expect(snapTarget({ scroll: 0, centers, dir: -1, vh })).toBeNull();
    });

    describe('wheel handling', async () => {
        const { createSnapScroll } = await import('@/Components/Home3D/snapScroll');

        // jsdom ไม่มีการเลื่อนจริง → จำลอง scrollY + scrollTo
        function setup({ reduced = false } = {}) {
            let y = 0;
            Object.defineProperty(window, 'scrollY', { configurable: true, get: () => y });
            window.scrollTo = vi.fn((o) => {
                y = o.top;
            });
            const snap = createSnapScroll({ getCenters: () => centers, reduced });
            snap.attach();
            const wheel = (deltaY, deltaX = 0) => {
                const e = new WheelEvent('wheel', { deltaY, deltaX, cancelable: true, bubbles: true });
                window.dispatchEvent(e);
                return e;
            };
            return { snap, wheel, y: () => y };
        }

        it('with reduced motion, a trackpad flick (burst of wheel events) moves only one stop', () => {
            const { snap, wheel, y } = setup({ reduced: true });
            for (let i = 0; i < 30; i++) wheel(8);
            expect(y()).toBe(1000);
            snap.detach();
        });

        it('ignores horizontal swipes that carry a little vertical delta', () => {
            const { snap, wheel, y } = setup({ reduced: true });
            const e = wheel(3, 40);
            expect(y()).toBe(0);
            expect(e.defaultPrevented).toBe(false);
            snap.detach();
        });

        it('lets a pinch-zoomed visitor scroll freely', () => {
            const { snap, wheel, y } = setup({ reduced: true });
            Object.defineProperty(window, 'visualViewport', { configurable: true, value: { scale: 2 } });
            const e = wheel(40);
            expect(y()).toBe(0);
            expect(e.defaultPrevented).toBe(false);
            delete window.visualViewport;
            snap.detach();
        });
    });
});

describe('sound effects', async () => {
    const { sfx, __soundState } = await import('@/Components/Home3D/sound');

    it('is safe to call before any user gesture and without Web Audio', () => {
        expect(() => {
            sfx.hover(0.5, 0.5);
            sfx.click();
            sfx.arrive(2);
            sfx.swoosh(1);
            sfx.coin();
            sfx.fly(1);
            sfx.pip(1);
            sfx.unlock();
        }).not.toThrow();
        expect(__soundState().hasContext).toBe(false);
    });

    it('remembers the mute switch', () => {
        sfx.setEnabled(false);
        expect(localStorage.getItem('tpix_home_sound')).toBe('off');
        sfx.setEnabled(true);
        expect(localStorage.getItem('tpix_home_sound')).toBe('on');
    });
});
