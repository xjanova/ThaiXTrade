/**
 * หน้าแอดมินตรวจ KYC ผู้สมัคร Validator — กระเป๋า owner ในเบราว์เซอร์เป็นคนเซ็น
 *
 * กันไม่ให้: ปุ่มกดได้ทั้งที่กระเป๋าไม่ใช่ owner · ส่ง tx ด้วยบัญชีที่ถูกสลับกลางทาง ·
 * บอกว่าสำเร็จก่อนเซิร์ฟเวอร์ตรวจ tx กับเชนแล้ว
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import * as ethers from 'ethers';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Layouts/AdminLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const axiosPost = vi.fn();
const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { post: (...a) => axiosPost(...a), get: (...a) => axiosGet(...a) } }));

const OWNER = '0xd2ea000000000000000000000000000000000001';
const APPLICANT = '0xabc0000000000000000000000000000000000001';
const CONTRACT = '0x443e487ca031fae41a285cf35bd89e90be7340e7';
const TX = '0x' + '1'.repeat(64);

const sentTxs = [];
let signerAddress = OWNER;

vi.mock('@/Composables/useInjectedOwnerWallet', async () => {
    const { ref } = await import('vue');
    const realEthers = await import('ethers');
    return {
        loadEthers: async () => realEthers,
        useInjectedOwnerWallet: () => {
            const address = ref('');
            return {
                address,
                connecting: ref(false),
                currentChainId: ref(4289),
                connect: async () => { address.value = signerAddress; },
                sendAs: async (expected, call) => {
                    if (address.value !== expected) throw new Error('NOT_OWNER');
                    sentTxs.push(call);
                    return { hash: TX };
                },
                waitReceipt: async () => ({ status: 1 }),
            };
        },
    };
});

import Kyc from '@/Pages/Admin/Validators/Kyc.vue';

function row(status, extra = {}) {
    return {
        applicant: APPLICANT, status, kyc_hash: null, consent_at: 1761000000, submitted_at: 0, reviewed_at: 0,
        reviewer: null, reject_reason: '', hash_matches_site: null, node: null, binding: null, actions: [],
        site_kyc: { status: 'approved', level: 'enhanced', uuid: 'kyc-uuid-1', doc_hash: '0x' + 'ab'.repeat(32), reviewed_at: null },
        ...extra,
    };
}

function mountPage(applicants) {
    return mount(Kyc, {
        props: { contract: CONTRACT, owner: OWNER, chainId: 4289, rpcUrl: 'https://rpc1.tpix.online', applicants },
    });
}

beforeEach(() => {
    sentTxs.length = 0;
    signerAddress = OWNER;
    axiosPost.mockReset().mockResolvedValue({ data: { success: true } });
    axiosGet.mockReset().mockResolvedValue({ data: { data: { applicants: [], owner: OWNER, loadError: null } } });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});
afterEach(() => vi.restoreAllMocks());

const buttonByText = (w, text) => w.findAll('button').find((b) => b.text().includes(text));

describe('Admin/Validators/Kyc', () => {
    it('keeps action buttons disabled until the owner wallet is connected', async () => {
        signerAddress = '0x' + '9'.repeat(40);
        const w = mountPage([row('ConsentGiven')]);
        expect(buttonByText(w, 'บันทึกเอกสารขึ้นเชน').attributes('disabled')).toBeDefined();

        await buttonByText(w, 'เชื่อมกระเป๋า owner').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('ไม่ใช่ owner');
        expect(buttonByText(w, 'บันทึกเอกสารขึ้นเชน').attributes('disabled')).toBeDefined();
    });

    it('submits the site KYC hash, then asks the server to verify the tx', async () => {
        const w = mountPage([row('ConsentGiven')]);
        await buttonByText(w, 'เชื่อมกระเป๋า owner').trigger('click');
        await flushPromises();

        await buttonByText(w, 'บันทึกเอกสารขึ้นเชน').trigger('click');
        await buttonByText(w, 'เซ็นและส่ง').trigger('click');
        await flushPromises();

        expect(sentTxs).toHaveLength(1);
        const parsed = new ethers.Interface(['function submitKYC(address,bytes32)']).parseTransaction({ data: sentTxs[0].data });
        expect(parsed.args[1]).toBe('0x' + 'ab'.repeat(32));
        expect(sentTxs[0].to).toBe(CONTRACT);
        expect(axiosPost).toHaveBeenCalledWith(`/admin/validators/kyc/${APPLICANT}/confirm`, {
            action: 'submit', tx_hash: TX, kyc_submission_uuid: 'kyc-uuid-1',
        });
        expect(axiosGet).toHaveBeenCalledWith('/admin/validators/kyc/applicants');
    });

    it('a manual hash needs a reason and is sent with it', async () => {
        const w = mountPage([row('ConsentGiven', { site_kyc: { status: 'none', doc_hash: null, uuid: null } })]);
        await buttonByText(w, 'เชื่อมกระเป๋า owner').trigger('click');
        await flushPromises();
        await buttonByText(w, 'บันทึกเอกสารขึ้นเชน').trigger('click');

        const inputs = w.findAll('input');
        await inputs[0].setValue('0x' + 'cd'.repeat(32));
        await buttonByText(w, 'เซ็นและส่ง').trigger('click');
        await flushPromises();
        expect(sentTxs).toHaveLength(0);
        expect(w.text()).toContain('เหตุผล');

        await inputs[1].setValue('ตรวจเอกสารตัวจริงที่สำนักงาน');
        await buttonByText(w, 'เซ็นและส่ง').trigger('click');
        await flushPromises();
        expect(sentTxs).toHaveLength(1);
        expect(axiosPost.mock.calls[0][1]).toEqual({ action: 'submit', tx_hash: TX, note: 'ตรวจเอกสารตัวจริงที่สำนักงาน' });
    });

    it('shows the server refusal and keeps a re-check button', async () => {
        axiosPost.mockRejectedValue({ response: { data: { error: { message: 'สถานะบนสัญญายังไม่ตรงกับรายการนี้' } } } });
        const w = mountPage([row('Submitted')]);
        await buttonByText(w, 'เชื่อมกระเป๋า owner').trigger('click');
        await flushPromises();

        await buttonByText(w, 'อนุมัติ').trigger('click');
        await buttonByText(w, 'เซ็นอนุมัติ').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('สถานะบนสัญญายังไม่ตรงกับรายการนี้');
        expect(buttonByText(w, 'ตรวจอีกครั้ง')).toBeTruthy();
    });

    it('reject needs a reason before anything is signed', async () => {
        const w = mountPage([row('Submitted')]);
        await buttonByText(w, 'เชื่อมกระเป๋า owner').trigger('click');
        await flushPromises();

        await buttonByText(w, 'ไม่ผ่าน').trigger('click');
        await buttonByText(w, 'เซ็นไม่ผ่าน').trigger('click');
        await flushPromises();
        expect(sentTxs).toHaveLength(0);
        expect(w.text()).toContain('ต้องใส่เหตุผลที่ไม่ผ่าน');
    });
});
