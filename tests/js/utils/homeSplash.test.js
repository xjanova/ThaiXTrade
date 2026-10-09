/**
 * TPIX TRADE — จอโหลดหน้าแรก ช่วง A (สคริปต์ inline ใน Blade)
 *
 * เจ้าของสั่ง: "หน้าแรกของเว็บควรมี หลอดดาวน์โหลด และอนิเมชั่นน้อง TPIX สวยๆ ระหว่างรอโหลด"
 * เทสต์ชุดนี้กันอาการที่ผู้ใช้จะเห็นทันที:
 *  - หลอดถอยหลัง / ถึง 100% ทั้งที่ยังไม่พร้อม
 *  - จอโหลดค้างบังเว็บไม่หาย (ต้องจบเองใน 12 วิ ไม่นับตอนแท็บถูกซ่อน)
 *  - จบแล้วไม่ถอดตัวเองออก / ใช้เวลาฉลองนานเกินไป
 *  - ภาษาไม่ตรงกับที่ผู้ใช้เลือก
 *  - ภาพน้องที่โหลดล่วงหน้าคนละเวอร์ชันกับที่หน้า 3D ใช้ (โหลดซ้ำสองรอบ)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { bootSplash, setHidden, splashValue, readSplashFile, shippedScript, splashMarkup } from './homeSplashDom';
import { POSES } from '@/Components/Home/spriteStage';

const FAKE = { toFake: ['setTimeout', 'clearTimeout', 'requestAnimationFrame', 'cancelAnimationFrame', 'Date', 'performance'] };

/** เดินเวลาทีละเฟรม เก็บค่าที่หลอดแสดงไว้ตรวจว่าไม่ถอย */
function run(ms, samples = []) {
    const step = 16;
    for (let t = 0; t < ms; t += step) {
        vi.advanceTimersByTime(Math.min(step, ms - t));
        const v = splashValue();
        if (Number.isFinite(v)) samples.push(v);
    }
    return samples;
}

const isMonotonic = (xs) => xs.every((v, i) => i === 0 || v >= xs[i - 1]);

beforeEach(() => {
    vi.useFakeTimers(FAKE);
});

afterEach(() => {
    vi.useRealTimers();
    delete window.__tpixSplash;
    document.body.innerHTML = '';
    localStorage.setItem('tpix_locale', 'en');
});

describe('home splash — stage A (before the JS bundle arrives)', () => {
    it('starts hidden in the markup and is revealed by its own script', () => {
        document.body.innerHTML = splashMarkup();
        expect(document.getElementById('tpix-splash').hidden).toBe(true);

        const { root, api } = bootSplash();
        expect(root.hidden).toBe(false);
        expect(api.active).toBe(true);
        expect(splashValue()).toBe(3);
        expect(root.querySelector('[data-splash-label]').textContent).not.toBe('');
    });

    it('creeps toward ~35% while waiting, never reaching it', () => {
        bootSplash();
        const samples = run(1000);
        expect(splashValue()).toBeGreaterThan(5);
        expect(splashValue()).toBeLessThan(35);
        run(9000, samples);
        expect(splashValue()).toBeGreaterThanOrEqual(30);
        expect(splashValue()).toBeLessThan(35);
        expect(isMonotonic(samples)).toBe(true);
    });

    it('never moves backwards, whatever the app reports', () => {
        const { api } = bootSplash();
        const samples = run(300);
        api.set(60);
        run(1500, samples);
        const high = splashValue();
        expect(high).toBeGreaterThanOrEqual(55);
        api.set(20, 25);
        api.set(Number.NaN);
        api.set(-5);
        run(1500, samples);
        expect(splashValue()).toBeGreaterThanOrEqual(high);
        expect(isMonotonic(samples)).toBe(true);
        expect(api.value()).toBeGreaterThanOrEqual(60);
    });

    it('creeps between real milestones up to (not past) the soft ceiling', () => {
        const { api } = bootSplash();
        api.set(40, 70);
        run(4000);
        expect(splashValue()).toBeGreaterThan(45);
        expect(splashValue()).toBeLessThan(70);
    });

    it('holds at 99% at most until done() — 100% means really ready', () => {
        const { api, root } = bootSplash();
        api.set(100, 150);
        run(5000);
        expect(splashValue()).toBeLessThanOrEqual(99);
        expect(root.classList.contains('is-done')).toBe(false);
    });
});

