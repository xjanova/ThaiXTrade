/**
 * TPIX TRADE — คลิปแนะนำบนหน้าแรก (IntroVideo / IntroVideoModal) + การระงับเสียงฉาก 3D ระหว่างเล่นคลิป
 *
 * - ก่อนกดเล่น = ภาพปกเท่านั้น ไม่มี <video> (หน้าแรกแบบธรรมดาห้ามโหลดวิดีโอหลายสิบ MB ตอนเปิดหน้า)
 * - โหลดล้ม = มีข้อความ + ปุ่มลองใหม่ และลองใหม่ต้อง load() ก่อน play()
 * - ออกจากหน้าระหว่างเล่น = หยุดเสียง + แจ้ง pause ให้หน้า 3D ปล่อยเสียงฉาก
 * - หน้าต่างเต็มจอ: ปิดด้วย Esc / คลิกพื้นหลัง และล็อก/คืนการเลื่อนหน้า
 * - sfx.hold(): การคลิกปุ่มคุมวิดีโอต้องไม่ปลุกเสียงฉากกลับมา
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import IntroVideo from '@/Components/Home/IntroVideo.vue';
import IntroVideoModal from '@/Components/Home/IntroVideoModal.vue';

let playSpy;
let pauseSpy;
let loadSpy;

beforeEach(() => {
    playSpy = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function () {
        Object.defineProperty(this, 'paused', { value: false, configurable: true });
        return Promise.resolve();
    });
    pauseSpy = vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
    loadSpy = vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {});
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    document.documentElement.style.overflow = '';
});

describe('IntroVideo', () => {
    it('shows only the poster until the viewer presses play', () => {
        const w = mount(IntroVideo);
        expect(w.find('video').exists()).toBe(false);
        expect(w.find('.intro-video__poster img').attributes('src')).toContain('/images/home/intro-poster.webp');
    });

    it('mounts the video and plays it after the click', async () => {
        const w = mount(IntroVideo);
        await w.find('.intro-video__poster').trigger('click');
        await flushPromises();
        const video = w.find('video');
        expect(video.exists()).toBe(true);
        expect(video.attributes('src')).toContain('/videos/intro/tpix-intro-1080p.mp4');
        expect(video.attributes('preload')).toBe('metadata');
        expect(playSpy).toHaveBeenCalled();
    });

    it('autoplays when asked (the 3D page modal)', async () => {
        const w = mount(IntroVideo, { props: { autoplay: true } });
        await flushPromises();
        expect(w.find('video').exists()).toBe(true);
        expect(playSpy).toHaveBeenCalled();
    });

    it('relays play / pause so the 3D page can mute its own sound', async () => {
        const w = mount(IntroVideo, { props: { autoplay: true } });
        await flushPromises();
        await w.find('video').trigger('play');
        await w.find('video').trigger('pause');
        await w.find('video').trigger('ended');
        expect(w.emitted('play')).toHaveLength(1);
        expect(w.emitted('pause')).toHaveLength(2);
    });

    it('shows a retry message when the file cannot load, and reloads before playing again', async () => {
        const w = mount(IntroVideo, { props: { autoplay: true } });
        await flushPromises();
        await w.find('video').trigger('error');
        expect(w.find('[role="alert"]').exists()).toBe(true);
        expect(w.emitted('pause')).toHaveLength(1);

        playSpy.mockClear();
        await w.find('.intro-video__retry').trigger('click');
        await flushPromises();
        expect(loadSpy).toHaveBeenCalledTimes(1);
        expect(playSpy).toHaveBeenCalledTimes(1);
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('stops the sound when the page goes away mid-play', async () => {
        const w = mount(IntroVideo, { props: { autoplay: true } });
        await flushPromises();
        w.unmount();
        expect(pauseSpy).toHaveBeenCalled();
        expect(w.emitted('pause')).toHaveLength(1);
    });
});

describe('IntroVideoModal', () => {
    it('locks page scroll while open and restores it on close', async () => {
        document.documentElement.style.overflow = 'auto';
        const w = mount(IntroVideoModal, { attachTo: document.body });
        await flushPromises();
        expect(document.documentElement.style.overflow).toBe('hidden');
        w.unmount();
        expect(document.documentElement.style.overflow).toBe('auto');
    });

    it('closes on Escape and on a click on the backdrop, not on the video', async () => {
        const w = mount(IntroVideoModal, { attachTo: document.body });
        await flushPromises();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toHaveLength(1);

        document.querySelector('.ivm__box').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(w.emitted('close')).toHaveLength(1);

        document.querySelector('.ivm').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(w.emitted('close')).toHaveLength(2);
        w.unmount();
    });
});

describe('3D page sound hold', () => {
    it('keeps the scene silent while held, even when the viewer clicks the video controls', async () => {
        const resume = vi.fn(() => Promise.resolve());
        const suspend = vi.fn(() => Promise.resolve());
        class FakeCtx {
            constructor() { this.state = 'suspended'; this.currentTime = 0; this.destination = {}; this.sampleRate = 44100; }
            resume() { resume(); this.state = 'running'; return Promise.resolve(); }
            suspend() { suspend(); this.state = 'suspended'; return Promise.resolve(); }
            createGain() { return { gain: { value: 1, setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect(n) { return n; } }; }
            createDynamicsCompressor() { return { threshold: {}, knee: {}, ratio: {}, attack: {}, release: {}, connect(n) { return n; } }; }
            createConvolver() { return { connect(n) { return n; } }; }
            createBuffer(ch, len) { return { getChannelData: () => new Float32Array(len) }; }
        }
        vi.stubGlobal('AudioContext', FakeCtx);
        vi.resetModules();
        const { sfx } = await import('@/Components/Home3D/sound');

        sfx.unlock();
        expect(resume).toHaveBeenCalledTimes(1);

        sfx.hold(true);
        expect(suspend).toHaveBeenCalled();
        resume.mockClear();
        sfx.unlock();
        sfx.resume();
        expect(resume).not.toHaveBeenCalled();

        sfx.hold(false);
        expect(resume).toHaveBeenCalledTimes(1);
        vi.unstubAllGlobals();
    });
});
