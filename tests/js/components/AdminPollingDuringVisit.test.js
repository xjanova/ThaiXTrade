/**
 * โพลหลังบ้านต้องเว้นช่วงที่ Inertia visit วิ่งอยู่
 *
 * บั๊กที่ชุดนี้กัน: ฟอร์มหลังบ้านส่ง POST แล้วเซิร์ฟเวอร์ตอบ redirect พร้อม error ใน flash
 * ถ้าโพลแทรกก่อน GET ของ redirect จะถึง flash นั้นหมดอายุ Inertia เห็นว่าไม่มี error
 * แล้วเรียก onSuccess ฟอร์มปิดเหมือนบันทึกสำเร็จ ทั้งที่ไม่มีอะไรถูกบันทึก
 *
 * ใช้ Inertia router ของจริง ไม่ mock เพราะต้องพิสูจน์ว่า event ของ visit จริง
 * จบครบทุกทาง ถ้าทางไหนไม่จบ โพลจะหยุดเงียบไปจนกว่าจะเปลี่ยนหน้า
 */
import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import axios from 'axios';
import { router } from '@inertiajs/vue3';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { auth: {}, flash: {}, errors: {} }, url: '/admin' }),
}));

import AdminLayout from '@/Layouts/AdminLayout.vue';
import Finance from '@/Pages/Admin/Finance/Index.vue';
import MasterNode from '@/Pages/Admin/MasterNode/Index.vue';
import Treasury from '@/Pages/Admin/Treasury/Index.vue';
import Validators from '@/Pages/Admin/Validators/Index.vue';

const POLL_MS = 30000;
const SALE_URL = '/admin/token-sales';

const fetchMock = vi.fn();
let visits = [];
let pagePolls = [];
let wrappers = [];

/** ตอบแบบ Inertia หลังตาม redirect แล้ว error มาทาง props เหมือนของจริง */
function inertiaPage(config, errors = {}) {
    return {
        data: { component: 'Admin/TokenSales/Index', props: { errors }, url: SALE_URL, version: null },
        status: 200,
        statusText: 'OK',
        headers: { 'x-inertia': 'true' },
        config,
    };
}

/** visit ค้างจนเทสต์สั่งจบ ส่วนโพลของหน้าตอบทันที */
function testAdapter(config) {
    if (!config.headers['X-Inertia']) {
        pagePolls.push(config.url);
        return Promise.resolve({ data: {}, status: 200, statusText: 'OK', headers: {}, config });
    }

    return new Promise((res, rej) => {
        config.signal?.addEventListener('abort', () => rej(new axios.CanceledError()));
        visits.push({ config, res, rej });
    });
}

function mountAndTrack(component, options = {}) {
    const wrapper = mount(component, options);
    wrappers.push(wrapper);
    return wrapper;
}

async function mountLayout() {
    const wrapper = mountAndTrack(AdminLayout);
    await flushPromises();
    fetchMock.mockClear();
    return wrapper;
}

const tick = () => vi.advanceTimersByTimeAsync(POLL_MS);
const pollsTo = (url) => pagePolls.filter((polled) => polled === url).length;

const endings = [
    ['the server refuses it', (visit) => visit.res(inertiaPage(visit.config, { name: 'required' }))],
    ['it succeeds', (visit) => visit.res(inertiaPage(visit.config))],
    ['the server answers 500', (visit) => {
        document.addEventListener('inertia:invalid', (e) => e.preventDefault(), { once: true });
        visit.rej({ response: { status: 500, data: 'boom', headers: {} } });
    }],
    ['the network fails', (visit) => {
        document.addEventListener('inertia:exception', (e) => e.preventDefault(), { once: true });
        visit.rej(new Error('Network Error'));
    }],
    ['it is cancelled', () => router.cancel()],
];

beforeAll(() => {
    axios.defaults.adapter = testAdapter;
    router.init({
        initialPage: { component: 'Admin/TokenSales/Index', props: { errors: {} }, url: SALE_URL, version: null },
        resolveComponent: () => ({}),
        swapComponent: async () => {},
    });
});

beforeEach(() => {
    visits = [];
    pagePolls = [];
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({ ok: true, json: async () => ({ count: 0, alerts: [] }) });
    vi.stubGlobal('fetch', fetchMock);
    // เฉพาะ setInterval เพราะ flushPromises กับ Inertia ใช้ setTimeout ของจริงอยู่
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
});

