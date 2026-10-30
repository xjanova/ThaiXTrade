/**
 * TPIX TRADE — แท็บ Security, แผนที่ และฟอร์มคีย์ในแท็บ AI แก้ได้เฉพาะ super_admin
 *
 * เซิร์ฟเวอร์ตอบ 403 และไม่ส่งค่ากลุ่มเหล่านี้ให้ระดับล่างอยู่แล้ว
 * หน้านี้กันแค่แอดมินระดับล่างเจอฟอร์มว่าง ๆ แล้วกดบันทึกไปชน 403
 *
 * กัน: แท็บโผล่ให้ระดับล่าง · ไม่ส่ง prop แล้วเปิดแท็บให้เอง · สิทธิ์เงินกับสิทธิ์ความปลอดภัยปนกัน
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    usePage: () => ({ props: {} }),
    router: { post: vi.fn() },
    useForm: (data) => reactive({ ...data, errors: {}, processing: false, put: vi.fn(), post: vi.fn() }),
}));
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import Settings from '@/Pages/Admin/Settings/Index.vue';

function mountSettings(props = {}) {
    return mount(Settings, { props, global: { mocks: { $page: { props: {} } } } });
}

function tabLabels(props = {}) {
    return mountSettings(props).findAll('button').map((button) => button.text());
}

describe('หน้าตั้งค่า — แท็บ AI และแผนที่', () => {
    it('super_admin เห็นฟอร์มคีย์และแท็บแผนที่', () => {
        const wrapper = mountSettings({ canManageMoney: true, canManageSecurity: true });

        expect(wrapper.findAll('button').map((button) => button.text())).toContain('แผนที่');
        expect(wrapper.text()).toContain('Cloudflare Worker URL');
        expect(wrapper.text()).toContain('ที่ปรึกษา AI ของบอทเทรด');
    });

    it('แอดมินระดับล่างไม่เห็นฟอร์มคีย์ แต่ยังตั้งเวลา Auto Content ได้', () => {
        const wrapper = mountSettings({ canManageMoney: false, canManageSecurity: false });

        expect(wrapper.findAll('button').map((button) => button.text())).not.toContain('แผนที่');
        expect(wrapper.text()).not.toContain('Cloudflare Worker URL');
        expect(wrapper.text()).not.toContain('ที่ปรึกษา AI ของบอทเทรด');
        expect(wrapper.text()).toContain('Save Content Schedule');
    });

    it('ไม่ส่ง prop มาต้องถือว่าไม่มีสิทธิ์', () => {
        expect(mountSettings().text()).not.toContain('Cloudflare Worker URL');
    });
});

describe('หน้าตั้งค่า — แท็บ Security', () => {
    it('super_admin เห็นแท็บ Security', () => {
        expect(tabLabels({ canManageMoney: true, canManageSecurity: true })).toContain('Security');
    });

    it('แอดมินระดับล่างไม่เห็นแท็บ Security แต่ยังเห็นแท็บงานประจำ', () => {
        const labels = tabLabels({ canManageMoney: false, canManageSecurity: false });

        expect(labels).not.toContain('Security');
        ['General', 'SEO', 'AI', 'Social'].forEach((tab) => expect(labels).toContain(tab));
    });

    it('ไม่ส่ง prop มาต้องถือว่าไม่มีสิทธิ์', () => {
        expect(tabLabels()).not.toContain('Security');
    });

    it('สิทธิ์เงินอย่างเดียวไม่เปิดแท็บ Security', () => {
        expect(tabLabels({ canManageMoney: true, canManageSecurity: false })).not.toContain('Security');
    });
});
