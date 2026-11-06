/**
 * TPIX TRADE — หน้ารวมโครงการผู้สร้างเหรียญ (/token-creator/projects)
 *
 * กัน: หน้าว่างไม่มีข้อความ · ชื่อที่ไม่ยินยอมเผยแพร่โผล่เป็นช่องว่าง · ลิงก์ไวท์เปเปอร์/explorer/หน้าโครงการหาย
 *      · ค้นหาแล้วไม่เจอแต่ขึ้นข้อความ "ยังไม่มีโครงการ"
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

const routerGet = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: (...args) => routerGet(...args) },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import Projects from '@/Pages/TokenCreator/Projects.vue';
import { t } from '@/Composables/useTranslation';

const $ = (w, sel) => w.find(`[data-test="${sel}"]`);

const project = (overrides = {}) => ({
    uuid: 'u-1',
    url: '/token-creator/projects/u-1',
    applicant_type: 'company',
    display_name: 'บริษัท สยามฟาร์ม จำกัด',
    display_name_en: 'Siam Farm Co., Ltd.',
    registration_number: '0105566000001',
    project_name: 'Siam Farm',
    token_symbol: 'SFARM',
    category: 'utility',
    approved_at: '2026-11-06T03:00:00Z',
    tokens: [{
        id: 5, name: 'Siam Farm Token', symbol: 'SFARM', contract_address: '0x1234567890abcdef1234567890abcdef12345678',
        whitepaper_url: '/token-factory/5/whitepaper', explorer_url: 'https://explorer.tpix.online/address/0x1234567890abcdef1234567890abcdef12345678',
    }],
    ...overrides,
});

const mountPage = (props = {}) => mount(Projects, {
    props: { projects: [project()], meta: { page: 1, last_page: 1, total: 1 }, q: '', ...props },
});

describe('TokenCreator/Projects', () => {
    beforeEach(() => routerGet.mockReset());

    it('ยังไม่มีโครงการ → ข้อความหน้าว่างและปุ่มลงทะเบียน', () => {
        const w = mountPage({ projects: [], meta: { page: 1, last_page: 1, total: 0 } });

        expect($(w, 'empty').text()).toContain(t('page.creatorProjects.emptyTitle'));
        expect($(w, 'empty').find('a').attributes('href')).toBe('/token-creator');
        expect($(w, 'projects').exists()).toBe(false);
    });

    it('ค้นแล้วไม่เจอ → บอกว่าไม่พบ ไม่ใช่ "ยังไม่มีโครงการ"', () => {
        const w = mountPage({ projects: [], meta: { page: 1, last_page: 1, total: 0 }, q: 'zzz' });

        expect($(w, 'empty').text()).toContain('zzz');
        expect($(w, 'empty').text()).not.toContain(t('page.creatorProjects.emptyTitle'));
    });

    it('แสดงบริษัท เหรียญ และลิงก์ไวท์เปเปอร์/explorer/หน้าโครงการ', () => {
        const w = mountPage();

        expect($(w, 'display-name').text()).toContain('บริษัท สยามฟาร์ม จำกัด');
        expect($(w, 'whitepaper-link').attributes('href')).toBe('/token-factory/5/whitepaper');
        expect($(w, 'explorer-link').attributes('href')).toContain('/address/0x1234567890abcdef');
        expect($(w, 'explorer-link').attributes('rel')).toContain('noopener');
        expect($(w, 'project-link').attributes('href')).toBe('/token-creator/projects/u-1');
    });

    it('ผู้สร้างไม่เผยแพร่ชื่อ → ขึ้นข้อความแทนช่องว่าง', () => {
        const w = mountPage({ projects: [project({ applicant_type: 'individual', display_name: null, display_name_en: null, registration_number: null, tokens: [] })] });

        expect($(w, 'display-name').text()).toContain(t('page.creatorProjects.nameHidden'));
        expect(w.text()).toContain(t('page.creatorProjects.noTokens'));
    });

    it('กดค้นหา → ส่ง q ที่ตัดช่องว่างแล้ว', async () => {
        const w = mountPage();
        await w.find('input[name="q"]').setValue('  0xabc  ');
        await $(w, 'search').trigger('submit');

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet.mock.calls[0][0]).toBe('/token-creator/projects');
        expect(routerGet.mock.calls[0][1]).toEqual({ q: '0xabc' });
    });
});