afterEach(async () => {
    router.cancel();
    await flushPromises();
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers = [];
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('admin layout polling', () => {
    it('polls on schedule when no visit is running', async () => {
        await mountLayout();

        await tick();

        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
            '/admin/notifications/unread-count',
            '/admin/system-alerts/active',
        ]);
    });

    it('still loads the bell and alerts when the layout mounts', async () => {
        mountAndTrack(AdminLayout);

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('does not poll between a form POST and its redirect', async () => {
        await mountLayout();

        router.post(SALE_URL, { name: '' });
        await tick();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it.each(endings)('polls again after %s', async (_name, finish) => {
        await mountLayout();

        router.post(SALE_URL, { name: '' });
        await tick();
        expect(fetchMock).not.toHaveBeenCalled();

        finish(visits[0]);
        await flushPromises();
        await tick();

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('stays paused when a second visit interrupts the first', async () => {
        await mountLayout();

        router.post(SALE_URL, { name: '' });
        router.post(SALE_URL, { name: 'again' });
        await tick();
        expect(fetchMock).not.toHaveBeenCalled();

        visits[1].res(inertiaPage(visits[1].config));
        await flushPromises();
        await tick();

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    /*
     * Inertia ยิง finish ของ visit เดียวกันสองครั้งได้ ถ้า visit ใหม่แทรกตอนหน้าเก่ากำลัง setPage
     * ถ้านับ start/finish เป็นตัวเลข ยอดจะติดลบแล้วการหยุดโพลจะใช้ไม่ได้อีกเลยจนเปลี่ยนหน้า
     */
    it('still pauses after Inertia reports the same finish twice', async () => {
        await mountLayout();

        document.dispatchEvent(new CustomEvent('inertia:start'));
        document.dispatchEvent(new CustomEvent('inertia:finish'));
        document.dispatchEvent(new CustomEvent('inertia:finish'));
        document.dispatchEvent(new CustomEvent('inertia:start'));
        await tick();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('clears its timer and listeners on unmount', async () => {
        const added = vi.spyOn(document, 'addEventListener');
        const removed = vi.spyOn(document, 'removeEventListener');
        const inertiaEvents = (spy) => spy.mock.calls.map(([type]) => type).filter((type) => type.startsWith('inertia:'));

        const wrapper = await mountLayout();
        expect(vi.getTimerCount()).toBe(1);

        wrapper.unmount();
        wrappers = [];
        await tick();

        expect(vi.getTimerCount()).toBe(0);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(inertiaEvents(added)).not.toEqual([]);
        expect(inertiaEvents(removed).sort()).toEqual(inertiaEvents(added).sort());
    });
});

describe('admin page polling', () => {
    it.each([
        ['Finance', Finance, '/admin/finance/stripe-balance'],
        ['MasterNode', MasterNode, '/admin/masternode/stats'],
        ['Treasury', Treasury, '/admin/treasury/balances'],
        ['Validators', Validators, '/admin/validators/stats'],
    ])('%s pauses during a visit and resumes after it', async (_name, page, url) => {
        mountAndTrack(page);
        await flushPromises();

        await tick();
        expect(pollsTo(url)).toBe(1);

        router.post(SALE_URL, { name: '' });
        await tick();
        expect(pollsTo(url)).toBe(1);

        visits[0].res(inertiaPage(visits[0].config, { name: 'required' }));
        await flushPromises();
        await tick();

        expect(pollsTo(url)).toBe(2);
    });
});

describe('admin polling source', () => {
    /*
     * Auth/Login.vue รอสคริปต์ Turnstile โหลด ไม่ได้ยิงเซิร์ฟเวอร์ และทำงานก่อนมี session แอดมิน
     */
    const NOT_SERVER_POLLS = ['Pages/Admin/Auth/Login.vue'];

    it('sends every timer through useAdminPolling so a new poll cannot skip the pause', () => {
        const root = resolve(process.cwd(), 'resources/js');
        const files = ['Pages/Admin', 'Components/Admin']
            .flatMap((dir) => readdirSync(join(root, dir), { recursive: true }).map((file) => `${dir}/${String(file).replaceAll('\\', '/')}`))
            .filter((file) => file.endsWith('.vue'))
            .concat('Layouts/AdminLayout.vue')
            .filter((file) => !NOT_SERVER_POLLS.includes(file));

        expect(files.filter((file) => readFileSync(join(root, file), 'utf8').includes('setInterval('))).toEqual([]);
    });
});
