/**
 * TPIX TRADE — ตัวช่วยเทสต์จอโหลดหน้าแรก: โหลด markup + สคริปต์ "ตัวจริง" ลง jsdom
 *
 * ใช้ไฟล์เดียวกับที่ Blade ฝังลงหน้า (resources/views/partials/home-splash/*)
 * และตัดคอมเมนต์ด้วยกติกาเดียวกับ body.blade.php → เทสต์โค้ดที่ผู้ใช้ได้รับจริง ไม่ใช่สำเนา
 *
 * Developed by Xman Studio
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

export const SPLASH_DIR = resolve(process.cwd(), 'resources/views/partials/home-splash');

export const readSplashFile = (name) => readFileSync(resolve(SPLASH_DIR, name), 'utf8');

/** กติกาตัดคอมเมนต์เดียวกับ body.blade.php (preg_replace สามตัว) */
export function stripLikeBlade(js) {
    return js
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^[ \t]*\/\/.*$/gm, '')
        .replace(/\n\s*\n+/g, '\n');
}

/** markup ของ body.blade.php โดยไม่มีส่วนของ Blade/สคริปต์ */
export function splashMarkup() {
    return readSplashFile('body.blade.php')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@php[\s\S]*?@endphp/g, '')
        .replace(/<script>[\s\S]*?<\/script>/g, '');
}

export const shippedScript = () => stripLikeBlade(readSplashFile('splash.js'));

let hidden = false;
Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });

/** สลับแท็บซ่อน/เห็น แล้วยิง visibilitychange แบบเบราว์เซอร์จริง */
export function setHidden(value) {
    hidden = value;
    document.dispatchEvent(new Event('visibilitychange'));
}

/**
 * เปิดจอโหลดใหม่ทั้งชุด — เรียกหลัง vi.useFakeTimers() เสมอ (สคริปต์จับ requestAnimationFrame ตอนบูต)
 * @returns {{ api: any, root: HTMLElement }}
 */
export function bootSplash({ lang = 'en', defaultLocale = null, reduced = false } = {}) {
    hidden = false;
    document.head.innerHTML = defaultLocale ? `<meta name="default-locale" content="${defaultLocale}">` : '';
    document.body.innerHTML = splashMarkup();
    delete window.__tpixSplash;
    if (lang) localStorage.setItem('tpix_locale', lang);
    else localStorage.removeItem('tpix_locale');
    window.matchMedia = (query) => ({
        matches: reduced && query.includes('prefers-reduced-motion'),
        media: query,
        addEventListener() {},
        removeEventListener() {},
    });
    // สคริปต์ inline แบบ classic (ไม่ใช่ module) — รันด้วย Function ให้เหมือนแท็ก <script>
    new Function(shippedScript())();
    return { api: window.__tpixSplash, root: document.getElementById('tpix-splash') };
}

export const splashValue = () => Number(document.querySelector('[data-splash-bar]')?.getAttribute('aria-valuenow') ?? NaN);
