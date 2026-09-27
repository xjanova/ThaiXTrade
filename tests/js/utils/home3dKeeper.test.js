import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { keep, takeKept, discard, hasKept, KEEP_MS } from '@/Components/Home3D/keeper';
import { getTickers, __resetTickerFeed, MAX_AGE_MS } from '@/utils/tickerFeed';
import axios from 'axios';

vi.mock('axios', () => ({ default: { get: vi.fn() } }));

function fakeWorld() {
    const canvas = document.createElement('canvas');
    document.body.appendChild(canvas);
    return {
        engine: { lost: false, dispose: vi.fn() },
        world: { dispose: vi.fn() },
        canvas,
    };
}

describe('home 3D keeper (reuse the world when coming back to the home page)', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        discard();
        vi.useRealTimers();
    });

    it('hands back the same world instead of building a new one', () => {
        const w = fakeWorld();
        keep(w);
        expect(hasKept()).toBe(true);
        const got = takeKept();
        expect(got.engine).toBe(w.engine);
        expect(got.world).toBe(w.world);
        expect(w.engine.dispose).not.toHaveBeenCalled();
        // เอาไปแล้ว = ไม่อยู่ในที่เก็บ
        expect(takeKept()).toBeNull();
    });

    it('frees the GPU when the visitor does not come back in time', () => {
        const w = fakeWorld();
        keep(w);
        vi.advanceTimersByTime(KEEP_MS + 1);
        expect(w.engine.dispose).toHaveBeenCalledTimes(1);
        expect(w.world.dispose).toHaveBeenCalledTimes(1);
        expect(w.canvas.isConnected).toBe(false);
        expect(takeKept()).toBeNull();
    });

    it('keeps only one world — an older one is freed', () => {
        const a = fakeWorld();
        const b = fakeWorld();
        keep(a);
        keep(b);
        expect(a.engine.dispose).toHaveBeenCalledTimes(1);
        expect(takeKept().engine).toBe(b.engine);
    });

    it('never hands back a world whose GPU context was lost', () => {
        const w = fakeWorld();
        keep(w);
        w.engine.lost = true;
        expect(hasKept()).toBe(false);
        expect(takeKept()).toBeNull();
        expect(w.engine.dispose).toHaveBeenCalledTimes(1);
    });
});

describe('ticker feed (one request for the whole page)', () => {
    beforeEach(() => {
        __resetTickerFeed();
        axios.get.mockReset();
    });

    it('shares one request between callers that ask at the same time', async () => {
        axios.get.mockResolvedValue({ data: { success: true, data: [1] } });
        const [a, b] = await Promise.all([getTickers(), getTickers()]);
        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(a).toBe(b);
    });

    it('asks again once the answer is older than the max age (auto refresh still gets fresh prices)', async () => {
        vi.useFakeTimers();
        axios.get.mockResolvedValue({ data: { success: true, data: [1] } });
        await getTickers();
        await getTickers();
        expect(axios.get).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(MAX_AGE_MS + 1);
        await getTickers();
        expect(axios.get).toHaveBeenCalledTimes(2);
        vi.useRealTimers();
    });

    it('does not cache a failed answer', async () => {
        axios.get.mockResolvedValueOnce({ data: { success: false } }).mockResolvedValueOnce({ data: { success: true, data: [1] } });
        await getTickers();
        const second = await getTickers();
        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(second.data.success).toBe(true);
    });
});
