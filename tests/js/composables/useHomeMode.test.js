/**
 * TPIX TRADE — เลือกหน้าแรก 3D / แบบเดิม
 *
 * เจ้าของสั่ง: "หน้าแรกเป็น full 3D และเครื่องที่ไม่แรงใช้หน้าเว็บเดิม"
 * เทสต์นี้กันเครื่องอ่อน (ไม่มี WebGL2, การ์ดจอซอฟต์แวร์, RAM น้อย, ประหยัดเน็ต, ขอลดการเคลื่อนไหว)
 * หลุดไปได้หน้า 3D และกันผู้ใช้ที่เลือกหน้าเดิมเองถูกบังคับกลับไป 3D
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import { checkCapability, pickHomeMode, FALLBACK_DAYS } from '@/Composables/useHomeMode';

function fakeEnv({ gl = true, renderer = 'ANGLE (NVIDIA GeForce RTX 3060)', reduced = false, nav = {}, ...rest } = {}) {
    return {
        navigator: { hardwareConcurrency: 8, deviceMemory: 8, ...nav },
        matchMedia: (q) => ({ matches: reduced && q.includes('reduced-motion') }),
        makeCanvas: () => ({
            getContext: () =>
                gl
                    ? {
                          getExtension: (name) => (name === 'WEBGL_debug_renderer_info' ? { UNMASKED_RENDERER_WEBGL: 1 } : { loseContext() {} }),
                          getParameter: () => renderer,
                          RENDERER: 2,
                      }
                    : null,
        }),
        search: '',
        pref: null,
        failedAt: null,
        now: 1_800_000_000_000,
        ...rest,
    };
}

describe('checkCapability', () => {
    it('accepts a normal desktop GPU', () => {
        expect(checkCapability(fakeEnv())).toMatchObject({ ok: true });
    });

    it.each([
        ['no-webgl2', { gl: false }],
        ['software-gpu', { renderer: 'Google SwiftShader' }],
        ['software-gpu', { renderer: 'llvmpipe (LLVM 15.0.7, 256 bits)' }],
        ['low-memory', { nav: { deviceMemory: 2 } }],
        ['few-cores', { nav: { hardwareConcurrency: 2 } }],
        ['save-data', { nav: { connection: { saveData: true } } }],
        ['reduced-motion', { reduced: true }],
    ])('rejects %s', (reason, env) => {
        expect(checkCapability(fakeEnv(env))).toMatchObject({ ok: false, reason });
    });
});

describe('pickHomeMode', () => {
    it('gives capable devices the 3D page automatically', () => {
        expect(pickHomeMode(fakeEnv())).toEqual({ mode: '3d', reason: 'auto' });
    });

    it('gives weak devices the classic page', () => {
        expect(pickHomeMode(fakeEnv({ gl: false }))).toEqual({ mode: 'classic', reason: 'no-webgl2' });
    });

    it('respects ?view= in the URL first', () => {
        expect(pickHomeMode(fakeEnv({ search: '?view=classic' })).mode).toBe('classic');
        expect(pickHomeMode(fakeEnv({ search: '?view=3d', gl: false })).mode).toBe('3d');
    });

    it('keeps the classic page when the visitor chose it', () => {
        expect(pickHomeMode(fakeEnv({ pref: 'classic' }))).toEqual({ mode: 'classic', reason: 'user' });
    });

    it('never forces 3D on a device that cannot run it, even if chosen before', () => {
        expect(pickHomeMode(fakeEnv({ pref: '3d', gl: false })).mode).toBe('classic');
    });

    it('stays on the classic page for a week after the 3D page fell back', () => {
        const now = 1_800_000_000_000;
        const day = 86_400_000;
        expect(pickHomeMode(fakeEnv({ now, failedAt: now - 2 * day })).mode).toBe('classic');
        expect(pickHomeMode(fakeEnv({ now, failedAt: now - (FALLBACK_DAYS + 1) * day })).mode).toBe('3d');
    });
});