describe('home splash — finishing', () => {
    it('done(): fills to 100%, cheers, fades out, removes itself and tells whoever waits', () => {
        const { api, root } = bootSplash();
        const cheer = root.querySelector('[data-splash-cheer]');
        Object.defineProperty(cheer, 'complete', { value: true });
        Object.defineProperty(cheer, 'naturalWidth', { value: 560 });
        const gone = vi.fn();
        api.onGone(gone);
        run(800);

        api.done();
        expect(api.active).toBe(false);
        const { fill, hold, fade } = api.timing;
        // เจ้าของต้องการ: พร้อมแล้วอย่าให้รอนาน — ฉลองรวมจางไม่เกิน ~0.8 วิ
        expect(fill + hold + fade).toBeLessThanOrEqual(750);

        run(fill);
        expect(root.classList.contains('is-done')).toBe(true);
        expect(root.classList.contains('is-cheer')).toBe(true);
        expect(splashValue()).toBe(100);
        expect(root.querySelector('[data-splash-pct]').textContent).toBe('100%');
        expect(root.querySelector('[data-splash-label]').textContent).toBe("Ready — let's go!");

        run(hold);
        expect(root.classList.contains('is-leaving')).toBe(true);
        expect(document.getElementById('tpix-splash')).not.toBeNull();
        expect(gone).not.toHaveBeenCalled();

        run(fade + 60);
        expect(document.getElementById('tpix-splash')).toBeNull();
        expect(gone).toHaveBeenCalledTimes(1);
        expect(api.gone).toBe(true);

        // มาช้าหลังจบ = ทันที
        const late = vi.fn();
        api.onGone(late);
        expect(late).toHaveBeenCalledTimes(1);
    });

    it('keeps the flying pose when the cheer image has not loaded yet', () => {
        const { api, root } = bootSplash();
        api.done();
        run(api.timing.fill + 20);
        expect(root.classList.contains('is-done')).toBe(true);
        expect(root.classList.contains('is-cheer')).toBe(false);
    });

    it('fail(): fades straight out without celebrating', () => {
        const { api, root } = bootSplash();
        run(500);
        api.fail();
        expect(root.classList.contains('is-leaving')).toBe(true);
        expect(root.classList.contains('is-done')).toBe(false);
        run(api.timing.fade + 60);
        expect(document.getElementById('tpix-splash')).toBeNull();
    });

    it('fail() during the celebration skips straight to fading', () => {
        const { api } = bootSplash();
        api.done();
        api.fail();
        run(api.timing.fade + 60);
        expect(document.getElementById('tpix-splash')).toBeNull();
    });

    it('calls after it is gone are harmless', () => {
        const { api } = bootSplash();
        api.fail();
        run(1000);
        expect(() => {
            api.set(50);
            api.done();
            api.fail();
        }).not.toThrow();
        expect(api.gone).toBe(true);
    });

    it('never traps the user: finishes by itself after 12 s', () => {
        const { api } = bootSplash();
        run(11900);
        expect(api.active).toBe(true);
        run(200);
        expect(api.active).toBe(false);
        run(1000);
        expect(document.getElementById('tpix-splash')).toBeNull();
    });

    it('does not count time while the tab is in the background', () => {
        const { api } = bootSplash();
        run(2000);
        setHidden(true);
        run(30000);
        expect(api.active).toBe(true);
        setHidden(false);
        run(9900);
        expect(api.active).toBe(true);
        run(200);
        expect(api.active).toBe(false);
    });

    it('reduced motion: no bounce, a short plain fade', () => {
        const { api, root } = bootSplash({ reduced: true });
        expect(api.timing.fill).toBe(0);
        api.done();
        run(api.timing.fill + api.timing.hold + 10);
        expect(root.classList.contains('is-leaving')).toBe(true);
        run(api.timing.fade + 60);
        expect(document.getElementById('tpix-splash')).toBeNull();
        expect(api.timing.fill + api.timing.hold + api.timing.fade).toBeLessThan(450);
    });
});

