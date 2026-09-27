/**
 * TPIX TRADE — เลือกหน้าแรกแบบ 3D เต็มจอ หรือแบบเดิม (lite)
 *
 * ลำดับการตัดสิน (อันแรกที่ตอบได้ชนะ):
 *   1. ?view=classic / ?view=3d ใน URL (ไว้ทดสอบ/ส่งลิงก์)
 *   2. ผู้ใช้เคยกดสลับเอง → จำไว้ในเครื่อง
 *   3. เคยเปิด 3D แล้วเครื่องไม่ไหว (เฟรมเรตต่ำ/GPU หลุด) → ใช้หน้าเดิม 7 วันก่อนลองใหม่
 *   4. ตรวจเครื่อง: WebGL2 + ไม่ใช่การ์ดจอซอฟต์แวร์ + RAM/คอร์พอ + ไม่ประหยัดเน็ต + ไม่ขอลดการเคลื่อนไหว
 *
 * หลังเปิด 3D แล้วยังมีตัววัด FPS จริง (engine governor) คอยถอยกลับหน้าเดิมให้อีกชั้น
 *
 * Developed by Xman Studio
 */

import { ref, readonly } from 'vue';

const PREF_KEY = 'tpix_home_mode'; // 'classic' | '3d' (ผู้ใช้เลือกเอง)
const FALLBACK_KEY = 'tpix_home_3d_failed'; // เวลาที่ 3D ล้มครั้งล่าสุด (ms)
export const FALLBACK_DAYS = 7;
/** ล้มแบบชั่วคราว — ไม่จำ รอบหน้าลอง 3D ใหม่ */
const TRANSIENT_FAILURES = new Set(['chunk', 'context']);

function storage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function read(key) {
    try {
        return storage()?.getItem(key) ?? null;
    } catch {
        return null;
    }
}

function write(key, value) {
    try {
        if (value === null) storage()?.removeItem(key);
        else storage()?.setItem(key, value);
    } catch {
        // โหมดส่วนตัว — จำไม่ได้ก็ไม่เป็นไร เลือกใหม่ทุกครั้งที่เข้า
    }
}

/**
 * ตรวจความสามารถของเครื่อง — คืน { ok, reason }
 * แยกเป็นฟังก์ชันบริสุทธิ์ให้เทสต์ได้ (ส่ง env ปลอมเข้ามา)
 */
export function checkCapability(env = {}) {
    const nav = env.navigator ?? (typeof navigator !== 'undefined' ? navigator : {});
    const matchMedia = env.matchMedia ?? (typeof window !== 'undefined' ? window.matchMedia?.bind(window) : null);
    const makeCanvas = env.makeCanvas ?? (() => document.createElement('canvas'));

    if (matchMedia?.('(prefers-reduced-motion: reduce)')?.matches) return { ok: false, reason: 'reduced-motion' };
    if (nav.connection?.saveData) return { ok: false, reason: 'save-data' };
    if (nav.deviceMemory && nav.deviceMemory < 4) return { ok: false, reason: 'low-memory' };
    if (nav.hardwareConcurrency && nav.hardwareConcurrency < 4) return { ok: false, reason: 'few-cores' };

    let gl = null;
    try {
        gl = makeCanvas().getContext('webgl2', { failIfMajorPerformanceCaveat: true });
    } catch {
        gl = null;
    }
    if (!gl) return { ok: false, reason: 'no-webgl2' };

    let renderer = '';
    try {
        const info = gl.getExtension('WEBGL_debug_renderer_info');
        renderer = String(info ? gl.getParameter(info.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER) || '');
    } catch {
        renderer = '';
    }
    gl.getExtension('WEBGL_lose_context')?.loseContext();

    // การ์ดจอซอฟต์แวร์ = วาดด้วย CPU ช้ามาก หน้า 3D จะกระตุกแน่นอน
    if (/swiftshader|llvmpipe|software|microsoft basic render/i.test(renderer)) {
        return { ok: false, reason: 'software-gpu', renderer };
    }
    return { ok: true, reason: 'capable', renderer };
}

/** เลือกโหมดตอนเข้าหน้า */
export function pickHomeMode(env = {}) {
    const search = env.search ?? (typeof location !== 'undefined' ? location.search : '');
    const now = env.now ?? Date.now();
    const view = new URLSearchParams(search).get('view');
    if (view === 'classic' || view === 'lite') return { mode: 'classic', reason: 'url' };
    if (view === '3d') return { mode: '3d', reason: 'url' };

    const pref = env.pref !== undefined ? env.pref : read(PREF_KEY);
    if (pref === 'classic') return { mode: 'classic', reason: 'user' };

    const failedAt = Number(env.failedAt !== undefined ? env.failedAt : read(FALLBACK_KEY));
    const cap = checkCapability(env);
    if (pref === '3d') return cap.ok ? { mode: '3d', reason: 'user' } : { mode: 'classic', reason: cap.reason };
    if (failedAt && now - failedAt < FALLBACK_DAYS * 86400000) return { mode: 'classic', reason: 'recent-fallback' };

    return cap.ok ? { mode: '3d', reason: 'auto' } : { mode: 'classic', reason: cap.reason };
}

const mode = ref(null);
const reason = ref('');
const capable = ref(false);

export function useHomeMode() {
    if (mode.value === null && typeof window !== 'undefined') {
        const picked = pickHomeMode();
        mode.value = picked.mode;
        reason.value = picked.reason;
        capable.value = picked.mode === '3d' || checkCapability().ok;
    }

    return {
        mode: readonly(mode),
        reason: readonly(reason),
        capable: readonly(capable),
        /** ผู้ใช้กดสลับเอง */
        choose(next) {
            write(PREF_KEY, next);
            if (next === '3d') write(FALLBACK_KEY, null);
            mode.value = next;
            reason.value = 'user';
        },
        /**
         * หน้า 3D ล้มเอง → กลับหน้าเดิม
         * เครื่องช้า/บูตไม่ขึ้น = จำไว้ 7 วัน · เน็ตสะดุดโหลดโค้ดไม่ได้/GPU รีเซ็ตชั่วคราว = รอบนี้รอบเดียว
         */
        fallback(why = 'slow') {
            if (!TRANSIENT_FAILURES.has(why)) write(FALLBACK_KEY, String(Date.now()));
            mode.value = 'classic';
            reason.value = why;
        },
    };
}
