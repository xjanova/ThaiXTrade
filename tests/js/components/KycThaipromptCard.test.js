/**
 * TPIX TRADE — การ์ด "ยืนยันตัวตนด้วยบัญชี Thaiprompt" บนหน้า /kyc
 *
 * เจ้าของสั่ง: "ให้ไปยืนยันใน thaiprompt app ถ้าผ่านก็บันทึกว่าผ่านแล้ว
 *              ถ้าเคยยืนยันแล้วก็ผ่านเลย ไม่ต้องยืนยันอีก"
 *
 * กัน: ปุ่มกดได้โดยไม่ยินยอม · สถานะรอทำ eKYC ไม่บอกขั้นตอน · ผ่านแล้วยังโชว์การ์ดซ้ำ
 *      · ไม่ได้ตั้งค่า client แล้วการ์ดยังโผล่ให้กดแล้วพัง
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

const page = reactive({ props: { flash: {}, errors: {} }, url: '/kyc' });
const routerPost = vi.fn();
const routerReload = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3');
    return {
        ...actual,
        usePage: () => page,
        router: { post: (...a) => routerPost(...a), reload: (...a) => routerReload(...a), visit: vi.fn() },
        Head: { template: '<div />' },
    };
});

vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(() => Promise.resolve('data:image/png;base64,QR')) } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import axios from 'axios';
import KycIndex from '@/Pages/Kyc/Index.vue';

const base = {
    submission: null,
    history: [],
    gate: { features: {} },
    features: [],
    requirements: { basic: ['id_card_front', 'selfie_with_id'], enhanced: [] },
    uploads: { max_size_kb: 8192, extensions: ['jpg'] },
    consent: { version: '1.0', retention_days: 1825 },
    deletionRequest: null,
};

const tp = (link = null, available = true) => ({
    available,
    link,
    app_link: 'thaiprompt://ekyc?from=profile',
    download_url: 'https://main.thaiprompt.online',
});

const mountPage = (props) => mount(KycIndex, { props: { ...base, ...props } });
const button = (w, text) => w.findAll('button').find(b => b.text().includes(text));

beforeEach(() => {
    routerPost.mockReset();
    routerReload.mockReset();
    axios.post.mockReset();
    page.props.errors = {};
});

describe('Kyc page — Thaiprompt card', () => {
    it('offers Thaiprompt first and needs consent before leaving the site', async () => {
        const w = mountPage({ thaiprompt: tp() });

        expect(w.text()).toContain('ยืนยันตัวตนด้วยบัญชี Thaiprompt');
        const go = button(w, 'ยืนยันด้วย Thaiprompt');
        expect(go.attributes('disabled')).toBeDefined();

        await w.find('.tp-consent input').setValue(true);
        await go.trigger('click');

        expect(routerPost).toHaveBeenCalledWith('/kyc/thaiprompt/connect', { consent: true }, expect.any(Object));
    });

    it('keeps the document form folded as the fallback', async () => {
        const w = mountPage({ thaiprompt: tp() });

        expect(w.find('form').exists()).toBe(false);
        await button(w, 'ส่งเอกสารกับ TPIX TRADE').trigger('click');
        expect(w.find('form').exists()).toBe(true);
    });

    it('shows the in-app steps while waiting and checks again on demand', async () => {
        axios.post.mockResolvedValue({ data: { success: true, thaiprompt: { status: 'pending', needs_reconnect: false } } });
        const w = mountPage({ thaiprompt: tp({ status: 'none', needs_reconnect: false, last_checked_at: null }) });
        await flushPromises();

        expect(w.text()).toContain('สแกนบัตรประชาชนและถ่ายใบหน้า');
        // เปิดหน้ามาแล้วถามสถานะให้ทันทีหนึ่งครั้ง
        expect(axios.post).toHaveBeenCalledWith('/kyc/thaiprompt/refresh');

        await button(w, 'ตรวจสอบอีกครั้ง').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('Thaiprompt กำลังตรวจ');
    });

    it('reloads the page as soon as Thaiprompt says approved', async () => {
        axios.post.mockResolvedValue({ data: { success: true, thaiprompt: { status: 'approved', needs_reconnect: false } } });
        mountPage({ thaiprompt: tp({ status: 'pending', needs_reconnect: false }) });
        await flushPromises();

        expect(routerReload).toHaveBeenCalled();
    });

    it('asks to reconnect when the link expired', () => {
        const w = mountPage({ thaiprompt: tp({ status: 'none', needs_reconnect: true }) });

        expect(w.text()).toContain('สิทธิ์เชื่อมกับ Thaiprompt หมดอายุแล้ว');
        expect(button(w, 'ยืนยันด้วย Thaiprompt')).toBeTruthy();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('hides the card once the account is verified', () => {
        const w = mountPage({
            submission: { uuid: 'u1', status: 'approved', source: 'thaiprompt', reviewed_at: '2026-10-09T10:00:00Z', documents: [] },
            thaiprompt: tp({ status: 'approved', needs_reconnect: false }),
        });

        expect(w.find('.tp-card').exists()).toBe(false);
        expect(w.text()).toContain('ยืนยันผ่านบัญชี Thaiprompt');
    });

    it('stays out of the way when the Thaiprompt client is not configured', () => {
        const w = mountPage({ thaiprompt: tp(null, false) });

        expect(w.find('.tp-card').exists()).toBe(false);
        expect(w.find('form').exists()).toBe(true);
    });

    it('shows the error that came back from the Thaiprompt redirect', () => {
        page.props.errors = { kyc: 'คุณยังไม่ได้อนุญาตให้ Thaiprompt ส่งผลยืนยันตัวตนมาให้ TPIX TRADE' };
        const w = mountPage({ thaiprompt: tp() });

        expect(w.text()).toContain('คุณยังไม่ได้อนุญาตให้ Thaiprompt');
    });
});
