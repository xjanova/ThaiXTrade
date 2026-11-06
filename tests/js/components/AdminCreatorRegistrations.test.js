/**
 * TPIX TRADE — หลังบ้านตรวจใบลงทะเบียนผู้สร้างเหรียญ
 *
 * กัน: อนุมัติได้ทั้งที่ KYC/หลักฐานไม่ผ่าน · อนุมัติโดยไม่ถามยืนยัน · ส่งเปอร์เซ็นต์แทน bps
 *      · ปฏิเสธโดยไม่มีเหตุผล · แอดมินทั่วไปเห็นปุ่มบันทึกการตั้งค่า
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

const page = reactive({ props: { flash: {}, errors: {} } });
const formCalls = [];

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: r } = await import('vue');
    return {
        Head: { template: '<div />' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => page,
        router: { get: vi.fn() },
        useForm: (data) => {
            const form = r({
                ...data,
                errors: {},
                processing: false,
                _transform: (d) => d,
                transform(fn) { form._transform = fn; return form; },
                data() { return Object.fromEntries(Object.keys(data).map((k) => [k, form[k]])); },
            });
            for (const method of ['post', 'put']) {
                form[method] = (url, opts) => formCalls.push({ method, url, payload: form._transform(form.data()), opts });
            }
            return form;
        },
    };
});
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import Index from '@/Pages/Admin/CreatorRegistrations/Index.vue';
import Show from '@/Pages/Admin/CreatorRegistrations/Show.vue';
import { t } from '@/Composables/useTranslation';

const $ = (w, sel) => w.find(`[data-test="${sel}"]`);

const registration = (overrides = {}) => ({
    uuid: '11111111-2222-3333-4444-555555555555',
    status: 'submitted',
    wallet_address: '0xabcdef0000000000000000000000000000000001',
    project_name: 'Siam Farm',
    token_symbol: 'SFARM',
    form: { applicant_type: 'individual', full_name: 'สมชาย ใจดี', category: 'utility', source_of_funds: 'own_funds', is_pep: false },
    agreement_message: 'TPIX Token Creator Agreement',
    agreement_signature: '0x' + '1'.repeat(130),
    starter_allocation_bps: null,
    reviewed_at: null,
    rejection_reason: null,
    ...overrides,
});

const goodEvidence = {
    ownership_signature_valid: true,
    agreement_signature_valid: true,
    agreement_hash_matches: true,
    form_hash_matches: true,
};

const mountShow = (props = {}) => mount(Show, {
    props: {
        registration: registration(),
        user: { id: 7, name: 'สมชาย', email: 'somchai@example.com' },
        kyc: { approved: true, level: 'basic' },
        evidence: goodEvidence,
        defaultStarterBps: 1000,
        maxStarterBps: 2000,
        canDecide: true,
        ...props,
    },
});

let confirmSpy;
beforeEach(() => {
    formCalls.length = 0;
    page.props.errors = {};
    page.props.flash = {};
    confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
});
afterEach(() => confirmSpy.mockRestore());

describe('Admin/CreatorRegistrations/Show', () => {
    it('อนุมัติ: ถามยืนยัน แล้วส่งเป็น bps', async () => {
        const w = mountShow();
        await $(w, 'starter-input').setValue('12.5');
        await $(w, 'approve').trigger('click');

        expect(confirmSpy).toHaveBeenCalled();
        expect(formCalls).toHaveLength(1);
        expect(formCalls[0].url).toBe('/admin/creator-registrations/11111111-2222-3333-4444-555555555555/approve');
        expect(formCalls[0].payload).toEqual({ starter_allocation_bps: 1250 });
    });

    it('กดยกเลิกในกล่องยืนยัน → ไม่ส่ง', async () => {
        confirmSpy.mockReturnValue(false);
        const w = mountShow();
        await $(w, 'approve').trigger('click');

        expect(formCalls).toHaveLength(0);
    });

    it('KYC ไม่ผ่าน → ปุ่มอนุมัติปิด', async () => {
        const w = mountShow({ kyc: { approved: false, level: null } });

        expect($(w, 'kyc-missing').exists()).toBe(true);
        expect($(w, 'approve').attributes('disabled')).toBeDefined();
        await $(w, 'approve').trigger('click');
        expect(formCalls).toHaveLength(0);
    });

    it('หลักฐานลายเซ็นไม่ผ่าน → ปุ่มอนุมัติปิดและเห็นว่าข้อไหนไม่ผ่าน', () => {
        const w = mountShow({ evidence: { ...goodEvidence, form_hash_matches: false } });

        expect($(w, 'approve').attributes('disabled')).toBeDefined();
        expect($(w, 'evidence-formHash').classes()).toContain('text-trading-red');
    });

    it('ปฏิเสธต้องมีเหตุผลอย่างน้อย 5 ตัวอักษร', async () => {
        const w = mountShow();
        expect($(w, 'reject').attributes('disabled')).toBeDefined();

        await $(w, 'reason-input').setValue('เว็บไซต์โครงการยังไม่ครบ');
        await $(w, 'reject').trigger('click');

        expect(formCalls[0]).toMatchObject({ method: 'post', url: '/admin/creator-registrations/11111111-2222-3333-4444-555555555555/reject' });
        expect(formCalls[0].payload.reason).toBe('เว็บไซต์โครงการยังไม่ครบ');
    });

    it('ใบที่ตัดสินแล้ว → ไม่มีปุ่มอนุมัติ/ปฏิเสธ แสดงส่วนเริ่มโครงการ', () => {
        const w = mountShow({ registration: registration({ status: 'approved', starter_allocation_bps: 1500, reviewed_at: '2026-11-06T00:00:00Z' }) });

        expect($(w, 'approve').exists()).toBe(false);
        expect($(w, 'reject').exists()).toBe(false);
        expect($(w, 'decision').text()).toContain('15%');
    });

    it('ไม่ใช่ super admin → เห็นข้อมูลแต่ไม่มีปุ่มตัดสิน', () => {
        const w = mountShow({ canDecide: false });

        expect($(w, 'approve').exists()).toBe(false);
        expect($(w, 'reject').exists()).toBe(false);
        expect($(w, 'super-only').exists()).toBe(true);
    });

    it('error จากเซิร์ฟเวอร์ขึ้นให้เห็น และมีลิงก์ PDF', () => {
        page.props.errors = { registration: 'ผู้สร้างยังไม่ผ่าน KYC' };
        const w = mountShow();

        expect($(w, 'error').text()).toBe('ผู้สร้างยังไม่ผ่าน KYC');
        expect($(w, 'pdf').attributes('href')).toBe('/admin/creator-registrations/11111111-2222-3333-4444-555555555555/pdf');
    });
});

describe('Admin/CreatorRegistrations/Show — เอกสารประกอบ', () => {
    const doc = (overrides = {}) => ({
        uuid: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', type: 'company_certificate', mime: 'application/pdf',
        size: 4096, sha256: 'e'.repeat(64), original_name: 'cert.pdf', publish: false, public: false, ...overrides,
    });

    it('แสดงรายการ ลิงก์เปิดไฟล์ผ่านหลังบ้าน และบอกว่าเผยแพร่หรือไม่', () => {
        const w = mountShow({
            registration: registration({ documents: [doc(), doc({ uuid: 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee', type: 'project_photo', public: true })], public_url: '/token-creator/projects/x' }),
        });

        const rows = w.findAll('[data-test="document-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].find('[data-test="document-open"]').attributes('href'))
            .toBe('/admin/creator-registrations/11111111-2222-3333-4444-555555555555/documents/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        expect(rows[1].text()).toContain(t('admin.creatorRegistrations.show.docPublic'));
        expect($(w, 'public-url').attributes('href')).toBe('/token-creator/projects/x');
    });

    it('ขาดเอกสารที่จำเป็น → เตือน · ไม่มีเอกสาร → ข้อความว่าง', () => {
        const w = mountShow({ registration: registration({ documents: [], missing_documents: ['company_certificate'] }) });

        expect($(w, 'documents-missing').text()).toContain(t('page.tokenCreator.docs.types.company_certificate'));
        expect($(w, 'documents-empty').exists()).toBe(true);
    });

    it('เอกสารไม่ตรงกับที่ลงนาม → ปุ่มอนุมัติปิด', () => {
        const w = mountShow({ evidence: { ...goodEvidence, documents_hash_matches: false } });

        expect($(w, 'approve').attributes('disabled')).toBeDefined();
    });
});

describe('Admin/CreatorRegistrations/Index', () => {
    const base = {
        registrations: { data: [registration()], links: [], last_page: 1 },
        filters: { status: 'submitted' },
        counts: { draft: 0, submitted: 1, approved: 0, rejected: 0 },
        settings: { required: false, default_starter_bps: 1000, max_starter_bps: 2000 },
    };

    it('super admin บันทึกการตั้งค่า → ส่ง bps', async () => {
        const w = mount(Index, { props: { ...base, canEditSettings: true } });
        await $(w, 'required-toggle').setValue(true);
        await $(w, 'default-starter').setValue('5');
        await $(w, 'save-settings').trigger('click');

        expect(formCalls[0]).toMatchObject({ method: 'put', url: '/admin/creator-registrations/settings' });
        expect(formCalls[0].payload).toEqual({ required: true, default_starter_bps: 500 });
    });

    it('แอดมินทั่วไป → ไม่มีปุ่มบันทึก ช่องแก้ไม่ได้', () => {
        const w = mount(Index, { props: { ...base, canEditSettings: false } });

        expect($(w, 'save-settings').exists()).toBe(false);
        expect($(w, 'required-toggle').attributes('disabled')).toBeDefined();
    });

    it('ตารางว่าง → ข้อความว่าง', () => {
        const w = mount(Index, { props: { ...base, registrations: { data: [] } } });

        expect($(w, 'empty').exists()).toBe(true);
        expect(w.findAll('[data-test="row"]')).toHaveLength(0);
    });
});
