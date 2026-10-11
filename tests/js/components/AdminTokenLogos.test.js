/**
 * TPIX TRADE — โลโก้เหรียญในหลังบ้าน (/admin/tokens และ /admin/token-factory)
 *
 * โลโก้ชุดนี้ขึ้น tokenlist.json ที่ explorer และ TPIX Wallet ใช้ — กติกา PNG/WebP จัตุรัส ≥128px ≤512KB
 *
 * กัน: ปุ่มแก้ไขโผล่ให้ moderator/support (เซิร์ฟเวอร์ตอบ 403 อยู่แล้ว แต่ไม่ควรล่อให้กด)
 *      · ไฟล์ผิดชนิด/ใหญ่เกินถูกส่งขึ้นเซิร์ฟเวอร์โดยไม่เตือน · ปุ่มตั้งโลโก้โผล่กับเหรียญที่ยังไม่ deploy
 *      · ฟอร์มแก้ไขไม่มีสวิตช์ "รับรอง" (ขึ้น/ไม่ขึ้น tokenlist.json)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const routerPost = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: r } = await import('vue');

    return {
        Head: { template: '<div />' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => ({ props: { flash: {}, errors: {} } }),
        router: { post: (...args) => routerPost(...args), get: vi.fn(), patch: vi.fn(), delete: vi.fn() },
        useForm: (data) => {
            const initial = { ...data };
            const form = r({
                ...data,
                errors: {},
                processing: false,
                reset() { Object.assign(form, initial); },
                clearErrors() { form.errors = {}; },
                transform() { return form; },
                post: vi.fn(),
            });
            return form;
        },
    };
});
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Admin/DataTable.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/Admin/ConfirmDialog.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/Admin/Modal.vue', () => ({
    default: { props: ['show', 'title'], template: '<div v-if="show" data-test="modal"><slot /><slot name="footer" /></div>' },
}));

import AdminTokens from '@/Pages/Admin/Tokens/Index.vue';
import AdminTokenFactory from '@/Pages/Admin/TokenFactory/Index.vue';

const fileOf = (name, type, bytes = 1024) => new File([new Uint8Array(bytes)], name, { type });

async function pick(input, file) {
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    await input.trigger('change');
    await flushPromises();
}

describe('Admin/Tokens/Index — สิทธิ์และกติกาโลโก้', () => {
    const chains = [{ id: 1, name: 'TPIX Chain' }];

    it('moderator/support ไม่เห็นปุ่มเพิ่มเหรียญ', () => {
        const wrapper = mount(AdminTokens, { props: { tokens: [], chains, canManage: false } });

        expect(wrapper.text()).not.toContain('Add Token');
        expect(wrapper.text()).not.toContain('Add your first token');
    });

    it('admin เห็นปุ่มเพิ่มเหรียญ และฟอร์มมีสวิตช์รับรอง (ค่าตั้งต้นเปิด)', async () => {
        const wrapper = mount(AdminTokens, { props: { tokens: [], chains, canManage: true } });

        const add = wrapper.findAll('button').find((b) => b.text().includes('Add Token'));
        expect(add).toBeTruthy();
        await add.trigger('click');

        const toggle = wrapper.find('[data-test="verified-toggle"]');
        expect(toggle.exists()).toBe(true);
        expect(toggle.classes()).toContain('bg-primary-500');
        expect(wrapper.text()).toContain('tokenlist.json');
    });

    it('ไฟล์ JPG/SVG และไฟล์ใหญ่เกินถูกเตือนทันที ไม่ถูกแนบไปกับฟอร์ม', async () => {
        const wrapper = mount(AdminTokens, {
            props: { tokens: [], chains, canManage: true, logoRules: { max_kb: 512, min_px: 128, types: 'PNG, WebP' } },
        });
        await wrapper.findAll('button').find((b) => b.text().includes('Add Token')).trigger('click');

        const input = wrapper.find('input[type="file"]');
        expect(input.attributes('accept')).toBe('image/png,image/webp');

        await pick(input, fileOf('logo.jpg', 'image/jpeg'));
        expect(wrapper.find('[data-test="logo-file-error"]').text()).toContain('PNG หรือ WebP');

        await pick(input, fileOf('logo.svg', 'image/svg+xml'));
        expect(wrapper.find('[data-test="logo-file-error"]').text()).toContain('PNG หรือ WebP');

        await pick(input, fileOf('big.png', 'image/png', 600 * 1024));
        expect(wrapper.find('[data-test="logo-file-error"]').text()).toContain('512 KB');

        // ไม่มีไฟล์ไหนถูกแนบ — ปุ่มยังเป็น "อัปโหลดรูป" ไม่ใช่ "เปลี่ยนไฟล์"
        expect(wrapper.text()).toContain('อัปโหลดรูป');
        expect(wrapper.text()).not.toContain('เปลี่ยนไฟล์');
    });
});

describe('Admin/TokenFactory/Index — ปุ่มตั้งโลโก้', () => {
    const token = (overrides = {}) => ({
        id: 1,
        name: 'Factory Coin',
        symbol: 'FAC',
        status: 'deployed',
        is_listed: true,
        is_verified: false,
        logo_url: null,
        contract_address: '0x' + 'f'.repeat(40),
        creator_address: '0x' + '9'.repeat(40),
        total_supply: '1000000',
        created_at: '2026-10-11T00:00:00Z',
        metadata: {},
        ...overrides,
    });

    const mountPage = (tokens, canModerate = true) => mount(AdminTokenFactory, {
        props: {
            tokens: { data: tokens, last_page: 1 },
            stats: {},
            factoryReady: { ready: true },
            factoryConfig: { policy: { types: {} } },
            canModerate,
            logoRules: { max_kb: 512, min_px: 128, types: 'PNG, WebP' },
        },
    });

    beforeEach(() => routerPost.mockReset());

    it('มีปุ่มเฉพาะเหรียญที่ deploy แล้ว และเฉพาะคนที่มีสิทธิ์', () => {
        const page = mountPage([token(), token({ id: 2, symbol: 'PEND', status: 'pending', contract_address: null })]);
        expect(page.findAll('[data-test="logo-button"]')).toHaveLength(1);
        expect(page.find('[data-test="logo-button"]').text()).toBe('ตั้งโลโก้');

        const withLogo = mountPage([token({ logo_url: '/storage/token-logos/4289/x.png' })]);
        expect(withLogo.find('[data-test="logo-button"]').text()).toBe('เปลี่ยนโลโก้');

        expect(mountPage([token()], false).find('[data-test="logo-button"]').exists()).toBe(false);
    });

    it('ไฟล์ผิดชนิดหรือใหญ่เกินไม่ถูกส่งขึ้นเซิร์ฟเวอร์ และเห็นข้อความเตือน', async () => {
        const page = mountPage([token()]);
        await page.find('[data-test="logo-button"]').trigger('click');

        const input = page.find('[data-test="logo-input"]');
        expect(input.attributes('accept')).toBe('image/png,image/webp');

        await pick(input, fileOf('logo.gif', 'image/gif'));
        expect(page.find('[data-test="logo-error"]').text()).toContain('PNG หรือ WebP');

        await pick(input, fileOf('big.webp', 'image/webp', 513 * 1024));
        expect(page.find('[data-test="logo-error"]').text()).toContain('512 KB');

        expect(routerPost).not.toHaveBeenCalled();
    });
});
