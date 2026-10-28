/**
 * TPIX TRADE — ตัวเล่นวิดีโอบทเรียน (หมวดเรียนรู้)
 *
 * กัน 3 อาการ:
 *  1. กดบทก่อน metadata มาถึง → เวลาไม่ขยับ (ต้องรอ loadedmetadata แล้วค่อยข้าม)
 *  2. เล่นไปแล้วบทที่ไฮไลต์ไม่ตามเวลา
 *  3. ไฟล์วิดีโอหาย → จอดำเงียบ ๆ (ต้องบอกผู้ชม)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import LearnVideoPlayer from '@/Components/Learn/LearnVideoPlayer.vue';

const lesson = {
    video: '/videos/learn/tpix-wallet-basics.mp4',
    poster: '/videos/learn/tpix-wallet-basics.jpg',
    duration: 106,
    chapters: [
        { t: 0, label: 'แนะนำ' },
        { t: 15, label: 'รับเหรียญ' },
        { t: 26, label: 'ส่งเหรียญ' },
    ],
};

function mountPlayer(props = {}) {
    const wrapper = mount(LearnVideoPlayer, { props: { lesson, ...props } });
    const video = wrapper.find('video').element;
    video.play = vi.fn(() => Promise.resolve());
    return { wrapper, video };
}

const active = (wrapper) => wrapper.findAll('ol button').findIndex((b) => b.classes().includes('text-primary-300'));

describe('LearnVideoPlayer', () => {
    it('shows the video, poster, length and one button per chapter', () => {
        const { wrapper } = mountPlayer();
        const video = wrapper.find('video');
        expect(video.attributes('src')).toBe(lesson.video);
        expect(video.attributes('poster')).toBe(lesson.poster);
        expect(video.attributes('preload')).toBe('metadata');
        expect(wrapper.text()).toContain('1:46');
        expect(wrapper.findAll('ol button').map((b) => b.text())).toEqual(['0:00แนะนำ', '0:15รับเหรียญ', '0:26ส่งเหรียญ']);
    });

    it('waits for metadata before jumping to a chapter', async () => {
        const { wrapper, video } = mountPlayer();
        Object.defineProperty(video, 'readyState', { value: 0, configurable: true });

        await wrapper.findAll('ol button')[2].trigger('click');
        expect(video.play).not.toHaveBeenCalled();

        Object.defineProperty(video, 'readyState', { value: 1, configurable: true });
        video.dispatchEvent(new Event('loadedmetadata'));
        expect(video.currentTime).toBe(26);
        expect(video.play).toHaveBeenCalledTimes(1);
    });

    it('highlights the chapter that is playing', async () => {
        const { wrapper, video } = mountPlayer();
        expect(active(wrapper)).toBe(0);

        video.currentTime = 20;
        await wrapper.find('video').trigger('timeupdate');
        expect(active(wrapper)).toBe(1);
    });

    it('tells the viewer when the video cannot load', async () => {
        const { wrapper } = mountPlayer({ lang: 'en' });
        await wrapper.find('video').trigger('error');
        expect(wrapper.text()).toContain('The video could not be loaded');
    });

    it('hides the chapter list when asked (download page)', () => {
        const { wrapper } = mountPlayer({ showChapters: false });
        expect(wrapper.find('ol').exists()).toBe(false);
    });
});
