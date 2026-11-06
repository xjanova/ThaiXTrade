/**
 * TPIX TRADE — หน้าโครงการสาธารณะของผู้สร้างเหรียญ (/token-creator/projects/{uuid})
 *
 * กัน: คำเตือนความเสี่ยงหาย · ลิงก์ที่ไม่ใช่ https กลายเป็นลิงก์กดได้ · หัวข้อว่างโผล่
 *      · ภาพเอกสารชี้ไปไฟล์อื่นที่ไม่ใช่ภาพลายน้ำจากเซิร์ฟเวอร์
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import Project from '@/Pages/TokenCreator/Project.vue';
import { t } from '@/Composables/useTranslation';

const $ = (w, sel) => w.find(`[data-test="${sel}"]`);

const risks = [
    { id: 'study', th: 'ศึกษาก่อนซื้อ', en: 'Study first' },
    { id: 'no-freeze', th: 'แพลตฟอร์มอายัดเหรียญบนเชนไม่ได้', en: 'The platform cannot freeze tokens on chain' },
];

const project = (overrides = {}) => ({
    uuid: 'u-1',
    applicant_type: 'company',
    entity: { company_name: 'บริษัท สยามฟาร์ม จำกัด', registration_number: '0105566000001' },
    project_name: 'Siam Farm',
    token_symbol: 'SFARM',
    category: 'utility',
    description: 'เหรียญสะสมแต้มเกษตรกร',
    team_description: null,
    use_of_funds: 'พัฒนาแอป',
    links: { website: 'https://siamfarm.example', twitter: 'javascript:alert(1)' },
    wallet_address: '0xabc',
    starter_allocation_bps: 1000,
    kyc_verified: true,
    approved_at: '2026-11-06T03:00:00Z',
    agreement_version: '2026-11-06.2',
    agreement_hash: 'a'.repeat(64),
    documents: [{ uuid: 'd-1', type: 'company_certificate', url: '/token-creator/projects/u-1/documents/d-1' }],
    tokens: [{ id: 5, name: 'Siam Farm Token', symbol: 'SFARM', contract_address: '0x1', whitepaper_url: '/token-factory/5/whitepaper' }],
    ...overrides,
});

const mountPage = (p = {}) => mount(Project, { props: { project: project(p), risks } });

describe('TokenCreator/Project', () => {
    it('แสดงป้าย KYC คำเตือนความเสี่ยง และข้อมูลนิติบุคคล', () => {
        const w = mountPage();

        expect($(w, 'kyc-badge').text()).toContain(t('page.creatorProject.kycBadge'));
        expect($(w, 'risks').findAll('li')).toHaveLength(2);
        expect($(w, 'entity').text()).toContain('บริษัท สยามฟาร์ม จำกัด');
        expect($(w, 'starter').text()).toContain('10');
    });

    it('ลิงก์ที่ไม่ใช่ https ไม่ถูกแสดงเป็นลิงก์', () => {
        const w = mountPage();
        const hrefs = $(w, 'about').findAll('a').map((a) => a.attributes('href'));

        expect(hrefs).toEqual(['https://siamfarm.example']);
    });

    it('ภาพเอกสารมาจาก url ภาพลายน้ำของเซิร์ฟเวอร์ และลิงก์ไวท์เปเปอร์ของเหรียญ', () => {
        const w = mountPage();

        expect($(w, 'document-image').attributes('src')).toBe('/token-creator/projects/u-1/documents/d-1');
        expect($(w, 'whitepaper-link').attributes('href')).toBe('/token-factory/5/whitepaper');
    });

    it('ไม่มีข้อมูลที่ยินยอม → ไม่มีหัวข้อว่าง', () => {
        const w = mountPage({ entity: {}, documents: [], tokens: [], kyc_verified: false, links: {} });

        expect($(w, 'entity').exists()).toBe(false);
        expect($(w, 'documents').exists()).toBe(false);
        expect($(w, 'tokens').exists()).toBe(false);
        expect($(w, 'kyc-badge').exists()).toBe(false);
        expect($(w, 'risks').exists()).toBe(true);
    });
});
