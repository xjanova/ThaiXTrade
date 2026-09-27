import { describe, it, expect } from 'vitest';
import { createIdleDirector, FIDGETS, IDLE_TIMING } from '@/Components/Home/mascotIdle';

/** เดินเวลาไปเรื่อยๆ เก็บท่าที่ถูกสั่ง */
function run(d, seconds, ctx = {}) {
    const out = [];
    for (let t = 0; t < seconds; t += 0.1) {
        const a = d.update(0.1, ctx);
        if (a) out.push(a);
    }
    return out;
}

describe('mascot idle director', () => {
    it('fidgets first, yawns once, then dozes off', () => {
        const d = createIdleDirector({ random: () => 0.5 });
        const acts = run(d, IDLE_TIMING.sleepAt + 1);
        expect(acts.at(-1)).toBe('doze');
        expect(acts.filter((a) => a === 'yawn')).toHaveLength(1);
        expect(acts.indexOf('yawn')).toBeGreaterThan(0);
        acts.filter((a) => a !== 'yawn' && a !== 'doze').forEach((a) => expect(FIDGETS).toContain(a));
        expect(d.asleep).toBe(true);
    });

    it('never repeats the same fidget twice in a row', () => {
        const d = createIdleDirector({ random: () => 0, timing: { fidgetMin: 1, fidgetMax: 1, yawnAt: 999, sleepAt: 999 } });
        const acts = run(d, 30);
        expect(acts.length).toBeGreaterThan(5);
        for (let i = 1; i < acts.length; i++) expect(acts[i]).not.toBe(acts[i - 1]);
    });

    it('stays asleep until the user comes back, then asks to wake', () => {
        const d = createIdleDirector({ timing: { sleepAt: 2, yawnAt: 999, fidgetMin: 999, fidgetMax: 999 } });
        expect(run(d, 3)).toEqual(['doze']);
        expect(run(d, 60)).toEqual([]);
        expect(d.activity()).toBe('wake');
        expect(d.asleep).toBe(false);
        // ตื่นแล้วขยับอีกไม่ต้องตื่นซ้ำ
        expect(d.activity()).toBeNull();
    });

    it('activity resets the clock so an active user never sees a yawn', () => {
        const d = createIdleDirector({ timing: { fidgetMin: 999, fidgetMax: 999, yawnAt: 5, sleepAt: 8 } });
        for (let i = 0; i < 20; i++) {
            expect(run(d, 4)).toEqual([]);
            d.activity();
        }
    });

    it('does not get sleepy while chatting or talking', () => {
        const d = createIdleDirector({ timing: { fidgetMin: 1, fidgetMax: 1, yawnAt: 2, sleepAt: 3 } });
        expect(run(d, 120, { busy: true })).toEqual([]);
        expect(d.asleep).toBe(false);
    });

    it('waits for the current move to finish before starting another', () => {
        const d = createIdleDirector({ timing: { fidgetMin: 1, fidgetMax: 1, yawnAt: 999, sleepAt: 999 } });
        expect(run(d, 10, { acting: true })).toEqual([]);
        // ปล่อยแล้วได้ท่าทันที (เลยเวลามาแล้ว)
        expect(d.update(0.1, {})).not.toBeNull();
    });

    it('settles down quietly right before dozing off (no fidget in the last seconds)', () => {
        const d = createIdleDirector({ random: () => 0, timing: { fidgetMin: 1, fidgetMax: 1, yawnAt: 999, sleepAt: 20 } });
        let lastFidgetAt = 0;
        for (let t = 0; t < 21; t += 0.1) {
            const a = d.update(0.1, {});
            if (a && a !== 'doze') lastFidgetAt = t;
        }
        expect(d.asleep).toBe(true);
        expect(lastFidgetAt).toBeLessThan(12.5);
    });

    it('ignores a huge frame gap (tab was in background)', () => {
        const d = createIdleDirector({ timing: { fidgetMin: 999, fidgetMax: 999, yawnAt: 999, sleepAt: 5 } });
        expect(d.update(600, {})).toBeNull();
        expect(d.quiet).toBeLessThan(1);
    });
});
