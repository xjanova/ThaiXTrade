/**
 * TPIX TRADE — หน้าแนะนำตัวมาสเตอร์ Copy Trade (/ai-trade/master/{id})
 *
 * กัน:
 *  - ผลงานถูกโชว์โดยไม่บอกว่าเป็นเงินจริงจากไม้ที่ปิดแล้ว หรือไม่มีคำเตือนว่าผลในอดีตไม่รับประกัน
 *  - คลิปของมาสเตอร์ถูกโหลดจากบุคคลที่สามก่อนผู้ชมกดเล่น · ลิงก์นอกต้นทางที่รู้จักถูกวาด
 *  - ข้อความจากผู้ใช้ (แนะนำตัว · ชื่อคลิป · รีวิว) ถูกวาดเป็น markup
 *  - โปรไฟล์ที่ถูกปิด/ไม่มีอยู่ทำให้หน้าพังแทนที่จะบอกว่าไม่พบ
 *  - ผู้ชมไม่รู้ว่าค่าบริการหักจากยอด TPIX ที่เติมไว้
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import axios from 'axios';
import AiTradeMaster from '@/Pages/AiTradeMaster.vue';

vi.mock('axios', () => ({ default: { get: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

enableAutoUnmount(afterEach);
// ปีกกาจำเป็น: mockReset() คืนตัว mock เอง และ Vitest เรียกสิ่งที่ beforeEach คืนมาเป็นตัวเก็บกวาดหลังเทสต์
beforeEach(() => { axios.get.mockReset(); });

const clip = (overrides = {}) => ({
    id: 3, kind: 'clip', provider: 'youtube', title: 'How I trade', status: 'visible', featured: true,
    url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', embed_url: 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', ...overrides,
});

function profile(overrides = {}) {
    return {
        id: 7, display_name: 'Alpha', bio: 'Trend on 4h', wallet_short: '0xaaaa…aaaa', fee_rate_pct: 20, platform_share_pct: 20,
        status: 'active', accepting: true, promoted: false, pair: 'BTC/USDT', timeframe: '4h', meets_floor: true, followers: 3,
        record: { mode: 'live', closed: 24, win_rate: 83.3, return_pct: 31, max_drawdown_pct: 2, days: 60.4 },
        rank: { tier: 3, key: 'tier_3', name_th: 'ระดับ 3', name_en: 'Tier 3' }, rating: { avg: 4.5, count: 2 },
        intro: 'Line one\nLine two', featured: [clip()], embed: true,
        ...overrides,
    };
}

function respond({ master = profile(), settings = {}, reviews = [], status = 200 } = {}) {
    axios.get.mockImplementation((url) => {
        if (url.endsWith('/reviews')) return Promise.resolve({ data: { success: true, data: { reviews } } });
        if (status !== 200) return Promise.reject({ response: { status } });

        return Promise.resolve({
            data: { success: true, data: { enabled: true, live_ready: true, fees: { collect: true, currency: 'TPIX', min_balance_tpix: 10 }, ...settings, master } },
        });
    });
}

async function mountPage() {
    const wrapper = mount(AiTradeMaster, { props: { masterId: 7 } });
    await flushPromises();

    return wrapper;
}

const byId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`);

describe('AiTradeMaster — the profile', () => {
    it('loads the public profile by id and shows who the master is', async () => {
        respond();
        const wrapper = await mountPage();

        expect(axios.get).toHaveBeenCalledWith('/api/v1/ai-bot/copy/masters/7');
        const header = byId(wrapper, 'master-header').text();
        expect(header).toContain('Alpha');
        expect(header).toContain('0xaaaa…aaaa');
        expect(byId(wrapper, 'master-rank').text()).toBe('Tier 3');
        expect(byId(wrapper, 'master-rating').text()).toContain('4.5');
    });

    it('labels the results as real money on closed trades and warns that the past guarantees nothing', async () => {
        respond();
        const record = byId(await mountPage(), 'master-record').text();

        expect(record).toContain('real money');
        expect(record).toContain('+31.00%');
        expect(record).toContain('-2.00%');
        expect(record).toContain('Past results do not guarantee future results');
    });

    it('explains a promoted badge and flags results under the requirements', async () => {
        respond({ master: profile({ promoted: true, meets_floor: false }) });
        const wrapper = await mountPage();

        expect(byId(wrapper, 'master-promoted').exists()).toBe(true);
        // คำอธิบายของป้าย "แนะนำ" ต้องอยู่บนหน้าเป็นข้อความ ไม่ใช่แค่ title ของป้าย
        expect(byId(wrapper, 'master-promoted-note').text()).toContain('not from a performance ranking');
        expect(byId(wrapper, 'master-below-floor').exists()).toBe(true);
    });

    it('renders the introduction as text with its line breaks, never as markup', async () => {
        respond({ master: profile({ intro: '<img src=x onerror=alert(1)>\nsecond' }) });
        const intro = byId(await mountPage(), 'master-intro');

        expect(intro.text()).toContain('<img src=x onerror=alert(1)>');
        expect(intro.find('img').exists()).toBe(false);
        expect(intro.find('p').classes()).toContain('whitespace-pre-line');
    });

    it('says so when there is no introduction and no clip', async () => {
        respond({ master: profile({ intro: null, featured: [] }) });
        const wrapper = await mountPage();

        expect(byId(wrapper, 'master-intro').text()).toContain('has not written an introduction');
        expect(byId(wrapper, 'master-no-clips').exists()).toBe(true);
    });
});

describe('AiTradeMaster — featured clips', () => {
    it('loads nothing from the provider until the viewer presses play, then embeds a sandboxed player', async () => {
        respond();
        const wrapper = await mountPage();

        expect(wrapper.find('iframe').exists()).toBe(false);
        expect(byId(wrapper, 'master-clips').text()).toContain('not investment advice');

        await byId(wrapper, 'copy-media-play-3').trigger('click');

        const frame = byId(wrapper, 'copy-media-frame-3');
        expect(frame.attributes('src')).toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
        expect(frame.attributes('sandbox')).toBe('allow-scripts allow-same-origin allow-presentation allow-popups');
        expect(frame.attributes('referrerpolicy')).toBe('strict-origin-when-cross-origin');
    });

    it('shows up to three clips and links out without leaking the referrer', async () => {
        respond({
            master: profile({
                featured: [
                    clip({ id: 1 }),
                    clip({ id: 2, provider: 'facebook', url: 'https://www.facebook.com/watch/?v=1234567890123', embed_url: 'https://www.facebook.com/plugins/video.php?href=x' }),
                    clip({ id: 3, provider: 'tiktok', url: 'https://www.tiktok.com/embed/v2/7300000000000000000', embed_url: 'https://www.tiktok.com/embed/v2/7300000000000000000' }),
                ],
            }),
        });
        const wrapper = await mountPage();

        expect(wrapper.findAll('[data-testid^="copy-media-item-"]')).toHaveLength(3);
        const link = byId(wrapper, 'copy-media-item-2').find('a');
        expect(link.text()).toBe('Open on Facebook');
        expect(link.attributes('rel')).toBe('noopener noreferrer nofollow');
        expect(link.attributes('target')).toBe('_blank');
    });

    it('drops a clip whose link is not on a known origin and never embeds an unknown player', async () => {
        respond({
            master: profile({
                featured: [clip({ id: 4, url: 'javascript:alert(1)' }), clip({ id: 5, embed_url: 'https://evil.example/embed/x' })],
            }),
        });
        const wrapper = await mountPage();

        expect(byId(wrapper, 'copy-media-item-4').exists()).toBe(false);
        expect(byId(wrapper, 'copy-media-item-5').exists()).toBe(true);
        expect(byId(wrapper, 'copy-media-play-5').exists()).toBe(false);
        expect(wrapper.html()).not.toContain('javascript:');
        expect(wrapper.html()).not.toContain('evil.example');
    });

    it('offers links only when the server does not allow embedding', async () => {
        respond({ master: profile({ embed: false }) });
        const wrapper = await mountPage();

        expect(byId(wrapper, 'copy-media-play-3').exists()).toBe(false);
        expect(byId(wrapper, 'copy-media-item-3').find('a').exists()).toBe(true);
    });

    it('renders a clip title as text', async () => {
        respond({ master: profile({ featured: [clip({ title: '<b>bold</b>' })] }) });
        const item = byId(await mountPage(), 'copy-media-item-3');

        expect(item.text()).toContain('<b>bold</b>');
        expect(item.find('b').exists()).toBe(false);
    });
});

describe('AiTradeMaster — fee and following', () => {
    it('states the fee, that it comes out of prepaid TPIX, and sends the viewer to the Copy Trade panel', async () => {
        respond();
        const wrapper = await mountPage();
        const fee = byId(wrapper, 'master-fee').text();

        expect(fee).toContain('20% of your realised profit');
        expect(byId(wrapper, 'master-fee-prepaid').text()).toContain('at least 10 TPIX');
        expect(byId(wrapper, 'master-follow-link').attributes('href')).toBe('/ai-trade?master=7#copy-trade');
    });

    it('does not mention prepaid TPIX while fees are not collected', async () => {
        respond({ settings: { fees: { collect: false, currency: 'TPIX' } } });

        expect(byId(await mountPage(), 'master-fee-prepaid').exists()).toBe(false);
    });

    it('offers no follow link for a master who paused, and warns while real-money trading is closed', async () => {
        respond({ master: profile({ accepting: false, status: 'paused' }) });
        const paused = await mountPage();
        expect(byId(paused, 'master-follow-link').exists()).toBe(false);
        expect(byId(paused, 'master-not-accepting').exists()).toBe(true);

        respond({ settings: { live_ready: false } });
        const closed = await mountPage();
        expect(byId(closed, 'master-needs-live').exists()).toBe(true);
    });
});

describe('AiTradeMaster — reviews and failures', () => {
    it('lists reviews as text', async () => {
        respond({ reviews: [{ id: 11, stars: 4, reviewer: '0xbbbb…bbbb', comment: '<script>x</script> solid' }] });
        const review = byId(await mountPage(), 'master-review-11');

        expect(review.text()).toContain('★★★★');
        expect(review.text()).toContain('<script>x</script> solid');
        expect(review.find('script').exists()).toBe(false);
    });

    it('skips the review request when nobody has reviewed yet', async () => {
        respond({ master: profile({ rating: { avg: null, count: 0 } }) });
        const wrapper = await mountPage();

        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(byId(wrapper, 'master-no-reviews').exists()).toBe(true);
    });

    it('never says there are no reviews while they are still loading or failed to load', async () => {
        let release;
        axios.get.mockImplementation((url) => {
            if (url.endsWith('/reviews')) return new Promise((resolve, reject) => { release = reject; });

            return Promise.resolve({ data: { success: true, data: { enabled: true, live_ready: true, fees: {}, master: profile() } } });
        });
        const wrapper = await mountPage();

        expect(byId(wrapper, 'master-reviews-loading').exists()).toBe(true);
        expect(byId(wrapper, 'master-no-reviews').exists()).toBe(false);

        release({ response: { status: 500 } });
        await flushPromises();

        expect(byId(wrapper, 'master-reviews-error').exists()).toBe(true);
        expect(byId(wrapper, 'master-no-reviews').exists()).toBe(false);
        // ส่วนที่เหลือของหน้ายังอยู่ครบ
        expect(byId(wrapper, 'master-header').text()).toContain('Alpha');
    });

    it('says not found for a closed or unknown profile', async () => {
        respond({ status: 404 });
        const wrapper = await mountPage();

        expect(byId(wrapper, 'master-missing').exists()).toBe(true);
        expect(byId(wrapper, 'master-header').exists()).toBe(false);
    });

    it('offers a retry when the server cannot be reached', async () => {
        respond({ status: 500 });
        const wrapper = await mountPage();
        expect(byId(wrapper, 'master-error').exists()).toBe(true);

        respond();
        await byId(wrapper, 'master-error').find('button').trigger('click');
        await flushPromises();

        expect(byId(wrapper, 'master-header').text()).toContain('Alpha');
    });
});