describe('home splash — page behaviour', () => {
    const labelsAt = (api) => {
        const label = () => document.querySelector('[data-splash-label]').textContent;
        const seen = [label()];
        for (const pct of [30, 60, 85]) {
            api.set(pct);
            run(1500);
            seen.push(label());
        }
        return seen;
    };

    it('speaks the language the visitor picked (tpix_locale)', () => {
        const th = bootSplash({ lang: 'th' });
        expect(th.root.getAttribute('lang')).toBe('th');
        expect(document.querySelector('[data-splash-bar]').getAttribute('aria-label')).toBe('กำลังโหลด TPIX TRADE');
        expect(document.querySelector('[data-splash-label]').textContent).toMatch(/[฀-๿]/);

        const en = bootSplash({ lang: 'en' });
        expect(en.root.getAttribute('lang')).toBe('en');
        expect(document.querySelector('[data-splash-label]').textContent).toBe('Preparing the TPIX world…');
    });

    it('falls back to the admin default locale, then Thai', () => {
        bootSplash({ lang: null, defaultLocale: 'en' });
        expect(document.getElementById('tpix-splash').getAttribute('lang')).toBe('en');
        bootSplash({ lang: null });
        expect(document.getElementById('tpix-splash').getAttribute('lang')).toBe('th');
    });

    it('has a status line for every stage in both languages', () => {
        const th = labelsAt(bootSplash({ lang: 'th' }).api);
        const en = labelsAt(bootSplash({ lang: 'en' }).api);
        expect(th).toHaveLength(en.length);
        expect(new Set(th).size).toBe(th.length);
        expect(new Set(en).size).toBe(en.length);
        [...th, ...en].forEach((s) => expect(s.trim()).not.toBe(''));
    });

    it('exposes a real progressbar to assistive tech', () => {
        const { api } = bootSplash();
        api.set(42);
        run(2000);
        const bar = document.querySelector('[role="progressbar"]');
        expect(bar.getAttribute('aria-valuemin')).toBe('0');
        expect(bar.getAttribute('aria-valuemax')).toBe('100');
        expect(Number(bar.getAttribute('aria-valuenow'))).toBeGreaterThanOrEqual(42);
        expect(bar.getAttribute('aria-valuetext')).toMatch(/^\d+% · /);
        // จอโหลดไม่มีอะไรให้โฟกัส — ไม่แย่งคีย์บอร์ดจากหน้าข้างใต้
        expect(document.querySelectorAll('#tpix-splash a, #tpix-splash button, #tpix-splash [tabindex]')).toHaveLength(0);
    });

    it('drops the brand mark if the file is missing instead of showing a broken image', () => {
        const { root } = bootSplash();
        root.querySelector('[data-splash-mark]').dispatchEvent(new Event('error'));
        expect(root.querySelector('[data-splash-mark]')).toBeNull();
        expect(root.querySelector('.tpix-splash__word').textContent).toBe('TPIX TRADE');
    });

    it('stops the page underneath from scrolling while it is up', () => {
        const { root } = bootSplash();
        const wheel = new Event('wheel', { cancelable: true, bubbles: true });
        root.dispatchEvent(wheel);
        expect(wheel.defaultPrevented).toBe(true);
    });

    it('moves the bar and Nong TPIX with transforms only (no layout work)', () => {
        const { api } = bootSplash();
        api.set(50);
        run(3000);
        const rider = document.querySelector('[data-splash-rider]').style;
        const fill = document.querySelector('[data-splash-fill]').style;
        expect(rider.transform).toMatch(/^translate3d\(/);
        expect(fill.transform).toMatch(/^translate3d\(-/);
        expect(rider.left).toBe('');
        expect(fill.width).toBe('');
    });
});

describe('home splash — what Blade ships', () => {
    it('survives the comment stripping and cannot close its own <script> early', () => {
        const js = shippedScript();
        expect(js).toContain('window.__tpixSplash');
        expect(js).not.toMatch(/<\/script/i);
        expect(js.length).toBeLessThan(readSplashFile('splash.js').length);
        expect(readSplashFile('splash.css')).not.toMatch(/<\/style/i);
    });

    it('asks for the brand mark with the same cache-busting version as BrandLogo.vue', () => {
        const markup = readSplashFile('body.blade.php');
        const used = markup.match(/\/images\/brand\/tpix-trade-mark\.svg\?v=(\d+)/);
        expect(used).not.toBeNull();
        // อ่านเป็นข้อความ (ไม่ import) — ไฟล์ของอีกทีมอาจกำลังแก้อยู่ ไม่ให้เทสต์นี้ล้มตาม
        const logoPath = resolve(process.cwd(), 'resources/js/Components/Brand/BrandLogo.vue');
        if (!existsSync(logoPath)) return;
        const version = readFileSync(logoPath, 'utf8').match(/BRAND_VERSION\s*=\s*(\d+)/);
        if (version) expect(used[1]).toBe(version[1]);
    });

    it('preloads the same mascot files (same ?v=) that the 3D page uses', () => {
        const html = readSplashFile('head.blade.php') + readSplashFile('body.blade.php');
        const refs = [...html.matchAll(/\/images\/mascot\/(\w+)\.webp\?v=\d+/g)];
        expect(refs.length).toBeGreaterThanOrEqual(4);
        refs.forEach(([url, pose]) => expect(POSES[pose]?.src, url).toBe(url));
    });
});
